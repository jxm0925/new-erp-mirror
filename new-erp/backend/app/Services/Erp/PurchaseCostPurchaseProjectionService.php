<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 退货只重估出库成本；换货沿原品实际出库继续传递，原退款、应付及票税事实保留。 */
class PurchaseCostPurchaseProjectionService
{
    public function deriveMovementCosts(array $costs): array
    {
        [$derived] = $this->project($costs);
        return $derived;
    }

    public function plannedChanges(array $costs): array
    {
        [, $updates] = $this->project($costs);
        return $updates;
    }

    public function apply(array $costs): array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Purchase cost projection requires a transaction.');
        [, $updates] = $this->project($costs);
        foreach ($updates as $update) DB::table($update['table'])->where('id', $update['id'])->update($update['after']);
        return $updates;
    }

    private function project(array $costs): array
    {
        if ($costs === []) return [[], []];
        $movements = DB::table('erp_inventory_transaction_items')->whereIntegerInRaw('id', array_keys($costs))
            ->whereIn('source_type', ['purchase_return', 'purchase_receipt', 'inventory_quality_event', PurchaseReceiptCostAdjustmentService::SOURCE])
            ->orderBy('id')->lockForUpdate()->get();
        $changed = $movements->filter(fn ($row) => bccomp((string) $row->cost_amount, (string) $costs[$row->id], 4) !== 0);
        if ($changed->isEmpty()) return [[], []];
        $updates = []; $derived = [];
        $returnIds = [];
        foreach ($changed->where('source_type', 'purchase_return')->pluck('source_item_id')->unique() as $id) {
            $line = DB::table('erp_purchase_return_items')->where('id', $id)->lockForUpdate()->first();
            if (! $line) $this->fail('采购退货成本缺少对应原明细。');
            $outbounds = $this->postedMovements('purchase_return', (int) $line->return_id)->where('source_item_id', $id);
            $amount = '0'; $qty = '0';
            foreach ($outbounds as $out) {
                if (bccomp((string) $out->change_qty, '0', 8) >= 0) $this->fail('采购退货出库方向不一致。');
                $amount = bcsub($amount, (string) ($costs[$out->id] ?? $out->cost_amount), 4);
                $qty = bcsub($qty, (string) $out->change_qty, 8);
            }
            if (bccomp($qty, (string) $line->posted_base_qty, 8) !== 0 || bccomp($qty, '0', 8) <= 0)
                $this->fail('采购退货出库数量与原明细不一致。');
            $this->stage($updates, 'erp_purchase_return_items', $line,
                ['inventory_cost_amount' => $amount, 'unit_cost_snapshot' => bcdiv($amount, $qty, 8)]);
            $returnIds[] = (int) $line->return_id;
        }
        foreach (array_unique($returnIds) as $id) {
            $document = DB::table('erp_purchase_returns')->where('id', $id)->lockForUpdate()->first();
            $sum = '0';
            foreach (DB::table('erp_purchase_return_items')->where('return_id', $id)->orderBy('id')->lockForUpdate()->get() as $line)
                $sum = bcadd($sum, $updates['erp_purchase_return_items:'.$line->id]['after']['inventory_cost_amount'] ?? $line->inventory_cost_amount, 4);
            $this->stage($updates, 'erp_purchase_returns', $document, ['cost_amount' => $sum]);
        }

        $receiptLineIds = $changed->whereIn('source_type', ['purchase_receipt', PurchaseReceiptCostAdjustmentService::SOURCE])->pluck('source_item_id')->unique();
        $qualityIds = $changed->where('source_type', 'inventory_quality_event')->pluck('source_id')->unique();
        $exchanges = DB::table('erp_purchase_exchange_orders')->where('exchange_status', '!=', 'cancelled')
            ->where(fn ($query) => $query->whereIn('source_receipt_item_id', $receiptLineIds)->orWhereIn('inventory_quality_event_id', $qualityIds))
            ->orderBy('id')->lockForUpdate()->get();
        foreach ($exchanges as $exchange) {
            $delta = '0';
            $outbounds = $exchange->inventory_quality_event_id
                ? $this->postedMovements('inventory_quality_event', (int) $exchange->inventory_quality_event_id) : collect();
            if ($outbounds->isNotEmpty()) {
                $qty = '0'; $postedCost = '0';
                foreach ($outbounds as $out) {
                    if ((int) $out->source_item_id !== (int) $exchange->source_receipt_item_id || (int) $out->item_id !== (int) $exchange->item_id
                        || bccomp((string) $out->change_qty, '0', 8) >= 0) $this->fail('采购换货原品出库来源或方向不一致。');
                    $qty = bcsub($qty, (string) $out->change_qty, 8);
                    $postedCost = bcsub($postedCost, (string) ($costs[$out->id] ?? $out->cost_amount), 4);
                    $delta = bcsub($delta, bcsub((string) ($costs[$out->id] ?? $out->cost_amount), (string) $out->cost_amount, 4), 4);
                }
                if (bccomp($qty, (string) $exchange->exchange_base_qty, 8) !== 0) $this->fail('采购换货原品出库数量不一致。');
                $replacementCost = $postedCost;
            } else {
                $inbounds = $this->postedMovements('purchase_receipt', (int) $exchange->source_receipt_id)
                    ->where('source_item_id', $exchange->source_receipt_item_id);
                $qty = '0'; $sourceDelta = '0'; $total = '0';
                foreach ($inbounds as $in) {
                    $qty = bcadd($qty, (string) $in->change_qty, 8);
                    $total = bcadd($total, (string) ($costs[$in->id] ?? $in->cost_amount), 4);
                    $sourceDelta = bcadd($sourceDelta, bcsub((string) ($costs[$in->id] ?? $in->cost_amount), (string) $in->cost_amount, 4), 4);
                }
                foreach ($movements->where('source_type', PurchaseReceiptCostAdjustmentService::SOURCE)->where('source_item_id', $exchange->source_receipt_item_id) as $oldCorrection) {
                    $total = bcadd($total, (string) $costs[$oldCorrection->id], 4);
                    $sourceDelta = bcadd($sourceDelta, bcsub((string) $costs[$oldCorrection->id], (string) $oldCorrection->cost_amount, 4), 4);
                }
                $delta = $sourceDelta;
                if (bccomp($delta, '0', 4) !== 0) $replacementCost = CuttingDecimal::share($total, $qty, (string) $exchange->exchange_base_qty);
            }
            if (bccomp($delta, '0', 4) === 0) continue;
            if (bccomp($replacementCost, '0', 4) < 0) $this->fail('换货成本重算后为负，请核对原品成本来源。');
            $this->stage($updates, 'erp_purchase_exchange_orders', $exchange, ['replacement_inventory_cost' => $replacementCost]);
            if (! $exchange->replacement_receipt_id) continue;
            $receipt = DB::table('erp_purchase_receipts')->where('id', $exchange->replacement_receipt_id)->lockForUpdate()->first();
            $lines = DB::table('erp_purchase_receipt_items')->where('receipt_id', $exchange->replacement_receipt_id)->orderBy('id')->lockForUpdate()->get();
            if (! $receipt || $receipt->settlement_mode !== 'replacement_no_charge' || $lines->count() !== 1)
                $this->fail('换货替换到货来源不完整，不能推测替换品成本。');
            $line = $lines->first();
            $base = (string) ($line->original_received_base_qty ?: $line->actual_base_qty ?: $line->standard_base_qty ?: $line->receipt_qty);
            if ((int) $line->item_id !== (int) $exchange->item_id || bccomp($base, (string) $exchange->exchange_base_qty, 8) !== 0)
                $this->fail('换货替换到货与原品物料或数量不一致。');
            $stockQty = (string) $line->final_stockable_base_qty;
            $stockCost = bccomp($stockQty, '0', 8) > 0 ? CuttingDecimal::share($replacementCost, $base, $stockQty) : '0.0000';
            $this->stage($updates, 'erp_purchase_receipt_items', $line, ['inventory_cost_amount' => $stockCost]);
            $this->stage($updates, 'erp_purchase_receipts', $receipt, ['inventory_cost_amount' => $stockCost]);
            $inbounds = $this->postedMovements('purchase_receipt', (int) $receipt->id)->where('source_item_id', $line->id);
            if ($receipt->stock_post_status !== 'posted') continue;
            $leftQty = $stockQty; $leftCost = $stockCost;
            foreach ($inbounds as $in) {
                if (bccomp((string) $in->change_qty, '0', 8) <= 0) $this->fail('换货替换品入库方向不一致。');
                $derived[$in->id] = CuttingDecimal::share($leftCost, $leftQty, (string) $in->change_qty);
                $leftQty = bcsub($leftQty, (string) $in->change_qty, 8);
                $leftCost = bcsub($leftCost, $derived[$in->id], 4);
            }
            if (bccomp($leftQty, '0', 8) !== 0 || bccomp($leftCost, '0', 4) !== 0) $this->fail('换货替换品入库流水不完整。');
        }
        return [$derived, array_values($updates)];
    }

    private function postedMovements(string $type, int $id)
    {
        return DB::table('erp_inventory_transaction_items as m')->join('erp_inventory_transactions as t', 't.id', '=', 'm.transaction_id')
            ->where('m.source_type', $type)->where('m.source_id', $id)->where('t.posting_status', 'posted')
            ->orderBy('m.id')->lockForUpdate()->get(['m.*']);
    }

    private function stage(array &$updates, string $table, object $row, array $values): void
    {
        $before = []; $after = [];
        foreach ($values as $field => $value) if (bccomp((string) $row->{$field}, $value, str_contains($field, 'unit_cost') ? 8 : 4) !== 0) {
            $before[$field] = $row->{$field}; $after[$field] = $value;
        }
        if ($after) $updates[$table.':'.$row->id] = ['table' => $table, 'id' => (int) $row->id, 'before' => $before, 'after' => $after];
    }

    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['actual_amount' => $message]);
    }
}
