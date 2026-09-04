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

        $receipts = DB::table('erp_purchase_receipt_items as pri')
            ->join('erp_purchase_receipts as pr', 'pr.id', '=', 'pri.receipt_id')
            ->leftJoin('erp_purchase_order_items as poi', 'poi.id', '=', 'pri.order_item_id')
            ->where('pr.supplier_id', $supplierId)
            ->where('pri.item_id', $itemId)
            ->where('pr.confirm_status', 'confirmed')
            ->selectRaw('COUNT(DISTINCT pr.id) total_receipt_count, COALESCE(SUM(pri.receipt_qty),0) total_received_qty, COALESCE(SUM(pri.qualified_base_qty),0) total_qualified_base_qty, COALESCE(SUM(pri.unqualified_base_qty),0) total_unqualified_base_qty, COALESCE(AVG(NULLIF(pri.unit_price,0)),0) avg_price, MAX(pr.updated_at) last_receipt_at, COUNT(DISTINCT CASE WHEN poi.expected_arrival_date IS NULL OR pr.receipt_date <= poi.expected_arrival_date THEN pr.id END) on_time_receipt_count')
            ->first();

        $latestPrice = DB::table('erp_purchase_receipt_items as pri')
            ->join('erp_purchase_receipts as pr', 'pr.id', '=', 'pri.receipt_id')
            ->where('pr.supplier_id', $supplierId)
            ->where('pri.item_id', $itemId)
            ->where('pr.confirm_status', 'confirmed')
            ->orderByDesc('pr.updated_at')
            ->value('pri.unit_price') ?? 0;

        $returns = DB::table('erp_purchase_return_items as preti')
            ->join('erp_purchase_returns as pret', 'pret.id', '=', 'preti.return_id')
            ->where('pret.supplier_id', $supplierId)
            ->where('preti.item_id', $itemId)
            ->where('pret.return_status', 'completed')
            ->selectRaw('COUNT(DISTINCT pret.id) return_count, COALESCE(SUM(preti.approved_base_qty),0) total_return_base_qty, MAX(pret.updated_at) last_return_at')
            ->first();

        $receiptCount = (int) ($receipts->total_receipt_count ?? 0);
        $onTimeCount = (int) ($receipts->on_time_receipt_count ?? 0);
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
                'total_received_qty' => (float) ($receipts->total_received_qty ?? 0),
                'total_qualified_base_qty' => $qualified,
                'total_unqualified_base_qty' => $unqualified,
                'last_price' => (float) $latestPrice,
                'avg_price' => (float) ($receipts->avg_price ?? 0),
                'last_receipt_at' => $receipts->last_receipt_at ?? null,
                'return_count' => (int) ($returns->return_count ?? 0),
                'total_return_base_qty' => $returned,
                'last_return_at' => $returns->last_return_at ?? null,
                'on_time_rate' => $receiptCount > 0 ? round($onTimeCount / $receiptCount, 4) : null,
                'qualified_rate' => $receivedBase > 0 ? round($qualified / $receivedBase, 4) : null,
                'return_rate' => $receivedBase > 0 ? round($returned / $receivedBase, 4) : null,
                'data_source' => 'system',
            ],
        );
    }
}
