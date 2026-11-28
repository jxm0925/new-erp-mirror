<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionOperation;
use App\Models\Erp\ProductionPerformanceBinding;
use App\Models\Erp\ProductionPerformanceTemplate;
use App\Models\Erp\ItemCategory;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 提成模板：按标准工序设一套比例（如 下料 0.5%、焊接 1.5%）。
 * 适用范围：一套设为默认模板，可再按商品分类指定其他模板；BOM 节点上的指定比例/不计优先于模板。
 * 已生成的工单和包装作业沿用生成时的比例快照，模板之后再改不追溯。
 */
final class ProductionPerformanceTemplateService
{
    public const PERMISSION = 'production.performance.manage';
    public const READ_PERMISSIONS = ['production.performance.view', 'production.performance.edit', self::PERMISSION];
    public const WRITE_PERMISSIONS = ['production.performance.edit', self::PERMISSION];

    public function paginate(array $filters): array
    {
        $page = ProductionPerformanceTemplate::query()->with('lines.operation:id,operation_no,operation_name')
            ->when($filters['keyword'] ?? null, fn ($q, $v) => $q->where('template_name', 'like', '%'.$v.'%'))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderByRaw("status = 'enabled' desc")->orderByDesc('updated_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 10));
        $page->getCollection()->transform(fn ($template) => $this->present($template));
        return $page->toArray();
    }

    /** 模板里可选的标准工序（只读，不要求工序管理权限）。 */
    public function operations(?string $keyword): array
    {
        return ProductionOperation::query()->where('status', 'enabled')
            ->when($keyword, fn ($q, $v) => $q->where(fn ($w) => $w->where('operation_name', 'like', '%'.$v.'%')->orWhere('operation_no', 'like', '%'.$v.'%')))
            ->orderBy('sort')->orderBy('id')->limit(200)->get(['id', 'operation_no', 'operation_name'])->toArray();
    }

    public function show(int $id): array
    {
        return $this->present(ProductionPerformanceTemplate::query()->with('lines.operation:id,operation_no,operation_name')->findOrFail($id), true);
    }

    public function save(?int $id, array $data, int $actor): array
    {
        return DB::transaction(function () use ($id, $data, $actor): array {
            $template = $id ? ProductionPerformanceTemplate::query()->lockForUpdate()->findOrFail($id) : new ProductionPerformanceTemplate();
            if ($id && (int) ($data['expected_version'] ?? 0) !== (int) $template->business_version)
                $this->fail('performance_template_version_conflict', '模板已被其他人修改，请刷新后再保存。', 409);
            $name = trim((string) $data['template_name']);
            if (ProductionPerformanceTemplate::query()->where('template_name', $name)->when($id, fn ($q) => $q->whereKeyNot($id))->exists())
                $this->fail('performance_template_name_duplicate', '已有同名模板，请换一个名称。', 422);
            $lines = $this->lines($data['lines'] ?? []);
            if ($id && ($data['status'] ?? $template->status) === 'disabled' && $template->status === 'enabled') $this->assertUnused($template, '停用');
            $template->fill(['template_name' => $name, 'remark' => trim((string) ($data['remark'] ?? '')) ?: null,
                'status' => $data['status'] ?? ($template->status ?: 'enabled'), 'updated_by_legacy_id' => $actor]);
            if (! $id) $template->fill(['created_by_legacy_id' => $actor, 'business_version' => 1]);
            else $template->business_version = (int) $template->business_version + 1;
            $template->save();
            $template->lines()->delete();
            foreach ($lines as $line) $template->lines()->create($line);
            return $this->show((int) $template->id);
        });
    }

    public function destroy(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $template = ProductionPerformanceTemplate::query()->lockForUpdate()->findOrFail($id);
            $this->assertUnused($template, '删除');
            $template->delete();
        });
    }

    /** 设为默认模板：没有指定模板的路线都按它计提成。 */
    public function setDefault(int $id, object $user): array
    {
        $actor = $this->actor($user);
        return DB::transaction(function () use ($id, $actor): array {
            $template = ProductionPerformanceTemplate::query()->lockForUpdate()->findOrFail($id);
            if ($template->status !== 'enabled') $this->fail('performance_template_disabled', '模板已停用，请先启用。', 422);
            $binding = ProductionPerformanceBinding::query()->lockForUpdate()->firstOrNew(['binding_key' => ProductionPerformanceBinding::DEFAULT_KEY]);
            if (! $binding->exists) $binding->created_by_legacy_id = $actor;
            $binding->fill(['category_id' => null, 'template_id' => $template->id, 'updated_by_legacy_id' => $actor])->save();
            $this->log('set_default_performance_template', 'performance_template', (int) $template->id, $actor, ['template_id' => (int) $template->id, 'template_name' => $template->template_name]);
            return $this->show((int) $template->id);
        });
    }

    /** 一次替换完整分类范围，空集合表示全部解除；历史工单的比例快照不变。 */
    public function syncCategories(int $id, array $categoryIds, ?int $expectedVersion, object $user): array
    {
        return $this->changeCategories($id, $categoryIds, $expectedVersion, $user, 'replace');
    }

    public function bindCategories(int $id, array $categoryIds, object $user, ?int $expectedVersion = null): array
    {
        return $this->changeCategories($id, $categoryIds, $expectedVersion, $user, 'bind');
    }

    public function unbindCategories(int $id, array $categoryIds, object $user, ?int $expectedVersion = null): array
    {
        return $this->changeCategories($id, $categoryIds, $expectedVersion, $user, 'unbind');
    }

    private function changeCategories(int $id, array $categoryIds, ?int $expectedVersion, object $user, string $mode): array
    {
        $actor = $this->actor($user);
        $ids = array_map('intval', $categoryIds);
        if (array_filter($ids, fn ($value) => $value < 1) || count($ids) !== count(array_unique($ids))) $this->fail('performance_category_invalid', '商品分类不能重复或为空。', 422);
        return DB::transaction(function () use ($id, $ids, $expectedVersion, $actor, $mode): array {
            $template = ProductionPerformanceTemplate::query()->lockForUpdate()->findOrFail($id);
            if ($expectedVersion !== null && $expectedVersion !== (int) $template->business_version) $this->fail('performance_template_version_conflict', '模板或适用范围已被其他人修改，请刷新后重新确认。', 409);
            $current = ProductionPerformanceBinding::query()->where('template_id', $id)->whereNotNull('category_id')->orderBy('category_id')->lockForUpdate()->get();
            $before = $current->pluck('category_id')->map(fn ($value) => (int) $value)->all();
            $desired = match ($mode) {
                'bind' => array_values(array_unique(array_merge($before, $ids))),
                'unbind' => array_values(array_diff($before, $ids)),
                default => $ids,
            };
            sort($desired);
            // 两个模板即便都还没有绑定行，也会争用同一分类行锁，不能互相抢占。
            $categories = ItemCategory::query()->whereIn('id', array_unique(array_merge($before, $ids)))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            // 解除只删除本模板的绑定，必须允许逐次清理停用、错类型等历史范围。
            if ($mode !== 'unbind') {
                foreach ($desired as $categoryId) {
                    $category = $categories->get($categoryId);
                    if (! $category || $category->category_type !== 'product' || $category->status !== 'enabled') $this->fail('performance_category_invalid', '只能绑定已启用的商品分类', 422);
                }
                if ($desired && $template->status !== 'enabled') $this->fail('performance_template_disabled', '模板已停用，请先启用。', 422);
                $occupied = ProductionPerformanceBinding::query()->whereIn('category_id', $desired)->where('template_id', '<>', $id)->orderBy('category_id')->lockForUpdate()->first();
                if ($occupied) $this->fail('performance_category_conflict', '商品分类“'.($categories->get((int) $occupied->category_id)?->category_name ?? $occupied->category_id).'”已指定其他模板，请先在原模板解除后再选择。', 409);
            }
            $added = array_values(array_diff($desired, $before));
            $removed = array_values(array_diff($before, $desired));
            if (! $added && ! $removed) return $this->show($id);
            if ($removed) ProductionPerformanceBinding::query()->where('template_id', $id)->whereIn('category_id', $removed)->delete();
            foreach ($added as $categoryId) ProductionPerformanceBinding::create([
                'binding_key' => ProductionPerformanceBinding::categoryKey($categoryId), 'category_id' => $categoryId,
                'template_id' => $id, 'created_by_legacy_id' => $actor, 'updated_by_legacy_id' => $actor,
            ]);
            $previousVersion = (int) $template->business_version;
            $template->update(['last_applied_at' => now(), 'business_version' => $previousVersion + 1, 'updated_by_legacy_id' => $actor]);
            $this->log($mode.'_performance_template_categories', 'performance_template', $id, $actor, [
                'template_id' => $id, 'before_category_ids' => $before, 'category_ids' => $desired,
                'added_category_ids' => $added, 'removed_category_ids' => $removed,
                'before_version' => $previousVersion, 'after_version' => (int) $template->business_version,
            ]);
            return $this->show($id);
        }, 5);
    }

    private function assertUnused(ProductionPerformanceTemplate $template, string $action): void
    {
        $bindings = ProductionPerformanceBinding::query()->where('template_id', $template->id)->get();
        if ($bindings->isEmpty()) return;
        $isDefault = $bindings->contains(fn ($row) => $row->binding_key === ProductionPerformanceBinding::DEFAULT_KEY);
        $categories = $bindings->whereNotNull('category_id')->count();
        $usage = implode('、', array_filter([$isDefault ? '默认模板' : null, $categories ? "{$categories} 个商品分类" : null]));
        $this->fail('performance_template_in_use', "模板正在被使用（{$usage}），请先把默认模板或这些分类改用其他模板后再{$action}。", 422);
    }

    private function log(string $type, string $entityType, int $entityId, int $actor, array $snapshot): void
    {
        DB::table('erp_production_master_commands')->insert([
            'client_command_id' => $type.':'.$entityId.':'.Str::uuid(), 'command_type' => $type, 'entity_type' => $entityType, 'entity_id' => $entityId,
            'request_hash' => hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE)),
            'response_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE),
            'initiated_by_legacy_id' => $actor, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function actor(object $user): int
    {
        $actor = (int) ($user->legacy_id ?? 0);
        if ($actor <= 0) $this->fail('performance_template_actor_missing', '请使用真实账号操作。', 403);
        return $actor;
    }

    private function lines(array $rows): array
    {
        if ($rows === [] || count($rows) > 100) $this->fail('performance_template_lines_invalid', '请至少设置一道工序的比例（最多 100 道）。', 422);
        $ids = array_map(fn ($row) => (int) ($row['operation_id'] ?? 0), $rows);
        if (in_array(0, $ids, true) || count($ids) !== count(array_unique($ids))) $this->fail('performance_template_operation_invalid', '每道工序只能出现一次。', 422);
        if (ProductionOperation::query()->whereIn('id', $ids)->count() !== count($ids)) $this->fail('performance_template_operation_invalid', '部分工序不存在，请重新选择。', 422);
        $calculator = app(ProductionPerformanceCalculator::class);
        return array_map(function ($row) use ($calculator): array {
            $rate = $calculator->ratio($row['performance_rate'] ?? null);
            if (bccomp($rate, bcadd($rate, '0', 6), 8) !== 0) $this->fail('performance_ratio_precision', '比例最多保留 4 位小数的百分比。', 422);
            return ['operation_id' => (int) $row['operation_id'], 'performance_rate' => bcadd($rate, '0', 6)];
        }, $rows);
    }

    private function present(ProductionPerformanceTemplate $template, bool $withCategories = false): array
    {
        $bindings = ProductionPerformanceBinding::query()->where('template_id', $template->id)->get();
        $categoryIds = $bindings->whereNotNull('category_id')->pluck('category_id')->all();
        $categories = $withCategories && $categoryIds ? ItemCategory::query()->whereIn('id', $categoryIds)->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'category_code', 'category_name'])->map(fn ($c) => ['id' => (int) $c->id, 'category_code' => $c->category_code, 'category_name' => $c->category_name])->values()->all() : [];
        $lines = $template->lines->map(fn ($line) => ['operation_id' => (int) $line->operation_id, 'performance_rate' => (string) $line->performance_rate,
            'operation_no' => $line->operation?->operation_no, 'operation_name' => $line->operation?->operation_name])->values()->all();
        return ['id' => (int) $template->id, 'template_name' => $template->template_name, 'remark' => $template->remark,
            'status' => $template->status, 'business_version' => (int) $template->business_version, 'lines' => $lines,
            'total_rate' => array_reduce($lines, fn ($sum, $line) => bcadd($sum, $line['performance_rate'], 6), '0'),
            'is_default' => $bindings->contains(fn ($row) => $row->binding_key === ProductionPerformanceBinding::DEFAULT_KEY),
            'category_count' => count($categoryIds), 'categories' => $categories,
            'last_applied_at' => optional($template->last_applied_at)->toDateTimeString(), 'updated_at' => optional($template->updated_at)->toDateTimeString()];
    }

    private function fail(string $code, string $message, int $status): never { throw new WorkOrderDomainException($code, $message, $status); }
}
