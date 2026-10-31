<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransfer;
use App\Models\Erp\InventoryTransferLine;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\Warehouse;
use App\Services\Erp\CuttingDecimal;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\ShipmentDirectSourcePolicy;
use App\Services\Erp\ShipmentSourceResolver;
use App\Services\Erp\WarehouseManagementScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 库存调拨：草稿 → 过账（成对出入库，即时生效）/ 取消。
 * 只移动可用数量（不含锁定、不良、待检）；按来源批次平均成本结转；序列号随行迁移；
 * 实物逐张管理的板材仍走“实物材料调拨”。调入行记录来源口径，保证外购直发的来源追溯不断链。
 */
class InventoryTransferService
{
    public const TARGET_LOCATION_PURPOSES = ['general', 'finished'];
    public const STATUSES = ['draft' => '草稿', 'posted' => '已过账', 'cancelled' => '已取消'];

    public function __construct(
        private readonly InventoryPostingKernel $kernel,
        private readonly InventoryAvailabilityService $availability,
        private readonly DocumentNumberService $numbers,
        private readonly ShipmentDirectSourcePolicy $directSources,
        private readonly ShipmentSourceResolver $sourceResolver,
    )
    {
    }

    public function save(array $data, ?int $id, ?int $operatorId): InventoryTransfer
    {
        return DB::transaction(function () use ($data, $id, $operatorId): InventoryTransfer {
            $transfer = $id ? $this->lockDraft($id) : null;
            $this->assertWarehouse((int) $data['source_warehouse_id']);
            $this->assertWarehouse((int) $data['target_warehouse_id']);
            $rows = [];
            foreach ($data['lines'] as $index => $line) {
                $rows[] = $this->validatedLine((int) $data['source_warehouse_id'], (int) $data['target_warehouse_id'], $line, $index + 1, false);
            }
            $header = [
                'source_warehouse_id' => (int) $data['source_warehouse_id'],
                'target_warehouse_id' => (int) $data['target_warehouse_id'],
                'transfer_date' => $data['transfer_date'] ?? now()->toDateString(),
                'remark' => $data['remark'] ?? null,
            ];
            if ($transfer) {
                $transfer->update($header + ['business_version' => (int) $transfer->business_version + 1]);
                InventoryTransferLine::query()->where('transfer_id', $transfer->id)->delete();
            } else {
                $transfer = InventoryTransfer::create($header + [
                    'transfer_no' => $this->numbers->next('inventory_transfer', 'DB'),
                    'transfer_status' => 'draft',
                    'created_by' => $operatorId,
                ]);
            }
            foreach ($rows as $row) InventoryTransferLine::create($row + ['transfer_id' => $transfer->id]);
            return $transfer->fresh('lines');
        });
    }

