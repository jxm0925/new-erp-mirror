<?php

namespace App\Services\Erp\Inventory;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryLocationBalance;
use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Services\Erp\CuttingDecimal;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\MaterialPhysicalService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 生产库存过账：完工入库、领料、退料、不良处置、开料与余料。业务只组装过账行，数量/成本/余额一律经 InventoryPostingKernel。 */
class ProductionInventoryPosting
{
    public function __construct(
        private readonly InventoryAvailabilityService $availability,
        private readonly MaterialPhysicalService $physicalMaterials,
        private readonly InventoryPostingKernel $kernel,
    )
    {
    }

    /**
     * 生产成品入库：一张入库记录 = 一条入库流水；按每台暂估材料成本入账（第 3 阶段领料后改实际成本）。
     * 物料需要序列号管理时，同时把每台的序列号登记为可用库存序列号。
     * @return array{transaction: InventoryTransaction, serial_ids: array<string, int>}
     */
    public function postProductionReceipt(\App\Models\Erp\MfgReceipt $receipt, \App\Models\Erp\MfgWorkOrder $workOrder, array $serialNos, ?int $operatorId = null): array
    {
        return DB::transaction(function () use ($receipt, $workOrder, $serialNos, $operatorId): array {
            if (InventoryTransaction::query()->where('source_type', 'mfg_receipt')->where('source_id', $receipt->id)
                ->where('transaction_type', 'production_receipt_inbound')->exists()) {
                throw ValidationException::withMessages(['receipt' => '这张成品入库已经过账，不能重复入库。']);
            }
            $item = Item::query()->findOrFail($workOrder->item_id);
            if ($item->materialManagementMode() === 'physical') {
                throw ValidationException::withMessages(['item' => '这个产品按实物身份管理，暂不支持从生产直接入库。']);
            }
            $transaction = $this->kernel->begin([
                
                'transaction_type' => 'production_receipt_inbound',
                'source_type' => 'mfg_receipt',
                'source_id' => $receipt->id,
                'source_no' => $receipt->receipt_no,
                'posting_status' => 'posted',
                'warehouse_id' => $receipt->warehouse_id,
                'location_id' => $receipt->location_id,
                'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId,
                'posted_at' => now(),
                'created_by' => $operatorId,
                'remark' => '生产成品入库，工单 '.$workOrder->work_order_no,
            ]);
            $this->kernel->apply($transaction, [
                'item_id' => $item->id,
                'warehouse_id' => $receipt->warehouse_id,
                'location_id' => $receipt->location_id,
                'batch_no' => $workOrder->work_order_no,
                'unit_id' => $item->unit_id,
                'change_qty' => (float) $receipt->qty,
                'unit_cost' => (float) $receipt->unit_cost,
                'cost_amount' => (float) $receipt->cost_amount,
                'cost_source_type' => $receipt->cost_source === 'actual_materials_provisional' ? 'production_actual_materials' : 'production_estimate',
                'source_type' => 'mfg_receipt',
                'source_id' => $receipt->id,
                'remark' => '工单 '.$workOrder->work_order_no.($receipt->cost_source === 'actual_materials_provisional' ? ' 成品入库（实际材料成本，制造待结算）' : ' 成品入库（暂估材料成本）'),
            ]);
            $serialIds = [];
            if ($item->serialTrackingMode() !== 'none' && $serialNos) {
                $balance = InventoryBalance::query()->where('item_id', $item->id)->where('warehouse_id', $receipt->warehouse_id)
                    ->where('location_id', $receipt->location_id)->where('batch_no', $workOrder->work_order_no)->firstOrFail();
                foreach ($serialNos as $serialNo) {
                    if (InventorySerial::query()->where('serial_no', $serialNo)->exists()) {
                        throw ValidationException::withMessages(['serial_no' => "序列号 {$serialNo} 已在库存里登记过，不能重复入库。"]);
                    }
                    $serial = InventorySerial::create([
                        'serial_no' => $serialNo, 'inventory_balance_id' => $balance->id, 'item_id' => $item->id,
                        'warehouse_id' => $receipt->warehouse_id, 'location_id' => $receipt->location_id, 'batch_no' => $workOrder->work_order_no,
                        'origin_type' => 'production', 'number_source' => 'system',
                        'source_document_type' => 'mfg_receipt', 'source_document_id' => $receipt->id, 'source_document_no' => $receipt->receipt_no,
                        'serial_status' => 'available', 'received_at' => now(), 'registered_at' => now(), 'posted_at' => now(),
                    ]);
                    InventorySerialEvent::create([
                        'inventory_serial_id' => $serial->id, 'event_type' => 'production_receipt_in', 'document_type' => 'mfg_receipt',
                        'document_id' => $receipt->id, 'document_no' => $receipt->receipt_no, 'from_status' => null, 'to_status' => 'available',
                        'warehouse_id' => $receipt->warehouse_id, 'location_id' => $receipt->location_id, 'batch_no' => $workOrder->work_order_no,
                        'event_payload' => ['work_order_no' => $workOrder->work_order_no], 'occurred_at' => now(),
                    ]);
                    $serialIds[$serialNo] = $serial->id;
                }
            }
            InventoryPostingLog::create([
                'source_type' => 'mfg_receipt',
                'source_id' => $receipt->id,
                'source_no' => $receipt->receipt_no,
                'transaction_type' => 'production_receipt_inbound',
                'transaction_id' => $transaction->id,
                'posting_status' => 'posted',
                'message' => '生产成品入库成功',
                'posted_by' => $operatorId,
                'posted_at' => now(),
            ]);
            return ['transaction' => $transaction, 'serial_ids' => $serialIds];
        }, 5);
    }

