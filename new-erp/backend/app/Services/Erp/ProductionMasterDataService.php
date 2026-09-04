<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\Product;
use App\Models\Erp\ProductionOperation;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\Sku;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionMasterDataService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    public function operations(array $filters, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->authorize($permissions, $superAdmin, 'production.operation.view');
        return ProductionOperation::query()
            ->when($filters['keyword'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('operation_no', 'like', "%{$v}%")->orWhere('operation_name', 'like', "%{$v}%")))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->orderBy('sort')->orderBy('id')->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function operation(int $id, array $permissions, bool $superAdmin): ProductionOperation
    {
        $this->authorize($permissions, $superAdmin, 'production.operation.view');
        return ProductionOperation::findOrFail($id);
    }

    public function createOperation(array $data, object $user, array $permissions, bool $superAdmin): ProductionOperation
    {
        $this->authorize($permissions, $superAdmin, 'production.operation.create');
        return $this->command('create_operation', 'operation', $data, $user, function () use ($data, $user): ProductionOperation {
            $number = $this->numbers->reservedNumber($data['reservation_token'], 'operation', $this->userId($user), $data['creation_session_id']);
            $operation = ProductionOperation::create([
                'operation_no' => $number,
                'operation_name' => trim($data['operation_name']),
                'status' => $data['status'] ?? 'enabled',
                'sort' => $data['sort'] ?? 0,
                'description' => $data['description'] ?? null,
                'business_version' => 1,
                'created_by_legacy_id' => $this->userId($user),
                'updated_by_legacy_id' => $this->userId($user),
            ]);
            $this->numbers->consume($data['reservation_token'], 'operation', $number, $this->userId($user), 'production_operation', $operation->id);
            return $operation;
        });
    }

    public function updateOperation(int $id, array $data, object $user, array $permissions, bool $superAdmin): ProductionOperation
    {
        $this->authorize($permissions, $superAdmin, 'production.operation.edit');
        return $this->command('update_operation', 'operation', $data + ['id' => $id], $user, function () use ($id, $data, $user): ProductionOperation {
            $operation = ProductionOperation::lockForUpdate()->findOrFail($id);
            $this->version($operation, $data);
            $operation->fill(array_filter([
                'operation_name' => isset($data['operation_name']) ? trim($data['operation_name']) : null,
                'sort' => $data['sort'] ?? null,
                'description' => array_key_exists('description', $data) ? $data['description'] : null,
            ], fn ($value, $key) => $value !== null || $key === 'description', ARRAY_FILTER_USE_BOTH));
            $operation->business_version++;
            $operation->updated_by_legacy_id = $this->userId($user);
            $operation->save();
            return $operation;
        });
    }

    public function setOperationEnabled(int $id, bool $enabled, array $data, object $user, array $permissions, bool $superAdmin): ProductionOperation
    {
        $this->authorize($permissions, $superAdmin, 'production.operation.toggle');
        return $this->command($enabled ? 'enable_operation' : 'disable_operation', 'operation', $data + ['id' => $id], $user, function () use ($id, $enabled, $data, $user): ProductionOperation {
            $operation = ProductionOperation::lockForUpdate()->findOrFail($id);
            $this->version($operation, $data);
            if (! $enabled && $operation->status === 'enabled' && DB::table('erp_production_routing_operations as ro')
                ->join('erp_production_routings as r', 'r.id', '=', 'ro.routing_id')
                ->where('ro.operation_id', $id)->where('r.status', 'active')->exists()) {
                throw ValidationException::withMessages(['status' => '工序已被生效工艺路线使用，不能停用。']);
            }
            $operation->status = $enabled ? 'enabled' : 'disabled';
            $operation->business_version++;
            $operation->updated_by_legacy_id = $this->userId($user);
            $operation->save();
            return $operation;
        });
    }

    public function routings(array $filters, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->authorize($permissions, $superAdmin, 'production.routing.view');
        return ProductionRouting::with(['outputItem', 'product', 'sku'])->withCount('operations')
            ->when($filters['keyword'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('routing_no', 'like', "%{$v}%")->orWhere('routing_name', 'like', "%{$v}%")))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['output_item_id'] ?? null, fn ($q, $v) => $q->where('output_item_id', $v))
            ->orderByDesc('is_default')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 20));
    }

    public function routing(int $id, array $permissions, bool $superAdmin): ProductionRouting
    {
        $this->authorize($permissions, $superAdmin, 'production.routing.view');
        return ProductionRouting::with(['outputItem', 'product', 'sku', 'operations.operation'])->findOrFail($id);
    }

    public function createRouting(array $data, object $user, array $permissions, bool $superAdmin): ProductionRouting
    {
        $this->authorize($permissions, $superAdmin, 'production.routing.create');
        return $this->command('create_routing', 'routing', $data, $user, function () use ($data, $user): ProductionRouting {
            $this->assertObjectRelation((int) $data['output_item_id'], $data['product_id'] ?? null, $data['sku_id'] ?? null);
            $number = $this->numbers->reservedNumber($data['reservation_token'], 'routing', $this->userId($user), $data['creation_session_id']);
            $routing = ProductionRouting::create([
                'routing_no' => $number,
                'routing_name' => trim($data['routing_name']),
                'output_item_id' => $data['output_item_id'],
                'product_id' => $data['product_id'] ?? null,
                'sku_id' => $data['sku_id'] ?? null,
                'version' => 1,
                'status' => 'draft',
                'is_default' => false,
                'remark' => $data['remark'] ?? null,
                'business_version' => 1,
                'created_by_legacy_id' => $this->userId($user),
                'updated_by_legacy_id' => $this->userId($user),
            ]);
            $this->syncOperations($routing, $data['operations']);
            $this->numbers->consume($data['reservation_token'], 'routing', $number, $this->userId($user), 'production_routing', $routing->id);
            return $routing->load(['outputItem', 'product', 'sku', 'operations.operation']);
        });
    }

    public function updateRouting(int $id, array $data, object $user, array $permissions, bool $superAdmin): ProductionRouting
    {
        $this->authorize($permissions, $superAdmin, 'production.routing.edit');
        return $this->command('update_routing', 'routing', $data + ['id' => $id], $user, function () use ($id, $data, $user): ProductionRouting {
            $routing = ProductionRouting::lockForUpdate()->findOrFail($id);
            $this->version($routing, $data);
            if ($routing->status !== 'draft') throw ValidationException::withMessages(['status' => '只有草稿工艺路线可以编辑。']);
            $this->assertObjectRelation(
                (int) ($data['output_item_id'] ?? $routing->output_item_id),
                array_key_exists('product_id', $data) ? $data['product_id'] : $routing->product_id,
                array_key_exists('sku_id', $data) ? $data['sku_id'] : $routing->sku_id,
            );
            $routing->fill(collect($data)->only(['routing_name', 'output_item_id', 'product_id', 'sku_id', 'remark'])->all());
            $routing->business_version++;
            $routing->updated_by_legacy_id = $this->userId($user);
            $routing->save();
            if (isset($data['operations'])) $this->syncOperations($routing, $data['operations']);
            return $routing->load(['outputItem', 'product', 'sku', 'operations.operation']);
        });
    }

    public function activateRouting(int $id, array $data, object $user, array $permissions, bool $superAdmin): ProductionRouting
    {
        $this->authorize($permissions, $superAdmin, 'production.routing.activate');
        return $this->command('activate_routing', 'routing', $data + ['id' => $id], $user, function () use ($id, $data, $user): ProductionRouting {
            $routing = ProductionRouting::with('operations.operation')->lockForUpdate()->findOrFail($id);
            $this->version($routing, $data);
            if ($routing->status !== 'draft') throw ValidationException::withMessages(['status' => '只有草稿工艺路线可以生效。']);
            if ($routing->operations->isEmpty()) throw ValidationException::withMessages(['operations' => '工艺路线至少需要一道工序。']);
            if ($routing->operations->contains(fn ($row) => $row->operation?->status !== 'enabled')) throw ValidationException::withMessages(['operations' => '工艺路线包含已停用工序，不能生效。']);
            $routing->status = 'active';
            $routing->business_version++;
            $routing->updated_by_legacy_id = $this->userId($user);
            $routing->save();
            return $routing->fresh(['outputItem', 'product', 'sku', 'operations.operation']);
        });
    }

    public function setDefaultRouting(int $id, array $data, object $user, array $permissions, bool $superAdmin): ProductionRouting
    {
        $this->authorize($permissions, $superAdmin, 'production.routing.default');
        return $this->command('default_routing', 'routing', $data + ['id' => $id], $user, function () use ($id, $data, $user): ProductionRouting {
            $candidate = ProductionRouting::findOrFail($id);
            // Lock the complete default scope before changing either side. Updating only
            // the new default leaves the old version stale even though its business fact
            // changed, allowing an old edit screen to overwrite newer routing state.
            $family = ProductionRouting::where('output_item_id', $candidate->output_item_id)
                ->orderBy('id')->lockForUpdate()->get();
            $routing = $family->firstWhere('id', $id);
            if (! $routing) throw ValidationException::withMessages(['id' => '工艺路线不存在。']);
            $this->version($routing, $data);
            if ($routing->status !== 'active') throw ValidationException::withMessages(['status' => '只有已生效工艺路线可以设为默认。']);
            foreach ($family->where('id', '<>', $routing->id)->where('is_default', true) as $oldDefault) {
                $oldDefault->is_default = false;
                $oldDefault->default_scope_key = null;
                $oldDefault->business_version = (int) $oldDefault->business_version + 1;
                $oldDefault->updated_by_legacy_id = $this->userId($user);
                $oldDefault->save();
            }
            $routing->is_default = true;
            $routing->default_scope_key = $routing->output_item_id;
            $routing->business_version++;
            $routing->updated_by_legacy_id = $this->userId($user);
            $routing->save();
            return $routing->fresh(['outputItem', 'product', 'sku', 'operations.operation']);
        });
    }

    public function copyRouting(int $id, array $data, object $user, array $permissions, bool $superAdmin): ProductionRouting
    {
        $this->authorize($permissions, $superAdmin, 'production.routing.create');
        return $this->command('copy_routing', 'routing', $data + ['id' => $id], $user, function () use ($id, $user): ProductionRouting {
            $candidate = ProductionRouting::findOrFail($id);
            // Every version in one routing family locks the same oldest row before MAX+1.
            // Locking only the selected source is insufficient when concurrent requests
            // copy V1 and V2; they would hold different rows and race for the same version.
            ProductionRouting::where('routing_no', $candidate->routing_no)
                ->orderBy('id')->lockForUpdate()->firstOrFail();

            // Test-only overlap widens the critical section so the independent-process
            // regression proves that both callers really contend for this family mutex.
            // It is inert outside the testing environment and never changes production timing.
            $testDelayMs = app()->environment('testing') ? max(0, (int) env('PRODUCTION_ROUTING_COPY_TEST_DELAY_MS', 0)) : 0;
            if ($testDelayMs > 0) usleep($testDelayMs * 1000);

            // The identity lookup above may establish an old REPEATABLE READ snapshot.
            // After the family mutex is acquired, both the selected source and the latest
            // family member must therefore be locking/current reads. Replacing either with
            // a plain find()/max() can make a waiting transaction miss the version just
            // committed by the previous copier and race for the same unique version.
            $source = ProductionRouting::with('operations')->lockForUpdate()->findOrFail($id);
            $latest = ProductionRouting::where('routing_no', $source->routing_no)
                ->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $nextVersion = (int) $latest->version + 1;
            $copy = ProductionRouting::create([
                'routing_no' => $source->routing_no, 'routing_name' => $source->routing_name,
                'output_item_id' => $source->output_item_id, 'product_id' => $source->product_id,
                'sku_id' => $source->sku_id, 'version' => $nextVersion, 'status' => 'draft',
                'is_default' => false, 'remark' => $source->remark, 'business_version' => 1,
                'created_by_legacy_id' => $this->userId($user), 'updated_by_legacy_id' => $this->userId($user),
            ]);
            foreach ($source->operations as $row) {
                $copy->operations()->create($row->only(['operation_id', 'sequence', 'parameters', 'is_key_operation', 'remark']));
            }
            return $copy->load(['outputItem', 'product', 'sku', 'operations.operation']);
        });
    }

    public function retireRouting(int $id, array $data, object $user, array $permissions, bool $superAdmin): ProductionRouting
    {
        $this->authorize($permissions, $superAdmin, 'production.routing.activate');
        return $this->command('retire_routing', 'routing', $data + ['id' => $id], $user, function () use ($id, $data, $user): ProductionRouting {
            $routing = ProductionRouting::lockForUpdate()->findOrFail($id);
            $this->version($routing, $data);
            if ($routing->status !== 'active') throw ValidationException::withMessages(['status' => '只有已生效工艺路线可以退役。']);
            $routing->status = 'retired';
            $routing->is_default = false;
            $routing->default_scope_key = null;
            $routing->business_version++;
            $routing->updated_by_legacy_id = $this->userId($user);
            $routing->save();
            return $routing->fresh(['outputItem', 'product', 'sku', 'operations.operation']);
        });
    }

    public function selector(string $type, array $filters, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->authorize($permissions, $superAdmin, ['production.operation.view', 'production.routing.view', 'production.work_order.create']);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 20)));
        $query = match ($type) {
            'items' => Item::query()->where('status', 'enabled')->where('is_production_item', true)
                ->when($keyword, fn ($q) => $q->where(fn ($x) => $x->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%")))->orderBy('item_name'),
            'operations' => ProductionOperation::query()->where('status', 'enabled')
                ->when($keyword, fn ($q) => $q->where(fn ($x) => $x->where('operation_no', 'like', "%{$keyword}%")->orWhere('operation_name', 'like', "%{$keyword}%")))->orderBy('sort'),
            'products' => Product::query()->where('status', 'enabled')
                ->when($keyword, fn ($q) => $q->where(fn ($x) => $x->where('product_code', 'like', "%{$keyword}%")->orWhere('product_name', 'like', "%{$keyword}%")))->orderBy('product_name'),
            'skus' => Sku::query()->where('status', 'enabled')
                ->when($keyword, fn ($q) => $q->where(fn ($x) => $x->where('sku_code', 'like', "%{$keyword}%")->orWhere('sku_name', 'like', "%{$keyword}%")))->orderBy('sku_name'),
            'routings' => ProductionRouting::with('outputItem')->where('status', 'active')
                ->when($filters['output_item_id'] ?? null, fn ($q, $id) => $q->where('output_item_id', $id))
                ->when($keyword, fn ($q) => $q->where(fn ($x) => $x->where('routing_no', 'like', "%{$keyword}%")->orWhere('routing_name', 'like', "%{$keyword}%")))->orderByDesc('is_default')->orderByDesc('version'),
            default => throw ValidationException::withMessages(['type' => '不支持的生产主数据选择器类型。']),
        };
        return $query->paginate($perPage);
    }

    public function snapshot(ProductionRouting $routing): array
    {
        $routing->loadMissing(['outputItem', 'product', 'sku', 'operations.operation']);
        return [
            'routing_id' => (int) $routing->id, 'routing_no' => $routing->routing_no,
            'routing_name' => $routing->routing_name, 'version' => (int) $routing->version,
            'output_item_id' => (int) $routing->output_item_id,
            'output_item_code' => $routing->outputItem?->item_code, 'output_item_name' => $routing->outputItem?->item_name,
            'operations' => $routing->operations->map(fn ($row) => [
                'routing_operation_id' => (int) $row->id, 'operation_id' => (int) $row->operation_id,
                'operation_no' => $row->operation?->operation_no, 'operation_name' => $row->operation?->operation_name,
                'sequence' => (int) $row->sequence, 'parameters' => $row->parameters,
                'is_key_operation' => (bool) $row->is_key_operation, 'remark' => $row->remark,
            ])->values()->all(),
        ];
    }

    private function syncOperations(ProductionRouting $routing, array $rows): void
    {
        $sequences = collect($rows)->pluck('sequence');
        if ($sequences->duplicates()->isNotEmpty()) throw ValidationException::withMessages(['operations' => '同一路线的工序顺序号不能重复。']);
        $enabled = ProductionOperation::whereIn('id', collect($rows)->pluck('operation_id'))->where('status', 'enabled')->count();
        if ($enabled !== count(array_unique(collect($rows)->pluck('operation_id')->all()))) throw ValidationException::withMessages(['operations' => '存在无效或已停用的工序。']);
        $routing->operations()->delete();
        foreach (collect($rows)->sortBy('sequence')->values() as $row) {
            $routing->operations()->create(collect($row)->only(['operation_id', 'sequence', 'parameters', 'is_key_operation', 'remark'])->all());
        }
    }

    private function assertObjectRelation(int $outputItemId, mixed $productId, mixed $skuId): void
    {
        if ($productId && ! $skuId) throw ValidationException::withMessages(['sku_id' => '关联产品时必须同时选择对应 SKU，不能绕过 SKU-物料关系校验。']);
        if (! $skuId) return;
        $sku = Sku::find((int) $skuId);
        if (! $sku || ($productId && (int) $sku->product_id !== (int) $productId)) {
            throw ValidationException::withMessages(['sku_id' => '所选 SKU 不属于当前产品。']);
        }
        $matches = DB::table('erp_sku_item_relations')->where('sku_id', $sku->id)
            ->where('item_id', $outputItemId)->where('status', 'enabled')->where('is_primary', true)->exists();
        if (! $matches) {
            throw ValidationException::withMessages(['output_item_id' => '产出物料必须是该 SKU 当前启用的默认物料，不能由销售链路手工改配。']);
        }
    }

    private function command(string $type, string $entityType, array $data, object $user, callable $action)
    {
        $id = $data['client_command_id'];
        $hash = hash('sha256', json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        try {
            return DB::transaction(function () use ($type, $entityType, $data, $user, $action, $id, $hash) {
            $existing = DB::table('erp_production_master_commands')->where('client_command_id', $id)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_hash !== $hash || $existing->command_type !== $type) throw ValidationException::withMessages(['client_command_id' => '该请求标识已用于不同操作。']);
                $model = $entityType === 'operation' ? ProductionOperation::class : ProductionRouting::class;
                return $model::findOrFail($existing->entity_id);
            }
            DB::table('erp_production_master_commands')->insert([
                'client_command_id' => $id, 'command_type' => $type, 'entity_type' => $entityType,
                'request_hash' => $hash, 'initiated_by_legacy_id' => $this->userId($user), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $entity = $action();
            DB::table('erp_production_master_commands')->where('client_command_id', $id)->update([
                'entity_id' => $entity->id, 'response_snapshot' => json_encode(['id' => $entity->id]), 'updated_at' => now(),
            ]);
            return $entity;
            }, 5);
        } catch (QueryException $e) {
            if ((string) $e->getCode() !== '23000' && (int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            $existing = DB::table('erp_production_master_commands')->where('client_command_id', $id)->first();
            if (! $existing || $existing->request_hash !== $hash || $existing->command_type !== $type || ! $existing->entity_id) {
                throw ValidationException::withMessages(['client_command_id' => '重复请求正在处理或已用于不同操作，请刷新后重试。']);
            }
            $model = $entityType === 'operation' ? ProductionOperation::class : ProductionRouting::class;
            return $model::findOrFail($existing->entity_id);
        }
    }

    private function version($model, array $data): void
    {
        if ((int) ($data['expected_version'] ?? 0) !== (int) $model->business_version) throw ValidationException::withMessages(['expected_version' => '数据版本已变化，请刷新后重试。']);
    }

    private function authorize(array $permissions, bool $superAdmin, string|array $required): void
    {
        $required = (array) $required;
        if (! $superAdmin && collect($required)->every(fn ($code) => ! in_array($code, $permissions, true))) abort(403, '无权执行该生产基础数据操作。');
    }

    private function userId(object $user): ?int { return isset($user->legacy_id) ? (int) $user->legacy_id : null; }
}