    public function post(int $id, ?int $operatorId): InventoryTransfer
    {
        return DB::transaction(function () use ($id, $operatorId): InventoryTransfer {
            $transfer = $this->lockDraft($id);
            $lines = InventoryTransferLine::query()->where('transfer_id', $transfer->id)->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) throw ValidationException::withMessages(['lines' => '调拨单没有明细。']);
            $this->assertWarehouse((int) $transfer->source_warehouse_id);
            $this->assertWarehouse((int) $transfer->target_warehouse_id);

            $transaction = $this->kernel->begin([
                'transaction_type' => 'inventory_transfer',
                'source_type' => 'inventory_transfer',
                'source_id' => $transfer->id,
                'source_no' => $transfer->transfer_no,
                'posting_status' => 'posted',
                'warehouse_id' => $transfer->source_warehouse_id,
                'location_id' => $lines->first()->source_location_id,
                'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId,
                'posted_at' => now(),
                'created_by' => $operatorId,
                'remark' => $transfer->remark ?: '库存调拨',
            ]);

            foreach ($lines as $index => $line) {
                // 逐行重验并立即过账：同一批次拆多行时，后续行按已扣减后的可用量校验。
                $this->validatedLine((int) $transfer->source_warehouse_id, (int) $transfer->target_warehouse_id, [
                    'inventory_balance_id' => $line->inventory_balance_id,
                    'target_location_id' => $line->target_location_id,
                    'quantity' => $line->quantity,
                    'serial_ids' => $line->serial_ids ?? [],
                ], $index + 1, true);
                $source = InventoryBalance::query()->whereKey($line->inventory_balance_id)->lockForUpdate()->firstOrFail();
                $target = Location::query()->findOrFail($line->target_location_id);
                $qty = number_format((float) $line->quantity, 8, '.', '');
                $cost = CuttingDecimal::share((string) $source->inventory_value, (string) $source->quantity_on_hand, $qty);
                $provenance = $this->directSources->transferProvenance($this->sourceResolver->inboundFactsAt((int) $source->item_id,
                    (int) $source->warehouse_id, (int) $source->location_id, (string) $source->batch_no)->get()->map(fn ($fact) => (array) $fact)->all());
                $common = [
                    'item_id' => $source->item_id, 'batch_no' => $source->batch_no, 'unit_id' => $source->unit_id,
                    'unit_cost' => (float) $source->average_unit_cost, 'source_type' => 'inventory_transfer',
                    'source_id' => $transfer->id, 'source_item_id' => $line->id, 'remark' => $line->remark,
                ] + ($source->material_lot_id ? ['material_lot_id' => $source->material_lot_id] : []);
                $outbound = $this->kernel->apply($transaction, $common + [
                    'warehouse_id' => $source->warehouse_id, 'location_id' => $source->location_id,
                    'change_qty' => -(float) $line->quantity, 'cost_amount' => bcsub('0', $cost, 4),
                    'cost_source_type' => ShipmentDirectSourcePolicy::TRANSFER_NONE,
                ]);
                $this->kernel->apply($transaction, $common + [
                    'warehouse_id' => $target->warehouse_id, 'location_id' => $target->id,
                    'change_qty' => (float) $line->quantity, 'cost_amount' => bcsub('0', (string) $outbound->cost_amount, 4),
                    'cost_source_type' => $provenance,
                ]);
                $this->moveSerials($transfer, $line, $source, $target, $operatorId, (int) $transaction->id);
                $line->update(['cost_amount' => bcsub('0', (string) $outbound->cost_amount, 4)]);
            }

            $transfer->update(['transfer_status' => 'posted', 'inventory_transaction_id' => $transaction->id, 'posted_by' => $operatorId,
                'posted_at' => now(), 'business_version' => (int) $transfer->business_version + 1]);
            InventoryPostingLog::create([
                'source_type' => 'inventory_transfer', 'source_id' => $transfer->id, 'source_no' => $transfer->transfer_no,
                'transaction_type' => 'inventory_transfer', 'transaction_id' => $transaction->id, 'posting_status' => 'posted',
                'message' => '库存调拨过账成功', 'posted_by' => $operatorId, 'posted_at' => now(),
            ]);
            return $transfer->fresh('lines');
        }, 5);
    }

    public function cancel(int $id, ?int $operatorId): InventoryTransfer
    {
        return DB::transaction(function () use ($id, $operatorId): InventoryTransfer {
            $transfer = $this->lockDraft($id);
            $transfer->update(['transfer_status' => 'cancelled', 'cancelled_by' => $operatorId, 'cancelled_at' => now(),
                'business_version' => (int) $transfer->business_version + 1]);
            return $transfer->fresh();
        });
    }

    public function deleteDraft(int $id): void
    {
        DB::transaction(function () use ($id): void {
            $transfer = $this->lockDraft($id);
            InventoryTransferLine::query()->where('transfer_id', $transfer->id)->delete();
            $transfer->delete();
        });
    }

    private function validatedLine(int $sourceWarehouseId, int $targetWarehouseId, array $line, int $rowNo, bool $lock): array
    {
        $fail = fn (string $message) => throw ValidationException::withMessages(['lines' => "第 {$rowNo} 行：{$message}"]);
        $balance = $this->transferableBalances()->whereKey((int) ($line['inventory_balance_id'] ?? 0))->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $balance || (int) $balance->warehouse_id !== $sourceWarehouseId) $fail('来源库存不可调拨，请检查仓库、库位、批次有效期、管理范围及可用数量。');
        $item = Item::query()->find($balance->item_id);
        if (! $item) $fail('物料不存在。');
        if ($item->materialManagementMode() !== 'quantity') $fail("{$item->item_code} 为实物逐张管理物料，请在库存余额中使用“实物材料调拨”。");
        app(WarehouseManagementScopeService::class)->assertForward($item, $targetWarehouseId);
        $target = Location::query()->whereKey((int) ($line['target_location_id'] ?? 0))->lockForUpdate()->first();
        if (! $target || (int) $target->warehouse_id !== $targetWarehouseId || ! in_array($target->status, ['enabled', 'active'], true)) {
            $fail('调入库位不存在、已停用或不属于调入仓库。');
        }
        if ((int) $target->id === (int) $balance->location_id) $fail('调入库位与来源库位相同。');
        if (! in_array($target->location_purpose, self::TARGET_LOCATION_PURPOSES, true)) $fail('调拨只能调入普通库位或成品库位，不能调入发货待发区、返修区。');
        $qty = round((float) ($line['quantity'] ?? 0), 4);
        if ($qty <= 0) $fail('调拨数量必须大于 0。');
        $available = $this->availability->availableForOutbound($balance);
        if ($qty > $available + 0.00000001) $fail("{$item->item_code} 可调拨数量不足（可用 ".$this->qtyText($available).'）。');

        $serialIds = array_values(array_unique(array_map('intval', $line['serial_ids'] ?? [])));
        $serialStock = InventorySerial::query()->where('inventory_balance_id', $balance->id)->where('serial_status', 'available');
        $serialCount = (clone $serialStock)->count();
        $mode = $item->serialTrackingMode();
        // 系统追加的“含无编号 N 件”每次按本次数量重算，避免重复保存后残留旧数字。
        $remark = trim((string) preg_replace('/(；)?含无编号 \d+ 件/u', '', (string) ($line['remark'] ?? ''))) ?: null;
        if ($mode === 'none' && $serialCount === 0) {
            // 未启用编号且批次内无历史编号：纯数量调拨。
            if ($serialIds) $fail('该库存批次没有可调拨的序列号。');
        } else {
            // 规则与库存调整减少方向一致：编号件按所选编号移动，其余数量只能从无编号可用量中扣。
            if (abs($qty - round($qty)) > 0.00000001) $fail("{$item->item_code} 涉及单件编号，调拨数量必须为整数。");
            $pieces = (int) round($qty);
            if (count($serialIds) > $pieces) $fail('所选序列号数量不能超过调拨数量。');
            if ($serialIds) {
                $found = (clone $serialStock)->whereIn('id', $serialIds)->when($lock, fn ($q) => $q->lockForUpdate())->count();
                if ($found !== count($serialIds)) $fail('所选序列号不在该库存批次或不是可用状态。');
            }
            $unnumbered = $pieces - count($serialIds);
            $unnumberedAvailable = max(0, $available - $serialCount);
            if ($unnumbered > 0) {
                if ($unnumbered > $unnumberedAvailable + 0.00000001) {
                    $fail("{$item->item_code} 无编号可用 ".$this->qtyText($unnumberedAvailable).' 件，本行编号 '.count($serialIds)." 件 + 无编号 {$unnumbered} 件，请补选序列号。");
                }
                if ($mode === 'required') {
                    // 必须编号的物料只为历史无编号库存放行，并在明细上留痕。
                    $tag = "含无编号 {$unnumbered} 件";
                    $remark = $remark ? "{$remark}；{$tag}" : $tag;
                }
            }
        }
        return [
            'inventory_balance_id' => $balance->id, 'item_id' => $balance->item_id, 'source_location_id' => $balance->location_id,
            'target_location_id' => $target->id, 'batch_no' => $balance->batch_no, 'unit_id' => $balance->unit_id,
            'quantity' => $qty, 'serial_ids' => $serialIds ?: null, 'remark' => $remark,
        ];
    }

    /** 候选、保存和过账共用资格；必须在分页前剔除不可用的配置/阶段材料批次。 */
    public function transferableBalances(): \Illuminate\Database\Eloquent\Builder
    {
        return $this->availability->outboundBalanceQuery()
            ->join('erp_items as transfer_item', 'transfer_item.id', '=', 'erp_inventory_balances.item_id')
            ->leftJoin('erp_material_lots as transfer_lot', 'transfer_lot.id', '=', 'erp_inventory_balances.material_lot_id')
            ->whereColumn('transfer_item.management_scope', 'erp_warehouses.management_scope')
            ->whereIn('transfer_item.management_scope', ['factory', 'office'])
            ->where('transfer_item.is_stock_item', true)
            ->where(fn ($q) => $q->whereNull('transfer_item.material_management_mode')->orWhereIn('transfer_item.material_management_mode', ['', 'quantity']))
            ->where('erp_inventory_balances.quantity_available', '>', 0)
            ->whereRaw('(erp_inventory_balances.quantity_on_hand - erp_inventory_balances.quantity_locked - erp_inventory_balances.quantity_defective - erp_inventory_balances.quantity_pending) > 0')
            ->where(function ($q) {
                $q->whereNull('erp_inventory_balances.material_lot_id')->orWhere(function ($lot) {
                    $lot->whereColumn('transfer_lot.item_id', 'erp_inventory_balances.item_id')
                        ->whereNull('transfer_lot.configuration_id')->whereNull('transfer_lot.stage_id')
                        ->where(fn ($form) => $form->whereIn('transfer_lot.material_form', ['FULL_STOCK', 'STANDARD_LENGTH'])
                            ->orWhere(fn ($product) => $product->where('transfer_lot.material_form', 'PRODUCT')->where('transfer_item.item_type', 'finished_product')));
                });
            });
    }

    private function moveSerials(InventoryTransfer $transfer, InventoryTransferLine $line, InventoryBalance $source, Location $target, ?int $operatorId, int $transactionId): void
    {
        $ids = array_map('intval', $line->serial_ids ?? []);
        if (! $ids) return;
        $targetBalance = InventoryBalance::query()->where('item_id', $source->item_id)->where('warehouse_id', $target->warehouse_id)
            ->where('location_id', $target->id)->where('batch_no', $source->batch_no)->lockForUpdate()->firstOrFail();
        foreach (InventorySerial::query()->whereIn('id', $ids)->lockForUpdate()->get() as $serial) {
            $serial->update(['inventory_balance_id' => $targetBalance->id, 'warehouse_id' => $target->warehouse_id, 'location_id' => $target->id]);
            InventorySerialEvent::create([
                'inventory_serial_id' => $serial->id, 'event_type' => 'inventory_transfer', 'document_type' => 'inventory_transfer',
                'document_id' => $transfer->id, 'document_no' => $transfer->transfer_no, 'from_status' => 'available', 'to_status' => 'available',
                'warehouse_id' => $target->warehouse_id, 'location_id' => $target->id, 'batch_no' => $source->batch_no, 'operator_id' => $operatorId,
                'event_payload' => ['transfer_line_id' => $line->id, 'from_warehouse_id' => $source->warehouse_id, 'from_location_id' => $source->location_id,
                    'inventory_transaction_id' => $transactionId],
                'occurred_at' => now(),
            ]);
        }
    }

    private function qtyText(float $qty): string
    {
        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }

    private function assertWarehouse(int $id): void
    {
        $warehouse = Warehouse::query()->find($id);
        if (! $warehouse || ! in_array($warehouse->status, ['enabled', 'active'], true)) {
            throw ValidationException::withMessages(['warehouse_id' => '调出或调入仓库不存在或已停用。']);
        }
    }

    private function lockDraft(int $id): InventoryTransfer
    {
        $transfer = InventoryTransfer::query()->lockForUpdate()->findOrFail($id);
        if ($transfer->transfer_status !== 'draft') throw ValidationException::withMessages(['status' => '只有草稿调拨单可以操作。']);
        return $transfer;
    }
}
