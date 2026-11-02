<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransfer;
use App\Models\Erp\InventoryTransferLine;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\Warehouse;
use App\Services\Erp\CuttingDecimal;
use App\Services\Erp\AuthContextService;
use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\ShipmentDirectSourcePolicy;
use App\Services\Erp\ShipmentSourceResolver;
use App\Services\Erp\WarehouseManagementScopeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 同仓一步；跨仓调出冻结来源和成本，仓库整单接收后才形成目标可用库存。
 * 只移动可用数量（不含锁定、不良、待检）；按来源批次平均成本结转；序列号随行迁移；
 * 实物逐张管理的板材仍走“实物材料调拨”。调入行记录来源口径，保证外购直发的来源追溯不断链。
 */
class InventoryTransferService
{
    public const TARGET_LOCATION_PURPOSES = ['general', 'finished'];
    public const STATUSES = ['draft' => '草稿', 'in_transit' => '在途待接收', 'posted' => '已过账', 'cancelled' => '已取消'];

    public function __construct(
        private readonly InventoryPostingKernel $kernel,
        private readonly InventoryAvailabilityService $availability,
        private readonly DocumentNumberService $numbers,
        private readonly ShipmentDirectSourcePolicy $directSources,
        private readonly ShipmentSourceResolver $sourceResolver,
    )
    {
    }

    public function save(array $data, ?int $id, object $actor): InventoryTransfer
    {
        $this->assertPermission($actor, ['inventory.transfer.create']);
        return DB::transaction(function () use ($data, $id, $actor): InventoryTransfer {
            $operatorId = (int) ($actor->legacy_id ?? $actor->id ?? 0);
            $transfer = $id ? $this->lockDraft($id, $data) : null;
            if ($transfer) self::assertWarehouseScope((int) $transfer->source_warehouse_id, $actor);
            self::assertWarehouseScope((int) $data['source_warehouse_id'], $actor);
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
            foreach ($rows as $row) InventoryTransferLine::create($row + ['transfer_id' => $transfer->id, 'requested_target_location_id' => $row['target_location_id']]);
            return $transfer->fresh('lines');
        });
    }

    public function post(int $id, array $data, object $actor): InventoryTransfer
    {
        $this->assertPermission($actor, ['inventory.transfer.post']);
        return DB::transaction(function () use ($id, $data, $actor): InventoryTransfer {
            $operatorId = (int) ($actor->legacy_id ?? $actor->id ?? 0);
            $transfer = $this->lockDraft($id, $data);
            self::assertWarehouseScope((int) $transfer->source_warehouse_id, $actor);
            $lines = InventoryTransferLine::query()->where('transfer_id', $transfer->id)->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) throw ValidationException::withMessages(['lines' => '调拨单没有明细。']);
            $this->assertWarehouse((int) $transfer->source_warehouse_id);
            $this->assertWarehouse((int) $transfer->target_warehouse_id);

            $crossWarehouse = (int) $transfer->source_warehouse_id !== (int) $transfer->target_warehouse_id;
            $transaction = $this->kernel->begin([
                'transaction_type' => $crossWarehouse ? 'inventory_transfer_outbound' : 'inventory_transfer',
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
                $facts = $this->sourceResolver->inboundFactsAt((int) $source->item_id,
                    (int) $source->warehouse_id, (int) $source->location_id, (string) $source->batch_no)->get()->map(fn ($fact) => (array) $fact)->all();
                $provenance = $this->directSources->transferProvenance($facts);
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
                $frozenCost = bcsub('0', (string) $outbound->cost_amount, 4);
                $snapshot = array_replace($source->only(['item_id', 'warehouse_id', 'location_id', 'batch_no', 'unit_id', 'material_lot_id']), [
                    'unit_id' => $outbound->unit_id, 'material_lot_id' => $outbound->material_lot_id,
                    'unit_cost' => bcdiv($frozenCost, $qty, 8), 'provenance' => $provenance, 'inbound_facts' => $facts,
                    'outbound_transaction_item_id' => (int) $outbound->id,
                    'serial_nos' => InventorySerial::query()->whereIn('id', $line->serial_ids ?? [])->orderBy('id')->pluck('serial_no')->all(),
                ]);
                if (! $crossWarehouse) $this->kernel->apply($transaction, $common + [
                    'warehouse_id' => $target->warehouse_id, 'location_id' => $target->id,
                    'change_qty' => (float) $line->quantity, 'cost_amount' => $frozenCost, 'cost_source_type' => $provenance,
                ]);
                $this->moveSerials($transfer, $line, $snapshot, $crossWarehouse ? null : $target, $operatorId, (int) $transaction->id);
                $line->update(['unit_id' => $outbound->unit_id, 'cost_amount' => $frozenCost, 'source_snapshot' => $snapshot,
                    'requested_target_location_id' => $line->requested_target_location_id ?: $line->target_location_id]);
            }

            $transfer->update(['transfer_status' => $crossWarehouse ? 'in_transit' : 'posted',
                'inventory_transaction_id' => $crossWarehouse ? null : $transaction->id, 'outbound_transaction_id' => $transaction->id,
                'inbound_transaction_id' => $crossWarehouse ? null : $transaction->id,
                'dispatched_by' => $operatorId, 'dispatched_at' => now(), 'posted_by' => $crossWarehouse ? null : $operatorId,
                'posted_at' => $crossWarehouse ? null : now(), 'business_version' => (int) $transfer->business_version + 1]);
            InventoryPostingLog::create([
                'source_type' => 'inventory_transfer', 'source_id' => $transfer->id, 'source_no' => $transfer->transfer_no,
                'transaction_type' => $transaction->transaction_type, 'transaction_id' => $transaction->id, 'posting_status' => 'posted',
                'message' => $crossWarehouse ? '跨仓调拨已调出，待目标仓整单接收' : '同仓移库过账成功', 'posted_by' => $operatorId, 'posted_at' => now(),
            ]);
            return $transfer->fresh('lines');
        }, 5);
    }