    /**
     * 工序收料：车间收到本道要用的料时才扣库存（按库存平均成本出库）。
     * @param array<int, array{balance_id:int, item_id:int, material_id:int, qty:string, serial_ids:array<int>}> $allocations
     */
    public function postProductionMaterialIssue(\App\Models\Erp\MfgMaterialIssue $issue, \App\Models\Erp\MfgWorkOrder $workOrder, array $allocations, ?int $operatorId = null): InventoryTransaction
    {
        return DB::transaction(function () use ($issue, $workOrder, $allocations, $operatorId): InventoryTransaction {
            if (InventoryTransaction::query()->where('source_type', 'mfg_material_issue')->where('source_id', $issue->id)
                ->where('transaction_type', 'production_material_issue_outbound')->exists()) {
                throw ValidationException::withMessages(['issue' => '这张收料单已经扣过库存，不能重复扣。']);
            }
            if (! $allocations) throw ValidationException::withMessages(['issue' => '收料单没有要扣的库存。']);
            $transaction = $this->kernel->begin([
                'transaction_type' => 'production_material_issue_outbound',
                'source_type' => 'mfg_material_issue', 'source_id' => $issue->id, 'source_no' => $issue->issue_no,
                'posting_status' => 'posted', 'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId, 'posted_at' => now(), 'created_by' => $operatorId,
                'remark' => '工序收料，工单 '.$workOrder->work_order_no,
            ]);
            $total = '0';
            foreach ($allocations as $row) {
                $balance = InventoryBalance::query()->whereKey($row['balance_id'])->lockForUpdate()->firstOrFail();
                $item = Item::query()->findOrFail($row['item_id']);
                $quantity = number_format((float) $row['qty'], 8, '.', '');
                if ((int) $balance->item_id !== (int) $item->id || $item->materialManagementMode() === 'physical'
                    || bccomp($quantity, '0', 8) <= 0 || $this->availability->availableForOutbound($balance) + 0.00000001 < (float) $quantity) {
                    throw ValidationException::withMessages(['stock' => $item->item_name.' 可用库存不足或不能按数量领用，请刷新后重试。']);
                }
                $serialIds = array_values(array_unique(array_map('intval', $row['serial_ids'] ?? [])));
                $serials = collect();
                if ($serialIds) {
                    $serials = InventorySerial::query()->whereIn('id', $serialIds)->where('inventory_balance_id', $balance->id)
                        ->where('item_id', $item->id)->where('serial_status', 'available')->lockForUpdate()->get();
                    if ($serials->count() !== count($serialIds)) throw ValidationException::withMessages(['stock' => $item->item_name.' 的序列号已被占用，请刷新后重试。']);
                }
                if ($item->serialTrackingMode() === 'required' && bccomp($quantity, (string) count($serialIds), 8) !== 0) {
                    throw ValidationException::withMessages(['stock' => $item->item_name.' 按序列号管理，领用数量必须和序列号个数一致。']);
                }
                // 制造领料始终分配权威余额总金额，最后一次领完时承接全部尾差，避免零数量仍留成本。
                $cost = CuttingDecimal::share((string) $balance->inventory_value, (string) $balance->quantity_on_hand, $quantity);
                $fact = $this->kernel->apply($transaction, [
                    'item_id' => $item->id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
                    'batch_no' => $balance->batch_no, 'unit_id' => $balance->unit_id,
                    'change_qty' => bcsub('0', $quantity, 8), 'unit_cost' => (float) $balance->average_unit_cost, 'cost_amount' => bcsub('0', $cost, 4),
                    'cost_source_type' => 'production_issue_average', 'source_type' => 'mfg_material_issue', 'source_id' => $issue->id,
                    'source_item_id' => $row['material_id'] ?? null, 'remark' => '工单 '.$workOrder->work_order_no.' 工序收料',
                ]);
                $total = bcadd($total, bcsub('0', (string) $fact->cost_amount, 4), 4);
                foreach ($serials as $serial) {
                    $serial->update(['serial_status' => 'production_consumed', 'outbound_at' => now()]);
                    InventorySerialEvent::create([
                        'inventory_serial_id' => $serial->id, 'event_type' => 'production_material_issued', 'document_type' => 'mfg_material_issue',
                        'document_id' => $issue->id, 'document_no' => $issue->issue_no, 'from_status' => 'available', 'to_status' => 'production_consumed',
                        'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no,
                        'event_payload' => ['work_order_no' => $workOrder->work_order_no], 'occurred_at' => now(),
                    ]);
                }
            }
            InventoryPostingLog::create([
                'source_type' => 'mfg_material_issue', 'source_id' => $issue->id, 'source_no' => $issue->issue_no,
                'transaction_type' => 'production_material_issue_outbound', 'transaction_id' => $transaction->id,
                'posting_status' => 'posted', 'message' => '工序收料出库成功', 'posted_by' => $operatorId, 'posted_at' => now(),
            ]);
            $issue->update(['inventory_transaction_id' => $transaction->id, 'cost_amount' => $total]);
            return $transaction;
        }, 5);
    }

