<?php

namespace App\Services\Erp;

use App\Models\Erp\ProductionStage;
use App\Models\Erp\ProductionPackagingScheme;
use App\Models\Erp\Item;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Small maintainable process catalogs share one permission, command and audit boundary. */
class ProductionProcessCatalogService
{
    public const PACKAGING_QUANTITY_RULES = ['required', 'numeric', 'gt:0', 'regex:/^\d{1,10}(?:\.\d{1,8})?$/'];

    public static function packagingItemRules(): array
    {
        return ['items' => 'array', 'items.*.item_id' => 'required|integer|min:1|distinct',
            'items.*.qty_per_unit' => self::PACKAGING_QUANTITY_RULES, 'items.*.remark' => 'nullable|string|max:255'];
    }

    public function paginate(string $type, array $filters, array $permissions, bool $super): mixed
    {
        $this->authorize($permissions, $super, false);
        $model = $this->model($type);
        return $model::query()->when($type === 'packaging-schemes', fn ($q) => $q->with('items.item.unit')->withCount('items'))
            ->when($filters['keyword'] ?? null, fn ($q, $v) => $q->where(fn ($sub) => $sub->where('code', 'like', "%{$v}%")->orWhere('name', 'like', "%{$v}%")))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('sort')->orderBy('id')->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 10))));
    }

    public function detail(string $type, int $id, array $permissions, bool $super): array
    {
        $this->authorize($permissions, $super, false);
        $model = $this->model($type);
        return $model::query()->when($type === 'packaging-schemes', fn ($q) => $q->with('items.item.unit')->withCount('items'))->findOrFail($id)->toArray();
    }

    public function save(string $type, ?int $id, array $data, object $actor, array $permissions, bool $super): array
    {
        $this->authorize($permissions, $super, true);
        $model = $this->model($type);
        $actorId = (int) ($actor->legacy_id ?? $actor->id);
        $hash = hash('sha256', json_encode([$type, $id, $actorId, $data], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $commandId = $data['client_command_id'];
        try {
            return DB::transaction(function () use ($type, $id, $data, $actor, $actorId, $hash, $commandId, $model): array {
                if ($id) $model::query()->whereKey($id)->lockForUpdate()->firstOrFail();
                $existing = DB::table('erp_production_catalog_commands')->where('client_command_id', $commandId)->first();
                if ($existing) return $this->replay($existing, $hash);
                DB::table('erp_production_catalog_commands')->insert(['client_command_id' => $commandId,
                    'request_hash' => $hash, 'catalog_type' => $type, 'operator_legacy_id' => $actorId, 'created_at' => now(), 'updated_at' => now()]);
                $row = $id ? $model::query()->whereKey($id)->lockForUpdate()->firstOrFail() : new $model;
                if ($id && (int) $row->business_version !== (int) ($data['expected_version'] ?? 0)) throw ValidationException::withMessages(['expected_version' => '档案已变化，请刷新后重试。']);
                if ($type === 'packaging-schemes' && $row->exists) $row->load('items.item.unit');
                $before = $row->exists ? $row->toArray() : null;
                if (($data['status'] ?? 'enabled') === 'disabled' && $id && $row->status !== 'disabled') {
                    $routes = $this->routingRefs($type, $id, true);
                    if ($routes) throw ValidationException::withMessages(['status' => '生效中的工艺路线 '.implode('、', $routes).' 还在用这个'.$this->noun($type)
                        .'，不能停用。请先在这些路线的新版本里换掉它，或让路线停用后再停用。名称、排序、说明可以直接改。']);
                }
                if ($model::where('code', trim($data['code']))->when($id, fn ($q) => $q->where('id', '<>', $id))->exists()) throw ValidationException::withMessages(['code' => '档案编码已存在。']);
                $row->fill(['code' => trim($data['code']), 'name' => trim($data['name']), 'status' => $data['status'] ?? 'enabled',
                    'sort' => $data['sort'] ?? 0, 'description' => $data['description'] ?? null,
                    'business_version' => $id ? (int) $row->business_version + 1 : 1, 'updated_by_legacy_id' => $actorId]);
                if (! $id) $row->created_by_legacy_id = $actorId;
                $row->save();
                if ($type === 'packaging-schemes') {
                    if (array_key_exists('items', $data)) $this->syncPackagingItems($row, $data['items']);
                    $row->load('items.item.unit')->loadCount('items');
                }
                $response = $row->toArray();
                DB::table('erp_production_execution_events')->insert(['aggregate_type' => 'process_'.$type, 'aggregate_id' => $row->id,
                    'action' => $id ? 'update' : 'create', 'before_status' => $before['status'] ?? null, 'after_status' => $row->status,
                    'before_version' => $before['business_version'] ?? 0, 'after_version' => $row->business_version,
                    'fact_snapshot' => json_encode(['before' => $before, 'after' => $response], JSON_UNESCAPED_UNICODE),
                    'operator_legacy_id' => $actorId, 'operator_name' => $actor->nickname ?? $actor->username ?? null,
                    'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
                DB::table('erp_production_catalog_commands')->where('client_command_id', $commandId)->update(['entity_id' => $row->id,
                    'response_snapshot' => json_encode($response, JSON_UNESCAPED_UNICODE), 'updated_at' => now()]);
                return $response;
            }, 5);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            $existing = DB::table('erp_production_catalog_commands')->where('client_command_id', $commandId)->first();
            if (! $existing) throw $e;
            return $this->replay($existing, $hash);
        }
    }

    /** 删除：没有任何工艺路线和未完工的工单在用才能删；用过的请改为停用。 */
    public function delete(string $type, int $id, object $actor, array $permissions, bool $super): void
    {
        $this->authorize($permissions, $super, true);
        $model = $this->model($type);
        $noun = $this->noun($type);
        DB::transaction(function () use ($type, $id, $actor, $model, $noun): void {
            $row = $model::query()->whereKey($id)->lockForUpdate()->first();
            if (! $row) throw ValidationException::withMessages(['id' => $noun.'不存在或已删除，请刷新。']);
            $routes = $this->routingRefs($type, $id, false);
            if ($routes) throw ValidationException::withMessages(['id' => '工艺路线 '.implode('、', $routes).' 用过这个'.$noun
                .'，删掉会让这些路线对不上，不能删除。不想再用请改为“停用”。']);
            if ($type === 'stages' && DB::table('erp_mfg_work_order_operations as op')->join('erp_mfg_work_orders as wo', 'wo.id', '=', 'op.work_order_id')
                ->where('op.production_stage_id', $id)->whereNotIn('wo.status', ['completed', 'closed', 'cancelled'])->exists())
                throw ValidationException::withMessages(['id' => '还有没完工的工单在这个生产阶段里生产，不能删除，请改为“停用”。']);
            if ($type === 'packaging-schemes' && DB::table('erp_shipment_packing_contents')->where('packaging_scheme_id', $id)->exists())
                throw ValidationException::withMessages(['id' => '发货打包记录用过这个包装方案，不能删除，请改为“停用”。']);
            if ($type === 'packaging-schemes') $row->load('items.item.unit');
            $before = $row->toArray();
            $row->delete();
            $actorId = (int) ($actor->legacy_id ?? $actor->id);
            DB::table('erp_production_execution_events')->insert(['aggregate_type' => 'process_'.$type, 'aggregate_id' => $id,
                'action' => 'delete', 'before_status' => $before['status'] ?? null, 'after_status' => 'deleted',
                'before_version' => $before['business_version'] ?? 0, 'after_version' => ($before['business_version'] ?? 0) + 1,
                'fact_snapshot' => json_encode(['before' => $before, 'after' => null], JSON_UNESCAPED_UNICODE),
                'operator_legacy_id' => $actorId, 'operator_name' => $actor->nickname ?? $actor->username ?? null,
                'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        }, 5);
    }

    private function syncPackagingItems(ProductionPackagingScheme $scheme, array $rows): void
    {
        $rows = array_values($rows);
        $validated = Validator::make(['items' => $rows], self::packagingItemRules(), [
            'items.*.item_id.distinct' => '标准包装材料不能重复。',
            'items.*.qty_per_unit.gt' => '标准包装材料的单台用量必须大于 0。',
            'items.*.qty_per_unit.regex' => '单台用量最多 10 位整数、8 位小数。',
        ])->validate()['items'];
        $ids = collect($validated)->pluck('item_id')->map(fn ($id) => (int) $id);
        if ($ids->unique()->count() !== $ids->count()) throw ValidationException::withMessages(['items' => '标准包装材料不能重复。']);
        // 物料状态与方案明细一起锁定，避免校验后被停用或切到办公范围。
        $items = Item::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        foreach ($validated as $index => $line) {
            $item = $items->get((int) $line['item_id']);
            if (! $item || $item->status !== 'enabled' || $item->managementScope() !== 'factory') {
                throw ValidationException::withMessages(["items.{$index}.item_id" => '标准包装材料必须选择启用的工厂物料。']);
            }
        }
        $scheme->items()->delete();
        $scheme->items()->createMany(array_map(fn ($line, $index) => [
            'item_id' => (int) $line['item_id'], 'qty_per_unit' => $line['qty_per_unit'],
            'sort_order' => $index, 'remark' => $line['remark'] ?? null,
        ], $validated, array_keys($validated)));
    }

    /** 引用这个档案的工艺路线（编号+版本），$activeOnly 只看生效中的；最多列 5 条。 */
    private function routingRefs(string $type, int $id, bool $activeOnly): array
    {
        $field = $type === 'stages' ? 'production_stage_id' : 'packaging_scheme_id';
        $query = DB::table('erp_production_routing_operations as node')->join('erp_production_routings as route', 'route.id', '=', 'node.routing_id')
            ->where('node.'.$field, $id)->when($activeOnly, fn ($q) => $q->where('route.status', 'active'));
        $refs = (clone $query)->select('route.routing_no', 'route.version')->distinct()->orderBy('route.routing_no')->limit(5)->get()
            ->map(fn ($r) => $r->routing_no.' V'.$r->version)->all();
        $total = (clone $query)->distinct()->count('route.id');
        if ($total > count($refs)) $refs[] = '等 '.$total.' 条';
        return $refs;
    }

    private function noun(string $type): string
    {
        return $type === 'stages' ? '生产阶段' : '包装方案';
    }

    private function replay(object $command, string $hash): array
    {
        if ($command->request_hash !== $hash) throw ValidationException::withMessages(['client_command_id' => '请求标识已用于其他操作。']);
        if (! $command->response_snapshot) throw ValidationException::withMessages(['client_command_id' => '请求正在处理，请稍后重试。']);
        return json_decode($command->response_snapshot, true, 512, JSON_THROW_ON_ERROR);
    }

    private function model(string $type): string
    {
        return match ($type) { 'stages' => ProductionStage::class, 'packaging-schemes' => ProductionPackagingScheme::class,
            default => throw ValidationException::withMessages(['type' => '不支持的工艺档案类型。']) };
    }

    private function authorize(array $permissions, bool $super, bool $write): void
    {
        $required = $write ? ['production.routing.create', 'production.routing.edit'] : ['production.routing.view', 'production.routing.create', 'production.routing.edit', 'production.work_order.view', 'production.task.view'];
        if (! $super && array_intersect($required, $permissions) === []) abort(403, '当前用户没有工艺档案权限。');
    }
}
