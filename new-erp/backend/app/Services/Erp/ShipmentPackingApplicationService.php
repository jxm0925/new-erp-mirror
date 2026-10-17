<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Item;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\SalesShipment;
use App\Models\Erp\SalesShipmentLine;
use App\Models\Erp\SalesShipmentPackage;
use App\Models\Erp\ShipmentPackingContent;
use App\Models\Erp\ShipmentPackingLaborSession;
use App\Models\Erp\ShipmentPackingMaterial;
use App\Models\Erp\ShipmentPackingOperation;
use App\Models\Erp\ShipmentPackingParticipant;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** Packing is factual shipment execution; no sale price or earned amount is calculated here. */
class ShipmentPackingApplicationService
{
    public function __construct(private readonly InventoryService $inventory) {}

    public function freezeRequirements(SalesShipment $shipment): void
    {
        foreach ($shipment->lines()->get() as $line) {
            if ($line->packing_source_snapshot !== null) continue;
            $context = app(ProductionShipmentSourceResolver::class)->contextForShipmentLine($line);
            $line->update(['packing_routing_snapshot' => $context['routing_snapshot'] ?? null,
                'packing_source_snapshot' => $context['source_rows'] ?? []]);
        }
    }

    public function requirements(SalesShipment $shipment): array
    {
        return $shipment->lines()->with('orderLine')->get()->map(function (SalesShipmentLine $line): array {
            // Frozen route/source choices remain historical facts, but readiness
            // must be checked against current formal production completion.
            $context = app(ProductionShipmentSourceResolver::class)->contextForShipmentLine($line);
            $route = $line->packing_routing_snapshot;
            // Older drafts have no frozen packing context. Read the formal source resolver only;
            // a current route is never substituted for an already frozen historic route.
            if ($line->packing_source_snapshot === null) {
                $route = $context['routing_snapshot'] ?? null;
            }
            $nodes = $this->shipmentNodes($route);
            $schemes = $nodes->groupBy('packaging_scheme_id')->map(fn ($group, $id) => [
                'id' => (int) $id,
                'name' => $group->first()['packaging_scheme_name'] ?? $group->first()['packaging_scheme_name_snapshot'] ?? '',
                'operations' => $group->map(fn ($node) => ['name' => $node['operation_name'], 'sequence' => $node['sequence']])->values()->all(),
            ])->values()->all();
            return ['shipment_line_id' => (int) $line->id, 'sales_order_line_id' => (int) $line->sales_order_line_id,
                'item_id' => (int) $line->item_id, 'item_name' => $line->orderLine?->item_name,
                'product_name' => $line->orderLine?->product_name, 'base_qty' => $line->base_qty,
                'serial_tracking_mode' => \App\Models\Erp\Item::find($line->item_id)?->serialTrackingMode() ?? 'none',
                'batch_no' => $line->batch_no, 'serial_ids' => $line->serial_snapshot['inventory_serial_ids'] ?? [],
                'serials' => DB::table('erp_inventory_serials')->whereIn('id', $line->serial_snapshot['inventory_serial_ids'] ?? [])->get(['id', 'serial_no'])->map(fn ($row) => (array) $row)->all(),
                'packing_required' => $nodes->isNotEmpty(), 'schemes' => $schemes, 'packing_route' => $this->routeIdentity($route),
                'production_ready' => $context['production_ready'], 'production_blockers' => $context['blockers'],
                'continuations' => $context['continuations'] ?? []];
        })->all();
    }

