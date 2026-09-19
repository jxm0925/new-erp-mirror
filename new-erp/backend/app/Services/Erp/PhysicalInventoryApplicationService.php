<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Location;
use App\Models\Erp\Warehouse;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PhysicalInventoryApplicationService
{
    public function __construct(
        private readonly InventoryService $inventory,
        private readonly MaterialPhysicalService $physicalMaterials,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function transfer(int $physicalId, array $payload, object $operator): array
    {
        return DB::transaction(function () use ($physicalId, $payload, $operator): array {
            $actorId = (int) ($operator->legacy_id ?? $operator->id ?? 0);
            $reason = trim((string) ($payload['reason'] ?? ''));
            $targetBatch = trim((string) ($payload['target_batch_no'] ?? ''));
            $hash = $this->commandHash('transfer', $physicalId, $payload, $actorId);
            $existing = DB::table('erp_material_physical_transfers')
                ->where('client_command_id', $payload['client_command_id'])->lockForUpdate()->first();
            if ($existing) {
                $this->assertReplay($existing, $physicalId, $hash, $actorId);
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
            $this->assertLocator((int) $payload['target_warehouse_id'], (int) $payload['target_location_id']);
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
                'target_batch_no' => $targetBatch,
                'total_cost' => $physical->total_cost,
                'reason' => $reason,
                'status' => 'PENDING',
                'moved_by_legacy_id' => $actorId,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $transfer = DB::table('erp_material_physical_transfers')->where('id', $transferId)->lockForUpdate()->first();
            $transaction = $this->inventory->postPhysicalTransfer($transfer, $physical, $sourceBalance, $operator);
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
                'status' => 'POSTED',
                'moved_at' => now(),
                'updated_at' => now(),
            ]);
            return $this->transferResult(DB::table('erp_material_physical_transfers')->where('id', $transferId)->first());
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
        if ($row->status !== 'POSTED') {
            throw ValidationException::withMessages(['client_command_id' => '该实物命令尚未完成，请刷新后确认状态。']);
        }
    }

    private function transferResult(object $row): array
    {
        return [
            'transfer_id' => (int) $row->id,
            'transfer_no' => $row->transfer_no,
            'physical_material_id' => (int) $row->physical_material_id,
            'source_inventory_balance_id' => (int) $row->source_inventory_balance_id,
            'target_inventory_balance_id' => (int) $row->target_inventory_balance_id,
            'inventory_transaction_id' => (int) $row->inventory_transaction_id,
            'status' => $row->status,
            'total_cost' => (string) $row->total_cost,
        ];
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
