<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Location;
use App\Models\Erp\MfgReceipt;
use App\Models\Erp\MfgCuttingBatch;
use App\Models\Erp\MfgMaterialIssue;
use App\Models\Erp\MfgMaterialReturn;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLink;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgUnitReport;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\Warehouse;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\AuthContextService;
use Illuminate\Support\Collection;
use App\Services\Erp\Inventory\ProductionInventoryPosting;
use App\Services\Erp\InventoryReservationService;
use App\Services\Erp\CuttingDecimal;
use Illuminate\Support\Facades\DB;

/** 申请不改库存；只有仓库确认或完整的自动交接事务才正式记账。 */
final class ReceiptService
{
    use ProductionActor;

    public const STATUS_LABELS = ['pending' => '待仓库确认', 'confirmed' => '已确认', 'rejected' => '已驳回', 'cancelled' => '已撤回'];

    public function __construct(
        private readonly ProductionInventoryPosting $inventory,
        private readonly WorkOrderService $workOrders,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function receive(int $workOrderId, array $unitIds, int $warehouseId, int $locationId, ?string $remark, object $actor): MfgReceipt
    {
        $this->assertPermission($actor, ['production.receipt.execute', 'production.task.execute']);
        return DB::transaction(function () use ($workOrderId, $unitIds, $warehouseId, $locationId, $remark, $actor) {
            $wo = $this->lockWorkOrder($workOrderId, $actor);
            CancellationProductionService::assertProductionAllowed((int) $wo->id);
            $units = $this->completedUnits($wo, $unitIds);
            $this->receiptLocation($wo, $warehouseId, $locationId);
            $receipt = $this->createReceipt($wo, $units, $warehouseId, $locationId, $remark, $actor, 'manual');
            foreach ($units as $unit) {
                $unit->update(['status' => 'pending_receipt']);
                $this->workOrders->log($wo, 'unit_receipt_requested', 'completed', 'pending_receipt',
                    $unit->serial_no.' 提交入库，待仓库确认（'.$receipt->receipt_no.'）', $actor, ['receipt_id' => $receipt->id], $unit->id);
            }
            $this->workOrders->log($wo, 'receipt_requested', $wo->status, $wo->status,
                '提交入库 '.$units->count().' 台，待仓库确认（'.$receipt->receipt_no.'）', $actor, ['receipt_id' => $receipt->id]);
            return $receipt->fresh();
        }, 5);
    }

    /** 不能用请求里的 auto/staging 标志直接入库；本入口必须完成冻结接收方的实际交接。 */
    public function receiveForHandoff(int $workOrderId, int $operationId, array $unitIds, int $targetOperationId, object $actor): array
    {
        $this->assertPermission($actor, ['production.task.execute']);
        return DB::transaction(function () use ($workOrderId, $operationId, $unitIds, $targetOperationId, $actor) {
            $wo = $this->lockWorkOrder($workOrderId, $actor);
            CancellationProductionService::assertProductionAllowed((int) $wo->id);
            $units = $this->completedUnits($wo, $unitIds);
            $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->lockForUpdate()->findOrFail($operationId);
            // 自动入库只接受该冻结末道已报合格的设备，不能借手填工序把普通待入库伪装为交接。
            $passed = MfgUnitReport::query()->where('work_order_id', $wo->id)->where('work_order_operation_id', $op->id)
                ->whereIn('unit_id', $units->pluck('id'))->where('result', 'passed')->lockForUpdate()->pluck('unit_id')->unique();
            if ($passed->count() !== $units->count()) $this->fail('handoff_units_invalid', '本批设备尚未完成所选末道工序，不能直接交接。');
            $completion = app(TaskCompletionService::class);
            $target = $completion->validatedHandoff($wo, $op, $units->pluck('id')->all(), $targetOperationId);
            $staging = $target['kind'] === 'shipment';
            $location = $staging ? $this->stagingLocation($wo) : $completion->handoffLocation($wo);
            if (! $location) $this->fail('completion_location_missing', '没有可用于交接记账的启用工厂库位，请先维护仓库及库位。');
            $this->receiptLocation($wo, (int) $location->warehouse_id, (int) $location->id, true);
            $receipt = $this->createReceipt($wo, $units, (int) $location->warehouse_id, (int) $location->id,
                '完工直接交接给'.$target['operation_name'].'，系统自动记账', $actor, 'auto_handoff');
            $this->postConfirmed($receipt, $wo, $units, $actor, $staging);
            $result = $completion->finishHandoff($wo, $receipt, $units->pluck('id')->all(), $target, $actor);
            return ['receipt' => $receipt->fresh(), 'handoff_operation_name' => $target['operation_name']] + $result;
        }, 5);
    }

    public function confirm(int $id, array $data, object $actor): MfgReceipt
    {
        $this->assertPermission($actor, ['inventory.inbound.confirm']);
        return DB::transaction(function () use ($id, $data, $actor) {
            [$receipt, $wo, $units] = $this->pendingReceipt($id, $data, $actor);
            CancellationProductionService::assertProductionAllowed((int) $wo->id);
            $location = Location::query()->lockForUpdate()->find((int) ($data['location_id'] ?? 0));
            $warehouseId = (int) ($data['warehouse_id'] ?? $location?->warehouse_id ?? 0);
            $this->receiptLocation($wo, $warehouseId, (int) $location?->id);
            $scanned = array_map(fn ($sn) => trim((string) $sn), $data['serial_nos'] ?? []);
            $expected = $receipt->serial_nos ?: [];
            sort($scanned, SORT_STRING);
            sort($expected, SORT_STRING);
            $requiresSerialCheck = \App\Models\Erp\Item::query()->findOrFail($wo->item_id)->serialTrackingMode() !== 'none';
            if ($requiresSerialCheck && ($scanned !== $expected || count(array_unique($scanned)) !== count($scanned)))
                $this->fail('serial_confirmation_mismatch', '请逐个核对本单全部编号，不能重复、漏扫或带入其他编号。');
            $this->workOrders->convertIfDemandGone($wo, $actor);
            $receipt->update(['warehouse_id' => $warehouseId, 'location_id' => $location->id, 'status' => 'confirmed',
                'confirmed_by_legacy_id' => $this->actorId($actor), 'confirmed_by_name' => $this->actorName($actor),
                'business_version' => (int) $receipt->business_version + 1]);
            $this->postConfirmed($receipt, $wo, $units, $actor);
            return $receipt->fresh();
        }, 5);
    }

    public function reject(int $id, array $data, object $actor): MfgReceipt
    {
        $this->assertPermission($actor, ['inventory.inbound.confirm']);
        $reason = trim((string) ($data['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 500) $this->fail('reject_reason_required', '请填写500字以内的驳回原因。');
        return DB::transaction(function () use ($id, $data, $actor, $reason) {
            [$receipt, $wo, $units] = $this->pendingReceipt($id, $data, $actor);
            $receipt->update(['status' => 'rejected', 'rejected_by_legacy_id' => $this->actorId($actor),
                'rejected_by_name' => $this->actorName($actor), 'rejected_at' => now(), 'reject_reason' => $reason,
                'business_version' => (int) $receipt->business_version + 1]);
            foreach ($units as $unit) $unit->update(['status' => 'completed']);
            $this->workOrders->log($wo, 'receipt_rejected', $wo->status, $wo->status,
                '仓库驳回入库 '.$receipt->receipt_no.'：'.$reason, $actor, ['receipt_id' => $receipt->id]);
            return $receipt->fresh();
        }, 5);
    }

    public function cancel(int $id, array $data, object $actor): MfgReceipt
    {
        $this->assertPermission($actor, ['production.receipt.execute', 'production.task.execute']);
        return DB::transaction(function () use ($id, $data, $actor) {
            [$receipt, $wo, $units] = $this->pendingReceipt($id, $data, $actor);
            if ((int) $receipt->operator_legacy_id !== (int) $this->actorId($actor)) $this->fail('permission_denied', '只能撤回自己提交的入库申请。', 403);
            $receipt->update(['status' => 'cancelled', 'cancelled_by_legacy_id' => $this->actorId($actor), 'cancelled_at' => now(),
                'business_version' => (int) $receipt->business_version + 1]);
            foreach ($units as $unit) $unit->update(['status' => 'completed']);
            $this->workOrders->log($wo, 'receipt_cancelled', $wo->status, $wo->status,
                '申请人撤回入库 '.$receipt->receipt_no, $actor, ['receipt_id' => $receipt->id]);
            return $receipt->fresh();
        }, 5);
    }

    private function lockWorkOrder(int $id, object $actor): MfgWorkOrder
    {
        $orderId = MfgWorkOrder::query()->whereKey($id)->value('sales_order_id');
        if ($orderId) SalesOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
        $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($id);
        $wo->setRelation('materials', \App\Models\Erp\MfgWorkOrderMaterial::query()->where('work_order_id', $wo->id)->orderBy('id')->lockForUpdate()->get());
        if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true)) $this->fail('status_invalid', '工单不在生产中，不能办理入库。');
        return $wo;
    }

    private function completedUnits(MfgWorkOrder $wo, array $unitIds): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $unitIds))));
        if (! $ids || count($ids) !== count($unitIds)) $this->fail('unit_required', '请选择本次入库的设备，不能重复。');
        $units = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('id', $ids)->orderBy('unit_no')->lockForUpdate()->get();
        if ($units->count() !== count($ids) || $units->contains(fn ($u) => $u->status !== 'completed'))
            $this->fail('unit_invalid', '只有生产完成、等入库的设备可以提交，请刷新后重选。');
        return $units;
    }

    /** 工单锁先于单据和设备锁；版本和状态都在锁内校验，命令重放由统一命令账本恢复原结果。 */
    private function pendingReceipt(int $id, array $data, object $actor): array
    {
        $workOrderId = (int) MfgReceipt::query()->findOrFail($id)->work_order_id;
        $wo = $this->lockWorkOrder($workOrderId, $actor);
        $receipt = MfgReceipt::query()->lockForUpdate()->findOrFail($id);
        if ((int) ($data['expected_version'] ?? 0) !== (int) $receipt->business_version)
            $this->fail('version_conflict', '入库申请已变化，请刷新后重新核对。', 409);
        if ($receipt->status !== 'pending' || $receipt->inventory_transaction_id)
            $this->fail('receipt_status_invalid', '这张入库申请已处理，不能重复确认、驳回或撤回。', 409);
        $units = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('serial_no', $receipt->serial_nos ?: [])
            ->orderBy('unit_no')->lockForUpdate()->get();
        if ($units->count() !== count($receipt->serial_nos ?: []) || $units->count() !== (int) $receipt->qty
            || $units->contains(fn ($u) => $u->status !== 'pending_receipt' || $u->inventory_serial_id))
            $this->fail('receipt_units_changed', '申请中的设备状态或编号已变化，请刷新后核对。', 409);
        return [$receipt, $wo, $units];
    }

    private function receiptLocation(MfgWorkOrder $wo, int $warehouseId, int $locationId, bool $handoff = false): Location
    {
        $warehouse = Warehouse::query()->lockForUpdate()->find($warehouseId);
        $location = Location::query()->lockForUpdate()->find($locationId);
        if (! $warehouse || ! in_array($warehouse->status, ['enabled', 'active'], true) || ($warehouse->management_scope && $warehouse->management_scope !== 'factory'))
            $this->fail('warehouse_invalid', '生产入库必须选择启用的工厂仓库。');
        if (! $location || (int) $location->warehouse_id !== $warehouseId || ! in_array($location->status, ['enabled', 'active'], true))
            $this->fail('location_invalid', '库位不属于所选启用仓库，或库位已经停用。');
        $purposes = self::allowedLocationPurposes($wo, $handoff);
        if (! in_array($location->location_purpose, $purposes, true))
            $this->fail('location_purpose_invalid', $purposes === ['general'] ? '半成品请入普通库存库位。' : '成品请入成品库位；直接交待发区须走正式完工交接。');
        return $location;
    }

    private function createReceipt(MfgWorkOrder $wo, Collection $units, int $warehouseId, int $locationId, ?string $remark, object $actor, string $source): MfgReceipt
    {
        return MfgReceipt::create([
            'receipt_no' => $this->numbers->next('mfg_receipt', 'MR'), 'work_order_id' => $wo->id,
            'status' => $source === 'auto_handoff' ? 'confirmed' : 'pending', 'confirmation_source' => $source,
            'warehouse_id' => $warehouseId, 'location_id' => $locationId,
            'requested_warehouse_id' => $warehouseId, 'requested_location_id' => $locationId, 'requested_at' => now(),
            'qty' => $units->count(), 'unit_cost' => '0.0000', 'cost_amount' => '0.0000', 'cost_source' => 'pending_confirmation',
            'serial_nos' => $units->pluck('serial_no')->values()->all(), 'business_version' => 1,
            'operator_legacy_id' => $this->actorId($actor), 'operator_name' => $this->actorName($actor), 'remark' => $remark ?: null,
        ]);
    }

    private function postConfirmed(MfgReceipt $receipt, MfgWorkOrder $wo, Collection $units, object $actor, bool $staging = false): void
    {
        $warehouseId = (int) $receipt->warehouse_id;
        $locationId = (int) $receipt->location_id;
        $location = Location::query()->findOrFail($locationId);
        $semi = self::allowedLocationPurposes($wo) === ['general'];
        $qty = $units->count();
        // 做完几台设备就能入库几台：成本按目前已领的料暂估分摊，最后一批承接全部差额。
        // 还会继续产出的设备 = 没入库、没报废、没取消的设备（含本批）。
        $open = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereNotIn('status', ['stored', 'scrapped', 'cancelled'])->lockForUpdate()->count();
        $lastBatch = $qty >= $open;
        // 待确认余料尚无最终净耗，任何入库批次都不能用零成本先过账；未登记开料仍沿原尾批收口。
        if ($awaiting = MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('status', 'awaiting_confirm')->lockForUpdate()->first(['id', 'batch_no']))
            $this->fail('cutting_confirmation_pending', '开料批次 '.$awaiting->batch_no.' 余料待仓库确认，最终净耗尚未确定，请完成确认后再办理本批入库。');
        if ($lastBatch && ($unsettled = MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('status', 'issued')->lockForUpdate()->first(['id', 'batch_no'])))
            $this->fail('cutting_not_settled', '这是本工单最后一批设备，开料批次 '.$unsettled->batch_no.' 尚未登记产出和余料，请先完成登记。');
        $actualCost = '0.0000';
        foreach (MfgMaterialIssue::query()->where('work_order_id', $wo->id)->lockForUpdate()->get() as $issue) $actualCost = bcadd($actualCost, (string) $issue->cost_amount, 4);
        // 已审核的生产退料按原单价冲回，从实际材料成本里扣除。
        foreach (MfgMaterialReturn::query()->where('work_order_id', $wo->id)->where('status', 'approved')->lockForUpdate()->get() as $return) $actualCost = bcsub($actualCost, (string) $return->cost_amount, 4);
        foreach (MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('status', 'settled')->lockForUpdate()->get() as $batch) $actualCost = bcadd($actualCost, (string) $batch->net_cost_amount, 4);
        // 集中开料按定额分给本单的板材成本
        $actualCost = bcadd($actualCost, (string) \App\Models\Erp\MfgCuttingAllocation::query()->where('work_order_id', $wo->id)->lockForUpdate()->sum('cost_amount'), 4);
        // 集中开料记录按件数带过来的板材／管材成本（切完结束时已从仓库扣）
        $actualCost = bcadd($actualCost, (string) \App\Models\Erp\MfgCuttingRunAllocation::query()->where('work_order_id', $wo->id)->lockForUpdate()->sum('cost_amount'), 4);
        $alreadyCost = '0.0000';
        foreach (MfgReceipt::query()->where('work_order_id', $wo->id)->where('status', 'confirmed')->whereNotNull('inventory_transaction_id')->lockForUpdate()->get() as $oldReceipt) $alreadyCost = bcadd($alreadyCost, (string) $oldReceipt->cost_amount, 4);
        $remainingCost = bcsub($actualCost, $alreadyCost, 4);
        if (bccomp($remainingCost, '0', 4) < 0) $this->fail('cost_reconciliation_required', '已入库暂估金额超过当前实际材料净耗，请先按正式成本调整核对，不能改写历史入库。');
        // 早批按已领材料暂估，尾批承接全部差额；人工、外协和后到费用仍未结算，不能把实际材料成本称为最终制造成本。
        $costAmount = CuttingDecimal::share($remainingCost, (string) max($qty, $open), (string) $qty);
        $unitCost = bcdiv($costAmount, (string) $qty, 4);
        $receipt->update(['unit_cost' => $unitCost, 'cost_amount' => $costAmount, 'cost_source' => 'actual_materials_provisional']);
        $posted = $this->inventory->postProductionReceipt($receipt, $wo, $units->pluck('serial_no')->all(), $this->actorId($actor));
        $confirmedAt = $posted['transaction']->posted_at ?? now();
        $receipt->update(['inventory_transaction_id' => $posted['transaction']->id, 'confirmed_at' => $confirmedAt]);
        app(InventoryReservationService::class)->reserveProductionReceipt($receipt, $wo);
        foreach ($units as $unit) {
            $before = $unit->status;
            $unit->update(['status' => 'stored', 'stored_at' => $confirmedAt, 'warehouse_id' => $warehouseId, 'location_id' => $locationId,
                'inventory_serial_id' => $posted['serial_ids'][$unit->serial_no] ?? null]);
            $this->workOrders->log($wo, 'unit_stored', $before, 'stored', $unit->serial_no.($semi ? ' 半成品入库' : ' 成品入库，待试水').'（'.$receipt->receipt_no.'）',
                $actor, ['receipt_id' => $receipt->id, 'confirmation_source' => $receipt->confirmation_source], $unit->id);
        }
        $wo->update(['stored_qty' => (float) $wo->stored_qty + $qty]);
        $where = $staging ? '自动记入成品待发区 '.$location->location_code.'，等发货试水' : ($semi ? '半成品入库' : '成品入库');
        $this->workOrders->log($wo, 'received', $wo->status, $wo->status, "{$where} {$qty} 台（{$receipt->receipt_no}）", $actor,
            ['receipt_id' => $receipt->id, 'staging' => $staging, 'confirmation_source' => $receipt->confirmation_source]);
        if ($wo->source_type === 'work_order' && ($link = MfgWorkOrderLink::query()->where('child_work_order_id', $wo->id)->first())) {
            $parent = MfgWorkOrder::query()->find($link->parent_work_order_id);
            if ($parent) $this->workOrders->log($parent, 'semi_stored', $parent->status, $parent->status,
                "半成品工单 {$wo->work_order_no} 入库 {$wo->item_name} {$qty}".($wo->unit_name ?: '').'，可以收料了', $actor, ['child_work_order_id' => $wo->id]);
        }
        $this->workOrders->refreshCompletion($wo, $actor);
    }

    private function assertPermission(object $actor, array $anyOf): void
    {
        $auth = app(AuthContextService::class);
        if (! $auth->isSuperAdmin($actor) && ! array_intersect($anyOf, $auth->permissionCodes($actor)))
            $this->fail('permission_denied', '当前账号没有办理这项入库操作的权限。', 403);
    }

    public function paginate(array $filters, object $actor): array
    {
        $query = $this->readQuery($actor)->with(['workOrder', 'warehouse', 'location', 'requestedWarehouse', 'requestedLocation', 'inventoryTransaction']);
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['work_order_id'])) $query->where('work_order_id', (int) $filters['work_order_id']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $like = '%'.$keyword.'%';
            $query->where(fn ($q) => $q->where('receipt_no', 'like', $like)->orWhere('operator_name', 'like', $like)
                ->orWhere('serial_nos', 'like', $like)->orWhereHas('workOrder', fn ($w) => $w->where('work_order_no', 'like', $like)
                    ->orWhere('item_name', 'like', $like)->orWhere('product_name', 'like', $like)));
        }
        $amounts = in_array('inventory.amount.view', app(AuthContextService::class)->permissionCodes($actor), true);
        $page = $query->orderByDesc('requested_at')->orderByDesc('id')->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))));
        $page->setCollection($page->getCollection()->map(fn ($r) => $this->present($r, $amounts, $actor)));
        return $page->toArray();
    }

    public function show(int $id, object $actor): array
    {
        $receipt = $this->readQuery($actor)->findOrFail($id);
        return $this->present($receipt, in_array('inventory.amount.view', app(AuthContextService::class)->permissionCodes($actor), true), $actor);
    }

    private function readQuery(object $actor)
    {
        $this->assertPermission($actor, ['inventory.inbound.confirm', 'production.receipt.execute', 'production.work_order.view', 'production.task.view', 'production.task.execute']);
        $auth = app(AuthContextService::class);
        $all = $auth->isSuperAdmin($actor) || array_intersect(['inventory.inbound.confirm', 'production.work_order.view'], $auth->permissionCodes($actor));
        return MfgReceipt::query()->when(! $all, fn ($q) => $q->where('operator_legacy_id', $this->actorId($actor)));
    }

    /** 金额权限由调用方明确传入；pending 的零仅是存储占位，永不作为正式成本输出。 */
    public function present(MfgReceipt $receipt, bool $amounts, ?object $actor = null): array
    {
        $receipt->loadMissing(['workOrder', 'warehouse', 'location', 'requestedWarehouse', 'requestedLocation', 'inventoryTransaction']);
        $wo = $receipt->workOrder;
        $requiresSerialCheck = $wo && \App\Models\Erp\Item::query()->findOrFail($wo->item_id)->serialTrackingMode() !== 'none';
        $units = MfgWorkOrderUnit::query()->where('work_order_id', $receipt->work_order_id)->whereIn('serial_no', $receipt->serial_nos ?: [])->orderBy('unit_no')->get();
        $codes = $actor ? app(AuthContextService::class)->permissionCodes($actor) : [];
        $pending = $receipt->status === 'pending';
        $confirm = $pending && in_array('inventory.inbound.confirm', $codes, true);
        $confirmed = $receipt->status === 'confirmed' && $receipt->inventory_transaction_id;
        $confirmedAt = $confirmed ? ($receipt->confirmed_at ?? $receipt->inventoryTransaction?->posted_at ?? $receipt->created_at) : null;
        return [
            'id' => $receipt->id, 'receipt_no' => $receipt->receipt_no, 'work_order_id' => $receipt->work_order_id,
            'work_order_no' => $wo?->work_order_no, 'product_name' => $wo?->product_name ?: $wo?->item_name,
            'item_id' => $wo?->item_id, 'item_name' => $wo?->item_name, 'sku_name' => $wo?->sku_name, 'unit_name' => $wo?->unit_name,
            'status' => $receipt->status, 'status_label' => self::STATUS_LABELS[$receipt->status] ?? $receipt->status,
            'confirmation_source' => $receipt->confirmation_source, 'business_version' => (int) $receipt->business_version,
            'qty' => (float) $receipt->qty, 'requires_serial_check' => (bool) $requiresSerialCheck,
            'serial_nos' => $requiresSerialCheck ? ($receipt->serial_nos ?: []) : [], 'unit_ids' => $units->pluck('id')->all(),
            'units' => $units->map(fn ($u) => ['id' => $u->id, 'unit_no' => $u->unit_no, 'serial_no' => $requiresSerialCheck ? $u->serial_no : null,
                'status' => $u->status, 'status_label' => WorkOrderQueryService::UNIT_LABELS[$u->status] ?? $u->status])->values()->all(),
            'requested_warehouse_id' => $receipt->requested_warehouse_id, 'requested_warehouse_name' => $receipt->requestedWarehouse?->warehouse_name,
            'requested_location_id' => $receipt->requested_location_id, 'requested_location_code' => $receipt->requestedLocation?->location_code,
            'requested_location_name' => $receipt->requestedLocation?->location_name,
            'warehouse_id' => $confirmed ? $receipt->warehouse_id : null, 'warehouse_name' => $confirmed ? $receipt->warehouse?->warehouse_name : null,
            'location_id' => $confirmed ? $receipt->location_id : null, 'location_code' => $confirmed ? $receipt->location?->location_code : null,
            'location_name' => $confirmed ? $receipt->location?->location_name : null, 'allowed_location_purposes' => $wo ? (self::allowedLocationPurposes($wo) === ['general'] ? ['general'] : ['finished']) : [],
            'operator_legacy_id' => $receipt->operator_legacy_id, 'operator_name' => $receipt->operator_name,
            'requested_at' => ($receipt->requested_at ?? $receipt->created_at)?->toDateTimeString(),
            'confirmed_by_legacy_id' => $receipt->confirmed_by_legacy_id, 'confirmed_by_name' => $receipt->confirmed_by_name,
            'confirmed_at' => $confirmedAt?->toDateTimeString(), 'rejected_by_legacy_id' => $receipt->rejected_by_legacy_id,
            'rejected_by_name' => $receipt->rejected_by_name, 'rejected_at' => $receipt->rejected_at?->toDateTimeString(), 'reject_reason' => $receipt->reject_reason,
            'cancelled_by_legacy_id' => $receipt->cancelled_by_legacy_id, 'cancelled_at' => $receipt->cancelled_at?->toDateTimeString(),
            'cost_source' => $receipt->cost_source, 'cost_status' => $confirmed ? $receipt->cost_source : 'pending_confirmation',
            'cost_status_label' => $confirmed ? ($receipt->cost_source === 'actual_materials_provisional' ? '实际材料暂估' : '暂估成本') : '待确认',
            'unit_cost' => $amounts && $confirmed ? $receipt->unit_cost : null, 'cost_amount' => $amounts && $confirmed ? $receipt->cost_amount : null,
            'inventory_transaction_id' => $receipt->inventory_transaction_id, 'remark' => $receipt->remark,
            'actions' => ['confirm' => (bool) $confirm, 'reject' => (bool) $confirm,
                'cancel' => $pending && $actor && (int) $receipt->operator_legacy_id === (int) $this->actorId($actor)
                    && (bool) array_intersect(['production.receipt.execute', 'production.task.execute'], $codes)],
        ];
    }

    public static function allowedLocationPurposes(MfgWorkOrder $wo, bool $handoff = false): array
    {
        return $wo->source_type === 'work_order' || $wo->item?->item_type === 'semi_finished'
            ? ['general'] : ($handoff ? ['finished', 'shipping_staging'] : ['finished']);
    }

    /** 待发用途是正式资料，不能用库位名称猜测；指定编码同样不能绕过用途与范围。 */
    public function stagingLocation(MfgWorkOrder $wo): ?Location
    {
        $enabled = Warehouse::query()->whereIn('status', ['enabled', 'active'])
            ->where(fn ($q) => $q->whereNull('management_scope')->orWhere('management_scope', 'factory'))->pluck('id');
        $query = Location::query()->whereIn('status', ['enabled', 'active'])->whereIn('warehouse_id', $enabled)->where('location_purpose', 'shipping_staging');
        if ($code = trim((string) config('erp.ship_staging_location_code'))) {
            $hit = (clone $query)->where('location_code', $code)->first();
            if ($hit) return $hit;
        }
        return $query->orderByRaw('warehouse_id = ? DESC', [(int) $wo->warehouse_id])->orderBy('id')->first();
    }
}