    /** 一次接收完整冻结明细；不重读源余额均价，也不把短少或破损自动变成损益。 */
    public function confirm(int $id, array $data, object $actor): InventoryTransfer
    {
        $this->assertPermission($actor, ['inventory.inbound.confirm']);
        return DB::transaction(function () use ($id, $data, $actor) {
            $transfer = $this->lockTransit($id, $data);
            self::assertWarehouseScope((int) $transfer->target_warehouse_id, $actor);
            $this->assertWarehouse((int) $transfer->target_warehouse_id);
            $lines = InventoryTransferLine::query()->where('transfer_id', $id)->orderBy('id')->lockForUpdate()->get();
            $requested = collect($data['lines'] ?? [])->keyBy('line_id');
            if ($lines->isEmpty() || $requested->count() !== count($data['lines'] ?? []) || $requested->count() !== $lines->count()
                || $requested->keys()->diff($lines->pluck('id'))->isNotEmpty())
                throw ValidationException::withMessages(['lines' => '必须一次确认本调拨单全部明细，不能漏行、重复或混入其他单据。']);
            $expected = $lines->flatMap(fn ($line) => $line->source_snapshot['serial_nos'] ?? [])->values()->all();
            $scanned = array_map(fn ($sn) => trim((string) $sn), $data['serial_nos'] ?? []);
            sort($expected, SORT_STRING); sort($scanned, SORT_STRING);
            if (! array_key_exists('serial_nos', $data) || $expected !== $scanned || count(array_unique($scanned)) !== count($scanned))
                throw ValidationException::withMessages(['serial_nos' => '请逐个核对本单完整编号集合，不能重复、漏扫或带入其他编号。']);
            $operatorId = (int) ($actor->legacy_id ?? $actor->id ?? 0);
            $transaction = $this->kernel->begin([
                'transaction_type' => 'inventory_transfer_inbound', 'source_type' => 'inventory_transfer', 'source_id' => $id,
                'source_no' => $transfer->transfer_no, 'posting_status' => 'posted', 'warehouse_id' => $transfer->target_warehouse_id,
                'transaction_date' => now()->toDateString(), 'posted_by' => $operatorId, 'posted_at' => now(), 'created_by' => $operatorId,
                'remark' => '跨仓调拨整单确认接收',
            ]);
            foreach ($lines as $line) {
                $snapshot = $line->source_snapshot ?? [];
                $outbound = InventoryTransactionItem::query()->whereKey($snapshot['outbound_transaction_item_id'] ?? 0)
                    ->where('transaction_id', $transfer->outbound_transaction_id)->where('source_type', 'inventory_transfer')
                    ->where('source_id', $id)->where('source_item_id', $line->id)->lockForUpdate()->first();
                if (! $outbound || (int) $outbound->item_id !== (int) $line->item_id || (int) $outbound->warehouse_id !== (int) $transfer->source_warehouse_id
                    || (int) $outbound->location_id !== (int) $line->source_location_id || (string) $outbound->batch_no !== (string) $line->batch_no
                    || (int) $outbound->material_lot_id !== (int) ($snapshot['material_lot_id'] ?? 0) || (int) $outbound->unit_id !== (int) $line->unit_id
                    || bccomp((string) $outbound->change_qty, bcsub('0', (string) $line->quantity, 8), 8) !== 0
                    || bccomp((string) $outbound->cost_amount, bcsub('0', (string) $line->cost_amount, 4), 4) !== 0)
                    throw ValidationException::withMessages(['source_snapshot' => '调拨明细与已冻结调出流水不一致，不能接收。']);
                $target = Location::query()->whereKey((int) ($requested->get($line->id)['target_location_id'] ?? 0))->lockForUpdate()->first();
                if (! $target || (int) $target->warehouse_id !== (int) $transfer->target_warehouse_id
                    || ! in_array($target->status, ['enabled', 'active'], true) || ! in_array($target->location_purpose, self::TARGET_LOCATION_PURPOSES, true))
                    throw ValidationException::withMessages(['target_location_id' => '请在原目标仓库内选择启用的普通或成品库位。']);
                $provenance = $snapshot['provenance'] ?? ShipmentDirectSourcePolicy::TRANSFER_NONE;
                if (! in_array($provenance, [...ShipmentDirectSourcePolicy::TRANSFER_SOURCES, ShipmentDirectSourcePolicy::TRANSFER_NONE], true))
                    throw ValidationException::withMessages(['source_snapshot' => '冻结的调拨来源口径不完整。']);
                $this->kernel->apply($transaction, [
                    'item_id' => $line->item_id, 'warehouse_id' => $target->warehouse_id, 'location_id' => $target->id,
                    'batch_no' => $line->batch_no, 'unit_id' => $line->unit_id, 'material_lot_id' => $snapshot['material_lot_id'] ?? null,
                    'change_qty' => (string) $line->quantity, 'unit_cost' => $snapshot['unit_cost'], 'cost_amount' => (string) $line->cost_amount,
                    'cost_source_type' => $provenance, 'source_type' => 'inventory_transfer', 'source_id' => $id, 'source_item_id' => $line->id,
                    'remark' => $line->remark,
                ]);
                $this->moveSerials($transfer, $line, $snapshot, $target, $operatorId, (int) $transaction->id);
                $line->update(['target_location_id' => $target->id]);
            }
            $transfer->update(['transfer_status' => 'posted', 'inbound_transaction_id' => $transaction->id,
                'confirmed_by' => $operatorId, 'confirmed_at' => now(), 'posted_by' => $operatorId, 'posted_at' => now(),
                'business_version' => (int) $transfer->business_version + 1]);
            InventoryPostingLog::create(['source_type' => 'inventory_transfer', 'source_id' => $id, 'source_no' => $transfer->transfer_no,
                'transaction_type' => $transaction->transaction_type, 'transaction_id' => $transaction->id, 'posting_status' => 'posted',
                'message' => '跨仓调拨整单接收成功', 'posted_by' => $operatorId, 'posted_at' => now()]);
            return $transfer->fresh('lines');
        }, 5);
    }

