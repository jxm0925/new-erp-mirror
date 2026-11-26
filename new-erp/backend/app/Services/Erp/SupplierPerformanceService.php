<?php

namespace App\Services\Erp;

use App\Models\Erp\SupplierItemStat;
use Illuminate\Support\Facades\DB;

class SupplierPerformanceService
{
    public function refresh(int $supplierId, int $itemId): SupplierItemStat
    {
        $orders = DB::table('erp_purchase_order_items as poi')
            ->join('erp_purchase_orders as po', 'po.id', '=', 'poi.order_id')
            ->where('po.supplier_id', $supplierId)
            ->where('poi.item_id', $itemId)
            ->whereNotIn('po.purchase_status', ['cancelled'])
            ->selectRaw('COUNT(DISTINCT po.id) total_order_count, COALESCE(SUM(poi.order_qty),0) total_order_qty, COALESCE(SUM(poi.amount),0) total_order_amount')
            ->first();

        // 确认时冻结的含税成交额与实际基本数量才可跨采购单位汇总，采购单价不能直接平均。
        $receivedBaseQty = 'COALESCE(pri.actual_base_qty, pri.standard_base_qty)';
        // 旧行缺含税快照时，未税成交额须补原税额（缺税额再按税率算），运费沿冻结规则只加一次。
        $receivedAmount = "COALESCE(pri.amount_incl_tax, pri.receipt_cost + pri.freight_allocated_amount
            + CASE WHEN COALESCE(pri.tax_mode_snapshot, pr.tax_mode_snapshot) = 'tax_excluded'
                THEN COALESCE(pri.tax_amount_snapshot, ROUND(pri.receipt_cost * pri.tax_rate / 100,4)) ELSE 0 END)";
        $receiptRows = DB::table('erp_purchase_receipt_items as pri')
            ->join('erp_purchase_receipts as pr', 'pr.id', '=', 'pri.receipt_id')
            ->leftJoin('erp_purchase_order_items as poi', 'poi.id', '=', 'pri.order_item_id')
            ->where('pr.supplier_id', $supplierId)
            ->where('pri.item_id', $itemId)
            ->where('pr.confirm_status', 'confirmed')
            ->groupBy('pr.id')
            ->selectRaw("pr.id, SUM({$receivedBaseQty}) total_received_qty, SUM({$receivedAmount}) total_received_amount,
                SUM(pri.qualified_base_qty) total_qualified_base_qty, SUM(pri.unqualified_base_qty) total_unqualified_base_qty,
                MAX(pr.updated_at) last_receipt_at, COUNT(poi.expected_arrival_date) dated_line_count,
                SUM(CASE WHEN poi.expected_arrival_date IS NOT NULL
                    AND (pr.receipt_date IS NULL OR pr.receipt_date > poi.expected_arrival_date) THEN 1 ELSE 0 END) late_line_count");
        // 同一到货单同物料有多行时，只计一单；有交期的任一行迟到，整单就不能计为准时。
        $receipts = DB::query()->fromSub($receiptRows, 'receipt_stats')
            ->selectRaw('COUNT(*) total_receipt_count, COALESCE(SUM(total_received_qty),0) total_received_qty,
                COALESCE(SUM(total_received_amount) / NULLIF(SUM(total_received_qty),0),0) avg_price,
                COALESCE(SUM(total_qualified_base_qty),0) total_qualified_base_qty,
                COALESCE(SUM(total_unqualified_base_qty),0) total_unqualified_base_qty,
                MAX(last_receipt_at) last_receipt_at,
                COALESCE(SUM(CASE WHEN dated_line_count > 0 THEN 1 ELSE 0 END),0) dated_receipt_count,
                COALESCE(SUM(CASE WHEN dated_line_count > 0 AND late_line_count = 0 THEN 1 ELSE 0 END),0) on_time_receipt_count')
            ->first();

        $latestPrice = DB::table('erp_purchase_receipt_items as pri')
            ->join('erp_purchase_receipts as pr', 'pr.id', '=', 'pri.receipt_id')
            ->where('pr.supplier_id', $supplierId)
            ->where('pri.item_id', $itemId)
            ->where('pr.confirm_status', 'confirmed')
            ->orderByDesc('pr.updated_at')
            ->orderByDesc('pr.id')
            ->orderByDesc('pri.id')
            ->selectRaw("COALESCE({$receivedAmount} / NULLIF({$receivedBaseQty},0),0) base_unit_price")
            ->first()?->base_unit_price ?? 0;

        $returns = DB::table('erp_purchase_return_items as preti')
            ->join('erp_purchase_returns as pret', 'pret.id', '=', 'preti.return_id')
            ->where('pret.supplier_id', $supplierId)
            ->where('preti.item_id', $itemId)
            ->where('pret.return_status', 'completed')
            ->selectRaw('COUNT(DISTINCT pret.id) return_count, COALESCE(SUM(preti.approved_base_qty),0) total_return_base_qty, MAX(pret.updated_at) last_return_at')
            ->first();

        $receiptCount = (int) ($receipts->total_receipt_count ?? 0);
        $datedReceiptCount = (int) ($receipts->dated_receipt_count ?? 0);
        $onTimeCount = (int) ($receipts->on_time_receipt_count ?? 0);
        $totalReceivedBase = (float) ($receipts->total_received_qty ?? 0);
        $qualified = (float) ($receipts->total_qualified_base_qty ?? 0);
        $unqualified = (float) ($receipts->total_unqualified_base_qty ?? 0);
        $receivedBase = $qualified + $unqualified;
        $returned = (float) ($returns->total_return_base_qty ?? 0);

        return SupplierItemStat::updateOrCreate(
            ['supplier_id' => $supplierId, 'item_id' => $itemId],
            [
                'total_order_count' => (int) ($orders->total_order_count ?? 0),
                'total_order_qty' => (float) ($orders->total_order_qty ?? 0),
                'total_order_amount' => (float) ($orders->total_order_amount ?? 0),
                'total_receipt_count' => $receiptCount,
                'on_time_receipt_count' => $onTimeCount,
                'total_received_qty' => $totalReceivedBase,
                'total_qualified_base_qty' => $qualified,
                'total_unqualified_base_qty' => $unqualified,
                'last_price' => $latestPrice,
                'avg_price' => $receipts->avg_price ?? 0,
                'last_receipt_at' => $receipts->last_receipt_at ?? null,
                'return_count' => (int) ($returns->return_count ?? 0),
                'total_return_base_qty' => $returned,
                'last_return_at' => $returns->last_return_at ?? null,
                'on_time_rate' => $datedReceiptCount > 0 ? round($onTimeCount / $datedReceiptCount, 4) : null,
                'qualified_rate' => $receivedBase > 0 ? round($qualified / $receivedBase, 4) : null,
                'return_rate' => $receivedBase > 0 ? round($returned / $receivedBase, 4) : null,
                'data_source' => 'system',
            ],
        );
    }
}