    public function routingCandidates(SalesShipment $shipment, int $lineId, array $filters): array
    {
        $line = $shipment->lines()->with('orderLine')->findOrFail($lineId);
        $query = $this->candidateQuery($line)->when(trim((string) ($filters['keyword'] ?? '')) !== '',
            fn ($q) => $q->where(fn ($w) => $w->where('routing_no', 'like', '%'.trim($filters['keyword']).'%')
                ->orWhere('routing_name', 'like', '%'.trim($filters['keyword']).'%')));
        $page = $query->orderByDesc('version')->orderByDesc('id')->paginate(10, ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $before = $this->packingMaterials($this->originRoute($line));
        $page->setCollection($page->getCollection()->map(function ($route) use ($before) {
            $snapshot = app(ProductionMasterDataService::class)->snapshot($route);
            return ['id' => (int) $route->id, 'routing_no' => $route->routing_no, 'routing_name' => $route->routing_name,
                'version' => (int) $route->version, 'business_version' => (int) $route->business_version,
                'before_materials' => $before, 'packaging_materials' => $this->packingMaterials($snapshot)];
        }));
        return $page->toArray();
    }

    public function savedRouteSelections(SalesShipment $shipment): array
    {
        return $shipment->packingContents()->get(['shipment_line_id', 'routing_snapshot'])->map(fn ($content) =>
            $content->routing_snapshot['packing_route_selection'] ?? null)->filter()->unique('shipment_line_id')->values()->all();
    }

    public function configure(int $shipmentId, array $payload, object $actor): array
    {
        return $this->command('configure', $shipmentId, $payload, $actor, function (SalesShipment $shipment) use ($payload, $actor): array {
            $this->assertEditable($shipment);
            if ((int) ($payload['expected_version'] ?? 0) !== (int) $shipment->packing_version) $this->fail('包装方案已变化，请刷新后重试。');
            if ($shipment->packingOperations()->whereNotNull('claimed_at')->exists()) $this->fail('已有人员接单，不能重建包装方案。');
            $operationIds = $shipment->packingOperations()->select('id');
            if (ShipmentPackingLaborSession::whereIn('operation_id', clone $operationIds)->exists()
                || ShipmentPackingMaterial::whereIn('operation_id', clone $operationIds)->exists()) $this->fail('已有实际工时或用料，不能重建包装方案。');
            $this->freezeRequirements($shipment);
            $this->assertProductionReady($shipment);
            $lines = $shipment->lines()->get()->keyBy('id');
            // Candidate versions are locked and checked before replacing any packing contents or operations.
            $routes = $this->selectedRoutes($lines, (array) ($payload['packing_route_selections'] ?? []), $actor, $shipment);
            $coverage = []; $seenSerials = []; $packages = (array) ($payload['packages'] ?? []);
            if ($packages === []) $this->fail('请添加包裹及其产品明细。');

            // Validate the complete plan before replacing any selection. Partial requests must
            // not silently drop products, duplicate serials or mix another shipment's source.
            foreach ($packages as $packageIndex => $package) {
                if (empty($package['contents'])) $this->fail('每个包裹至少需要一项产品。');
                foreach ($package['contents'] as $row) {
                    $line = $lines->get((int) ($row['shipment_line_id'] ?? 0));
                    if (! $line) $this->fail('包裹产品必须属于当前发货单。');
                    $quantity = CuttingDecimal::value($row['base_qty'] ?? '', 8, true);
                    $coverage[$line->id] = bcadd($coverage[$line->id] ?? '0', $quantity, 8);
                    $nodes = $this->shipmentNodes($routes[$line->id]);
                    $schemeId = (int) ($row['packaging_scheme_id'] ?? 0);
                    if ($nodes->isNotEmpty() && ! $nodes->contains(fn ($node) => (int) ($node['packaging_scheme_id'] ?? 0) === $schemeId)) {
                        $this->fail('所选包装方案不属于该产品冻结的工艺流程。');
                    }
                    if ($nodes->isEmpty() && $schemeId) $this->fail('当前产品未配置该包装方案。');
                    $serials = array_map('intval', (array) ($row['inventory_serial_ids'] ?? []));
                    $allowed = array_map('intval', (array) ($line->serial_snapshot['inventory_serial_ids'] ?? []));
                    if (count($serials) !== count(array_unique($serials)) || array_diff($serials, $allowed)
                        || array_intersect($seenSerials, $serials)) $this->fail('包裹序列号重复或不属于本次发货来源。');
                    $serialMode = \App\Models\Erp\Item::find($line->item_id)?->serialTrackingMode() ?? 'none';
                    if ($serialMode === 'required' && bccomp($quantity, (string) count($serials), 8) !== 0) $this->fail('包裹数量必须与所选序列号数量一致。');
                    if (bccomp($quantity, (string) count($serials), 8) < 0) $this->fail('包裹序列号数量不能超过该产品数量。');
                    if ($allowed === [] && $serials !== []) $this->fail('非序列号产品不能填写序列号。');
                    $seenSerials = array_merge($seenSerials, $serials);
                }
            }
            foreach ($lines as $line) {
                if (bccomp($coverage[$line->id] ?? '0', (string) $line->base_qty, 8) !== 0) $this->fail('所有包裹中该产品的数量合计必须等于本次发货数量。');
                if (array_diff((array) ($line->serial_snapshot['inventory_serial_ids'] ?? []), $seenSerials)) $this->fail('本次发货的每个序列号都必须分配到包裹。');
            }
            $shipment->packingOperations()->delete();
            $shipment->packingContents()->delete();
            $shipment->packages()->delete();
            foreach (array_values($packages) as $packageIndex => $package) {
                $parcel = SalesShipmentPackage::create(['shipment_id' => $shipment->id,
                    'package_no' => trim((string) ($package['package_no'] ?? '')) ?: $shipment->shipment_no.'-'.str_pad((string) ($packageIndex + 1), 2, '0', STR_PAD_LEFT),
                    'carrier_name' => $package['carrier_name'] ?? $shipment->carrier_name_snapshot,
                    'tracking_no' => $package['tracking_no'] ?? $shipment->tracking_no,
                    'weight' => $package['weight'] ?? null, 'volume' => $package['volume'] ?? null,
                    'freight_amount' => $package['freight_amount'] ?? 0, 'package_status' => 'packing']);
                $groups = [];
                foreach ($package['contents'] as $row) {
                    $line = $lines->get((int) $row['shipment_line_id']);
                    $route = $routes[$line->id];
                    $schemeId = (int) ($row['packaging_scheme_id'] ?? 0);
                    $nodes = $this->shipmentNodes($route)->filter(fn ($node) => (int) ($node['packaging_scheme_id'] ?? 0) === $schemeId)->sortBy('sequence')->values();
                    $content = ShipmentPackingContent::create(['shipment_id' => $shipment->id, 'package_id' => $parcel->id,
                        'shipment_line_id' => $line->id, 'sales_order_line_id' => $line->sales_order_line_id, 'item_id' => $line->item_id,
                        'packaging_scheme_id' => $schemeId ?: null,
                        'packaging_scheme_name_snapshot' => $nodes->first()['packaging_scheme_name'] ?? null,
                        'base_qty' => CuttingDecimal::value($row['base_qty'], 8, true),
                        'serial_snapshot' => ['inventory_serial_ids' => array_map('intval', $row['inventory_serial_ids'] ?? [])],
                        'source_snapshot' => $line->packing_source_snapshot, 'routing_snapshot' => $route]);
                    if ($nodes->isEmpty()) continue;
                    // 同一路线版本的不同来源也可能冻结了不同档案及节点规则，只合并完整冻结事实相同的包装链。
                    $key = hash('sha256', json_encode($this->canonicalSnapshot([
                        'routing_id' => (int) $route['routing_id'], 'routing_version' => (int) $route['version'],
                        'packaging_scheme_id' => $schemeId, 'item_id' => (int) $line->item_id, 'operations' => $nodes->all(),
                    ]), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
                    $groups[$key] ??= ['route' => $route, 'nodes' => $nodes, 'ids' => [], 'qty' => '0'];
                    $groups[$key]['ids'][] = $content->id;
                    $groups[$key]['qty'] = bcadd($groups[$key]['qty'] ?? '0', (string) $content->base_qty, 8);
                }
                foreach ($groups as $chain => $group) {
                    foreach ($group['nodes'] as $index => $node) {
                        ShipmentPackingOperation::create(['shipment_id' => $shipment->id, 'package_id' => $parcel->id,
                            'routing_id' => $group['route']['routing_id'], 'routing_version_snapshot' => $group['route']['version'],
                            'routing_operation_id' => $node['routing_operation_id'], 'operation_id' => $node['operation_id'],
                            'operation_code_snapshot' => $node['operation_no'] ?? null, 'operation_name_snapshot' => $node['operation_name'],
                            'is_public_snapshot' => (bool) ($node['is_public'] ?? false),
                            'production_stage_id' => $node['production_stage_id'] ?? null,
                            'stage_code_snapshot' => $node['stage_code'] ?? $node['production_stage_code'] ?? null,
                            'stage_name_snapshot' => $node['stage_name'] ?? $node['production_stage_name'] ?? null,
                            'performance_rate_snapshot' => $node['performance_rate'] ?? null,
                            'sequence' => (int) $node['sequence'], 'chain_key' => $chain,
                            'packing_content_ids' => $group['ids'], 'planned_base_qty' => $group['qty'],
                            'packaging_materials_snapshot' => $node['packaging_materials'] ?? [],
                            'setup_standard_minutes_snapshot' => $node['setup_standard_minutes'] ?? null,
                            'unit_standard_minutes_snapshot' => $node['unit_standard_minutes'] ?? null,
                            'quality_mode_snapshot' => $node['quality_mode'] ?? 'none',
                            'status' => $index === 0 ? 'READY' : 'WAITING']);
                    }
                }
                if (! $parcel->packingOperations()->exists()) $parcel->update(['package_status' => 'packed']);
            }
            $shipment->update(['packing_version' => (int) $shipment->packing_version + 1, 'packing_configured_at' => now()]);
            $this->log($shipment->id, null, 'configure', $actor, ['package_count' => count($packages), 'packing_version' => $shipment->packing_version,
                'packing_route_selections' => array_values(array_filter(array_map(fn ($route) => $route['packing_route_selection'] ?? null, $routes)))]);
            return ['shipment_id' => $shipment->id, 'packing_version' => $shipment->packing_version];
        });
    }

    public function action(int $operationId, string $action, array $payload, object $actor): array
    {
        $shipmentId = (int) ShipmentPackingOperation::query()->whereKey($operationId)->value('shipment_id');
        return $this->command($action, $shipmentId, $payload + ['operation_id' => $operationId], $actor, function (SalesShipment $shipment) use ($operationId, $action, $payload, $actor): array {
            $this->assertEditable($shipment);
            $op = ShipmentPackingOperation::query()->where('shipment_id', $shipment->id)->lockForUpdate()->findOrFail($operationId);
            if ((int) $op->business_version !== (int) ($payload['expected_version'] ?? 0)) $this->fail('包装工序已变化，请刷新后重试。');
            $actorId = (int) $actor->legacy_id;
            if (in_array($op->status, ['COMPLETED', 'CANCELLED'], true)) $this->fail('已完成或取消的包装工序不能再次操作。');
            if ($action === 'claim') {
                if ($op->status !== 'READY' || $op->owner_legacy_id) $this->fail('该工序尚未就绪或已被他人接单。');
                $op->fill(['owner_legacy_id' => $actorId, 'claimed_at' => now()]);
                $this->participant($op, $actor, 'OWNER', $actorId);
            } elseif ($action === 'collaborators') {
                $this->assertOwner($op, $actorId);
                $ids = array_values(array_unique(array_map('intval', (array) ($payload['employee_legacy_ids'] ?? []))));
                $ids = array_values(array_diff($ids, [$actorId]));
                $users = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->whereIn(DB::raw('LOWER(status)'), ['normal', 'active'])->get();
                if ($users->count() !== count($ids)) $this->fail('协同人必须选择现有启用账号。');
                $auth = app(AuthContextService::class);
                foreach ($users as $user) {
                    if (! $auth->isSuperAdmin($user) && ! in_array('sales_order.shipment.packing.execute', $auth->permissionCodes($user), true)) $this->fail('所选协同人没有包装执行权限。');
                }
                $removed = $op->participants()->where('role', 'COLLABORATOR')->whereNotIn('employee_legacy_id', $ids)->pluck('employee_legacy_id');
                if ($op->laborSessions()->whereIn('employee_legacy_id', $removed)->where('status', 'ACTIVE')->exists()) $this->fail('仍在计时的协同人须先暂停自己的作业。');
                $op->participants()->where('role', 'COLLABORATOR')->whereNotIn('employee_legacy_id', $ids)->update(['is_active' => false]);
                foreach ($users as $user) $this->participant($op, $user, 'COLLABORATOR', $actorId);
                // Selection and its exact owner/collaborator allocation are one
                // command. A rejected allocation rolls the personnel changes back.
                app(ProductionPerformanceApplicationService::class)->planScopeShares('shipment_packing_operation', (int) $op->id,
                    isset($payload['shares']) ? (array) $payload['shares'] : null, $actor, $auth->permissionCodes($actor));
            } elseif (in_array($action, ['start', 'pause'], true)) {
                if (! $op->participants()->where('employee_legacy_id', $actorId)->where('is_active', true)->exists()) $this->fail('只有接单人选择的参与人员可以记录本人工时。');
                if ($action === 'start') {
                    // The same employee row also serializes ordinary production starts, so
                    // simultaneous commands in separate tasks cannot both open a timer.
                    DB::table('erp_legacy_admin_users')->where('legacy_id', $actorId)->lockForUpdate()->first();
                    app(WarehouseLaborSessionService::class)->assertNoActiveWarehouse($actorId);
                    if (ShipmentPackingLaborSession::query()->where('employee_legacy_id', $actorId)->where('status', 'ACTIVE')->lockForUpdate()->first()) {
                        $this->fail('本人已有正在计时的包装作业，请先暂停原作业。');
                    }
                    if (DB::table('erp_production_labor_sessions')->where('employee_legacy_id', $actorId)->where('status', 'ACTIVE')->lockForUpdate()->first()) {
                        $this->fail('本人已有正在计时的生产或下料作业，请先暂停原作业。');
                    }
                    if ($op->status === 'READY') {
                        $this->assertOwner($op, $actorId);
                        $this->assertProductionReady($shipment);
                        $op->fill(['status' => 'IN_PROGRESS', 'started_at' => $op->started_at ?: now()]);
                    }
                    if ($op->status !== 'IN_PROGRESS') $this->fail('工序尚未开工，协同人不能先开始作业。');
                    ShipmentPackingLaborSession::create(['operation_id' => $op->id, 'employee_legacy_id' => $actorId, 'status' => 'ACTIVE', 'started_at' => now()]);
                } else {
                    $session = $op->laborSessions()->where('employee_legacy_id', $actorId)->where('status', 'ACTIVE')->lockForUpdate()->first();
                    if (! $session) $this->fail('本人没有正在计时的作业。');
                    $this->closeSession($session, 'PAUSED');
                }
            } elseif ($action === 'materials') {
                $this->assertOwner($op, $actorId);
                if ($op->status !== 'IN_PROGRESS') $this->fail('包装工序开工后才能登记实际用料。');
                $materials = [];
                $balanceTotals = [];
                $usagePolicy = app(ShipmentPackingMaterialUsagePolicy::class);
                foreach ((array) ($payload['materials'] ?? []) as $row) {
                    $balance = InventoryBalance::query()->with('unit.standardUnit')->lockForUpdate()->findOrFail((int) ($row['inventory_balance_id'] ?? 0));
                    $allowed = collect($op->packaging_materials_snapshot ?? [])->pluck('component_item_id')->map(fn ($id) => (int) $id)->all();
                    if (! in_array((int) $balance->item_id, $allowed, true)) $this->fail('包装用料必须来自本工序已配置的真实物料。');
                    $quantity = CuttingDecimal::value($row['base_qty'] ?? '', 8);
                    $baseUnit = app(UnitConversionDomainService::class)->canonicalUnit($balance->unit);
                    $usagePolicy->assertQuantity($quantity, (string) $balance->quantity_available, $baseUnit);
                    $balanceTotals[$balance->id] = bcadd($balanceTotals[$balance->id] ?? '0', $quantity, 8);
                    $usagePolicy->assertQuantity($balanceTotals[$balance->id], (string) $balance->quantity_available,
                        $baseUnit);
                    if (bccomp($quantity, '0', 8) > 0) {
                        $item = Item::query()->lockForUpdate()->findOrFail($balance->item_id);
                        app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'materials');
                    }
                    $materials[] = ShipmentPackingMaterial::create(['operation_id' => $op->id, 'inventory_balance_id' => $balance->id,
                        'item_id' => $balance->item_id, 'unit_id' => $balance->unit_id,
                        'base_qty' => $quantity,
                        'usage_snapshot' => $usagePolicy->snapshot($row),
                        'serial_snapshot' => ['inventory_serial_ids' => $row['inventory_serial_ids'] ?? [], 'physical_material_ids' => $row['physical_material_ids'] ?? []],
                        'posted_by_legacy_id' => $actorId]);
                }
                if ($materials === []) $this->fail('请至少选择一项实际用料及其库存来源。');
                $transaction = $this->inventory->postShipmentPackingMaterials($op, $materials, $actor);
                foreach ($materials as $material) {
                    $posted = $transaction->items->first(fn ($item) => (int) $item->source_item_id === (int) $material->id);
                    if (! $posted) throw new \LogicException('Missing packing material inventory posting.');
                    $material->update(['inventory_transaction_id' => $transaction->id, 'inventory_transaction_item_id' => $posted->id,
                        'cost_amount_snapshot' => bcsub('0', (string) $posted->cost_amount, 4), 'posted_at' => now()]);
                }
            } elseif ($action === 'complete') {
                $this->assertOwner($op, $actorId);
                if ($op->status !== 'IN_PROGRESS') $this->fail('只有加工中的包装工序可以完成。');
                if (bccomp(CuttingDecimal::value($payload['completed_base_qty'] ?? '', 8, true), (string) $op->planned_base_qty, 8) !== 0) $this->fail('完成数量必须等于本工序包裹产品数量；不足的产品不能随包裹发运。');
                foreach ($op->packaging_materials_snapshot ?? [] as $required) {
                    if (! $op->materials()->where('item_id', $required['component_item_id'])->whereNotNull('posted_at')->exists()) $this->fail('本工序配置的包装材料尚未登记正式库存耗用。');
                }
                // A responsible person cannot stop another person's timer. Each collaborator
                // pauses their own work before the owner confirms this package's good quantity.
                if ($op->laborSessions()->where('status', 'ACTIVE')->where('employee_legacy_id', '!=', $actorId)->exists()) $this->fail('协同人须先暂停本人的计时，再由接单人完成工序。');
                foreach ($op->laborSessions()->where('status', 'ACTIVE')->lockForUpdate()->get() as $session) $this->closeSession($session, 'COMPLETED');
                if (! $op->laborSessions()->where('employee_legacy_id', $actorId)->exists()) $this->fail('接单人必须记录实际作业工时。');
                $op->fill(['status' => $op->quality_mode_snapshot === 'none' ? 'COMPLETED' : 'WAIT_QUALITY',
                    'completed_base_qty' => $op->planned_base_qty, 'completed_at' => $op->quality_mode_snapshot === 'none' ? now() : null]);
            } elseif ($action === 'inspect') {
                if ($op->status !== 'WAIT_QUALITY') $this->fail('该包装工序当前不处于待质检状态。');
                $auth = app(AuthContextService::class);
                if (! $auth->isSuperAdmin($actor) && ! in_array('sales_order.shipment.packing.quality', $auth->permissionCodes($actor), true)) $this->fail('没有包装质检权限。');
                if (! in_array($payload['result'] ?? '', ['passed', 'failed'], true)) $this->fail('请选择合格或不合格。');
                $passed = $payload['result'] === 'passed';
                $op->fill(['quality_result' => $payload['result'], 'inspected_by_legacy_id' => $actorId,
                    'inspected_at' => now(), 'inspection_remark' => $payload['reason'] ?? null,
                    'status' => $passed ? 'COMPLETED' : 'READY', 'completed_at' => $passed ? now() : null]);
            } else $this->fail('未知包装工序动作。');
            $op->business_version = (int) $op->business_version + 1;
            $op->save();
            if (in_array($action, ['complete', 'inspect'], true) && $op->status === 'COMPLETED') {
                app(ProductionPerformanceApplicationService::class)->ensureDefaultShares('shipment_packing_operation', (int) $op->id);
                $next = ShipmentPackingOperation::query()->where('package_id', $op->package_id)->where('chain_key', $op->chain_key)->where('sequence', '>', $op->sequence)->orderBy('sequence')->lockForUpdate()->first();
                if ($next && $next->status === 'WAITING') $next->update(['status' => 'READY', 'business_version' => (int) $next->business_version + 1]);
                if (! ShipmentPackingOperation::query()->where('package_id', $op->package_id)->where('status', '!=', 'COMPLETED')->exists()) $op->package()->update(['package_status' => 'packed']);
            }
            $this->log($shipment->id, $op->id, $action, $actor, collect($payload)->except(['client_command_id'])->all());
            return ['shipment_id' => (int) $shipment->id, 'operation_id' => (int) $op->id, 'status' => $op->status, 'business_version' => (int) $op->business_version];
        });
    }

    public function assertReady(SalesShipment $shipment): void
    {
        $this->assertProductionReady($shipment);
        foreach ($shipment->lines()->get() as $line) {
            $route = $line->packing_routing_snapshot;
            if ($line->packing_source_snapshot === null) $route = app(ProductionShipmentSourceResolver::class)->contextForShipmentLine($line)['routing_snapshot'] ?? null;
            if ($this->shipmentNodes($route)->isEmpty()) continue;
            $contents = $shipment->packingContents()->where('shipment_line_id', $line->id)->get();
            if (bccomp((string) $contents->sum('base_qty'), (string) $line->base_qty, 8) !== 0) $this->fail('发货产品尚未完整分配包装方案和包裹。');
            foreach ($contents as $content) {
                if (! ShipmentPackingOperation::query()->where('package_id', $content->package_id)->whereJsonContains('packing_content_ids', (int) $content->id)->exists()) $this->fail('包裹缺少对应产品的包装工序。');
            }
        }
        if ($shipment->packingOperations()->where('status', '!=', 'COMPLETED')->exists()) $this->fail('本次发货的包装工序尚未全部完成。');
    }

    public function assertCanCancel(SalesShipment $shipment): void
    {
        if ($shipment->packingOperations()->whereNotNull('started_at')->exists()) $this->fail('该发货单已发生包装作业，请先核对包装用料及工时，不能直接取消或删除。');
    }

    public function projection(ShipmentPackingOperation $op, int $actorId, array $filters = []): array
    {
        $op->loadMissing(['package', 'participants', 'materials.item']);
        $labor = $op->laborSessions();
        $totals = (clone $labor)->selectRaw("employee_legacy_id, COUNT(*) as session_count,
            SUM(CASE WHEN ended_at IS NOT NULL THEN actual_labor_minutes ELSE 0 END) as closed_minutes,
            SUM(CASE WHEN status='ACTIVE' THEN 1 ELSE 0 END) as active_count")
            ->groupBy('employee_legacy_id')->get()->keyBy('employee_legacy_id');
        $participants = $op->participants->map(fn ($row) => ['employee_legacy_id' => (int) $row->employee_legacy_id,
            'name' => $row->employee_name_snapshot, 'role' => $row->role, 'is_active' => (bool) $row->is_active,
            'actual_labor_minutes' => bcadd((string) ($totals[$row->employee_legacy_id]->closed_minutes ?? 0), '0', 2),
            'has_labor' => (int) ($totals[$row->employee_legacy_id]->session_count ?? 0) > 0,
            'is_timing' => (int) ($totals[$row->employee_legacy_id]->active_count ?? 0) > 0])->values()->all();
        $sessions = (clone $labor)->orderByDesc('id')->paginate(10, ['*'], 'labor_page', max(1, (int) ($filters['labor_page'] ?? 1)));
        $names = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $sessions->getCollection()->pluck('employee_legacy_id'))
            ->get(['legacy_id', 'nickname', 'username'])->keyBy('legacy_id');
        $sessions->setCollection($sessions->getCollection()->map(fn ($row) => [
            'id' => (int) $row->id, 'employee_legacy_id' => (int) $row->employee_legacy_id,
            'employee_name' => ($names[$row->employee_legacy_id]->nickname ?? '') ?: ($names[$row->employee_legacy_id]->username ?? null),
            'status' => $row->status, 'started_at' => $row->started_at?->toISOString(), 'ended_at' => $row->ended_at?->toISOString(),
            'end_reason' => $row->ended_at ? $row->status : null, 'actual_labor_minutes' => $row->actual_labor_minutes]));
        $me = collect($participants)->firstWhere('employee_legacy_id', $actorId);
        $active = ! in_array($op->status, ['COMPLETED', 'CANCELLED'], true);
        $sharePlan = null;
        if ((int) $op->owner_legacy_id === $actorId) {
            $assignment = \App\Models\Erp\ProductionPerformanceAssignment::query()->with('shares')
                ->where('active_scope_key', 'shipment_packing_operation:'.$op->id)->first();
            if ($assignment) $sharePlan = ['version_no' => (int) $assignment->version_no, 'status' => $assignment->status,
                'shares' => $assignment->shares->map(fn ($share) => ['employee_legacy_id' => (int) $share->employee_legacy_id,
                    'share_ratio' => (string) $share->share_ratio])->values()->all()];
        }
        return ['id' => (int) $op->id, 'shipment_id' => (int) $op->shipment_id, 'package_id' => (int) $op->package_id, 'share_plan' => $sharePlan,
            'package_no' => $op->package?->package_no, 'operation_name' => $op->operation_name_snapshot,
            'is_public_snapshot' => (bool) $op->is_public_snapshot,
            'stage_name' => $op->stage_name_snapshot, 'sequence' => (int) $op->sequence, 'status' => $op->status,
            'quality_result' => $op->quality_result,
            'owner_legacy_id' => $op->owner_legacy_id ? (int) $op->owner_legacy_id : null,
            'planned_base_qty' => $op->planned_base_qty, 'completed_base_qty' => $op->completed_base_qty,
            'business_version' => (int) $op->business_version, 'participants' => $participants,
            'labor' => ['schema_ready' => true, 'closed_minutes' => bcadd((string) (clone $labor)->whereNotNull('ended_at')->sum('actual_labor_minutes'), '0', 2),
                'active_count' => (clone $labor)->where('status', 'ACTIVE')->count(),
                'my_active_session_id' => (clone $labor)->where('employee_legacy_id', $actorId)->where('status', 'ACTIVE')->value('id'),
                'shared' => false, 'sessions' => $sessions->toArray()],
            'contents' => $op->contents()->with('shipmentLine.orderLine')->get()->map(fn ($row) => [
                'id' => $row->id, 'product_name' => $row->shipmentLine?->orderLine?->product_name,
                'item_name' => $row->shipmentLine?->orderLine?->item_name, 'base_qty' => $row->base_qty,
                'packaging_scheme_name' => $row->packaging_scheme_name_snapshot,
                'inventory_serial_ids' => $row->serial_snapshot['inventory_serial_ids'] ?? [],
                'serial_nos' => DB::table('erp_inventory_serials')->whereIn('id', $row->serial_snapshot['inventory_serial_ids'] ?? [])->pluck('serial_no')->all()])->all(),
            'material_requirements' => collect($op->packaging_materials_snapshot ?? [])->map(fn ($row) => [
                'item_id' => (int) $row['component_item_id'], 'item_name' => DB::table('erp_items')->where('id', $row['component_item_id'])->value('item_name'),
                'required_base_qty' => bcmul((string) $row['base_qty_per_output_unit'], (string) $op->planned_base_qty, 8)])->all(),
            'materials' => $op->materials->map(fn ($row) => ['id' => $row->id, 'item_name' => $row->item?->item_name,
                'inventory_balance_id' => $row->inventory_balance_id, 'base_qty' => $row->base_qty, 'usage_snapshot' => $row->usage_snapshot, 'posted_at' => $row->posted_at])->all(),
            'allowed_actions' => [
                'claim' => $active && $op->status === 'READY' && ! $op->owner_legacy_id,
                'collaborators' => $active && (int) $op->owner_legacy_id === $actorId,
                'start' => $active && $me && $me['is_active'] && ! $me['is_timing'] && ($op->status === 'IN_PROGRESS' || ((int) $op->owner_legacy_id === $actorId && $op->status === 'READY')),
                'pause' => $active && $me && $me['is_timing'],
                'materials' => $active && (int) $op->owner_legacy_id === $actorId && $op->status === 'IN_PROGRESS' && ! empty($op->packaging_materials_snapshot),
                'complete' => $active && (int) $op->owner_legacy_id === $actorId && $op->status === 'IN_PROGRESS',
                'inspect' => $active && $op->status === 'WAIT_QUALITY',
            ]];
    }

    private function originRoute(SalesShipmentLine $line): ?array
    {
        return $line->packing_source_snapshot !== null ? $line->packing_routing_snapshot
            : (app(ProductionShipmentSourceResolver::class)->contextForShipmentLine($line)['routing_snapshot'] ?? null);
    }

    private function candidateQuery(SalesShipmentLine $line)
    {
        $schemes = $this->shipmentNodes($this->originRoute($line))->pluck('packaging_scheme_id')->map('intval')->unique()->all();
        return ProductionRouting::where('output_item_id', $line->item_id)->where('status', 'active')
            ->where(fn ($q) => $q->whereNull('product_id')->orWhere('product_id', $line->orderLine?->product_id))
            ->where(fn ($q) => $q->whereNull('sku_id')->orWhere('sku_id', $line->orderLine?->sku_id))
            ->whereHas('operations', fn ($q) => $q->where('execution_context', 'shipment')->whereIn('packaging_scheme_id', $schemes))
            ->whereDoesntHave('operations', fn ($q) => $q->where('execution_context', 'shipment')
                ->where(fn ($w) => $w->whereNull('packaging_scheme_id')->orWhereNotIn('packaging_scheme_id', $schemes)))
            ->whereDoesntHave('operations', fn ($q) => $q->where('execution_context', 'shipment')->whereHas('packagingScheme', fn ($w) => $w->where('status', '!=', 'enabled')));
    }

    private function selectedRoutes($lines, array $selections, object $actor, SalesShipment $shipment): array
    {
        $routes = []; foreach ($lines as $line) $routes[$line->id] = $line->packing_routing_snapshot;
        $seen = [];
        foreach ($selections as $selection) {
            $line = $lines->get((int) ($selection['shipment_line_id'] ?? 0));
            if (! $line || isset($seen[$line->id])) $this->fail('所选包装版本必须对应当前发货行且不能重复。');
            $seen[$line->id] = true;
            $reason = trim((string) ($selection['reason'] ?? ''));
            if ($reason === '') $this->fail('选择包装版本时必须填写原因。');
            $candidate = $this->candidateQuery($line)->lockForUpdate()->find((int) ($selection['production_routing_id'] ?? 0));
            if (! $candidate || (int) $candidate->business_version !== (int) ($selection['expected_routing_version'] ?? 0))
                throw new \App\Exceptions\Erp\WorkOrderDomainException('packing_routing_changed', '所选包装版本已变化或失效，请重新选择后提交。', 409);
            $route = app(ProductionMasterDataService::class)->snapshot($candidate);
            // Only the shipment tail is chosen here. The WO and shipment-line production facts remain frozen.
            $route['operations'] = $this->shipmentNodes($route)->values()->all();
            $route['snapshot_scope'] = 'shipment_tail'; $route['operation_count'] = count($route['operations']);
            $route['production_operation_count'] = 0;
            $route['packing_route_selection'] = ['shipment_line_id' => (int) $line->id,
                'shipment_id' => (int) $shipment->id, 'item_id' => (int) $line->item_id,
                'source_snapshot_hash' => hash('sha256', json_encode($this->canonicalSnapshot($line->packing_source_snapshot ?? []), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR)),
                'production_routing_id' => (int) $candidate->id, 'expected_routing_version' => (int) $candidate->business_version,
                'reason' => $reason, 'before_route' => $this->routeIdentity($line->packing_routing_snapshot),
                'packing_route' => $this->routeIdentity($route), 'before_materials' => $this->packingMaterials($line->packing_routing_snapshot),
                'packaging_materials' => $this->packingMaterials($route), 'selected_by_legacy_id' => (int) $actor->legacy_id,
                'selected_at' => now()->toISOString(), 'packing_version' => (int) $shipment->packing_version + 1];
            $routes[$line->id] = $route;
        }
        return $routes;
    }

    private function routeIdentity(?array $route): ?array
    {
        return $route ? ['id' => (int) ($route['routing_id'] ?? 0), 'routing_no' => $route['routing_no'] ?? null,
            'routing_name' => $route['routing_name'] ?? null, 'version' => (int) ($route['version'] ?? 0)] : null;
    }

    private function packingMaterials(?array $route): array
    {
        return $this->shipmentNodes($route)->flatMap(fn ($node) => collect($node['packaging_materials'] ?? [])->map(function ($row) use ($node) {
            $item = Item::find((int) ($row['component_item_id'] ?? 0));
            return ['component_item_id' => (int) ($row['component_item_id'] ?? 0), 'item_code' => $item?->item_code,
                'item_name' => $item?->item_name, 'base_qty_per_output_unit' => $row['base_qty_per_output_unit'] ?? null,
                'operation_name' => $node['operation_name'] ?? null];
        }))->values()->all();
    }

    private function shipmentNodes(?array $route): \Illuminate\Support\Collection
    {
        return collect($route['operations'] ?? [])->filter(fn ($node) => ($node['execution_context'] ?? 'production') === 'shipment');
    }

    private function canonicalSnapshot(array $snapshot): array
    {
        if (! array_is_list($snapshot)) ksort($snapshot, SORT_STRING);
        foreach ($snapshot as &$value) if (is_array($value)) $value = $this->canonicalSnapshot($value);
        return $snapshot;
    }

    private function assertProductionReady(SalesShipment $shipment): void
    {
        foreach ($shipment->lines()->get() as $line) app(ProductionInventoryContinuationService::class)->assertShipmentLineReady($line);
    }

    private function assertEditable(SalesShipment $shipment): void
    {
        if (! in_array($shipment->shipment_status, ['draft', 'pending_outbound', 'outbound_posted'], true)) $this->fail('已发运或取消的单据不能修改包装作业。');
    }

    private function assertOwner(ShipmentPackingOperation $op, int $actorId): void
    {
        if ((int) $op->owner_legacy_id !== $actorId) $this->fail('只有该工序接单人可以选择协同人、登记用料或完成工序。');
    }

    private function participant(ShipmentPackingOperation $op, object $user, string $role, int $selectedBy): void
    {
        ShipmentPackingParticipant::updateOrCreate(['operation_id' => $op->id, 'employee_legacy_id' => $user->legacy_id],
            ['employee_name_snapshot' => $user->nickname ?: $user->username, 'role' => $role, 'is_active' => true, 'selected_by_legacy_id' => $selectedBy]);
    }

    private function closeSession(ShipmentPackingLaborSession $session, string $status): void
    {
        $ended = now();
        $session->update(['status' => $status, 'ended_at' => $ended, 'actual_labor_minutes' => round(max(0, $session->started_at->diffInSeconds($ended, false)) / 60, 2)]);
    }

    private function command(string $action, int $shipmentId, array $payload, object $actor, \Closure $mutation): array
    {
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '' || strlen($commandId) > 100) $this->fail('缺少有效的操作编号，请刷新后重试。');
        $hash = hash('sha256', json_encode([$action, $shipmentId, $payload], JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
        $actorId = (int) $actor->legacy_id;
        $replay = function ($row) use ($hash): array {
            if ($row->request_hash !== $hash) $this->fail('同一操作编号不能用于不同内容。');
            return json_decode($row->response, true);
        };
        try {
            return DB::transaction(function () use ($shipmentId, $actorId, $commandId, $hash, $mutation, $replay): array {
                $shipment = SalesShipment::query()->lockForUpdate()->findOrFail($shipmentId);
                $existing = DB::table('erp_shipment_packing_commands')->where('operator_legacy_id', $actorId)->where('client_command_id', $commandId)->first();
                if ($existing) return $replay($existing);
                $response = $mutation($shipment);
                DB::table('erp_shipment_packing_commands')->insert(['operator_legacy_id' => $actorId, 'client_command_id' => $commandId,
                    'request_hash' => $hash, 'response' => json_encode($response, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
                return $response;
            }, 5);
        } catch (QueryException $exception) {
            // A unique command conflict rolls back every inventory/labor mutation. Only the
            // exact persisted request may be replayed after a concurrent successful attempt.
            $existing = DB::table('erp_shipment_packing_commands')->where('operator_legacy_id', $actorId)->where('client_command_id', $commandId)->first();
            if ($existing) return $replay($existing);
            throw $exception;
        }
    }

    private function log(int $shipmentId, ?int $operationId, string $action, object $actor, array $payload): void
    {
        DB::table('erp_shipment_packing_logs')->insert(['shipment_id' => $shipmentId, 'operation_id' => $operationId,
            'action' => $action, 'operator_legacy_id' => (int) $actor->legacy_id,
            'payload' => json_encode($payload, JSON_UNESCAPED_UNICODE), 'created_at' => now(), 'updated_at' => now()]);
    }

    private function fail(string $message): never { throw ValidationException::withMessages(['packing' => $message]); }
}
