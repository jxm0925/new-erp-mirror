<?php

namespace App\Services\Erp;

use App\Services\Erp\Inventory\WarehouseInventoryPosting;
use App\Services\Erp\Inventory\InventoryTransferService;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Location;
use App\Models\Erp\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use App\Exceptions\Erp\WorkOrderDomainException;

final class PhysicalInventoryApplicationService
{
    public function __construct(
        private readonly WarehouseInventoryPosting $inventory,
        private readonly MaterialPhysicalService $physicalMaterials,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function transfer(int $physicalId, array $payload, object $operator): array
    {
        $this->assertPermission($operator, ['inventory.physical.manage']);
        return DB::transaction(function () use ($physicalId, $payload, $operator): array {
            $actorId = (int) ($operator->legacy_id ?? $operator->id ?? 0);
            $reason = trim((string) ($payload['reason'] ?? ''));
            $targetBatch = trim((string) ($payload['target_batch_no'] ?? ''));
            $hash = $this->commandHash('transfer', $physicalId, $payload, $actorId);
            $existing = DB::table('erp_material_physical_transfers')
                ->where('client_command_id', $payload['client_command_id'])->lockForUpdate()->first();
            if ($existing) {
                $this->assertReplay($existing, $physicalId, $hash, $actorId);
                $origin = json_decode($existing->source_snapshot ?? '{}', true, 512, JSON_THROW_ON_ERROR);
                $originWarehouse = $origin['warehouse_id'] ?? InventoryBalance::query()->whereKey($existing->source_inventory_balance_id)->value('warehouse_id');
                InventoryTransferService::assertWarehouseScope((int) $originWarehouse, $operator);
                return $this->transferResult($existing);
            }

            if ($reason === '' || mb_strlen($reason) > 1000 || $targetBatch === '') {
                throw ValidationException::withMessages(['reason' => '实物调拨必须填写目标批次和调拨原因。']);
            }
            $physical = $this->physicalMaterials->assertWarehousePhysical($physicalId);
            if ((int) $physical->business_version !== (int) $payload['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => '实物版本已变化，请刷新后重试。']);
            }
            $this->physicalMaterials->assertPhysicalNotInOpenDocument($physicalId);
            $sourceHolding = DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->lockForUpdate()->firstOrFail();
            $sourceBalance = InventoryBalance::query()->whereKey($sourceHolding->inventory_balance_id)->lockForUpdate()->firstOrFail();
            InventoryTransferService::assertWarehouseScope((int) $sourceBalance->warehouse_id, $operator);
            $snapshot = $sourceBalance->only(['item_id', 'unit_id', 'material_lot_id', 'warehouse_id', 'location_id', 'batch_no']);
            $snapshot['unit_id'] = $sourceBalance->unit_id ?: \App\Models\Erp\Item::query()->whereKey($physical->item_id)->value('unit_id');
            if (! $snapshot['unit_id']) throw ValidationException::withMessages(['unit_id' => '实物来源缺少正式库存单位，不能调拨。']);
            $this->assertLocator((int) $payload['target_warehouse_id'], (int) $payload['target_location_id']);
            app(WarehouseManagementScopeService::class)->assertForward((int) $physical->item_id, (int) $payload['target_warehouse_id']);
            if ((int) $sourceBalance->warehouse_id === (int) $payload['target_warehouse_id']
                && (int) $sourceBalance->location_id === (int) $payload['target_location_id']
                && (string) $sourceBalance->batch_no === $targetBatch) {
                throw ValidationException::withMessages(['target_location_id' => '调拨目标与当前仓库、库位、批次完全相同。']);
            }

            $transferId = DB::table('erp_material_physical_transfers')->insertGetId([
                'transfer_no' => $this->numbers->next('material_physical_transfer', 'MPT'),
                'client_command_id' => $payload['client_command_id'],
                'command_hash' => $hash,
                'expected_version' => $payload['expected_version'],
                'physical_material_id' => $physicalId,
                'source_holding_id' => $sourceHolding->id,
                'source_inventory_balance_id' => $sourceBalance->id,
                'target_warehouse_id' => $payload['target_warehouse_id'],
                'target_location_id' => $payload['target_location_id'],
                'requested_target_location_id' => $payload['target_location_id'],
                'target_batch_no' => $targetBatch,
                'total_cost' => $physical->total_cost,
                'source_snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR),
                'business_version' => 1,
                'reason' => $reason,
                'status' => 'PENDING',
                'moved_by_legacy_id' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $transfer = DB::table('erp_material_physical_transfers')->where('id', $transferId)->lockForUpdate()->first();
            $crossWarehouse = (int) $sourceBalance->warehouse_id !== (int) $transfer->target_warehouse_id;
            $transaction = $this->inventory->postPhysicalTransfer($transfer, $physical, $sourceBalance, $operator, $crossWarehouse ? 'out' : 'both');
            if ($crossWarehouse) {
                $holdingId = $this->physicalMaterials->nonWarehouseHolding((int) $physical->material_lot_id, 'TRANSIT', $transferId, (string) $transfer->total_cost, 'ACTIVE');
                $this->physicalMaterials->movePhysical($physical, $sourceHolding, $holdingId, 'TRANSFER_OUT', 'IN_TRANSIT', (int) $transaction->id, $actorId);
                DB::table('erp_material_physical_transfers')->where('id', $transferId)->update([
                    'transit_holding_id' => $holdingId, 'outbound_transaction_id' => $transaction->id,
                    'status' => 'IN_TRANSIT', 'dispatched_at' => now(), 'moved_at' => now(), 'updated_at' => now(),
                ]);
                return $this->transferResult(DB::table('erp_material_physical_transfers')->where('id', $transferId)->first());
            }
            $targetBalance = InventoryBalance::query()->where([
                'item_id' => $physical->item_id,
                'warehouse_id' => $transfer->target_warehouse_id,
                'location_id' => $transfer->target_location_id,
                'batch_no' => $transfer->target_batch_no,
            ])->lockForUpdate()->firstOrFail();
            $targetHolding = $this->physicalMaterials->warehouseHolding(
                $targetBalance,
                (string) $physical->material_form,
                (int) $sourceHolding->material_lot_id,
            );
            $this->physicalMaterials->movePhysical(
                $physical,
                $sourceHolding,
                (int) $targetHolding->id,
                'TRANSFER',
                'AVAILABLE',
                (int) $transaction->id,
                $actorId,
            );
            DB::table('erp_material_physical_transfers')->where('id', $transferId)->update([
                'target_holding_id' => $targetHolding->id,
                'target_inventory_balance_id' => $targetBalance->id,
                'inventory_transaction_id' => $transaction->id,
                'outbound_transaction_id' => $transaction->id, 'inbound_transaction_id' => $transaction->id,
                'status' => 'POSTED',
                'dispatched_at' => now(),
                'moved_at' => now(),
                'updated_at' => now(),
            ]);
            return $this->transferResult(DB::table('erp_material_physical_transfers')->where('id', $transferId)->first());
        }, 5);
    }

    public function confirmTransfer(int $id, array $payload, object $operator): array
    {
        $this->assertPermission($operator, ['inventory.inbound.confirm']);
        return DB::transaction(function () use ($id, $payload, $operator) {
            $transfer = $this->lockedTransit($id, $payload);
            InventoryTransferService::assertWarehouseScope((int) $transfer->target_warehouse_id, $operator);
            $ids = array_map('intval', $payload['physical_ids'] ?? []);
            if ($ids !== [(int) $transfer->physical_material_id])
                throw ValidationException::withMessages(['physical_ids' => '请完整核对本单实物编号，不能漏扫、重复或混入其他实物。']);
            $this->assertLocator((int) $transfer->target_warehouse_id, (int) ($payload['target_location_id'] ?? 0));
            $physical = DB::table('erp_material_physicals')->where('id', $transfer->physical_material_id)->lockForUpdate()->firstOrFail();
            $holding = DB::table('erp_material_holdings')->where('id', $transfer->transit_holding_id)->lockForUpdate()->firstOrFail();
            $snapshot = json_decode($transfer->source_snapshot ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            if (! isset($snapshot['item_id'], $snapshot['unit_id'], $snapshot['material_lot_id'], $snapshot['warehouse_id'], $snapshot['location_id'], $snapshot['batch_no']))
                throw ValidationException::withMessages(['source' => '实物调拨缺少完整冻结来源，不能接收。']);
            if ($physical->status !== 'IN_TRANSIT' || (int) $physical->business_version !== (int) $transfer->expected_version + 1
                || (int) $physical->current_holding_id !== (int) $holding->id
                || $holding->position_type !== 'TRANSIT' || $holding->status !== 'ACTIVE' || (int) $holding->position_id !== $id
                || bccomp((string) $holding->quantity, '1', 8) !== 0 || bccomp((string) $holding->total_cost, (string) $transfer->total_cost, 4) !== 0
                || bccomp((string) $physical->total_cost, (string) $transfer->total_cost, 4) !== 0
                || (int) $physical->item_id !== (int) ($snapshot['item_id'] ?? 0) || (int) $physical->material_lot_id !== (int) ($snapshot['material_lot_id'] ?? 0))
                throw ValidationException::withMessages(['physical_ids' => '在途实物、持有数量或冻结成本已变化，不能接收。']);
            $out = InventoryTransactionItem::query()->where('transaction_id', $transfer->outbound_transaction_id)
                ->where('source_type', 'material_physical_transfer')->where('source_id', $id)->where('source_item_id', $physical->id)->lockForUpdate()->first();
            if (! $out || (int) $out->item_id !== (int) $snapshot['item_id'] || (int) $out->unit_id !== (int) $snapshot['unit_id']
                || (int) $out->material_lot_id !== (int) $snapshot['material_lot_id'] || (int) $out->warehouse_id !== (int) $snapshot['warehouse_id']
                || (int) $out->location_id !== (int) $snapshot['location_id'] || (string) $out->batch_no !== (string) $snapshot['batch_no']
                || bccomp((string) $out->change_qty, '-1', 8) !== 0 || bccomp((string) $out->cost_amount, bcsub('0', (string) $transfer->total_cost, 4), 4) !== 0)
                throw ValidationException::withMessages(['source' => '实物调拨的冻结来源与正式调出流水不一致。']);
            $transfer->target_location_id = (int) $payload['target_location_id'];
            DB::table('erp_material_physical_transfers')->where('id', $id)->update(['target_location_id' => $transfer->target_location_id]);
            $sourceBalance = InventoryBalance::query()->findOrFail($transfer->source_inventory_balance_id);
            $transaction = $this->inventory->postPhysicalTransfer($transfer, $physical, $sourceBalance, $operator, 'in');
            $targetBalance = InventoryBalance::query()->where('item_id', $physical->item_id)->where('warehouse_id', $transfer->target_warehouse_id)
                ->where('location_id', $transfer->target_location_id)->where('batch_no', $transfer->target_batch_no)->lockForUpdate()->firstOrFail();
            $targetHolding = $this->physicalMaterials->warehouseHolding($targetBalance, $physical->material_form, (int) $snapshot['material_lot_id']);
            $actorId = (int) ($operator->legacy_id ?? $operator->id ?? 0);
            $this->physicalMaterials->movePhysical($physical, $holding, (int) $targetHolding->id, 'TRANSFER_IN', 'AVAILABLE', (int) $transaction->id, $actorId);
            DB::table('erp_material_holdings')->where('id', $holding->id)->update(MaterialHoldingInvariant::debit($holding, '1', (string) $transfer->total_cost)
                + ['status' => 'CLOSED', 'business_version' => (int) $holding->business_version + 1, 'updated_at' => now()]);
            DB::table('erp_material_physical_transfers')->where('id', $id)->update([
                'target_holding_id' => $targetHolding->id, 'target_inventory_balance_id' => $targetBalance->id, 'inbound_transaction_id' => $transaction->id,
                'status' => 'POSTED', 'confirmed_by_legacy_id' => $actorId, 'confirmed_at' => now(),
                'business_version' => (int) $transfer->business_version + 1, 'updated_at' => now(),
            ]);
            return $this->transferDetail($id);
        }, 5);
    }

    public function recordTransferException(int $id, array $payload, object $operator): array
    {
        $this->assertPermission($operator, ['inventory.inbound.confirm', 'inventory.physical.manage']);
        return DB::transaction(function () use ($id, $payload, $operator) {
            $transfer = $this->lockedTransit($id, $payload);
            $snapshot = json_decode($transfer->source_snapshot ?? '{}', true, 512, JSON_THROW_ON_ERROR);
            app(InventoryTransferService::class)->assertExceptionScope((int) ($snapshot['warehouse_id'] ?? 0), (int) $transfer->target_warehouse_id, $operator, 'inventory.physical.manage');
            $reason = trim((string) ($payload['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > 500) throw ValidationException::withMessages(['reason' => '请填写500字以内的接收异常情况。']);
            $actorId = (int) ($operator->legacy_id ?? $operator->id ?? 0);
            DB::table('erp_material_physical_transfers')->where('id', $id)->update(['receipt_exception' => $reason,
                'exception_by_legacy_id' => $actorId, 'exception_at' => now(), 'business_version' => (int) $transfer->business_version + 1, 'updated_at' => now()]);
            InventoryPostingLog::create(['source_type' => 'material_physical_transfer', 'source_id' => $id, 'source_no' => $transfer->transfer_no,
                'transaction_type' => 'physical_transfer_exception', 'transaction_id' => $transfer->outbound_transaction_id,
                'posting_status' => 'in_transit', 'message' => $reason, 'posted_by' => $actorId, 'posted_at' => now()]);
            return $this->transferDetail($id);
        }, 5);
    }

    public function dispose(int $physicalId, array $payload, object $operator): array
    {
        return DB::transaction(function () use ($physicalId, $payload, $operator): array {
            $actorId = (int) ($operator->legacy_id ?? $operator->id ?? 0);
            $reason = trim((string) ($payload['reason'] ?? ''));
            $hash = $this->commandHash('dispose', $physicalId, $payload, $actorId);
            $existing = DB::table('erp_material_physical_disposals')
                ->where('client_command_id', $payload['client_command_id'])->lockForUpdate()->first();
            if ($existing) {
                $this->assertReplay($existing, $physicalId, $hash, $actorId, 'disposed_by_legacy_id');
                return $this->disposalResult($existing);
            }
            if ($reason === '' || mb_strlen($reason) > 1000) {
                throw ValidationException::withMessages(['reason' => '实物报废必须填写原因。']);
            }
            $physical = $this->physicalMaterials->assertWarehousePhysical($physicalId);
            if ((int) $physical->business_version !== (int) $payload['expected_version']) {
                throw ValidationException::withMessages(['expected_version' => '实物版本已变化，请刷新后重试。']);
            }
            $this->physicalMaterials->assertPhysicalNotInOpenDocument($physicalId);
            $sourceHolding = DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->lockForUpdate()->firstOrFail();
            $sourceBalance = InventoryBalance::query()->whereKey($sourceHolding->inventory_balance_id)->lockForUpdate()->firstOrFail();
            $disposalId = DB::table('erp_material_physical_disposals')->insertGetId([
                'disposal_no' => $this->numbers->next('material_disposal', 'MD'),
                'client_command_id' => $payload['client_command_id'],
                'command_hash' => $hash,
                'expected_version' => $payload['expected_version'],
                'physical_material_id' => $physicalId,
                'source_holding_id' => $sourceHolding->id,
                'quantity' => '1',
                'total_cost' => $physical->total_cost,
                'reason' => $reason,
                'status' => 'PENDING',
                'disposed_by_legacy_id' => $actorId,
                'disposed_at' => now(),
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $disposal = DB::table('erp_material_physical_disposals')->where('id', $disposalId)->lockForUpdate()->first();
            $transaction = $this->inventory->postPhysicalDisposal($disposal, $physical, $sourceBalance, $operator);
            $targetId = $this->physicalMaterials->nonWarehouseHolding(
                (int) $sourceHolding->material_lot_id,
                'DISPOSAL',
                $disposalId,
                (string) $physical->total_cost,
                'DISPOSED',
            );
            $this->physicalMaterials->movePhysical(
                $physical,
                $sourceHolding,
                $targetId,
                'DISPOSE',
                'DISPOSED',
                (int) $transaction->id,
                $actorId,
            );
            DB::table('erp_material_physical_disposals')->where('id', $disposalId)->update([
                'disposal_holding_id' => $targetId,
                'inventory_transaction_id' => $transaction->id,
                'status' => 'POSTED',
                'updated_at' => now(),
            ]);
            return $this->disposalResult(DB::table('erp_material_physical_disposals')->where('id', $disposalId)->first());
        }, 5);
    }

    private function assertLocator(int $warehouseId, int $locationId): void
    {
        if (!Warehouse::query()->whereKey($warehouseId)->whereIn('status', ['active', 'enabled'])->exists()) {
            throw ValidationException::withMessages(['target_warehouse_id' => '调拨目标仓库不存在或已停用。']);
        }
        if (!Location::query()->whereKey($locationId)->where('warehouse_id', $warehouseId)
            ->whereIn('status', ['active', 'enabled'])->exists()) {
            throw ValidationException::withMessages(['target_location_id' => '调拨目标库位不属于目标仓库或已停用。']);
        }
    }

    private function commandHash(string $action, int $physicalId, array $payload, int $actorId): string
    {
        $body = $action === 'transfer'
            ? [$action, $physicalId, $actorId, (int) $payload['expected_version'], (int) $payload['target_warehouse_id'],
                (int) $payload['target_location_id'], trim((string) $payload['target_batch_no']), trim((string) $payload['reason'])]
            : [$action, $physicalId, $actorId, (int) $payload['expected_version'], trim((string) $payload['reason'])];
        return hash('sha256', json_encode($body, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE));
    }

    private function assertReplay(object $row, int $physicalId, string $hash, int $actorId, string $actorColumn = 'moved_by_legacy_id'): void
    {
        if ((int) $row->physical_material_id !== $physicalId || !hash_equals((string) $row->command_hash, $hash)
            || (int) $row->{$actorColumn} !== $actorId) {
            throw ValidationException::withMessages(['client_command_id' => '命令号已被其他操作者或不同实物动作使用。']);
        }
        if ($row->status !== 'POSTED' && ! ($actorColumn === 'moved_by_legacy_id' && $row->status === 'IN_TRANSIT')) {
            throw ValidationException::withMessages(['client_command_id' => '该实物命令尚未完成，请刷新后确认状态。']);
        }
    }

    private function transferResult(object $row): array
    {
        $snapshot = json_decode($row->source_snapshot ?? '{}', true, 512, JSON_THROW_ON_ERROR);
        $source = InventoryBalance::query()->find($row->source_inventory_balance_id);
        $physical = DB::table('erp_material_physicals')->where('id', $row->physical_material_id)->first();
        $item = $physical ? \App\Models\Erp\Item::query()->with('unit')->find($physical->item_id) : null;
        $sourceWarehouseId = (int) ($snapshot['warehouse_id'] ?? $source?->warehouse_id ?? 0);
        $sourceLocationId = (int) ($snapshot['location_id'] ?? $source?->location_id ?? 0);
        $warehouses = Warehouse::query()->whereIn('id', [$sourceWarehouseId, $row->target_warehouse_id])->get()->keyBy('id');
        $locations = Location::query()->whereIn('id', [$sourceLocationId, $row->target_location_id])->get()->keyBy('id');
        return array_replace((array) $row, ['id' => (int) $row->id, 'kind' => 'material_physical_transfer', 'transfer_id' => (int) $row->id,
            'physical_material_id' => (int) $row->physical_material_id, 'physical_no' => $physical?->physical_no,
            'item_id' => $item?->id, 'item' => $item?->toArray(), 'unit_id' => $snapshot['unit_id'] ?? $source?->unit_id,
            'dimensions' => json_decode($physical?->dimensions ?? '{}', true, 512, JSON_THROW_ON_ERROR), 'quantity' => '1.00000000',
            'source_warehouse_id' => $sourceWarehouseId, 'source_location_id' => $sourceLocationId,
            'source_warehouse' => $warehouses->get($sourceWarehouseId)?->toArray(), 'target_warehouse' => $warehouses->get($row->target_warehouse_id)?->toArray(),
            'source_location' => $locations->get($sourceLocationId)?->toArray(), 'target_location' => $locations->get($row->target_location_id)?->toArray(),
            'source_snapshot' => $snapshot, 'business_version' => (int) ($row->business_version ?? 1), 'has_exception' => ! empty($row->receipt_exception),
            'status_label' => ['IN_TRANSIT' => '在途待接收', 'POSTED' => '已过账', 'PENDING' => '办理中'][$row->status] ?? $row->status,
            'total_cost' => (string) $row->total_cost]);
    }

    public function transferDetail(int $id, ?object $actor = null): array
    {
        $row = DB::table('erp_material_physical_transfers')->where('id', $id)->firstOrFail();
        $result = $this->transferResult($row);
        if ($actor) {
            $this->assertPermission($actor, ['inventory.physical.manage', 'inventory.transfer.view', 'inventory.inbound.confirm']);
            $ids = InventoryTransferService::warehouseIds($actor);
            if ($ids !== null && ! in_array($result['source_warehouse_id'], $ids, true) && ! in_array((int) $row->target_warehouse_id, $ids, true))
                throw new WorkOrderDomainException('permission_denied', '本实物调拨不在当前账号仓库范围内。', 403);
        }
        return $result;
    }

    public function paginateTransfers(array $filters, object $actor): array
    {
        $this->assertPermission($actor, ['inventory.physical.manage', 'inventory.transfer.view', 'inventory.inbound.confirm']);
        $ids = InventoryTransferService::warehouseIds($actor);
        $query = DB::table('erp_material_physical_transfers as t')->join('erp_inventory_balances as b', 'b.id', '=', 't.source_inventory_balance_id')
            ->join('erp_material_physicals as p', 'p.id', '=', 't.physical_material_id')->join('erp_items as i', 'i.id', '=', 'p.item_id')
            ->when($ids !== null, fn ($q) => $q->where(fn ($w) => $w->whereIn('b.warehouse_id', $ids)->orWhereIn('t.target_warehouse_id', $ids)))
            ->when(! empty($filters['status']), fn ($q) => $q->where('t.status', strtoupper((string) $filters['status'])))
            ->when(! empty($filters['warehouse_id']), fn ($q) => $q->where(fn ($w) => $w->where('b.warehouse_id', $filters['warehouse_id'])->orWhere('t.target_warehouse_id', $filters['warehouse_id'])))
            ->when(array_key_exists('has_exception', $filters), fn ($q) => ! empty($filters['has_exception'])
                ? $q->whereNotNull('t.receipt_exception')->where('t.receipt_exception', '!=', '')
                : $q->where(fn ($w) => $w->whereNull('t.receipt_exception')->orWhere('t.receipt_exception', '')))
            ->when(trim((string) ($filters['keyword'] ?? '')), fn ($q, $kw) => $q->where(fn ($w) => $w->where('t.transfer_no', 'like', '%'.$kw.'%')
                ->orWhere('p.physical_no', 'like', '%'.$kw.'%')->orWhere('i.item_code', 'like', '%'.$kw.'%')->orWhere('i.item_name', 'like', '%'.$kw.'%')))
            ->select('t.*')->orderByDesc('t.id');
        $page = $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))));
        $page->getCollection()->transform(fn ($row) => $this->transferResult($row));
        return $page->toArray();
    }

    public function transitQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('erp_material_physical_transfers as t')->join('erp_material_physicals as p', 'p.id', '=', 't.physical_material_id')
            ->join('erp_material_holdings as h', 'h.id', '=', 't.transit_holding_id')->join('erp_inventory_balances as b', 'b.id', '=', 't.source_inventory_balance_id')
            ->where('t.status', 'IN_TRANSIT')->where('p.status', 'IN_TRANSIT')->whereColumn('p.current_holding_id', 'h.id')
            ->where('h.position_type', 'TRANSIT')->where('h.status', 'ACTIVE')->whereColumn('h.position_id', 't.id')
            ->whereNotNull('t.outbound_transaction_id')->whereNull('t.inbound_transaction_id')
            ->selectRaw("'material_physical_transfer' AS kind, t.id, t.transfer_no AS number, p.id AS line_id, p.id AS physical_material_id,
                p.item_id, CAST(JSON_UNQUOTE(JSON_EXTRACT(t.source_snapshot, '$.unit_id')) AS UNSIGNED) AS unit_id,
                t.target_batch_no AS batch_no, h.quantity, h.total_cost AS cost_amount,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(t.source_snapshot, '$.warehouse_id')) AS UNSIGNED) AS source_warehouse_id,
                CAST(JSON_UNQUOTE(JSON_EXTRACT(t.source_snapshot, '$.location_id')) AS UNSIGNED) AS source_location_id, t.target_warehouse_id, t.target_location_id,
                t.dispatched_at, t.receipt_exception");
    }

    public static function transferRules(string $action): array
    {
        $version = ['expected_version' => 'required|integer|min:1'];
        return match ($action) {
            'dispatch' => $version + ['target_warehouse_id' => 'required|integer|min:1', 'target_location_id' => 'required|integer|min:1',
                'target_batch_no' => 'required|string|max:80', 'reason' => 'required|string|max:1000'],
            'confirm' => $version + ['target_location_id' => 'required|integer|min:1', 'physical_ids' => 'required|array|min:1|max:1', 'physical_ids.*' => 'required|integer|min:1|distinct'],
            'exception' => $version + ['reason' => 'required|string|max:500'],
            default => throw new \InvalidArgumentException('Unknown physical transfer action.'),
        };
    }

    private function lockedTransit(int $id, array $payload): object
    {
        $transfer = DB::table('erp_material_physical_transfers')->where('id', $id)->lockForUpdate()->firstOrFail();
        if ((int) ($payload['expected_version'] ?? 0) !== (int) $transfer->business_version)
            throw new WorkOrderDomainException('version_conflict', '实物调拨单已经变化，请刷新后重新核对。', 409);
        if ($transfer->status !== 'IN_TRANSIT' || ! $transfer->transit_holding_id || ! $transfer->outbound_transaction_id || $transfer->inbound_transaction_id)
            throw ValidationException::withMessages(['status' => '只有尚未接收的在途实物调拨可以办理。']);
        if (! InventoryTransaction::query()->whereKey($transfer->outbound_transaction_id)->where('source_type', 'material_physical_transfer')->where('source_id', $id)
            ->where('transaction_type', 'physical_material_transfer_outbound')->where('posting_status', 'posted')->lockForUpdate()->exists())
            throw ValidationException::withMessages(['source' => '缺少本单正式实物调出流水。']);
        return $transfer;
    }

    private function assertPermission(object $actor, array $permissions): void
    {
        $auth = app(AuthContextService::class);
        if (! $auth->isSuperAdmin($actor) && ! array_intersect($permissions, $auth->permissionCodes($actor)))
            throw new WorkOrderDomainException('permission_denied', '没有办理本项实物调拨的权限。', 403);
    }

    private function disposalResult(object $row): array
    {
        return [
            'disposal_id' => (int) $row->id,
            'disposal_no' => $row->disposal_no,
            'physical_material_id' => (int) $row->physical_material_id,
            'inventory_transaction_id' => (int) $row->inventory_transaction_id,
            'status' => $row->status,
            'total_cost' => (string) $row->total_cost,
        ];
    }
}
