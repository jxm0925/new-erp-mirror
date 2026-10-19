<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionOperation;
use App\Models\Erp\ProductionPerformanceTemplate;
use App\Models\Erp\ProductionRouting;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 提成模板：按标准工序设一套比例（如 下料 0.5%、焊接 1.5%），一键套用到多条工艺路线。
 * 只改路线上的工序比例；已生成的工单和任务沿用自己的比例快照，不会被追溯修改。
 */
final class ProductionPerformanceTemplateService
{
    public const PERMISSION = 'production.performance.manage';

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
        return $this->present(ProductionPerformanceTemplate::query()->with('lines.operation:id,operation_no,operation_name')->findOrFail($id));
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
        ProductionPerformanceTemplate::query()->findOrFail($id)->delete();
    }

    /** 预览：每条路线逐道工序列出“现在 → 套用后”，没有在模板里的工序保持不变。 */
    public function preview(int $id, array $routingIds): array
    {
        $template = ProductionPerformanceTemplate::query()->with('lines')->findOrFail($id);
        $rates = $template->lines->mapWithKeys(fn ($line) => [(int) $line->operation_id => (string) $line->performance_rate])->all();
        $routings = ProductionRouting::query()->with(['operations.operation:id,operation_name', 'outputItem:id,item_code,item_name'])
            ->whereIn('id', $routingIds)->orderBy('id')->get();
        if ($routings->count() !== count(array_unique($routingIds))) $this->fail('performance_template_routing_missing', '部分工艺路线不存在，请刷新后重新选择。', 422);
        return $routings->map(function ($routing) use ($rates): array {
            $operations = $routing->operations->map(function ($operation) use ($rates): array {
                $current = $operation->performance_rate === null ? null : (string) $operation->performance_rate;
                $next = array_key_exists((int) $operation->operation_id, $rates) ? $rates[(int) $operation->operation_id] : $current;
                return ['routing_operation_id' => (int) $operation->id, 'sequence' => (int) $operation->sequence,
                    'operation_name' => $operation->operation?->operation_name, 'current_rate' => $current, 'new_rate' => $next,
                    'matched' => array_key_exists((int) $operation->operation_id, $rates),
                    'changed' => $current === null ? $next !== null : ($next === null || bccomp($current, $next, 6) !== 0)];
            })->values()->all();
            $blocked = in_array($routing->status, ['retired', 'disabled'], true) ? '已停用的路线不能套用' : null;
            return ['routing_id' => (int) $routing->id, 'routing_no' => $routing->routing_no, 'routing_name' => $routing->routing_name,
                'version' => (int) $routing->version, 'status' => $routing->status, 'business_version' => (int) $routing->business_version,
                'output_item' => $routing->outputItem ? ['item_code' => $routing->outputItem->item_code, 'item_name' => $routing->outputItem->item_name] : null,
                'operations' => $operations, 'changed_count' => count(array_filter($operations, fn ($row) => $row['changed'])),
                'unmatched_count' => count(array_filter($operations, fn ($row) => ! $row['matched'])), 'blocked_reason' => $blocked];
        })->all();
    }

    public function apply(int $id, array $routingIds, object $user): array
    {
        $actor = (int) ($user->legacy_id ?? 0);
        if ($actor <= 0) $this->fail('performance_template_actor_missing', '请使用真实账号操作。', 403);
        return DB::transaction(function () use ($id, $routingIds, $actor): array {
            $template = ProductionPerformanceTemplate::query()->lockForUpdate()->findOrFail($id);
            if ($template->status !== 'enabled') $this->fail('performance_template_disabled', '模板已停用，请先启用。', 422);
            ProductionRouting::query()->whereIn('id', $routingIds)->orderBy('id')->lockForUpdate()->get();
            $preview = $this->preview($id, $routingIds);
            $applied = 0; $operations = 0;
            foreach ($preview as $routing) {
                if ($routing['blocked_reason'] || ! $routing['changed_count']) continue;
                $changes = array_values(array_filter($routing['operations'], fn ($row) => $row['changed']));
                foreach ($changes as $row) DB::table('erp_production_routing_operations')->where('id', $row['routing_operation_id'])
                    ->update(['performance_rate' => $row['new_rate'], 'updated_at' => now()]);
                DB::table('erp_production_routings')->where('id', $routing['routing_id'])
                    ->update(['business_version' => DB::raw('business_version + 1'), 'updated_by_legacy_id' => $actor, 'updated_at' => now()]);
                DB::table('erp_production_master_commands')->insert([
                    'client_command_id' => 'performance-template:'.$template->id.':'.$routing['routing_id'].':'.Str::uuid(),
                    'command_type' => 'apply_performance_template', 'entity_type' => 'routing', 'entity_id' => $routing['routing_id'],
                    'request_hash' => hash('sha256', json_encode([$template->id, $template->business_version, $changes], JSON_UNESCAPED_UNICODE)),
                    'response_snapshot' => json_encode(['template_id' => (int) $template->id, 'template_name' => $template->template_name,
                        'template_version' => (int) $template->business_version, 'changes' => $changes], JSON_UNESCAPED_UNICODE),
                    'initiated_by_legacy_id' => $actor, 'created_at' => now(), 'updated_at' => now(),
                ]);
                $applied++; $operations += count($changes);
            }
            $template->update(['last_applied_at' => now()]);
            return ['routings' => $applied, 'operations' => $operations, 'skipped' => count($preview) - $applied];
        });
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

    private function present(ProductionPerformanceTemplate $template): array
    {
        $lines = $template->lines->map(fn ($line) => ['operation_id' => (int) $line->operation_id, 'performance_rate' => (string) $line->performance_rate,
            'operation_no' => $line->operation?->operation_no, 'operation_name' => $line->operation?->operation_name])->values()->all();
        return ['id' => (int) $template->id, 'template_name' => $template->template_name, 'remark' => $template->remark,
            'status' => $template->status, 'business_version' => (int) $template->business_version, 'lines' => $lines,
            'total_rate' => array_reduce($lines, fn ($sum, $line) => bcadd($sum, $line['performance_rate'], 6), '0'),
            'last_applied_at' => optional($template->last_applied_at)->toDateTimeString(), 'updated_at' => optional($template->updated_at)->toDateTimeString()];
    }

    private function fail(string $code, string $message, int $status): never { throw new WorkOrderDomainException($code, $message, $status); }
}
