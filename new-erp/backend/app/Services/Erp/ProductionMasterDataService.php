<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\Product;
use App\Models\Erp\ProductionOperation;
use App\Models\Erp\ProductionPerformanceBinding;
use App\Models\Erp\ProductionStage;
use App\Models\Erp\ProductionPackagingScheme;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\ProductionRoutingApplicability;
use App\Models\Erp\Sku;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ProductionMasterDataService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
    ) {}

    public function operations(array $filters, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->authorize($permissions, $superAdmin, 'production.operation.view');
        $activeRoutingCount = DB::table('erp_production_routing_operations as operation_route')
            ->join('erp_production_routings as active_route', 'active_route.id', '=', 'operation_route.routing_id')
            ->whereColumn('operation_route.operation_id', 'erp_production_operations.id')
            ->where('active_route.status', 'active')
            ->selectRaw('COUNT(DISTINCT operation_route.routing_id)');

        return ProductionOperation::query()
            ->addSelect(['active_routing_count' => $activeRoutingCount])
            ->when($filters['keyword'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('operation_no', 'like', "%{$v}%")->orWhere('operation_name', 'like', "%{$v}%")))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when(isset($filters['is_public']) && $filters['is_public'] !== '', fn ($q) => $q->where('is_public', (bool) $filters['is_public']))
            ->when(($filters['reference_status'] ?? null) === 'referenced', fn ($q) => $q->whereExists(
                DB::table('erp_production_routing_operations as reference_operation')
                    ->join('erp_production_routings as reference_route', 'reference_route.id', '=', 'reference_operation.routing_id')
                    ->whereColumn('reference_operation.operation_id', 'erp_production_operations.id')
                    ->where('reference_route.status', 'active')
                    ->selectRaw('1')
            ))
            ->when(($filters['reference_status'] ?? null) === 'unreferenced', fn ($q) => $q->whereNotExists(
                DB::table('erp_production_routing_operations as reference_operation')
                    ->join('erp_production_routings as reference_route', 'reference_route.id', '=', 'reference_operation.routing_id')
                    ->whereColumn('reference_operation.operation_id', 'erp_production_operations.id')
                    ->where('reference_route.status', 'active')
                    ->selectRaw('1')
            ))
            ->orderBy('sort')->orderBy('id')->paginate(min(20, max(1, (int) ($filters['per_page'] ?? 10))));
    }

    public function operation(int $id, array $permissions, bool $superAdmin): ProductionOperation
    {
        $this->authorize($permissions, $superAdmin, 'production.operation.view');
        $operation = ProductionOperation::findOrFail($id);
        $activeRoutings = ProductionRouting::query()
            ->with('applicabilities.item:id,item_code,item_name', 'applicabilities.category:id,category_name')
            ->where('status', 'active')
            ->whereHas('operations', fn ($query) => $query->where('operation_id', $id))
            ->orderBy('routing_no')->orderByDesc('version')
            ->get(['id', 'routing_no', 'routing_name', 'version', 'is_default']);

        $operation->setAttribute('active_routing_count', $activeRoutings->count());
        $operation->setAttribute('active_routings', $activeRoutings);
        $canToggle = $superAdmin || in_array('production.operation.toggle', $permissions, true);
        $operation->setAttribute('status_editable', $canToggle && $activeRoutings->isEmpty());
        $operation->setAttribute('status_lock_reason', ! $activeRoutings->isEmpty()
            ? "已被 {$activeRoutings->count()} 条生效路线引用，状态不可修改"
            : ($canToggle ? null : '无工序启停权限，状态不可修改'));
        return $operation;
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
                'is_public' => (bool) ($data['is_public'] ?? false),
                'auto_assignment_enabled' => (bool) ($data['auto_assignment_enabled'] ?? false),
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
        return $this->command('update_operation', 'operation', $data + ['id' => $id], $user, function () use ($id, $data, $user, $permissions, $superAdmin): ProductionOperation {
            $operation = ProductionOperation::lockForUpdate()->findOrFail($id);
            $this->version($operation, $data);
            if (array_key_exists('status', $data) && $data['status'] !== $operation->status) {
                $this->authorize($permissions, $superAdmin, 'production.operation.toggle');
                if ($this->hasActiveRoutingReference($id)) {
                    throw ValidationException::withMessages(['status' => '工序已被生效工艺路线使用，状态不可修改。']);
                }
                if ($data['status'] === 'disabled') $this->prepareOperationDisable($operation, $user);
                $operation->status = $data['status'];
            }
            $operation->fill(array_filter([
                'operation_name' => isset($data['operation_name']) ? trim($data['operation_name']) : null,
                'sort' => $data['sort'] ?? null,
                'description' => array_key_exists('description', $data) ? $data['description'] : null,
                'is_public' => array_key_exists('is_public', $data) ? (bool) $data['is_public'] : null,
                'auto_assignment_enabled' => array_key_exists('auto_assignment_enabled', $data) ? (bool) $data['auto_assignment_enabled'] : null,
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
            if (! $enabled && $operation->status === 'enabled') {
                if ($this->hasActiveRoutingReference($id)) {
                    throw ValidationException::withMessages(['status' => '工序已被生效工艺路线使用，不能停用。']);
                }
                $this->prepareOperationDisable($operation, $user);
            }
            $operation->status = $enabled ? 'enabled' : 'disabled';
            $operation->business_version++;
            $operation->updated_by_legacy_id = $this->userId($user);
            $operation->save();
            return $operation;
        });
    }

    public function deleteOperation(int $id, array $data, object $user, array $permissions, bool $superAdmin): array
    {
        $this->authorize($permissions, $superAdmin, 'production.operation.delete');
        return $this->deleteCommand('delete_operation', 'operation', $id, $data, $user, function () use ($id, $data): void {
            $operation = ProductionOperation::query()->lockForUpdate()->findOrFail($id);
            $this->version($operation, $data);
            if ($operation->status !== 'disabled') {
                throw ValidationException::withMessages(['status' => '工序必须先停用，且确认无业务引用后才能删除。']);
            }
            try {
                if (DB::table('erp_production_routing_operations')->where('operation_id', $operation->id)
                    ->lock('FOR UPDATE NOWAIT')->first(['id'])) {
                    throw ValidationException::withMessages(['operation' => '该工序已被工艺路线引用；即使路线已退役也必须保留历史，不能删除。']);
                }
                // 当前读保留回收站 BOM 的引用；编辑时软删的旧明细不算引用，反向取锁不得等待。
                if (DB::table('erp_bom_item_operations as bo')->join('erp_bom_items as bi', 'bi.id', '=', 'bo.bom_item_id')
                    ->where('bo.operation_id', $operation->id)->whereNull('bi.deleted_at')
                    ->lock('FOR UPDATE NOWAIT')->first(['bo.id'])) {
                    throw ValidationException::withMessages(['operation' => '该工序已被 BOM 用料行绑定；请先在 BOM 中解除绑定，不能删除。']);
                }
            } catch (QueryException $exception) {
                if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) throw $exception;
                throw ValidationException::withMessages(['operation' => '关联 BOM 或路线正在变更，本次删除未生效，请稍后重试。']);
            }
            $operation->delete();
        });
    }

    public function routings(array $filters, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->authorize($permissions, $superAdmin, 'production.routing.view');
        return ProductionRouting::with(['product', 'sku', 'applicabilities.item:id,item_code,item_name', 'applicabilities.category:id,category_name'])->withCount('operations')
            ->when($filters['keyword'] ?? null, fn ($q, $v) => $q->where(fn ($x) => $x->where('routing_no', 'like', "%{$v}%")->orWhere('routing_name', 'like', "%{$v}%")))
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['output_item_id'] ?? null, fn ($q, $v) => $q->applicableToItem((int) $v))
            ->orderByDesc('is_default')->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10));
    }

    public function routing(int $id, array $permissions, bool $superAdmin): ProductionRouting
    {
        $this->authorize($permissions, $superAdmin, 'production.routing.view');
        $routing = ProductionRouting::with(['applicabilities.item:id,item_code,item_name,spec,item_type,management_scope', 'applicabilities.category:id,category_code,category_name', 'product', 'sku', 'operations.operation', 'operations.materialSupplyRules.componentItem', 'operations.stage', 'operations.packagingScheme'])->findOrFail($id);
        $itemIds = $routing->operations->flatMap(fn ($operation) => $operation->packaging_materials ?? [])
            ->pluck('component_item_id')->filter(fn ($itemId) => (int) $itemId > 0)->map(fn ($itemId) => (int) $itemId)->unique()->values();
        $routing->setRelation('packaging_material_items', $itemIds->isEmpty() ? collect()
            : Item::with('unit.standardUnit')->whereIn('id', $itemIds)->get(['id', 'item_code', 'item_name', 'unit_id', 'item_type', 'management_scope']));
        return $this->withPerformance($routing);
    }

    public function createRouting(array $data, object $user, array $permissions, bool $superAdmin): ProductionRouting
    {
        $this->authorize($permissions, $superAdmin, 'production.routing.create');
        return $this->command('create_routing', 'routing', $data, $user, function () use ($data, $user): ProductionRouting {
            $this->assertObjectRelation($data['product_id'] ?? null, $data['sku_id'] ?? null);
            $number = $this->numbers->reservedNumber($data['reservation_token'], 'routing', $this->userId($user), $data['creation_session_id']);
            $routing = ProductionRouting::create([
                'routing_no' => $number,
                'routing_name' => trim($data['routing_name']),
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
            $this->syncApplicabilities($routing, $data, true);
            $this->syncOperations($routing, $data['operations'], $user);
            $this->assertRoutingManagementScope($routing);
            $this->numbers->consume($data['reservation_token'], 'routing', $number, $this->userId($user), 'production_routing', $routing->id);
            return $this->withPerformance($routing->load(['product', 'sku', 'applicabilities.item:id,item_code,item_name,spec,item_type,management_scope', 'applicabilities.category:id,category_code,category_name', 'operations.operation']));
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
                array_key_exists('product_id', $data) ? $data['product_id'] : $routing->product_id,
                array_key_exists('sku_id', $data) ? $data['sku_id'] : $routing->sku_id,
            );
            $routing->fill(collect($data)->only(['routing_name', 'product_id', 'sku_id', 'remark'])->all());
            $routing->business_version++;
            $routing->updated_by_legacy_id = $this->userId($user);
            $routing->save();
            $this->syncApplicabilities($routing, $data);
            if (isset($data['operations'])) $this->syncOperations($routing, $data['operations'], $user);
            $this->assertRoutingManagementScope($routing);
            return $this->withPerformance($routing->load(['product', 'sku', 'applicabilities.item:id,item_code,item_name,spec,item_type,management_scope', 'applicabilities.category:id,category_code,category_name', 'operations.operation']));
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
            $this->validateRoutingExecution($routing);
            $routing->status = 'active';
            $routing->business_version++;
            $routing->updated_by_legacy_id = $this->userId($user);
            $routing->save();
            return $routing->fresh(['product', 'sku', 'applicabilities.item:id,item_code,item_name,spec,item_type,management_scope', 'applicabilities.category:id,category_code,category_name', 'operations.operation']);
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
            // 默认只在同一路线编码的各版本之间唯一（default_scope_key 唯一索引保证）；同一层级适用范围
            // 重叠的其他默认路线一并取消，避免工单按“专用 > 指定物料 > 类目”选路线时出现并列默认。
            $family = ProductionRouting::where('routing_no', $candidate->routing_no)->orderBy('id')->lockForUpdate()->get();
            $routing = $family->firstWhere('id', $id);
            if (! $routing) throw ValidationException::withMessages(['id' => '工艺路线不存在。']);
            $this->version($routing, $data);
            if ($routing->status !== 'active') throw ValidationException::withMessages(['status' => '只有已生效工艺路线可以设为默认。']);
            $this->assertRoutingManagementScope($routing);
            $scope = $this->defaultScope($routing);
            $conflicts = ProductionRouting::with('applicabilities')->where('is_default', true)->where('routing_no', '<>', $routing->routing_no)
                ->where(fn ($q) => $routing->sku_id ? $q->where('sku_id', $routing->sku_id) : $q->whereNull('sku_id'))
                ->where(fn ($q) => $routing->product_id ? $q->where('product_id', $routing->product_id) : $q->whereNull('product_id'))
                ->orderBy('id')->lockForUpdate()->get()
                ->filter(function ($other) use ($scope) {
                    $o = $this->defaultScope($other);
                    return array_intersect($scope['items'], $o['items']) || array_intersect($scope['categories'], $o['categories']);
                })->values();
            foreach ($family->where('id', '<>', $routing->id)->where('is_default', true)->concat($conflicts) as $oldDefault) {
                $oldDefault->is_default = false;
                $oldDefault->default_scope_key = null;
                $oldDefault->business_version = (int) $oldDefault->business_version + 1;
                $oldDefault->updated_by_legacy_id = $this->userId($user);
                $oldDefault->save();
            }
            $routing->is_default = true;
            $routing->default_scope_key = 'routing:'.$routing->routing_no;
            $routing->business_version++;
            $routing->updated_by_legacy_id = $this->userId($user);
            $routing->save();
            $fresh = $routing->fresh(['product', 'sku', 'applicabilities.item:id,item_code,item_name,spec,item_type,management_scope', 'applicabilities.category:id,category_code,category_name', 'operations.operation']);
            // 告知调用方哪些适用范围重叠的路线被取消了默认。
            $fresh->setAttribute('replaced_defaults', $conflicts->map(fn ($r) => ['id' => (int) $r->id, 'routing_no' => $r->routing_no, 'routing_name' => $r->routing_name])->all());
            return $fresh;
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
            $source = ProductionRouting::with(['operations'])->lockForUpdate()->findOrFail($id);
            $this->assertRoutingManagementScope($source);
            $latest = ProductionRouting::where('routing_no', $source->routing_no)
                ->orderByDesc('version')->lockForUpdate()->firstOrFail();
            $nextVersion = (int) $latest->version + 1;
            $copy = ProductionRouting::create([
                'routing_no' => $source->routing_no, 'routing_name' => $source->routing_name,
                'product_id' => $source->product_id,
                'sku_id' => $source->sku_id, 'version' => $nextVersion, 'status' => 'draft',
                'is_default' => false, 'remark' => $source->remark, 'business_version' => 1,
                'created_by_legacy_id' => $this->userId($user), 'updated_by_legacy_id' => $this->userId($user),
            ]);
            $copiedBySequence = collect();
            foreach ($source->operations as $row) {
                $newRow = $copy->operations()->create($row->only([
                    'operation_id', 'sequence', 'parameters', 'is_key_operation', 'remark', 'standard_minutes',
                    'setup_standard_minutes', 'unit_standard_minutes',
                    'output_mode', 'quality_mode', 'work_mode', 'allow_continue_without_warehouse',
                    'production_stage_id', 'execution_context', 'shipment_operation_type', 'packaging_scheme_id', 'packaging_materials',
                ]));
                $copiedBySequence->put((int) $row->sequence, $newRow);
            }
            foreach ($source->operations as $row) {
                $newRow = $copiedBySequence->get((int) $row->sequence);
                foreach ($row->materialSupplyRules as $rule) {
                    $targetSequence = optional($source->operations->firstWhere('id', $rule->target_routing_operation_id))->sequence;
                    $newTarget = $copiedBySequence->get((int) $targetSequence);
                    if ($newTarget) $newRow->materialSupplyRules()->create($rule->only([
                        'component_item_id', 'required_qty_ratio', 'supply_mode', 'requires_delivery',
                        'participates_in_kitting', 'allow_partial_delivery', 'delivery_location_type', 'business_version',
                    ]) + ['target_routing_operation_id' => $newTarget->id]);
                }
            }
            foreach ($source->applicabilities()->get() as $scope) {
                $copy->applicabilities()->create($scope->only(['scope_type', 'item_id', 'item_category_id', 'scope_key']));
            }
            // 新版本沿用来源路线指定的提成模板；未指定的继续走默认模板。
            $binding = ProductionPerformanceBinding::query()->where('binding_key', ProductionPerformanceBinding::routingKey((int) $source->id))->first();
            if ($binding) ProductionPerformanceBinding::query()->create(['binding_key' => ProductionPerformanceBinding::routingKey((int) $copy->id),
                'routing_id' => $copy->id, 'template_id' => $binding->template_id,
                'created_by_legacy_id' => $this->userId($user), 'updated_by_legacy_id' => $this->userId($user)]);
            return $this->withPerformance($copy->load(['product', 'sku', 'applicabilities.item:id,item_code,item_name,spec,item_type,management_scope', 'applicabilities.category:id,category_code,category_name', 'operations.operation']));
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
            return $routing->fresh(['product', 'sku', 'applicabilities.item:id,item_code,item_name,spec,item_type,management_scope', 'applicabilities.category:id,category_code,category_name', 'operations.operation']);
        });
    }

    public function deleteRouting(int $id, array $data, object $user, array $permissions, bool $superAdmin): array
    {
        $this->authorize($permissions, $superAdmin, 'production.routing.delete');
        return $this->deleteCommand('delete_routing', 'routing', $id, $data, $user, function () use ($id, $data): void {
            $routing = ProductionRouting::query()->lockForUpdate()->findOrFail($id);
            $this->version($routing, $data);
            if ($routing->status !== 'draft' || $routing->is_default) {
                throw ValidationException::withMessages(['status' => '只有从未生效、未设为默认的工艺路线草稿可以删除。']);
            }

            $routingOperationIds = DB::table('erp_production_routing_operations')
                ->where('routing_id', $routing->id)->lockForUpdate()->pluck('id');
            $referenced = $routingOperationIds->isNotEmpty() && (
                    DB::table('erp_material_lots')->whereIn('stage_id', $routingOperationIds)->exists()
                    || DB::table('erp_routing_operation_material_supply_rules')
                        ->whereNotIn('routing_operation_id', $routingOperationIds)
                        ->whereIn('target_routing_operation_id', $routingOperationIds)->exists()
                );
            if ($referenced) {
                throw ValidationException::withMessages(['routing' => '该工艺路线或其节点已被生产配置、需求、工单或库存归属引用，不能删除。']);
            }

            // 供应规则的目标节点外键为 restrict，先清理本草稿内部规则，再由路线外键级联删除节点。
            if ($routingOperationIds->isNotEmpty()) {
                DB::table('erp_routing_operation_material_supply_rules')
                    ->whereIn('routing_operation_id', $routingOperationIds)->delete();
            }
            ProductionRoutingApplicability::query()->where('routing_id', $routing->id)->delete();
            $routing->delete();
        });
    }

    public function selector(string $type, array $filters, array $permissions, bool $superAdmin): LengthAwarePaginator
    {
        $this->authorize($permissions, $superAdmin, ['production.operation.view', 'production.routing.view', 'production.work_order.create', 'production.tech.manage']);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 10)));
        $query = match ($type) {
            'items' => app(ItemManagementScopeService::class)->applyScope(Item::query(), 'factory')->where('status', 'enabled')
                ->where(function ($items) use ($filters): void {
                    $items->where('is_production_item', true);
                    if (($filters['purpose'] ?? null) === 'routing') {
                        $items->orWhere(fn ($direct) => $direct->where('is_stock_item', true)->where('item_type', '!=', 'service')
                            ->where(fn ($strategy) => $strategy->where('manufacturing_strategy', 'purchase')
                                ->orWhere(fn ($legacy) => $legacy->where(fn ($empty) => $empty->whereNull('manufacturing_strategy')->orWhereIn('manufacturing_strategy', ['', 'unspecified']))
                                    ->where('is_purchase_item', true)->where('is_production_item', false))));
                    }
                })
                ->when($keyword, fn ($q) => $q->where(fn ($x) => $x->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%")))->orderBy('item_name'),
            'operations' => ProductionOperation::query()->where('status', 'enabled')
                ->when(isset($filters['is_public']) && $filters['is_public'] !== '', fn ($q) => $q->where('is_public', (bool) $filters['is_public']))
                ->when($keyword, fn ($q) => $q->where(fn ($x) => $x->where('operation_no', 'like', "%{$keyword}%")->orWhere('operation_name', 'like', "%{$keyword}%")))->orderBy('sort')->orderBy('id'),
            'products' => Product::query()->where('status', 'enabled')
                ->when($keyword, fn ($q) => $q->where(fn ($x) => $x->where('product_code', 'like', "%{$keyword}%")->orWhere('product_name', 'like', "%{$keyword}%")))->orderBy('product_name'),
            'skus' => Sku::query()->where('status', 'enabled')
                ->when($keyword, fn ($q) => $q->where(fn ($x) => $x->where('sku_code', 'like', "%{$keyword}%")->orWhere('sku_name', 'like', "%{$keyword}%")))->orderBy('sku_name'),
            'routings' => ProductionRouting::with('applicabilities.item:id,item_code,item_name', 'applicabilities.category:id,category_name')->where('status', 'active')
                ->when($filters['output_item_id'] ?? null, fn ($q, $id) => $q->applicableToItem((int) $id))
                ->when($keyword, fn ($q) => $q->where(fn ($x) => $x->where('routing_no', 'like', "%{$keyword}%")->orWhere('routing_name', 'like', "%{$keyword}%")))->orderByDesc('is_default')->orderByDesc('version'),
            default => throw ValidationException::withMessages(['type' => '不支持的生产主数据选择器类型。']),
        };
        return $query->paginate($perPage);
    }

    /**
     * 物料自动选用的路线：先按适用层级取最高一层（限定 SKU > 指定物料 > 最近的类目 > 上级类目），
     * 层内只有一条直接用；多条时取默认路线；仍不唯一由调用方阻断，要求明确指定。
     */
    public function defaultRoutingMatches(int $itemId, ?int $productId, ?int $skuId, bool $lock = true): \Illuminate\Support\Collection
    {
        $categories = ProductionRoutingApplicability::categoryChain($itemId);
        $candidates = ProductionRouting::with(['product', 'sku', 'operations.operation', 'applicabilities'])
            ->where('status', 'active')->applicableToItem($itemId)
            ->where(fn ($q) => $q->whereNull('sku_id')->when($skuId, fn ($x) => $x->orWhere('sku_id', $skuId)))
            ->where(fn ($q) => $q->whereNull('product_id')->when($productId, fn ($x) => $x->orWhere('product_id', $productId)))
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($candidates->isEmpty()) return $candidates;
        $rank = function ($row) use ($itemId, $categories): int {
            $base = $row->sku_id ? 10000 : 0;
            if ($row->applicabilities->contains(fn ($a) => (int) $a->item_id === $itemId)) return $base + 1000;
            $depth = $row->applicabilities->map(fn ($a) => array_search((int) $a->item_category_id, $categories, true))->filter(fn ($i) => $i !== false)->min();
            return $base + 100 - (int) $depth;
        };
        $highest = $candidates->max($rank);
        $top = $candidates->filter(fn ($row) => $rank($row) === $highest)->values();
        if ($top->count() === 1) return $top;
        $defaults = $top->where('is_default', true)->values();
        return $defaults->isNotEmpty() ? $defaults : $top;
    }

    /** 路线适用范围（物料、类目），用于默认路线的重叠判断。 */
    private function defaultScope(ProductionRouting $routing): array
    {
        $scopes = $routing->relationLoaded('applicabilities') ? $routing->applicabilities : $routing->applicabilities()->get();
        return [
            'items' => $scopes->where('scope_type', 'item')->pluck('item_id')->map(fn ($id) => (int) $id)->values()->all(),
            'categories' => $scopes->where('scope_type', 'category')->pluck('item_category_id')->map(fn ($id) => (int) $id)->values()->all(),
        ];
    }

    /** 比例只来自提成模板（路线指定或默认），路线工序上的旧比例列不再读取；无权限时由控制器整体脱敏。 */
    private function withPerformance(ProductionRouting $routing): ProductionRouting
    {
        $resolved = app(ProductionPerformanceRateResolver::class)->forRouting((int) $routing->id);
        $routing->operations->each(fn ($row) => $row->setAttribute('performance_rate', $resolved['rates'][(int) $row->operation_id] ?? null));
        $routing->setRelation('performance', collect(['template' => $resolved['template'], 'source' => $resolved['source'], 'rates' => (object) $resolved['rates']]));
        return $routing;
    }

    /**
     * 路线不带产出物料：产出取实际生产/发货的物料（$forItemId）。入库工序（只能是最后一道车间工序）
     * 产出的也是这个物料，中间半成品由多层 BOM 的独立工单负责。
     */
    public function snapshot(ProductionRouting $routing, ?int $forItemId = null): array
    {
        $snapshot = $this->routingSnapshot($routing);
        $item = $forItemId ? Item::query()->find($forItemId, ['id', 'item_code', 'item_name']) : null;
        $snapshot = array_merge($snapshot, ['output_item_id' => $item ? (int) $item->id : null,
            'output_item_code' => $item?->item_code, 'output_item_name' => $item?->item_name]);
        $snapshot['operations'] = array_map(function (array $op) use ($item): array {
            $stocks = $item && $op['execution_context'] === 'production' && in_array($op['output_mode'], ['warehouse_optional', 'warehouse_required'], true);
            return array_merge($op, ['output_item_id' => $stocks ? (int) $item->id : null,
                'output_item_code' => $stocks ? $item->item_code : null, 'output_item_name' => $stocks ? $item->item_name : null]);
        }, $snapshot['operations']);
        return $snapshot;
    }

    private function routingSnapshot(ProductionRouting $routing): array
    {
        $routing->loadMissing(['product', 'sku', 'operations.operation', 'operations.materialSupplyRules', 'operations.stage', 'operations.packagingScheme']);
        $performance = app(ProductionPerformanceRateResolver::class)->forRouting((int) $routing->id);
        return [
            'routing_id' => (int) $routing->id, 'routing_no' => $routing->routing_no,
            'routing_name' => $routing->routing_name, 'version' => (int) $routing->version,
            // Execution may stop at a stock-prebuild target while this historical
            // route still contains later production. Consumers must compare the
            // counts before treating a copied/truncated list as a finished route.
            'snapshot_scope' => 'complete_route',
            'operation_count' => $routing->operations->count(),
            'production_operation_count' => $routing->operations->filter(fn ($row) => ($row->execution_context ?: 'production') === 'production')->count(),
            // 生成时所用的提成模板（null=未配置模板，不计提成）；工单/包装作业按此快照，模板后改不追溯。
            'performance_template' => $performance['template'] ? $performance['template'] + ['source' => $performance['source']] : null,
            'operations' => $routing->operations->map(fn ($row) => [
                'routing_operation_id' => (int) $row->id, 'operation_id' => (int) $row->operation_id,
                'operation_no' => $row->operation?->operation_no, 'operation_name' => $row->operation?->operation_name,
                'is_public' => (bool) $row->operation?->is_public,
                'sequence' => (int) $row->sequence, 'parameters' => $row->parameters,
                'is_key_operation' => (bool) $row->is_key_operation, 'remark' => $row->remark,
                'production_stage_id' => $row->production_stage_id ? (int) $row->production_stage_id : null,
                'stage_code' => $row->stage?->code, 'stage_name' => $row->stage?->name,
                'execution_context' => $row->execution_context ?: 'production',
                'shipment_operation_type' => ($row->execution_context ?: 'production') === 'shipment' ? ($row->shipment_operation_type ?: 'packing') : null,
                'packaging_scheme_id' => $row->packaging_scheme_id ? (int) $row->packaging_scheme_id : null,
                'packaging_scheme_code' => $row->packagingScheme?->code, 'packaging_scheme_name' => $row->packagingScheme?->name,
                'performance_rate' => $performance['rates'][(int) $row->operation_id] ?? null,
                'packaging_materials' => (array) ($row->packaging_materials ?? []),
                'auto_assignment_enabled' => (bool) $row->operation?->auto_assignment_enabled,
                'standard_minutes' => $row->standard_minutes === null ? null : (float) $row->standard_minutes,
                'setup_standard_minutes' => (float) ($row->setup_standard_minutes ?? 0),
                'unit_standard_minutes' => $row->unit_standard_minutes === null
                    ? ($row->standard_minutes === null ? null : (float) $row->standard_minutes)
                    : (float) $row->unit_standard_minutes,
                'output_mode' => $row->output_mode ?: 'flow_only',
                'quality_mode' => $row->quality_mode ?: 'none',
                'work_mode' => $row->work_mode ?: 'manual',
                'allow_continue_without_warehouse' => (bool) $row->allow_continue_without_warehouse,
                'material_supply_rules' => $row->materialSupplyRules->map(fn ($rule) => [
                    'rule_id' => (int) $rule->id,
                    'component_item_id' => (int) $rule->component_item_id,
                    'target_routing_operation_id' => (int) $rule->target_routing_operation_id,
                    'required_qty_ratio' => (float) $rule->required_qty_ratio,
                    'supply_mode' => $rule->supply_mode,
                    'requires_delivery' => (bool) $rule->requires_delivery,
                    'participates_in_kitting' => (bool) $rule->participates_in_kitting,
                    'allow_partial_delivery' => (bool) $rule->allow_partial_delivery,
                    'delivery_location_type' => $rule->delivery_location_type,
                ])->values()->all(),
            ])->values()->all(),
        ];
    }

    private function syncOperations(ProductionRouting $routing, array $rows, object $user): void
    {
        $previous = $routing->operations()->get()->keyBy('id');
        foreach ($rows as &$row) {
            $old = isset($row['id']) ? $previous->get((int) $row['id']) : $previous->first(fn ($node) => (int) $node->sequence === (int) $row['sequence'] && (int) $node->operation_id === (int) $row['operation_id']);
            if (isset($row['id']) && ! $old) throw ValidationException::withMessages(['operations' => '工序明细标识不属于当前草稿路线。']);
            // 提成比例只能通过提成模板指定，路线保存不再写比例。
            unset($row['performance_rate']);
            $row['execution_context'] = $row['execution_context'] ?? 'production';
            $row['shipment_operation_type'] = $row['execution_context'] === 'shipment' ? ($row['shipment_operation_type'] ?? 'packing') : null;
            if ($row['execution_context'] === 'shipment' && ! in_array($row['shipment_operation_type'], ['water_test', 'packing', 'loading'], true)) throw ValidationException::withMessages(['operations' => '请选择试水、打包或装车作业类型。']);
            unset($row['output_item_id']);
            $row['packaging_materials'] = $row['packaging_materials'] ?? [];
            if ($row['shipment_operation_type'] === 'packing' && empty($row['packaging_scheme_id'])) throw ValidationException::withMessages(['operations' => '打包工序必须选择包装方案。']);
            if ($row['execution_context'] === 'shipment' && $row['shipment_operation_type'] !== 'packing'
                && (! empty($row['packaging_scheme_id']) || $row['packaging_materials'] !== [])) throw ValidationException::withMessages(['operations' => '只有打包工序可以配置包装方案和包装用料。']);
            if ($row['execution_context'] === 'production' && (! empty($row['packaging_scheme_id']) || $row['packaging_materials'] !== [])) throw ValidationException::withMessages(['operations' => '生产工序不能配置发货包装方案或包装用料。']);
            if ($row['execution_context'] === 'shipment' && ($row['material_supply_rules'] ?? []) !== []) throw ValidationException::withMessages(['operations' => '包装用料按基础单位数量配置，不能混用生产BOM用量占比。']);
        }
        unset($row);
        // 工序不指定产出物料：只有最后一道车间工序可以入库，入的是工单物料本身。
        $lastProduction = collect($rows)->where('execution_context', 'production')->max(fn ($row) => (int) $row['sequence']);
        foreach ($rows as $row) {
            if ($row['execution_context'] === 'production' && (int) $row['sequence'] !== (int) $lastProduction
                && in_array($row['output_mode'] ?? 'flow_only', ['warehouse_optional', 'warehouse_required'], true)) {
                throw ValidationException::withMessages(['operations' => '只有最后一道车间工序可以入库（产出本物料）。中间半成品请建成独立物料，在 BOM 中作为子件单独开工单；不入库的设为虚拟件。']);
            }
        }
        $stageIds = collect($rows)->pluck('production_stage_id')->filter()->unique();
        $schemeIds = collect($rows)->pluck('packaging_scheme_id')->filter()->unique();
        if (ProductionStage::whereIn('id', $stageIds)->where('status', 'enabled')->count() !== $stageIds->count()
            || ProductionPackagingScheme::whereIn('id', $schemeIds)->where('status', 'enabled')->count() !== $schemeIds->count()) throw ValidationException::withMessages(['operations' => '存在无效或停用的阶段/包装方案。']);
        $packingComponentIds = collect($rows)->flatMap(fn ($row) => $row['packaging_materials'] ?? [])->pluck('component_item_id')->unique();
        $this->assertProductionItems($packingComponentIds->all(), 'operations');
        if (Item::whereIn('id', $packingComponentIds)->where('status', 'enabled')->count() !== $packingComponentIds->count()) throw ValidationException::withMessages(['operations' => '包装用料包含无效或已停用物料。']);
        $sequences = collect($rows)->pluck('sequence');
        if ($sequences->duplicates()->isNotEmpty()) throw ValidationException::withMessages(['operations' => '同一路线的工序顺序号不能重复。']);
        $enabled = ProductionOperation::whereIn('id', collect($rows)->pluck('operation_id'))->where('status', 'enabled')->count();
        if ($enabled !== count(array_unique(collect($rows)->pluck('operation_id')->all()))) throw ValidationException::withMessages(['operations' => '存在无效或已停用的工序。']);
        $componentIds = collect($rows)->flatMap(fn ($row) => $row['material_supply_rules'] ?? [])->pluck('component_item_id')->unique();
        $this->assertProductionItems($componentIds->all(), 'operations');
        if (Item::whereIn('id', $componentIds)->where('status', 'enabled')->count() !== $componentIds->count()) {
            throw ValidationException::withMessages(['operations' => '工序用料包含不存在或已停用的物料。']);
        }
        // 规则同时引用来源和目标工序；先清除本草稿规则，避免目标外键阻止再次保存。
        // 已生效路线只能复制新版本，历史执行快照不会进入此重建路径。
        $operationIds = $routing->operations()->pluck('id');
        DB::table('erp_routing_operation_material_supply_rules')->whereIn('routing_operation_id', $operationIds)->delete();
        $routing->operations()->delete();
        $createdBySequence = collect();
        foreach (collect($rows)->sortBy('sequence')->values() as $row) {
            $created = $routing->operations()->create(collect($row)->only([
                'operation_id', 'sequence', 'parameters', 'is_key_operation', 'remark', 'standard_minutes',
                'setup_standard_minutes', 'unit_standard_minutes',
                'output_mode', 'quality_mode', 'work_mode', 'allow_continue_without_warehouse',
                'production_stage_id', 'execution_context', 'shipment_operation_type', 'packaging_scheme_id', 'packaging_materials',
            ])->all() + [
                'output_mode' => $row['output_mode'] ?? 'flow_only',
                'quality_mode' => $row['quality_mode'] ?? 'none',
                'work_mode' => $row['work_mode'] ?? 'manual',
                'allow_continue_without_warehouse' => (bool) ($row['allow_continue_without_warehouse'] ?? true),
            ]);
            $createdBySequence->put((int) $row['sequence'], $created);
        }
        foreach ($rows as $row) {
            $source = $createdBySequence->get((int) $row['sequence']);
            foreach (($row['material_supply_rules'] ?? []) as $rule) {
                $target = $createdBySequence->get((int) $rule['target_sequence']);
                if (! $target) throw ValidationException::withMessages(['operations' => '物料供应规则的目标工序顺序不属于当前路线。']);
                $requiresDelivery = ($rule['supply_mode'] ?? null) === 'dedicated_delivery';
                if (array_key_exists('requires_delivery', $rule) && (bool) $rule['requires_delivery'] !== $requiresDelivery) {
                    throw ValidationException::withMessages(['operations' => '是否配送必须与物料供应方式保持一致。']);
                }
                $source->materialSupplyRules()->create([
                    'component_item_id' => $rule['component_item_id'],
                    'target_routing_operation_id' => $target->id,
                    'required_qty_ratio' => $rule['required_qty_ratio'] ?? 1,
                    'supply_mode' => $rule['supply_mode'],
                    'requires_delivery' => $requiresDelivery,
                    'participates_in_kitting' => (bool) ($rule['participates_in_kitting'] ?? true),
                    'allow_partial_delivery' => (bool) ($rule['allow_partial_delivery'] ?? false),
                    'delivery_location_type' => $rule['delivery_location_type'] ?? 'operation_station',
                    'business_version' => 1,
                ]);
            }
        }
    }

    private function validateRoutingExecution(ProductionRouting $routing): void
    {
        $routing->loadMissing(['applicabilities.item', 'operations.materialSupplyRules.componentItem']);
        $this->assertRoutingManagementScope($routing);
        $scopes = $routing->applicabilities;
        if ($scopes->isEmpty()) throw ValidationException::withMessages(['applicable_item_ids' => '请至少选择一个适用物料或适用类目。']);
        $disabled = $scopes->where('scope_type', 'item')->filter(fn ($scope) => $scope->item?->status !== 'enabled');
        if ($disabled->isNotEmpty()) throw ValidationException::withMessages(['applicable_item_ids' => '适用物料包含不存在或已停用的物料。']);
        $production = $routing->operations->where('execution_context', 'production');
        // 只有发货作业的路线只给外购物料用（外购件直发包装）；按类目适用无法逐个确认，必须逐个指定物料。
        if ($production->isEmpty() && ($scopes->where('scope_type', 'category')->isNotEmpty()
            || $scopes->contains(fn ($scope) => ! app(ShipmentDirectSourcePolicy::class)->allowsShipmentOnlyRouting($scope->item)))) {
            throw ValidationException::withMessages(['operations' => '自制物料的工艺路线必须至少包含一道生产工序；外购物料可以维护独立发货包装路线（须逐个指定外购物料）。']);
        }
        $firstShipment = $routing->operations->where('execution_context', 'shipment')->min('sequence');
        if ($firstShipment !== null && $production->isNotEmpty() && $production->max('sequence') >= $firstShipment) throw ValidationException::withMessages(['operations' => '发货包装工序必须排在生产工序之后。']);
        foreach ($routing->operations as $operation) {
            if (($operation->execution_context ?: 'production') === 'shipment' && ($operation->shipment_operation_type ?: 'packing') === 'packing'
                && ! $operation->packaging_scheme_id) throw ValidationException::withMessages(['operations' => '打包工序必须选择包装方案。']);
        }
        // BOM 决定总需求量；路线只分配需求到工序。跨工序占比必须合计为一，避免重复备料。
        $rules = $routing->operations->flatMap(fn ($operation) => $operation->materialSupplyRules);
        foreach ($rules->groupBy('component_item_id') as $componentRules) {
            if ($componentRules->contains(fn ($rule) => $rule->componentItem?->status !== 'enabled')
                || abs((float) $componentRules->sum('required_qty_ratio') - 1) > 0.000001
                || $componentRules->pluck('target_routing_operation_id')->duplicates()->isNotEmpty()) {
                throw ValidationException::withMessages(['operations' => '同一物料的工序用量占比必须合计为100%，目标工序不能重复，且物料必须启用。']);
            }
        }
    }

    /**
     * 适用范围：路线不挂产出物料，适用物料和适用类目至少一项，不分主次。
     * 限定 SKU 的路线只适用于该 SKU 当前的默认物料（未传时自动带入）。
     */
    private function syncApplicabilities(ProductionRouting $routing, array $data, bool $creating = false): void
    {
        $existing = $creating ? collect() : $routing->applicabilities()->get();
        $itemIds = array_values(array_unique(array_map('intval', (array) ($data['applicable_item_ids'] ?? $existing->where('scope_type', 'item')->pluck('item_id')->all()))));
        $categoryIds = array_values(array_unique(array_map('intval', (array) ($data['applicable_category_ids'] ?? $existing->where('scope_type', 'category')->pluck('item_category_id')->all()))));
        if ($routing->sku_id) {
            $primary = $this->skuPrimaryItemId((int) $routing->sku_id);
            if (! $itemIds) $itemIds = [$primary];
            if ($itemIds !== [$primary] || $categoryIds) {
                throw ValidationException::withMessages(['applicable_item_ids' => '限定 SKU 的工艺路线只适用于该 SKU 当前的默认物料，不能再选其他物料或类目。']);
            }
        }
        if (! $itemIds && ! $categoryIds) throw ValidationException::withMessages(['applicable_item_ids' => '请至少选择一个适用物料或适用类目。']);
        if ($itemIds) $this->assertProductionItems($itemIds, 'applicable_item_ids');
        if ($categoryIds && DB::table('erp_item_categories')->whereIn('id', $categoryIds)->where('category_type', 'item')->count() !== count($categoryIds))
            throw ValidationException::withMessages(['applicable_category_ids' => '适用类目必须是已存在的物料类目。']);
        $current = $existing->map(fn ($scope) => $scope->scope_key)->sort()->values()->all();
        $target = collect(array_merge(array_map(fn ($id) => 'item:'.$id, $itemIds), array_map(fn ($id) => 'category:'.$id, $categoryIds)))->sort()->values()->all();
        if (! $creating && $current === $target) return;
        ProductionRoutingApplicability::query()->where('routing_id', $routing->id)->delete();
        foreach ($itemIds as $id) $routing->applicabilities()->create(['scope_type' => 'item', 'item_id' => $id, 'scope_key' => 'item:'.$id]);
        foreach ($categoryIds as $id) $routing->applicabilities()->create(['scope_type' => 'category', 'item_category_id' => $id, 'scope_key' => 'category:'.$id]);
        $routing->unsetRelation('applicabilities');
    }

    private function assertObjectRelation(mixed $productId, mixed $skuId): void
    {
        if ($productId && ! $skuId) throw ValidationException::withMessages(['sku_id' => '关联产品时必须同时选择对应 SKU，不能绕过 SKU-物料关系校验。']);
        if (! $skuId) return;
        $sku = Sku::find((int) $skuId);
        if (! $sku || ($productId && (int) $sku->product_id !== (int) $productId)) {
            throw ValidationException::withMessages(['sku_id' => '所选 SKU 不属于当前产品。']);
        }
        $this->skuPrimaryItemId((int) $sku->id);
    }

    private function skuPrimaryItemId(int $skuId): int
    {
        $itemId = DB::table('erp_sku_item_relations')->where('sku_id', $skuId)
            ->whereIn('status', ['active', 'enabled'])->where('is_primary', true)
            ->where(fn ($q) => $q->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
            ->where(fn ($q) => $q->whereNull('expired_at')->orWhere('expired_at', '>', now()))->value('item_id');
        if (! $itemId) throw ValidationException::withMessages(['sku_id' => '该 SKU 没有当前启用的默认物料，不能限定路线。']);
        return (int) $itemId;
    }

    private function assertProductionItems(array $itemIds, string $field): void
    {
        foreach (array_unique(array_map('intval', $itemIds)) as $itemId) {
            $item = Item::query()->lockForUpdate()->find($itemId);
            if (! $item) throw ValidationException::withMessages([$field => '生产资料包含不存在的物料。']);
            app(ItemManagementScopeService::class)->assertProductionAllowed($item, $field);
        }
    }

    private function assertRoutingManagementScope(ProductionRouting $routing): void
    {
        $routing->loadMissing(['operations.materialSupplyRules', 'applicabilities']);
        $categoryIds = $routing->applicabilities->where('scope_type', 'category')->pluck('item_category_id')->filter()->all();
        if ($categoryIds && \App\Models\Erp\ItemCategory::query()->whereIn('id', $categoryIds)->get()->contains(fn ($category) => $category->managementScope() !== 'factory'))
            throw ValidationException::withMessages(['applicable_category_ids' => '适用类目必须是工厂物料类目。']);
        $this->assertProductionItems(array_merge(
            $routing->applicabilities->where('scope_type', 'item')->pluck('item_id')->filter()->all(),
            $routing->operations->flatMap(fn ($row) => $row->materialSupplyRules)->pluck('component_item_id')->all(),
            $routing->operations->flatMap(fn ($row) => $row->packaging_materials ?? [])->pluck('component_item_id')->all()), 'operations');
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
                $entity = $model::findOrFail($existing->entity_id);
                return $entityType === 'routing' ? $entity->load(['product', 'sku', 'applicabilities.item:id,item_code,item_name,spec,item_type,management_scope', 'applicabilities.category:id,category_code,category_name', 'operations.operation']) : $entity;
            }
            DB::table('erp_production_master_commands')->insert([
                'client_command_id' => $id, 'command_type' => $type, 'entity_type' => $entityType,
                'request_hash' => $hash, 'initiated_by_legacy_id' => $this->userId($user), 'created_at' => now(), 'updated_at' => now(),
            ]);
            $entity = $action();
            $responseSnapshot = ['id' => $entity->id];
            DB::table('erp_production_master_commands')->where('client_command_id', $id)->update([
                'entity_id' => $entity->id, 'response_snapshot' => json_encode($responseSnapshot, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 'updated_at' => now(),
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
            $entity = $model::findOrFail($existing->entity_id);
            return $entityType === 'routing' ? $entity->load(['product', 'sku', 'applicabilities.item:id,item_code,item_name,spec,item_type,management_scope', 'applicabilities.category:id,category_code,category_name', 'operations.operation']) : $entity;
        }
    }

    private function deleteCommand(string $type, string $entityType, int $entityId, array $data, object $user, callable $action): array
    {
        $commandId = trim((string) ($data['client_command_id'] ?? ''));
        if ($commandId === '') throw ValidationException::withMessages(['client_command_id' => '写操作必须提供 client_command_id。']);
        $hashPayload = $data + ['entity_id' => $entityId];
        ksort($hashPayload);
        $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($type, $entityType, $entityId, $user, $action, $commandId, $hash): array {
            $existing = DB::table('erp_production_master_commands')->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_hash !== $hash || $existing->command_type !== $type) {
                    throw ValidationException::withMessages(['client_command_id' => '该请求标识已用于不同操作。']);
                }
                return ['id' => (int) $existing->entity_id, 'deleted' => true];
            }
            DB::table('erp_production_master_commands')->insert([
                'client_command_id' => $commandId, 'command_type' => $type, 'entity_type' => $entityType,
                'entity_id' => $entityId, 'request_hash' => $hash,
                'initiated_by_legacy_id' => $this->userId($user),
                'response_snapshot' => json_encode(['id' => $entityId, 'deleted' => true]),
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $action();
            return ['id' => $entityId, 'deleted' => true];
        }, 5);
    }

    private function version($model, array $data): void
    {
        if ((int) ($data['expected_version'] ?? 0) !== (int) $model->business_version) throw ValidationException::withMessages(['expected_version' => '数据版本已变化，请刷新后重试。']);
    }

    /** 工序被哪些路线和 BOM 引用，供停用前提醒。 */
    public function operationReferences(int $id, array $permissions, bool $superAdmin): array
    {
        $this->authorize($permissions, $superAdmin, 'production.operation.view');
        ProductionOperation::query()->findOrFail($id);
        $routings = DB::table('erp_production_routing_operations as ro')
            ->join('erp_production_routings as r', 'r.id', '=', 'ro.routing_id')
            ->where('ro.operation_id', $id)->whereIn('r.status', ['draft', 'active'])
            ->distinct()->orderBy('r.id')->get(['r.id', 'r.routing_no', 'r.routing_name', 'r.status']);
        return ['routings' => $routings->map(fn ($r) => (array) $r)->all(), 'boms' => app(BomOperationBindingService::class)->referencesForOperation($id)];
    }

    /** 停用工序的 BOM 规则：生效 BOM 引用时阻止；草稿 BOM 不阻止，停用后解除其绑定。 */
    private function prepareOperationDisable(ProductionOperation $operation, object $user): void
    {
        $bindings = app(BomOperationBindingService::class);
        $bindings->clearDraftBindings((int) $operation->id, (string) $operation->operation_name, $this->userId($user));
    }

    private function hasActiveRoutingReference(int $operationId): bool
    {
        try {
            return DB::table('erp_production_routing_operations as ro')
                ->join('erp_production_routings as r', 'r.id', '=', 'ro.routing_id')
                ->where('ro.operation_id', $operationId)->where('r.status', 'active')
                ->lock('FOR UPDATE NOWAIT')->first(['ro.id']) !== null;
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 3572) throw $exception;
            throw ValidationException::withMessages(['status' => '关联工艺路线正在变更，本次工序启停未生效，请稍后重试。']);
        }
    }

    private function authorize(array $permissions, bool $superAdmin, string|array $required): void
    {
        $required = (array) $required;
        if (! $superAdmin && collect($required)->every(fn ($code) => ! in_array($code, $permissions, true))) abort(403, '无权执行该生产基础数据操作。');
    }

    private function userId(object $user): ?int { return isset($user->legacy_id) ? (int) $user->legacy_id : null; }
}