    public function recordException(int $id, array $data, object $actor): InventoryTransfer
    {
        $this->assertPermission($actor, ['inventory.inbound.confirm', 'inventory.transfer.post']);
        return DB::transaction(function () use ($id, $data, $actor) {
            $transfer = $this->lockTransit($id, $data);
            $this->assertExceptionScope((int) $transfer->source_warehouse_id, (int) $transfer->target_warehouse_id, $actor, 'inventory.transfer.post');
            $reason = trim((string) ($data['reason'] ?? ''));
            if ($reason === '' || mb_strlen($reason) > 500) throw ValidationException::withMessages(['reason' => '请填写500字以内的接收异常情况。']);
            $operatorId = (int) ($actor->legacy_id ?? $actor->id ?? 0);
            $transfer->update(['receipt_exception' => $reason, 'exception_by' => $operatorId, 'exception_at' => now(),
                'business_version' => (int) $transfer->business_version + 1]);
            InventoryPostingLog::create(['source_type' => 'inventory_transfer', 'source_id' => $id, 'source_no' => $transfer->transfer_no,
                'transaction_type' => 'inventory_transfer_exception', 'transaction_id' => $transfer->outbound_transaction_id,
                'posting_status' => 'in_transit', 'message' => $reason, 'posted_by' => $operatorId, 'posted_at' => now()]);
            return $transfer->fresh('lines');
        }, 5);
    }

