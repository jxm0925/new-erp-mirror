<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

/**
 * 采购供应投影：每种物料还在路上的数量，按“需求→计划→订单→到货待入库→换货待补”分段，同一数量只算在当前所处的一段。
 * 不合格待处理（让步/退货未定）不计入；数量统一为基本单位。
 */
final class PurchaseSupplyProjectionService
{
    public const STAGES = ['request' => '采购需求', 'plan' => '采购计划', 'order' => '采购订单', 'receipt' => '到货待入库', 'exchange' => '换货待补'];
    private const CLOSED = ['cancelled', 'closed', 'rejected', 'void', 'voided'];
    private const EPS = 0.00001;

    /** @return array<int, array{total: float, stages: array<string, float>, documents: array<int, array>}> */
    public function project(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (! $itemIds) return [];
        $out = [];
        $add = function (int $itemId, string $stage, string $no, float $qty) use (&$out) {
            if ($qty <= self::EPS) return;
            $row = &$out[$itemId];
            $row['total'] = ($row['total'] ?? 0) + $qty;
            $row['stages'][$stage] = ($row['stages'][$stage] ?? 0) + $qty;
            $key = $stage.'|'.$no;
            $row['documents'][$key] = ['stage' => $stage, 'stage_label' => self::STAGES[$stage], 'document_no' => $no,
                'qty' => ($row['documents'][$key]['qty'] ?? 0) + $qty];
        };

        foreach (DB::table('erp_purchase_request_items as i')->join('erp_purchase_requests as r', 'r.id', '=', 'i.request_id')
            ->whereNull('r.deleted_at')->whereNotIn('r.request_status', self::CLOSED)->whereNotIn('i.line_status', self::CLOSED)
            ->whereIn('i.item_id', $itemIds)->where('i.remaining_qty', '>', 0)
            ->get(['i.item_id', 'r.request_no', 'i.remaining_qty']) as $r) $add((int) $r->item_id, 'request', (string) $r->request_no, (float) $r->remaining_qty);

        $planItems = DB::table('erp_purchase_plan_items as i')->join('erp_purchase_plans as p', 'p.id', '=', 'i.plan_id')
            ->whereNotIn('p.plan_status', self::CLOSED)->whereNotIn('i.line_status', self::CLOSED)->whereIn('i.item_id', $itemIds)
            ->get(['i.id', 'i.item_id', 'i.plan_qty', 'p.plan_no']);
        $splits = $planItems->isEmpty() ? collect() : DB::table('erp_purchase_plan_supplier_splits')->whereIn('plan_item_id', $planItems->pluck('id')->all())
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', self::CLOSED))
            ->get(['plan_item_id', 'order_id', 'split_status', 'purchase_qty', 'purchase_conversion_snapshot'])->groupBy('plan_item_id');
        foreach ($planItems as $p) {
            $split = 0.0;
            $waiting = 0.0;
            foreach ($splits->get($p->id, collect()) as $s) {
                $snap = is_string($s->purchase_conversion_snapshot) ? json_decode($s->purchase_conversion_snapshot, true) : (array) $s->purchase_conversion_snapshot;
                $base = (float) (($snap['planned_base_qty'] ?? null) ?? $s->purchase_qty);
                $split += $base;
                // 已下单的拆分由订单段计算，这里只算还没下单的。
                if (! $s->order_id && $s->split_status !== 'ordered') $waiting += $base;
            }
            $add((int) $p->item_id, 'plan', (string) $p->plan_no, $waiting + max(0, (float) $p->plan_qty - $split));
        }

        foreach (DB::table('erp_purchase_order_items as i')->join('erp_purchase_orders as o', 'o.id', '=', 'i.order_id')
            ->whereNotIn('o.purchase_status', self::CLOSED)->whereNotIn('i.line_status', [...self::CLOSED, 'received'])->where('i.remaining_qty', '>', 0)
            ->whereIn('i.item_id', $itemIds)
            ->selectRaw('i.item_id, o.purchase_order_no, i.remaining_qty * CASE WHEN i.conversion_factor_snapshot > 0 THEN i.conversion_factor_snapshot ELSE 1 END AS qty')
            ->get() as $r) $add((int) $r->item_id, 'order', (string) $r->purchase_order_no, (float) $r->qty);

        // 已确认到货、还没过账入库的合格（可入库）数量（含换货补发的到货单；过账后由库存承接）。
        foreach (DB::table('erp_purchase_receipt_items as i')->join('erp_purchase_receipts as r', 'r.id', '=', 'i.receipt_id')
            ->where('r.receipt_status', 'confirmed')->where('r.stock_post_status', 'pending')->where('i.is_stock_item_snapshot', 1)
            ->whereIn('i.item_id', $itemIds)
            ->selectRaw('i.item_id, r.receipt_no, COALESCE(i.final_stockable_base_qty, i.qualified_base_qty, 0) AS qty')
            ->get() as $r) $add((int) $r->item_id, 'receipt', (string) $r->receipt_no, (float) $r->qty);

        // 换货待补：补发到货单确认后（不论是否已过账、是否已最终验收）就交给到货段/库存，不再算换货在途。
        foreach (DB::table('erp_purchase_exchange_orders as e')->leftJoin('erp_purchase_receipts as r', 'r.id', '=', 'e.replacement_receipt_id')
            ->whereNotIn('e.exchange_status', ['completed', 'cancelled'])->whereIn('e.item_id', $itemIds)
            ->selectRaw("e.item_id, e.exchange_no, e.exchange_base_qty, e.replacement_received_base_qty AS accepted_qty,
                CASE WHEN r.receipt_status = 'confirmed' THEN COALESCE(r.physical_received_base_qty, 0) ELSE 0 END AS arrived_qty")
            ->get() as $e)
            $add((int) $e->item_id, 'exchange', (string) $e->exchange_no,
                max(0, (float) $e->exchange_base_qty - max((float) $e->accepted_qty, (float) $e->arrived_qty)));

        foreach ($out as &$row) {
            $row['total'] = round($row['total'], 4);
            $row['stages'] = array_map(fn ($q) => round($q, 4), $row['stages']);
            $row['documents'] = array_values(array_map(fn ($d) => ['qty' => round($d['qty'], 4)] + $d, $row['documents']));
        }
        return $out;
    }
}
