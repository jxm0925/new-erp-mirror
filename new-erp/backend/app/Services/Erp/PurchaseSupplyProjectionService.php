<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * 采购供应投影：每种物料还在路上的数量，按“需求→计划→订单→到货待入库→换货待补”分段，同一数量只算在当前所处的一段。
 * 不合格待处理（让步/退货未定）不计入；数量统一为基本单位。
 */
final class PurchaseSupplyProjectionService
{
    public const STAGES = ['request' => '采购需求', 'plan' => '采购计划', 'order' => '采购订单', 'receipt' => '到货待入库', 'exchange' => '换货待补'];
    private const CLOSED = ['cancelled', 'closed', 'rejected', 'void', 'voided'];
    private const EPS = 0.00001;

    /** 每种物料保留全量分段，区分备货、工单专属、销售订单专属、历史商品行专属及归属不明。 */
    public function project(array $itemIds): array
    {
        $itemIds = array_values(array_unique(array_filter(array_map('intval', $itemIds))));
        if (! $itemIds) return [];
        $requests = DB::table('erp_purchase_request_items as i')->join('erp_purchase_requests as r', 'r.id', '=', 'i.request_id')
            ->whereNull('r.deleted_at')->whereNotIn('r.request_status', self::CLOSED)->whereNotIn('i.line_status', self::CLOSED)
            ->whereIn('i.item_id', $itemIds)->where('i.remaining_qty', '>', 0)
            ->get(['i.id', 'i.request_id', 'i.item_id', 'r.request_no', 'i.remaining_qty']);

        $planItems = DB::table('erp_purchase_plan_items as i')->join('erp_purchase_plans as p', 'p.id', '=', 'i.plan_id')
            ->whereNotIn('p.plan_status', self::CLOSED)->whereNotIn('i.line_status', self::CLOSED)->whereIn('i.item_id', $itemIds)
            ->get(['i.id', 'i.item_id', 'i.request_id', 'i.request_item_id', 'i.plan_qty', 'p.plan_no']);
        $splits = $planItems->isEmpty() ? collect() : DB::table('erp_purchase_plan_supplier_splits')->whereIn('plan_item_id', $planItems->pluck('id')->all())
            ->where(fn ($q) => $q->whereNull('status')->orWhereNotIn('status', self::CLOSED))
            ->get(['plan_item_id', 'order_id', 'split_status', 'purchase_qty', 'purchase_conversion_snapshot'])->groupBy('plan_item_id');
        $orders = DB::table('erp_purchase_order_items as i')->join('erp_purchase_orders as o', 'o.id', '=', 'i.order_id')
            ->whereNotIn('o.purchase_status', self::CLOSED)->whereNotIn('i.line_status', [...self::CLOSED, 'received'])->where('i.remaining_qty', '>', 0)
            ->whereIn('i.item_id', $itemIds)
            ->selectRaw('i.id, i.item_id, o.purchase_order_no, i.remaining_qty * CASE WHEN i.conversion_factor_snapshot > 0 THEN i.conversion_factor_snapshot ELSE 1 END AS qty')
            ->get();
        // 补发单的来源沿换货单回到原到货行，不能因补发单没有采购订单行而失去归属。
        $receipts = DB::table('erp_purchase_receipt_items as i')->join('erp_purchase_receipts as r', 'r.id', '=', 'i.receipt_id')
            ->leftJoin('erp_purchase_exchange_orders as e', fn ($join) => $join->on('e.replacement_receipt_id', '=', 'r.id')->on('e.item_id', '=', 'i.item_id'))
            ->leftJoin('erp_purchase_receipt_items as source', 'source.id', '=', 'e.source_receipt_item_id')
            ->where('r.receipt_status', 'confirmed')->where('r.stock_post_status', 'pending')->where('i.is_stock_item_snapshot', 1)
            ->whereIn('i.item_id', $itemIds)
            ->selectRaw('i.id, i.item_id, r.receipt_no, COALESCE(source.order_item_id, i.order_item_id) AS order_item_id,
                e.source_receipt_item_id, COALESCE(i.final_stockable_base_qty, i.qualified_base_qty, 0) AS qty')
            ->get();
        $exchanges = DB::table('erp_purchase_exchange_orders as e')->leftJoin('erp_purchase_receipts as r', 'r.id', '=', 'e.replacement_receipt_id')
            ->leftJoin('erp_purchase_receipt_items as source', 'source.id', '=', 'e.source_receipt_item_id')
            ->whereNotIn('e.exchange_status', ['completed', 'cancelled'])->whereIn('e.item_id', $itemIds)
            ->selectRaw("e.id, e.item_id, e.exchange_no, e.source_receipt_item_id, source.order_item_id, e.exchange_base_qty,
                e.replacement_received_base_qty AS accepted_qty,
                CASE WHEN r.receipt_status = 'confirmed' THEN COALESCE(r.physical_received_base_qty, 0) ELSE 0 END AS arrived_qty")
            ->get();

        // 上游单据即使已转完/关闭，仍是下游在途的归属证据；只加载本次在途实际引用的行。
        $orderIds = $orders->pluck('id')->merge($receipts->pluck('order_item_id'))->merge($exchanges->pluck('order_item_id'))->filter()->unique();
        $orderSources = DB::table('erp_purchase_order_items as i')->join('erp_purchase_orders as o', 'o.id', '=', 'i.order_id')
            ->whereIn('i.id', $orderIds)->get(['i.id', 'i.item_id', 'i.request_id', 'i.request_item_id', 'i.plan_id', 'i.plan_item_id',
                'i.conversion_factor_snapshot', 'o.plan_id as order_plan_id', 'o.source_type', 'o.source_no', 'o.data_source'])->keyBy('id');
        $planIds = $planItems->pluck('id')->merge($orderSources->pluck('plan_item_id'))->filter()->unique();
        $planSources = DB::table('erp_purchase_plan_items as i')->join('erp_purchase_plans as p', 'p.id', '=', 'i.plan_id')
            ->whereIn('i.id', $planIds)->get(['i.id', 'i.item_id', 'i.request_id', 'i.request_item_id', 'p.data_source'])->keyBy('id');
        $requestIds = $requests->pluck('request_id')->merge($planSources->pluck('request_id'))->merge($orderSources->pluck('request_id'))->filter()->unique();
        $requestItemIds = $requests->pluck('id')->merge($planSources->pluck('request_item_id'))->merge($orderSources->pluck('request_item_id'))->filter()->unique();
        $requestSources = DB::table('erp_purchase_request_items as i')->join('erp_purchase_requests as r', 'r.id', '=', 'i.request_id')
            ->whereIn('i.item_id', $itemIds)->where(fn ($q) => $q->whereIn('i.request_id', $requestIds)
                ->orWhereIn('i.request_id', DB::table('erp_purchase_request_items')->whereIn('id', $requestItemIds)->select('request_id')))
            ->get(['i.id', 'i.request_id', 'i.item_id', 'i.request_qty', 'r.source_type', 'r.source_id', 'r.source_no'])->keyBy('id');
        $links = DB::table('erp_mfg_purchase_demand_links')->whereIn('purchase_request_id', $requestSources->pluck('request_id')->unique())
            ->get(['purchase_request_id', 'purchase_request_item_id', 'item_id', 'work_order_id', 'qty'])->groupBy('purchase_request_id');
        $salesLinks = Schema::hasTable('erp_purchase_request_sales_links')
            ? DB::table('erp_purchase_request_sales_links')->whereIn('purchase_request_item_id', $requestSources->keys())
                ->where('status', 'active')->orderBy('id')->get()->groupBy('purchase_request_item_id')
            : collect();
        $sales = DB::table('erp_sales_order_purchase_links')->whereIn('purchase_order_item_id', $orderIds)->where('status', 'active')
            ->selectRaw('purchase_order_item_id, SUM(purchase_qty) AS qty')->groupBy('purchase_order_item_id')->pluck('qty', 'purchase_order_item_id');
        $requestGroups = $requestSources->groupBy(fn ($r) => $r->request_id.'|'.$r->item_id);
        $requestScopes = []; $budgets = [];
        foreach ($requestSources as $r) {
            $allLinks = $links->get($r->request_id, collect());
            $group = $requestGroups->get($r->request_id.'|'.$r->item_id);
            $groupQty = (float) $group->sum('request_qty');
            $owners = [];
            foreach ($allLinks->where('item_id', $r->item_id) as $link) {
                if ($link->purchase_request_item_id && (int) $link->purchase_request_item_id !== (int) $r->id) continue;
                // 编辑需求会重建明细；失去行ID的旧关联按同单同物料的明细数量摊回，不能每行重复认领整笔。
                $qty = max(0, (float) $link->qty);
                if (! $link->purchase_request_item_id) {
                    $before = (float) $group->where('id', '<', $r->id)->sum('request_qty');
                    $qty = $groupQty > self::EPS ? round($qty * ($before + (float) $r->request_qty) / $groupQty, 4) - round($qty * $before / $groupQty, 4) : 0;
                }
                $owners[(int) $link->work_order_id] = ($owners[(int) $link->work_order_id] ?? 0) + $qty;
            }
            $salesOwners = [];
            foreach ($salesLinks->get($r->id, collect()) as $link) {
                $lineId = (int) $link->sales_order_item_id;
                $salesOwners[$lineId] = ['sales_order_id' => (int) $link->sales_order_id, 'sales_order_item_id' => $lineId,
                    'qty' => ($salesOwners[$lineId]['qty'] ?? 0) + max(0, (float) $link->qty),
                    'source_snapshot' => is_string($link->source_snapshot) ? json_decode($link->source_snapshot, true) : (array) $link->source_snapshot];
            }
            // 整单销售来源以单头为准；旧需求没有单头ID时仍沿历史商品行关联，不能把同一材料额度投影两次。
            if ($r->source_type === 'sales' && ctype_digit((string) $r->source_id) && (int) $r->source_id > 0) {
                $owners = [];
                $salesOwners = [0 => ['sales_order_id' => (int) $r->source_id, 'sales_order_item_id' => null,
                    'qty' => max(0, (float) $r->request_qty), 'source_snapshot' => ['sales_order_no' => $r->source_no]]];
            }
            $base = max((float) $r->request_qty, array_sum($owners) + array_sum(array_column($salesOwners, 'qty')));
            $key = 'request|'.$r->id;
            $budgets[$key] = ['owners' => $owners, 'sales' => array_map(fn ($sale) => $sale['qty'], $salesOwners)];
            $requestScopes[$r->id] = ['key' => $key, 'request_id' => (int) $r->request_id, 'request_item_id' => (int) $r->id,
                'shared' => $allLinks->isEmpty() && ! $salesOwners && $r->source_type === 'manual' && ! trim((string) $r->source_id) && ! trim((string) $r->source_no),
                'owners' => $base > self::EPS ? array_map(fn ($q) => $q / $base, $owners) : [],
                'sales' => $base > self::EPS ? array_map(fn ($s) => ['ratio' => $s['qty'] / $base] + $s, $salesOwners) : []];
        }
        $unknown = ['shared' => false, 'owners' => []];
        $requestScope = function ($itemId, $requestItemId, $requestId) use ($requestScopes, $requestSources, $requestGroups, $unknown) {
            if ($requestItemId) return isset($requestSources[$requestItemId]) && (int) $requestSources[$requestItemId]->item_id === (int) $itemId
                && (! $requestId || (int) $requestSources[$requestItemId]->request_id === (int) $requestId) ? $requestScopes[$requestItemId] : $unknown;
            $group = $requestGroups->get($requestId.'|'.$itemId, collect());
            return $group->count() === 1 ? $requestScopes[$group->first()->id] : $unknown;
        };
        $planScopes = [];
        foreach ($planSources as $p) $planScopes[$p->id] = $p->request_item_id || $p->request_id
            ? $requestScope($p->item_id, $p->request_item_id, $p->request_id)
            : ['shared' => $p->data_source === 'manual', 'owners' => []];
        $orderScopes = [];
        foreach ($orderSources as $o) {
            $scope = $o->request_item_id || $o->request_id ? $requestScope($o->item_id, $o->request_item_id, $o->request_id)
                : ($o->plan_item_id ? (isset($planSources[$o->plan_item_id]) && (int) $planSources[$o->plan_item_id]->item_id === (int) $o->item_id ? $planScopes[$o->plan_item_id] : $unknown)
                    : ['shared' => ! $o->plan_id && ! $o->order_plan_id && in_array($o->source_type, [null, '', 'manual'], true)
                        && ! trim((string) $o->source_no) && $o->data_source === 'manual', 'owners' => []]);
            // 销售关联没有指定每批到货归属；有有效关联的整行均不共享，避免猜测剩余段属于哪一单。
            if (isset($sales[$o->id])) $scope = $unknown + ['sales_linked_base_qty' => (float) $sales[$o->id] * ((float) $o->conversion_factor_snapshot > 0 ? (float) $o->conversion_factor_snapshot : 1)];
            $orderScopes[$o->id] = $scope;
        }

        $out = [];
        $add = function (int $itemId, string $stage, string $no, int $lineId, float $qty, array $scope, array $trace = []) use (&$out, &$budgets) {
            $qty = round($qty, 4);
            if ($qty <= self::EPS) return;
            $owners = []; $remaining = $qty;
            // 多工单合并采购未逐到货行指定工单，五段统一按关联量占需求量比例分摊；跨段共用关联额度，不将结束工单份额转成备货。
            $shares = array_map(fn ($ratio) => round($qty * $ratio, 4), $scope['owners']);
            if ($shares) $shares[array_key_last($shares)] += round($qty * array_sum($scope['owners']), 4) - array_sum($shares);
            foreach ($shares as $woId => $share) {
                $take = round(min($remaining, max(0, $share), max(0, $budgets[$scope['key']]['owners'][$woId] ?? 0)), 4);
                $owners[$woId] = $take; $remaining = round($remaining - $take, 4); $budgets[$scope['key']]['owners'][$woId] -= $take;
            }
            // 销售商品台数不参与原料换算；这里的关联量本身就是该需求物料的基本单位，跨五段共用同一额度。
            $salesAllocations = [];
            $salesShares = array_map(fn ($sale) => round($qty * $sale['ratio'], 4), $scope['sales'] ?? []);
            if ($salesShares) $salesShares[array_key_last($salesShares)] += round($qty * array_sum(array_column($scope['sales'], 'ratio')), 4) - array_sum($salesShares);
            foreach ($scope['sales'] ?? [] as $salesLineId => $sale) {
                $take = round(min($remaining, max(0, $salesShares[$salesLineId]), max(0, $budgets[$scope['key']]['sales'][$salesLineId] ?? 0)), 4);
                $remaining = round($remaining - $take, 4); $budgets[$scope['key']]['sales'][$salesLineId] -= $take;
                if ($take > self::EPS) $salesAllocations[] = ['qty' => $take] + array_diff_key($sale, ['ratio' => true]);
            }
            $shared = $scope['shared'] ? $remaining : 0.0;
            $document = ['stage' => $stage, 'stage_label' => self::STAGES[$stage], 'document_no' => $no, 'line_id' => $lineId, 'qty' => $qty,
                'request_id' => $scope['request_id'] ?? null, 'request_item_id' => $scope['request_item_id'] ?? null,
                'work_order_qty' => $owners, 'sales_allocations' => $salesAllocations, 'shared_qty' => $shared,
                'unassigned_qty' => $remaining - $shared] + $trace;
            if (isset($scope['sales_linked_base_qty'])) $document['sales_linked_base_qty'] = $scope['sales_linked_base_qty'];
            $row = &$out[$itemId];
            $this->addDocument($row, $document);
            if ($shared > self::EPS) $this->addDocument($row['shared'], ['qty' => $shared] + $document);
            foreach ($owners as $woId => $take) if ($take > self::EPS) $this->addDocument($row['work_orders'][$woId], ['qty' => $take] + $document);
            foreach ($salesAllocations as $sale) {
                $saleDocument = ['qty' => $sale['qty'], 'sales_allocations' => [$sale]] + $document;
                $this->addDocument($row['sales_orders'][$sale['sales_order_id']], $saleDocument);
                if ($sale['sales_order_item_id']) $this->addDocument($row['sales_order_items'][$sale['sales_order_item_id']], $saleDocument);
            }
            if ($remaining - $shared > self::EPS) $this->addDocument($row['unassigned'], ['qty' => $remaining - $shared] + $document);
        };
        foreach ($requests as $r) $add((int) $r->item_id, 'request', (string) $r->request_no, (int) $r->id, (float) $r->remaining_qty, $requestScopes[$r->id] ?? $unknown);
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
            $add((int) $p->item_id, 'plan', (string) $p->plan_no, (int) $p->id, $waiting + max(0, (float) $p->plan_qty - $split), $planScopes[$p->id] ?? $unknown);
        }

        foreach ($orders as $r) $add((int) $r->item_id, 'order', (string) $r->purchase_order_no, (int) $r->id, (float) $r->qty, $orderScopes[$r->id] ?? $unknown, ['order_item_id' => (int) $r->id]);

        // 已确认到货、还没过账入库的合格（可入库）数量（含换货补发的到货单；过账后由库存承接）。
        foreach ($receipts as $r) $add((int) $r->item_id, 'receipt', (string) $r->receipt_no, (int) $r->id, (float) $r->qty,
            isset($orderSources[$r->order_item_id]) && (int) $orderSources[$r->order_item_id]->item_id === (int) $r->item_id ? $orderScopes[$r->order_item_id] : $unknown,
            ['order_item_id' => $r->order_item_id ? (int) $r->order_item_id : null, 'source_receipt_item_id' => $r->source_receipt_item_id ? (int) $r->source_receipt_item_id : null]);

        // 换货待补：补发到货单确认后（不论是否已过账、是否已最终验收）就交给到货段/库存，不再算换货在途。
        foreach ($exchanges as $e) $add((int) $e->item_id, 'exchange', (string) $e->exchange_no, (int) $e->id,
            max(0, (float) $e->exchange_base_qty - max((float) $e->accepted_qty, (float) $e->arrived_qty)),
            isset($orderSources[$e->order_item_id]) && (int) $orderSources[$e->order_item_id]->item_id === (int) $e->item_id ? $orderScopes[$e->order_item_id] : $unknown,
            ['order_item_id' => $e->order_item_id ? (int) $e->order_item_id : null, 'source_receipt_item_id' => (int) $e->source_receipt_item_id]);

        foreach ($out as &$row) {
            $this->roundProjection($row);
            foreach (['shared', 'unassigned'] as $scope) if (isset($row[$scope])) $this->roundProjection($row[$scope]);
            foreach (array_keys($row['work_orders'] ?? []) as $woId) $this->roundProjection($row['work_orders'][$woId]);
            foreach (['sales_orders', 'sales_order_items'] as $group) foreach (array_keys($row[$group] ?? []) as $id) $this->roundProjection($row[$group][$id]);
        }
        return $out;
    }

    private function addDocument(?array &$row, array $document): void
    {
        $row['total'] = ($row['total'] ?? 0) + $document['qty'];
        $row['stages'][$document['stage']] = ($row['stages'][$document['stage']] ?? 0) + $document['qty'];
        $row['documents'][] = $document;
    }

    private function roundProjection(array &$row): void
    {
        $row['total'] = round($row['total'], 4);
        $row['stages'] = array_map(fn ($q) => round($q, 4), $row['stages']);
        foreach ($row['documents'] as &$document) {
            foreach (['qty', 'shared_qty', 'unassigned_qty', 'sales_linked_base_qty'] as $key) if (isset($document[$key])) $document[$key] = round($document[$key], 4);
            $document['work_order_qty'] = array_map(fn ($q) => round($q, 4), $document['work_order_qty']);
        }
    }
}