    public function cancel(int $id, array $data, object $actor): InventoryTransfer
    {
        $this->assertPermission($actor, ['inventory.transfer.create', 'inventory.transfer.post']);
        return DB::transaction(function () use ($id, $data, $actor): InventoryTransfer {
            $operatorId = (int) ($actor->legacy_id ?? $actor->id ?? 0);
            $transfer = $this->lockDraft($id, $data);
            self::assertWarehouseScope((int) $transfer->source_warehouse_id, $actor);
            $transfer->update(['transfer_status' => 'cancelled', 'cancelled_by' => $operatorId, 'cancelled_at' => now(),
                'business_version' => (int) $transfer->business_version + 1]);
            return $transfer->fresh();
        });
    }

    public function deleteDraft(int $id, array $data, object $actor): array
    {
        $this->assertPermission($actor, ['inventory.transfer.delete']);
        return DB::transaction(function () use ($id, $data, $actor): array {
            $transfer = $this->lockDraft($id, $data);
            self::assertWarehouseScope((int) $transfer->source_warehouse_id, $actor);
            InventoryTransferLine::query()->where('transfer_id', $transfer->id)->delete();
            $transfer->delete();
            return ['id' => $id, 'deleted' => true];
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

    private function moveSerials(InventoryTransfer $transfer, InventoryTransferLine $line, array $source, ?Location $target, ?int $operatorId, int $transactionId): void
    {
        $ids = array_map('intval', $line->serial_ids ?? []);
        if (! $ids) return;
        $targetBalance = $target ? InventoryBalance::query()->where('item_id', $line->item_id)->where('warehouse_id', $target->warehouse_id)
            ->where('location_id', $target->id)->where('batch_no', $line->batch_no)->lockForUpdate()->firstOrFail() : null;
        $fromStatus = $transfer->transfer_status === 'in_transit' ? 'in_transit' : 'available';
        $serials = InventorySerial::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        if ($serials->count() !== count($ids)) throw ValidationException::withMessages(['serial_ids' => '调拨编号记录不完整，请刷新核对。']);
        foreach ($serials as $serial) {
            if ($serial->serial_status !== $fromStatus || (int) $serial->item_id !== (int) $line->item_id || (string) $serial->batch_no !== (string) $line->batch_no
                || ($fromStatus === 'available' && (int) $serial->inventory_balance_id !== (int) $line->inventory_balance_id)
                || ($fromStatus === 'in_transit' && $serial->inventory_balance_id !== null))
                throw ValidationException::withMessages(['serial_ids' => '编号 '.$serial->serial_no.' 已不在本单可调拨状态。']);
            if ($fromStatus === 'in_transit') {
                $last = InventorySerialEvent::query()->where('inventory_serial_id', $serial->id)->orderByDesc('id')->lockForUpdate()->first();
                if (! $last || $last->event_type !== 'inventory_transfer_dispatched' || $last->document_type !== 'inventory_transfer'
                    || (int) $last->document_id !== (int) $transfer->id)
                    throw ValidationException::withMessages(['serial_ids' => '编号 '.$serial->serial_no.' 不属于本次在途调拨。']);
            }
            $serial->update($target ? ['serial_status' => 'available', 'inventory_balance_id' => $targetBalance->id,
                'warehouse_id' => $target->warehouse_id, 'location_id' => $target->id] : ['serial_status' => 'in_transit', 'inventory_balance_id' => null]);
            InventorySerialEvent::create([
                'inventory_serial_id' => $serial->id, 'event_type' => ! $target ? 'inventory_transfer_dispatched' : ($fromStatus === 'in_transit' ? 'inventory_transfer_confirmed' : 'inventory_transfer'),
                'document_type' => 'inventory_transfer', 'document_id' => $transfer->id, 'document_no' => $transfer->transfer_no,
                'from_status' => $fromStatus, 'to_status' => $target ? 'available' : 'in_transit',
                'warehouse_id' => $target?->warehouse_id ?? $source['warehouse_id'], 'location_id' => $target?->id ?? $source['location_id'],
                'batch_no' => $line->batch_no, 'operator_id' => $operatorId,
                'event_payload' => ['transfer_line_id' => $line->id, 'from_warehouse_id' => $source['warehouse_id'], 'from_location_id' => $source['location_id'],
                    'target_warehouse_id' => $transfer->target_warehouse_id, 'target_location_id' => $target?->id ?? $line->target_location_id, 'inventory_transaction_id' => $transactionId],
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

    private function lockDraft(int $id, array $data): InventoryTransfer
    {
        $transfer = InventoryTransfer::query()->lockForUpdate()->findOrFail($id);
        $this->assertVersion($transfer, $data);
        if ($transfer->transfer_status !== 'draft') throw ValidationException::withMessages(['status' => '只有草稿调拨单可以操作。']);
        return $transfer;
    }

    private function lockTransit(int $id, array $data): InventoryTransfer
    {
        $transfer = InventoryTransfer::query()->lockForUpdate()->findOrFail($id);
        $this->assertVersion($transfer, $data);
        if ($transfer->transfer_status !== 'in_transit' || ! $transfer->outbound_transaction_id || $transfer->inbound_transaction_id)
            throw ValidationException::withMessages(['status' => '只有已调出、尚未接收的跨仓调拨单可以办理。']);
        if (! InventoryTransaction::query()->whereKey($transfer->outbound_transaction_id)->where('source_type', 'inventory_transfer')
            ->where('source_id', $id)->where('transaction_type', 'inventory_transfer_outbound')->where('posting_status', 'posted')->lockForUpdate()->exists())
            throw ValidationException::withMessages(['source' => '缺少本单正式调出流水，不能接收或变更在途事实。']);
        return $transfer;
    }

    private function assertVersion(InventoryTransfer $transfer, array $data): void
    {
        if ((int) ($data['expected_version'] ?? 0) !== (int) $transfer->business_version)
            throw new WorkOrderDomainException('version_conflict', '调拨单已变化，请刷新后重新核对。', 409);
    }

    private function assertPermission(object $actor, array $permissions): void
    {
        $auth = app(AuthContextService::class);
        if (! $auth->isSuperAdmin($actor) && ! array_intersect($permissions, $auth->permissionCodes($actor)))
            throw new WorkOrderDomainException('permission_denied', '没有办理本项调拨操作的权限。', 403);
    }

    /** null 表示现有 all 数据范围；其他范围按仓库关联的正式负责人账号过滤。 */
    public static function warehouseIds(object $actor): ?array
    {
        $auth = app(AuthContextService::class);
        $scope = $auth->dataScope($actor);
        if ($scope === 'all') return null;
        $ids = $scope === 'department' ? $auth->departmentUserIds($actor) : [(int) ($actor->legacy_id ?? $actor->id ?? 0)];
        return Warehouse::query()->whereIn('manager_user_id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function assertWarehouseScope(int $warehouseId, object $actor): void
    {
        $ids = self::warehouseIds($actor);
        if ($ids !== null && ! in_array($warehouseId, $ids, true))
            throw new WorkOrderDomainException('permission_denied', '该仓库不在当前账号的数据范围内。', 403);
    }

    public function assertExceptionScope(int $sourceWarehouseId, int $targetWarehouseId, object $actor, string $dispatchPermission): void
    {
        $auth = app(AuthContextService::class);
        $codes = $auth->permissionCodes($actor);
        $ids = self::warehouseIds($actor);
        if (($auth->isSuperAdmin($actor) || in_array('inventory.inbound.confirm', $codes, true)) && ($ids === null || in_array($targetWarehouseId, $ids, true))) return;
        if (in_array($dispatchPermission, $codes, true) && ($ids === null || in_array($sourceWarehouseId, $ids, true))) return;
        throw new WorkOrderDomainException('permission_denied', '只能记录本人仓库范围内的调拨异常。', 403);
    }

    public static function rules(string $action): array
    {
        $version = ['expected_version' => 'required|integer|min:1'];
        return match ($action) {
            'create', 'update' => ($action === 'update' ? $version : []) + [
                'source_warehouse_id' => 'required|integer|min:1', 'target_warehouse_id' => 'required|integer|min:1',
                'transfer_date' => 'nullable|date', 'remark' => 'nullable|string|max:500', 'lines' => 'required|array|min:1|max:200',
                'lines.*.inventory_balance_id' => 'required|integer|min:1', 'lines.*.target_location_id' => 'required|integer|min:1',
                'lines.*.quantity' => 'required|numeric|gt:0', 'lines.*.serial_ids' => 'nullable|array|max:500',
                'lines.*.serial_ids.*' => 'required|integer|min:1|distinct', 'lines.*.remark' => 'nullable|string|max:500'],
            'confirm' => $version + ['lines' => 'required|array|min:1|max:200', 'lines.*.line_id' => 'required|integer|min:1|distinct',
                'lines.*.target_location_id' => 'required|integer|min:1', 'serial_nos' => 'present|array|max:100000', 'serial_nos.*' => 'required|string|max:120|distinct'],
            'exception' => $version + ['reason' => 'required|string|max:500'],
            'post', 'cancel', 'delete' => $version,
            default => throw new \InvalidArgumentException('Unknown inventory transfer action.'),
        };
    }

    public function present(InventoryTransfer $transfer): array
    {
        $transfer->load(['sourceWarehouse:id,warehouse_code,warehouse_name', 'targetWarehouse:id,warehouse_code,warehouse_name',
            'lines.item:id,item_code,item_name,spec', 'lines.unit:id,unit_name', 'lines.sourceLocation:id,location_code,location_name', 'lines.targetLocation:id,location_code,location_name']);
        $nos = InventorySerial::query()->whereIn('id', $transfer->lines->flatMap(fn ($line) => $line->serial_ids ?? []))->pluck('serial_no', 'id');
        foreach ($transfer->lines as $line) $line->setAttribute('serial_nos', collect($line->serial_ids ?? [])->map(fn ($id) => $nos[$id] ?? null)->filter()->values()->all());
        return $transfer->toArray() + ['status_label' => self::STATUSES[$transfer->transfer_status] ?? $transfer->transfer_status,
            'has_exception' => (bool) $transfer->receipt_exception];
    }

    public function transitQuery(): \Illuminate\Database\Query\Builder
    {
        return DB::table('erp_inventory_transfers as t')->join('erp_inventory_transfer_lines as l', 'l.transfer_id', '=', 't.id')
            ->where('t.transfer_status', 'in_transit')->whereNotNull('t.outbound_transaction_id')->whereNull('t.inbound_transaction_id')
            ->selectRaw("'inventory_transfer' AS kind, t.id, t.transfer_no AS number, l.id AS line_id, NULL AS physical_material_id,
                l.item_id, l.unit_id, l.batch_no, l.quantity, l.cost_amount, t.source_warehouse_id, l.source_location_id,
                t.target_warehouse_id, l.target_location_id, t.dispatched_at, t.receipt_exception");
    }
}