    /**
     * 生产退料入库：按原收料出库流水的批次和单价冲回（同一条流水分次退时最后一次承接尾差），退回原库位或库管选定的库位。
     * 按编号退的序列号恢复在库可用，挂到退入的库存行。
     * 不良退回（defective）的料入库即冻结：计入不良数量、不算可用，编号置为质量冻结，等库管处置后才能再领用。
     */
    public function postProductionMaterialReturn(\App\Models\Erp\MfgMaterialReturn $return, \App\Models\Erp\MfgWorkOrder $workOrder, ?int $operatorId = null): InventoryTransaction
    {
        return DB::transaction(function () use ($return, $workOrder, $operatorId): InventoryTransaction {
            $hold = $return->return_type === 'defective';
            if (InventoryTransaction::query()->where('source_type', 'mfg_material_return')->where('source_id', $return->id)
                ->where('transaction_type', 'production_material_return_inbound')->exists()) {
                throw ValidationException::withMessages(['return' => '这张退料单已经入过库，不能重复入库。']);
            }
            $lines = \App\Models\Erp\MfgMaterialReturnLine::query()->where('return_id', $return->id)->orderBy('id')->lockForUpdate()->get();
            if ($lines->isEmpty()) throw ValidationException::withMessages(['return' => '退料单没有明细。']);
            $transaction = $this->kernel->begin([
                'transaction_type' => 'production_material_return_inbound',
                'source_type' => 'mfg_material_return', 'source_id' => $return->id, 'source_no' => $return->return_no,
                'posting_status' => 'posted', 'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId, 'posted_at' => now(), 'created_by' => $operatorId,
                'remark' => '生产退料，工单 '.$workOrder->work_order_no,
            ]);
            $total = '0.0000';
            foreach ($lines as $line) {
                $source = InventoryTransactionItem::query()->with('transaction')->whereKey($line->source_transaction_item_id)->lockForUpdate()->first();
                if (! $source || $source->transaction?->transaction_type !== 'production_material_issue_outbound' || $source->source_type !== 'mfg_material_issue'
                    || (int) $source->source_id !== (int) $line->issue_id || (int) $source->item_id !== (int) $line->component_item_id) {
                    throw ValidationException::withMessages(['return' => $line->component_item_name.' 找不到对应的原收料出库流水，不能退料。']);
                }
                $returned = \App\Models\Erp\MfgMaterialReturnLine::query()->where('source_transaction_item_id', $source->id)->where('return_id', '!=', $return->id)
                    ->whereHas('materialReturn', fn ($q) => $q->where('status', 'approved'))->selectRaw('COALESCE(SUM(qty), 0) AS q, COALESCE(SUM(cost_amount), 0) AS c')->first();
                $remainingQty = bcsub(number_format(abs((float) $source->change_qty), 8, '.', ''), (string) $returned->q, 8);
                $remainingCost = bcsub(bcsub('0', (string) $source->cost_amount, 4), (string) $returned->c, 4);
                $quantity = number_format((float) $line->qty, 8, '.', '');
                if (bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, $remainingQty, 8) > 0) {
                    throw ValidationException::withMessages(['return' => $line->component_item_name.' 退料数量超过原收料未退数量，请刷新后重试。']);
                }
                $cost = CuttingDecimal::share($remainingCost, $remainingQty, $quantity);
                $warehouseId = (int) ($return->target_location_id ? $return->target_warehouse_id : $source->warehouse_id);
                $locationId = (int) ($return->target_location_id ?: $source->location_id);
                $change = [
                    'item_id' => $source->item_id, 'warehouse_id' => $warehouseId, 'location_id' => $locationId,
                    'batch_no' => $source->batch_no, 'unit_id' => $source->unit_id,
                    'change_qty' => $quantity, 'unit_cost' => (float) bcdiv($cost, $quantity, 4), 'cost_amount' => $cost,
                    'cost_source_type' => 'production_return_original_cost', 'source_type' => 'mfg_material_return', 'source_id' => $return->id,
                    'source_item_id' => $line->id, 'remark' => '工单 '.$workOrder->work_order_no.' 生产退料（原单 '.$source->transaction_no.'）',
                ];
                if ($source->material_lot_id) $change['material_lot_id'] = $source->material_lot_id;
                $this->kernel->apply($transaction, $change);
                $total = bcadd($total, $cost, 4);
                $line->update(['warehouse_id' => $warehouseId, 'location_id' => $locationId, 'unit_cost' => bcdiv($cost, $quantity, 4), 'cost_amount' => $cost]);
                if ($hold) $this->changeDefective((int) $source->item_id, $warehouseId, $locationId, (string) $source->batch_no, (float) $quantity);
                if ($line->inventory_serial_id) {
                    $serial = InventorySerial::query()->whereKey($line->inventory_serial_id)->lockForUpdate()->first();
                    if (! $serial || $serial->serial_status !== 'production_consumed' || (int) $serial->item_id !== (int) $source->item_id) {
                        throw ValidationException::withMessages(['return' => '编号 '.$line->serial_no.' 当前不是生产已领状态，不能退料。']);
                    }
                    $balance = InventoryBalance::query()->where('item_id', $source->item_id)->where('warehouse_id', $warehouseId)
                        ->where('location_id', $locationId)->where('batch_no', $source->batch_no)->firstOrFail();
                    $serial->update(['serial_status' => $hold ? 'quality_hold' : 'available', 'inventory_balance_id' => $balance->id, 'warehouse_id' => $warehouseId,
                        'location_id' => $locationId, 'outbound_at' => null]);
                    InventorySerialEvent::create([
                        'inventory_serial_id' => $serial->id, 'event_type' => 'production_material_returned', 'document_type' => 'mfg_material_return',
                        'document_id' => $return->id, 'document_no' => $return->return_no, 'from_status' => 'production_consumed', 'to_status' => $hold ? 'quality_hold' : 'available',
                        'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'batch_no' => $source->batch_no,
                        'event_payload' => ['work_order_no' => $workOrder->work_order_no, 'unit_serial_no' => $line->unit_serial_no], 'occurred_at' => now(),
                    ]);
                }
            }
            InventoryPostingLog::create([
                'source_type' => 'mfg_material_return', 'source_id' => $return->id, 'source_no' => $return->return_no,
                'transaction_type' => 'production_material_return_inbound', 'transaction_id' => $transaction->id,
                'posting_status' => 'posted', 'message' => '生产退料入库成功', 'posted_by' => $operatorId, 'posted_at' => now(),
            ]);
            $return->update(['inventory_transaction_id' => $transaction->id, 'cost_amount' => $total]);
            return $transaction;
        }, 5);
    }

    /**
     * 不良退料处置：$mode=release 只解冻不动数量（判定可用、转退供应商前）；scrap 先解冻再按退料单价出库。调用方须在事务内。
     * 返回报废出库流水（release 为 null）。
     */
    public function postProductionDefectDisposition(\App\Models\Erp\MfgMaterialReturnDisposition $disposition, \App\Models\Erp\MfgMaterialReturnLine $line,
        \App\Models\Erp\MfgWorkOrder $workOrder, string $mode, ?int $operatorId = null): ?InventoryTransaction
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Defect disposition posting requires an application transaction.');
        $qty = (float) $disposition->qty;
        $this->changeDefective((int) $line->component_item_id, (int) $line->warehouse_id, (int) $line->location_id, (string) $line->batch_no, -$qty);
        $serial = $line->inventory_serial_id ? InventorySerial::query()->whereKey($line->inventory_serial_id)->lockForUpdate()->first() : null;
        if ($line->inventory_serial_id && (! $serial || $serial->serial_status !== 'quality_hold'))
            throw ValidationException::withMessages(['disposition' => '编号 '.$line->serial_no.' 已不是质量冻结状态，不能重复处置。']);
        if ($mode === 'release') {
            if ($serial) {
                $serial->update(['serial_status' => 'available']);
                $this->defectSerialEvent($serial, 'production_defect_released', 'available', $disposition, $workOrder, $operatorId);
            }
            return null;
        }
        $transaction = $this->kernel->begin([
            'transaction_type' => 'production_defect_scrap_outbound',
            'source_type' => 'mfg_material_return_disposition', 'source_id' => $disposition->id, 'source_no' => $disposition->document_no,
            'posting_status' => 'posted', 'warehouse_id' => $line->warehouse_id, 'location_id' => $line->location_id, 'transaction_date' => now()->toDateString(),
            'posted_by' => $operatorId, 'posted_at' => now(), 'created_by' => $operatorId, 'remark' => '生产不良退料报废，工单 '.$workOrder->work_order_no,
        ]);
        $quantity = number_format($qty, 8, '.', '');
        $cost = CuttingDecimal::share((string) $line->cost_amount, number_format((float) $line->qty, 8, '.', ''), $quantity);
        $balance = InventoryBalance::query()->where('item_id', $line->component_item_id)->where('warehouse_id', $line->warehouse_id)
            ->where('location_id', $line->location_id)->where('batch_no', $line->batch_no)->lockForUpdate()->firstOrFail();
        $item = $this->kernel->apply($transaction, [
            'item_id' => $line->component_item_id, 'warehouse_id' => $line->warehouse_id, 'location_id' => $line->location_id, 'batch_no' => $line->batch_no,
            'unit_id' => $balance->unit_id, 'change_qty' => '-'.$quantity, 'unit_cost' => (float) $line->unit_cost, 'cost_amount' => bcsub('0', $cost, 4),
            'material_lot_id' => $balance->material_lot_id, 'cost_source_type' => 'production_return_original_cost',
            'source_type' => 'mfg_material_return_disposition', 'source_id' => $disposition->id, 'source_item_id' => $line->id,
            'remark' => '不良退料报废（退料单行 #'.$line->id.'）',
        ]);
        if ($serial) {
            $serial->update(['serial_status' => 'scrapped', 'outbound_at' => now()]);
            $this->defectSerialEvent($serial, 'production_defect_scrapped', 'scrapped', $disposition, $workOrder, $operatorId);
        }
        InventoryPostingLog::create([
            'source_type' => 'mfg_material_return_disposition', 'source_id' => $disposition->id, 'source_no' => $disposition->document_no,
            'transaction_type' => 'production_defect_scrap_outbound', 'transaction_id' => $transaction->id,
            'posting_status' => 'posted', 'message' => '不良退料报废出库成功', 'posted_by' => $operatorId, 'posted_at' => now(),
        ]);
        $disposition->update(['inventory_transaction_id' => $transaction->id, 'cost_amount' => bcsub('0', (string) $item->cost_amount, 4)]);
        return $transaction;
    }

    /** 冻结/解冻不良数量：库存行和库位汇总同步增减，可用数量随之重算。 */
    private function changeDefective(int $itemId, int $warehouseId, int $locationId, string $batchNo, float $change): void
    {
        $this->kernel->changeStatusQuantity(['item_id' => $itemId, 'warehouse_id' => $warehouseId, 'location_id' => $locationId, 'batch_no' => $batchNo],
            'quantity_defective', $change, 'disposition', '库存不良数量不足，可能已被其他处置使用，请刷新后重试。');
    }

    private function defectSerialEvent(InventorySerial $serial, string $type, string $to, \App\Models\Erp\MfgMaterialReturnDisposition $disposition, \App\Models\Erp\MfgWorkOrder $workOrder, ?int $operatorId): void
    {
        InventorySerialEvent::create([
            'inventory_serial_id' => $serial->id, 'event_type' => $type, 'document_type' => 'mfg_material_return_disposition',
            'document_id' => $disposition->id, 'document_no' => $disposition->document_no, 'from_status' => 'quality_hold', 'to_status' => $to,
            'warehouse_id' => $serial->warehouse_id, 'location_id' => $serial->location_id, 'batch_no' => $serial->batch_no, 'operator_id' => $operatorId,
            'event_payload' => ['work_order_no' => $workOrder->work_order_no, 'remark' => $disposition->remark], 'occurred_at' => now(),
        ]);
    }

    /** 开料整根／整张领入：只接受已锁定批次保存的真实来源，不开放普通数量接口扣板。 */
    public function postManufacturingCuttingIssue(\App\Models\Erp\MfgCuttingBatch $batch, \App\Models\Erp\MfgWorkOrder $workOrder, ?int $operatorId = null): array
    {
        return DB::transaction(function () use ($batch, $workOrder, $operatorId) {
            $batch = \App\Models\Erp\MfgCuttingBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ((int) $batch->work_order_id !== (int) $workOrder->id || $batch->status !== 'issued' || $batch->inventory_issue_transaction_id) {
                throw ValidationException::withMessages(['cutting' => '开料批次已领入或不属于当前工单。']);
            }
            $transaction = $this->kernel->begin([
                'transaction_type' => 'mfg_cutting_issue_outbound',
                'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id, 'source_no' => $batch->batch_no,
                'posting_status' => 'posted', 'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId, 'posted_at' => now(), 'created_by' => $operatorId,
                'remark' => '工单 '.$workOrder->work_order_no.' 开料领入',
            ]);
            $lines = [];
            foreach ($batch->input_lines as $input) {
                $balance = InventoryBalance::query()->lockForUpdate()->findOrFail($input['inventory_balance_id']);
                $item = Item::query()->lockForUpdate()->findOrFail($input['item_id']);
                $qty = CuttingDecimal::value($input['qty']);
                $validBalance = $this->availability->outboundBalanceQuery([(int) $item->id])->where('erp_inventory_balances.id', $balance->id)->exists();
                $available = min((float) $balance->quantity_available, $this->availability->calculate((float) $balance->quantity_on_hand,
                    (float) $balance->quantity_locked, (float) $balance->quantity_defective, (float) $balance->quantity_pending));
                $lot = $balance->material_lot_id ? DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->lockForUpdate()->first() : null;
                if (! $validBalance || (int) $balance->item_id !== (int) $item->id || $available + 0.00000001 < (float) $qty
                    || ($balance->material_lot_id && ! $lot)
                    || ($lot && ((int) $lot->item_id !== (int) $item->id || $lot->configuration_id || $lot->stage_id || ! in_array($lot->material_form, ['FULL_STOCK', 'STANDARD_LENGTH', 'REMNANT'], true)))) {
                    throw ValidationException::withMessages(['sources' => '所选开料来源不可用或数量已变化，请重新选料。']);
                }
                $physical = null;
                if ($item->materialManagementMode() === 'physical') {
                    $physical = $this->physicalMaterials->assertWarehousePhysical((int) ($input['physical_material_id'] ?? 0), (int) $item->id, (int) $balance->id);
                    $this->physicalMaterials->assertPhysicalNotInOpenDocument((int) $physical->id);
                    if (bccomp($qty, '1', 8) !== 0 || bccomp((string) $physical->total_cost, (string) $input['cost_amount'], 4) !== 0) {
                        throw ValidationException::withMessages(['sources' => '开料板材身份、整张数量或来源成本不一致。']);
                    }
                    $cost = (string) $physical->total_cost;
                } else {
                    if ($input['physical_material_id'] ?? null) throw ValidationException::withMessages(['sources' => '普通管材来源不能冒用板材实物身份。']);
                    if ($item->serialTrackingMode() !== 'none') throw ValidationException::withMessages(['sources' => '按序列号管理的原料须先绑定具体序列号，当前开料入口不能按普通数量耗用。']);
                    $cost = CuttingDecimal::share((string) $balance->inventory_value, (string) $balance->quantity_on_hand, $qty);
                    if (bccomp($cost, (string) $input['cost_amount'], 4) !== 0) throw ValidationException::withMessages(['sources' => '开料来源成本已变化，请重新确认。']);
                }
                $fact = $this->kernel->apply($transaction, [
                    'item_id' => $item->id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
                    'batch_no' => $balance->batch_no, 'unit_id' => $balance->unit_id, 'material_lot_id' => $balance->material_lot_id,
                    'change_qty' => bcsub('0', $qty, 8), 'cost_amount' => bcsub('0', $cost, 4),
                    'unit_cost' => bcdiv($cost, $qty, 8), 'cost_source_type' => $physical ? 'mfg_cutting_physical_total' : 'mfg_cutting_average',
                    'physical_operation' => $physical ? 'mfg_cutting_issue' : null, 'physical_material_id' => $physical?->id,
                    'cutting_source_key' => $input['source_key'], 'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id,
                    'remark' => '开料来源 '.$input['source_key'],
                ]);
                $lines[$input['source_key']] = ['transaction_item_id' => (int) $fact->id, 'cost_amount' => bcsub('0', (string) $fact->cost_amount, 4)];
            }
            InventoryPostingLog::create([
                'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id, 'source_no' => $batch->batch_no,
                'transaction_type' => $transaction->transaction_type, 'transaction_id' => $transaction->id,
                'posting_status' => 'posted', 'message' => '开料原料领入成功', 'posted_by' => $operatorId, 'posted_at' => now(),
            ]);
            $batch->update(['inventory_issue_transaction_id' => $transaction->id]);
            return ['transaction' => $transaction, 'lines' => $lines];
        }, 5);
    }

    /** 只退回人工确认的真实余料；净耗留在制造成本，禁止把整张／整根成本再记一次。 */
    public function postManufacturingCuttingSettlement(\App\Models\Erp\MfgCuttingBatch $batch, \App\Models\Erp\MfgWorkOrder $workOrder, ?int $operatorId = null): array
    {
        return DB::transaction(function () use ($batch, $workOrder, $operatorId) {
            $batch = \App\Models\Erp\MfgCuttingBatch::query()->lockForUpdate()->findOrFail($batch->id);
            if ((int) $batch->work_order_id !== (int) $workOrder->id || $batch->status !== 'issued'
                || ! $batch->inventory_issue_transaction_id || $batch->inventory_return_transaction_id) {
                throw ValidationException::withMessages(['cutting' => '这张开料批次不能再次结算。']);
            }
            $positive = collect($batch->remnants)->filter(fn ($row) => bccomp((string) $row['measure_qty'], '0', 8) > 0);
            if ($positive->isEmpty()) return ['transaction' => null, 'lines' => []];
            $transaction = $this->kernel->begin([
                'transaction_type' => 'mfg_cutting_remnant_inbound',
                'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id, 'source_no' => $batch->batch_no,
                'posting_status' => 'posted', 'transaction_date' => now()->toDateString(),
                'posted_by' => $operatorId, 'posted_at' => now(), 'created_by' => $operatorId,
                'remark' => '工单 '.$workOrder->work_order_no.' 开料余料入库',
            ]);
            $inputs = collect($batch->input_lines)->keyBy('source_key');
            $lines = [];
            foreach ($positive as $index => $remnant) {
                $input = $inputs->get($remnant['source_key']);
                if (! $input) throw ValidationException::withMessages(['remnants' => '余料缺少真实开料来源。']);
                $item = Item::query()->lockForUpdate()->findOrFail($input['item_id']);
                $lotId = DB::table('erp_material_lots')->insertGetId([
                    'lot_no' => app(DocumentNumberService::class)->next('material_lot', 'ML'), 'item_id' => $item->id,
                    'material_form' => 'REMNANT', 'cut_length_mm' => $input['mode'] === 'length' ? bcmul($remnant['measure_qty'], '1000', 2) : null,
                    'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id, 'parent_lot_id' => $input['material_lot_id'] ?: null,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                $qty = in_array($input['unit_kind'], ['root', 'sheet'], true) ? '1.00000000' : $remnant['measure_qty'];
                $batchNo = $batch->batch_no.'-R'.($index + 1);
                $fact = $this->kernel->apply($transaction, [
                    'item_id' => $item->id, 'warehouse_id' => $remnant['warehouse_id'], 'location_id' => $remnant['location_id'],
                    'batch_no' => $batchNo, 'unit_id' => $input['unit_id'], 'material_lot_id' => $lotId,
                    'change_qty' => $qty, 'cost_amount' => $remnant['cost_amount'], 'unit_cost' => bcdiv($remnant['cost_amount'], $qty, 8),
                    'cost_source_type' => 'mfg_cutting_remnant', 'physical_operation' => $input['mode'] === 'sheet' ? 'mfg_cutting_remnant' : null,
                    'physical_material_id' => $input['physical_material_id'], 'cutting_source_key' => $input['source_key'],
                    'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id, 'remark' => '开料余料来源 '.$input['source_key'],
                ]);
                $balance = InventoryBalance::query()->where('item_id', $item->id)->where('warehouse_id', $remnant['warehouse_id'])
                    ->where('location_id', $remnant['location_id'])->where('batch_no', $batchNo)->lockForUpdate()->firstOrFail();
                $physicalId = null;
                if ($input['mode'] === 'sheet') {
                    $physicalId = $this->physicalMaterials->createManufacturingRemnant($batch, $input, $remnant, $balance, $fact, $operatorId ?? 0);
                }
                $lines[$input['source_key']] = ['transaction_item_id' => (int) $fact->id, 'inventory_balance_id' => (int) $balance->id,
                    'material_lot_id' => $lotId, 'batch_no' => $batchNo, 'physical_material_id' => $physicalId, 'qty' => $qty];
            }
            InventoryPostingLog::create([
                'source_type' => 'mfg_cutting_batch', 'source_id' => $batch->id, 'source_no' => $batch->batch_no,
                'transaction_type' => $transaction->transaction_type, 'transaction_id' => $transaction->id,
                'posting_status' => 'posted', 'message' => '开料余料入库成功', 'posted_by' => $operatorId, 'posted_at' => now(),
            ]);
            $batch->update(['inventory_return_transaction_id' => $transaction->id]);
            return ['transaction' => $transaction, 'lines' => $lines];
        }, 5);
    }
}
