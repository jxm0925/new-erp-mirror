<?php

namespace App\Services\Erp;

use App\Models\Erp\{ItemCategory, PurchaseOrder, PurchaseOrderItem, PurchasePlan, SalesOrder, SalesOrderPurchaseLink};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;

class SalesOrderFinanceQueryService
{
    public function visibleOrders(object $user, bool $includeShared = false): Builder
    {
        $query = SalesOrder::query();
        app(SalesOrderVisibilityService::class)->apply($query, $user, $includeShared);
        return $query;
    }

    public function candidates(array $filters)
    {
        $query = PurchaseOrderItem::query()->with(['order.supplier', 'item', 'purchaseUnit'])
            ->whereHas('order', function (Builder $q): void {
                $q->where('audit_status', 'approved')->whereNotIn('purchase_status', ['cancelled', 'voided']);
                $this->factoryPurchaseDocument($q);
            })
            ->whereHas('item', fn ($q) => $q->where('management_scope', 'factory'))
            ->select('erp_purchase_order_items.*')->selectSub(
                SalesOrderPurchaseLink::selectRaw('COALESCE(SUM(purchase_qty),0)')
                    ->whereColumn('purchase_order_item_id', 'erp_purchase_order_items.id')->where('status', 'active'), 'assigned_purchase_qty');
        if (!empty($filters['purchase_order_item_id'])) $query->where('erp_purchase_order_items.id', (int) $filters['purchase_order_item_id']);
        if ($keyword = trim($filters['keyword'] ?? '')) $query->where(function ($q) use ($keyword): void {
            $q->whereHas('order', fn ($o) => $o->where('purchase_order_no', 'like', '%'.$keyword.'%')
                ->orWhereHas('supplier', fn ($s) => $s->where('supplier_name', 'like', '%'.$keyword.'%')))
                ->orWhere('spec_model', 'like', '%'.$keyword.'%')
                ->orWhereHas('item', fn ($i) => $i->where('item_code', 'like', '%'.$keyword.'%')->orWhere('item_name', 'like', '%'.$keyword.'%')->orWhere('spec', 'like', '%'.$keyword.'%')->orWhere('model', 'like', '%'.$keyword.'%'));
        });
        if (!empty($filters['category_id'])) {
            $ids = [(int) $filters['category_id']];
            $categories = $this->categories();
            if (! $categories->contains('id', $ids[0])) $ids = [];
            do {
                $previous = $ids;
                $ids = array_values(array_unique([...$ids, ...$categories->whereIn('parent_id', $ids)->pluck('id')->all()]));
            } while (count($previous) !== count($ids));
            $query->whereHas('item', fn ($q) => $q->whereIn('category_id', $ids));
        }
        $page = $query->orderByDesc('id')->paginate($filters['per_page'] ?? 10);
        $page->getCollection()->transform(fn ($row) => [
            'id' => $row->id, 'purchase_order_id' => $row->order_id, 'order_no' => $row->order->purchase_order_no,
            'supplier_name' => $row->order->supplier?->supplier_name, 'item_code' => $row->item?->item_code,
            'item_name' => $row->item?->item_name, 'spec_model' => $row->spec_model ?: ($row->item?->spec ?: $row->item?->model),
            'purchase_unit_name' => $row->purchaseUnit?->unit_name, 'purchase_qty' => $row->purchase_qty,
            'available_purchase_qty' => bcsub((string) $row->purchase_qty, (string) $row->assigned_purchase_qty, 8),
        ]);
        return $page;
    }

    public function categories()
    {
        return ItemCategory::where('category_type', 'item')->where('management_scope', 'factory')
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'parent_id', 'category_name']);
    }

    private function factoryPurchaseDocument(Builder $query): void
    {
        $query->where($query->getModel()->qualifyColumn('management_scope'), 'factory')->whereHas('items')
            ->whereDoesntHave('items', fn (Builder $lines) => $lines->whereDoesntHave('item', fn (Builder $item) => $item->where('management_scope', 'factory')));
        $model = $query->getModel();
        $sources = [];
        if ($model instanceof PurchasePlan || $model instanceof PurchaseOrder) {
            $sources = ['request_id' => 'request', 'request_item_id' => 'requestItem.request'];
        }
        if ($model instanceof PurchaseOrder) {
            $sources += ['plan_id' => 'plan', 'plan_item_id' => 'planItem.plan'];
            $query->where(fn (Builder $head) => $head->whereNull($model->qualifyColumn('plan_id'))
                ->orWhereHas('plan', fn (Builder $plan) => $this->factoryPurchaseDocument($plan)));
        }
        if ($sources === []) return;
        // Match the write guard's linked request/plan ownership before pagination,
        // including source heads reached through a source line ID.
        $query->whereDoesntHave('items', function (Builder $lines) use ($sources): void {
            $lines->where(function (Builder $invalid) use ($sources): void {
                foreach ($sources as $column => $relation) {
                    $invalid->orWhere(fn (Builder $source) => $source->whereNotNull($source->getModel()->qualifyColumn($column))
                        ->whereDoesntHave($relation, fn (Builder $head) => $this->factoryPurchaseDocument($head)));
                }
            });
        });
    }

    public function overview(SalesOrder $order): array
    {
        $shipments = DB::table('erp_sales_shipments')->where('sales_order_id', $order->id)
            ->whereIn('shipment_status', ['outbound_posted', 'partially_shipped', 'shipped', 'completed']);
        return [
            'sales_order_id' => $order->id, 'sales_order_no' => $order->sales_order_no,
            'order_status' => $order->order_status,
            'version' => (int) $order->purchase_link_version, 'currency' => $order->currency,
            'settlement' => app(SalesFinanceSettlementService::class)->status($order),
            'purchase_order_count' => $this->purchaseOrderSources()->where('sales_order_id', $order->id)->distinct()->count('purchase_order_id'),
            'posted_shipment_count' => (clone $shipments)->count(),
        ];
    }

    public function links(int $orderId, int $perPage)
    {
        $query = DB::table('erp_sales_order_purchase_links')->where('sales_order_id', $orderId)
            ->selectRaw("'purchase_order' AS source_type, id, created_at");
        if (Schema::hasTable('erp_purchase_request_sales_links')) {
            $query->unionAll(DB::table('erp_purchase_request_sales_links')->where('sales_order_id', $orderId)
                ->selectRaw("'purchase_request' AS source_type, id, created_at"));
        }
        $query->unionAll($this->salesRequestItems()->where('r.source_id', $orderId)
            ->selectRaw("'request_header_item' AS source_type, i.id, r.created_at"));
        $page = DB::query()->fromSub($query, 'links')->orderByDesc('created_at')->orderByDesc('id')->orderBy('source_type')->paginate($perPage);
        $legacy = SalesOrderPurchaseLink::whereIn('id', $page->getCollection()->where('source_type', 'purchase_order')->pluck('id'))->get()->keyBy('id');
        $requestIds = $page->getCollection()->where('source_type', 'purchase_request')->pluck('id');
        $requests = $requestIds->isEmpty() ? collect() : DB::table('erp_purchase_request_sales_links as l')
            ->join('erp_purchase_requests as r', 'r.id', '=', 'l.purchase_request_id')
            ->leftJoin('erp_purchase_request_items as i', 'i.id', '=', 'l.purchase_request_item_id')
            ->leftJoin('erp_units as u', 'u.id', '=', 'i.unit_id')->whereIn('l.id', $requestIds)
            ->get(['l.*', 'r.request_no', 'r.request_status', 'r.deleted_at as request_deleted_at',
                'i.item_id', 'i.item_code', 'i.item_name', 'i.spec_model', 'i.request_qty', 'u.unit_name'])->keyBy('id');
        $headerItems = $this->salesRequestItems()->whereIn('i.id', $page->getCollection()->where('source_type', 'request_header_item')->pluck('id'))
            ->leftJoin('erp_units as u', 'u.id', '=', 'i.unit_id')
            ->get(['i.*', 'r.source_id as sales_order_id', 'r.request_no', 'r.request_status', 'r.created_by', 'r.created_at as request_created_at', 'u.unit_name'])->keyBy('id');
        $projection = app(PurchaseSupplyProjectionService::class)->project($requests->where('status', 'active')->pluck('item_id')->merge($headerItems->pluck('item_id'))->filter()->all());
        $requestItemIds = $requests->where('status', 'active')->pluck('purchase_request_item_id')->merge($headerItems->keys())->filter();
        $orderSources = $requestItemIds->isEmpty() ? collect() : $this->purchaseOrderSources()
            ->join('erp_purchase_orders as o', 'o.id', '=', 'purchase_sources.purchase_order_id')->where('purchase_sources.sales_order_id', $orderId)
            ->whereIn('request_item_id', $requestItemIds)
            ->distinct()->orderBy('o.id')->get(['purchase_sources.request_item_id', 'o.id as purchase_order_id', 'o.purchase_order_no'])->groupBy('request_item_id');
        $page->getCollection()->transform(function ($row) use ($legacy, $requests, $headerItems, $projection, $orderSources) {
            if ($row->source_type === 'purchase_order') return $this->linkPayload($legacy[$row->id]);
            if ($row->source_type === 'request_header_item') {
                $item = $headerItems[$row->id];
                $active = $item->request_status !== 'cancelled';
                $documents = $active ? array_values(array_filter(
                    $projection[$item->item_id]['sales_orders'][$item->sales_order_id]['documents'] ?? [],
                    fn ($d) => (int) $d['request_item_id'] === (int) $item->id)) : [];
                $stages = array_fill_keys(array_keys(PurchaseSupplyProjectionService::STAGES), 0.0);
                foreach ($documents as $document) $stages[$document['stage']] = round($stages[$document['stage']] + $document['qty'], 4);
                return ['id' => (int) $item->id, 'source_type' => 'purchase_request', 'sales_order_id' => (int) $item->sales_order_id,
                    'sales_order_item_id' => null, 'purchase_request_id' => (int) $item->request_id, 'purchase_request_item_id' => (int) $item->id,
                    'purchase_qty' => $item->request_qty, 'status' => $active ? 'active' : 'reversed', 'reason' => '整张采购需求归属本销售订单',
                    'created_by' => $item->created_by, 'created_at' => $item->request_created_at, 'request_status' => $item->request_status,
                    'reverse_reason' => $active ? null : '采购需求已取消',
                    'order_sources' => $orderSources->get($item->id, collect())->values()->all(),
                    'source_snapshot' => ['order_no' => $item->request_no, 'request_no' => $item->request_no,
                        'item_id' => $item->item_id, 'item_code' => $item->item_code, 'item_name' => $item->item_name,
                        'spec_model' => $item->spec_model, 'purchase_unit_name' => $item->unit_name, 'base_unit_name' => $item->unit_name,
                        'source_request_qty' => $item->request_qty],
                    'progress' => ['total' => round(array_sum($stages), 4), 'stages' => $stages, 'documents' => $documents]];
            }
            $link = $requests[$row->id];
            $snapshot = is_string($link->source_snapshot) ? (json_decode($link->source_snapshot, true) ?: []) : (array) $link->source_snapshot;
            $documents = $link->status === 'active' ? array_values(array_filter(
                $projection[$link->item_id]['sales_order_items'][$link->sales_order_item_id]['documents'] ?? [],
                fn ($d) => (int) $d['request_item_id'] === (int) $link->purchase_request_item_id)) : [];
            $stages = array_fill_keys(array_keys(PurchaseSupplyProjectionService::STAGES), 0.0);
            foreach ($documents as $document) $stages[$document['stage']] = round($stages[$document['stage']] + $document['qty'], 4);
            return ['id' => (int) $link->id, 'source_type' => 'purchase_request', 'sales_order_id' => (int) $link->sales_order_id,
                'sales_order_item_id' => (int) $link->sales_order_item_id, 'purchase_request_id' => (int) $link->purchase_request_id,
                'purchase_request_item_id' => $link->purchase_request_item_id ? (int) $link->purchase_request_item_id : null,
                'purchase_qty' => $link->qty, 'status' => $link->status, 'created_by' => $link->created_by,
                'created_at' => $link->created_at, 'updated_at' => $link->updated_at, 'reversed_by' => $link->reversed_by, 'reversed_at' => $link->reversed_at,
                'request_status' => $link->request_deleted_at ? 'deleted' : $link->request_status,
                'order_sources' => $link->status === 'active' ? $orderSources->get($link->purchase_request_item_id, collect())->values()->all() : [],
                'source_snapshot' => ['order_no' => $link->request_no, 'request_no' => $link->request_no,
                    'item_id' => $link->item_id ?? $snapshot['item_id'] ?? null, 'item_code' => $link->item_code ?? $snapshot['item_code'] ?? null,
                    'item_name' => $link->item_name ?? $snapshot['item_name'] ?? null, 'spec_model' => $link->spec_model ?? $snapshot['spec_model'] ?? null,
                    'purchase_unit_name' => $link->unit_name ?? $snapshot['base_unit_name'] ?? null,
                    'base_unit_name' => $link->unit_name ?? $snapshot['base_unit_name'] ?? null,
                    'source_request_qty' => $link->request_qty ?? $snapshot['source_request_qty'] ?? null] + $snapshot,
                'progress' => ['total' => round(array_sum($stages), 4), 'stages' => $stages, 'documents' => $documents]];
        });
        $this->attachStored($page->getCollection());
        return $page;
    }

    /**
     * 采购在途投影只到“到货待入库”，正式入库后单据就退出在途；这里补上已入库的结果，
     * 让销售能看到订的货到没到仓库。只返回数量、入库单号和时间，不返回任何价格。
     * 一条采购需求行被多张销售订单分摊时，入库量无法逐单区分，按本单归属数量封顶并标记 shared。
     */
    private function attachStored($rows): void
    {
        $active = $rows->where('status', 'active');
        $requestItemIds = $active->where('source_type', 'purchase_request')->pluck('purchase_request_item_id')->filter()->unique()->values()->all();
        $orderItemIds = $active->where('source_type', 'purchase_order')->pluck('purchase_order_item_id')->filter()->unique()->values()->all();
        $posted = fn () => DB::table('erp_purchase_receipt_items as ri')->join('erp_purchase_receipts as r', 'r.id', '=', 'ri.receipt_id')
            ->leftJoin('erp_inventory_transactions as tx', fn ($join) => $join->on('tx.source_id', '=', 'r.id')
                ->where('tx.source_type', 'purchase_receipt')->where('tx.transaction_type', 'purchase_receipt_posting'))
            ->where('r.stock_post_status', 'posted')->where('ri.is_stock_item_snapshot', 1)->orderBy('r.id');
        $byRequestItem = $requestItemIds ? $posted()->join('erp_purchase_order_items as oi', 'oi.id', '=', 'ri.order_item_id')
            ->leftJoin('erp_purchase_plan_items as p', 'p.id', '=', 'oi.plan_item_id')->whereColumn('ri.item_id', 'oi.item_id')
            ->whereIn(DB::raw('COALESCE(oi.request_item_id, p.request_item_id)'), $requestItemIds)
            ->selectRaw('COALESCE(oi.request_item_id, p.request_item_id) AS source_key, r.receipt_no, tx.posted_at, COALESCE(ri.final_stockable_base_qty, ri.qualified_base_qty, 0) AS qty')
            ->get()->groupBy('source_key') : collect();
        $byOrderItem = $orderItemIds ? $posted()->whereIn('ri.order_item_id', $orderItemIds)
            ->selectRaw('ri.order_item_id AS source_key, r.receipt_no, tx.posted_at, COALESCE(ri.final_stockable_base_qty, ri.qualified_base_qty, 0) AS qty')
            ->get()->groupBy('source_key') : collect();
        $owners = $requestItemIds && Schema::hasTable('erp_purchase_request_sales_links') ? DB::table('erp_purchase_request_sales_links')
            ->whereIn('purchase_request_item_id', $requestItemIds)->where('status', 'active')
            ->selectRaw('purchase_request_item_id, COUNT(DISTINCT sales_order_id) AS owners')->groupBy('purchase_request_item_id')->pluck('owners', 'purchase_request_item_id') : collect();
        $rows->transform(function (array $row) use ($byRequestItem, $byOrderItem, $owners): array {
            $receipts = $row['status'] !== 'active' ? collect() : ($row['source_type'] === 'purchase_order'
                ? $byOrderItem->get($row['purchase_order_item_id'] ?? 0, collect()) : $byRequestItem->get($row['purchase_request_item_id'] ?? 0, collect()));
            $total = round((float) $receipts->sum('qty'), 4);
            $owned = (float) ($row['purchase_qty'] ?? 0);
            $row['stored'] = [
                'qty' => $owned > 0 ? min($total, $owned) : $total, 'total_qty' => $total,
                'shared' => (int) ($owners[$row['purchase_request_item_id'] ?? 0] ?? 0) > 1,
                'documents' => $receipts->map(fn ($r) => ['receipt_no' => $r->receipt_no, 'posted_at' => $r->posted_at, 'qty' => round((float) $r->qty, 4)])->values()->all(),
            ];
            return $row;
        });
    }

    /** Internal attribution prices remain stored; sales sees only the quantity relationship. */
    public function linkPayload(SalesOrderPurchaseLink $link): array
    {
        $data = Arr::only($link->toArray(), ['id', 'sales_order_id', 'purchase_order_id', 'purchase_order_item_id', 'purchase_qty',
            'status', 'reason', 'created_by', 'created_at', 'updated_at', 'reverse_reason', 'reversed_by', 'reversed_at']);
        $data['source_snapshot'] = Arr::only((array) $link->source_snapshot, ['order_no', 'supplier_id', 'supplier_name', 'item_id', 'item_code',
            'item_name', 'spec_model', 'purchase_unit_id', 'purchase_unit_name', 'source_purchase_qty']);
        $data['source_type'] = 'purchase_order';
        return $data;
    }

    public function purchasePayments(int $orderId, int $perPage)
    {
        // Full PO facts are shown once even when several items are linked. They are never charged wholesale to this sales order.
        return DB::table('erp_finance_cash_purchase_allocations as purpose')
            ->join('erp_finance_cash_documents as cash', 'cash.id', '=', 'purpose.cash_document_id')
            ->whereIn('purpose.purchase_order_id', SalesOrderPurchaseLink::where('sales_order_id', $orderId)->where('status', 'active')->select('purchase_order_id'))
            ->where('purpose.status', 'active')->where('cash.status', 'confirmed')
            ->select('purpose.id', 'purpose.purchase_order_id', 'purpose.purchase_order_no_snapshot as order_no_snapshot', 'purpose.payment_plan_id', 'purpose.sequence_no_snapshot', 'purpose.trigger_type_snapshot', 'purpose.plan_title_snapshot', 'purpose.amount', 'purpose.currency', 'purpose.direction',
                'cash.id as cash_document_id', 'cash.document_no', 'cash.business_date')
            ->orderByDesc('cash.business_date')->orderByDesc('purpose.id')->paginate($perPage);
    }

    public function statistics(object $user, array $filters): array
    {
        $orders = $this->visibleOrders($user, true);
        if ($keyword = trim($filters['keyword'] ?? '')) $orders->where(fn ($q) => $q->where('sales_order_no', 'like', '%'.$keyword.'%')->orWhere('customer_name', 'like', '%'.$keyword.'%'));
        if (!empty($filters['currency'])) $orders->where('currency', $filters['currency']);
        if (!empty($filters['order_status'])) $orders->where('order_status', $filters['order_status']);
        if (!empty($filters['order_date_start'])) $orders->whereDate('order_date', '>=', $filters['order_date_start']);
        if (!empty($filters['order_date_end'])) $orders->whereDate('order_date', '<=', $filters['order_date_end']);
        if (($filters['purchase_link_status'] ?? '') === 'linked') $orders->whereIn('id', $this->activeSalesLinks()->select('sales_order_id'));
        if (($filters['purchase_link_status'] ?? '') === 'unlinked') $orders->whereNotIn('id', $this->activeSalesLinks()->select('sales_order_id'));
        $linked = $this->activeSalesLinks()->whereIn('sales_order_id', (clone $orders)->select('id'))
            ->selectRaw('COUNT(*) AS link_count, COUNT(DISTINCT sales_order_id) AS linked_order_count')->first();
        $purchaseOrderCount = $this->purchaseOrderSources()->whereIn('sales_order_id', (clone $orders)->select('id'))->distinct()->count('purchase_order_id');
        $page = $orders->orderByDesc('id')->paginate($filters['per_page'] ?? 10);
        $page->getCollection()->transform(function ($order): array {
            $overview = $this->overview($order);
            return [...$overview, 'customer_name' => $order->customer_name, 'order_date' => $order->order_date?->toDateString(), 'order_status' => $order->order_status];
        });
        return [...$page->toArray(), 'summary' => ['order_count' => $page->total(), 'linked_order_count' => (int) $linked->linked_order_count,
            'purchase_order_count' => $purchaseOrderCount, 'link_count' => (int) $linked->link_count],
            'summary_scope' => '全部符合筛选条件的订单；采购关联仅统计单据数量。'];
    }

    private function activeSalesLinks()
    {
        $query = DB::table('erp_sales_order_purchase_links')->where('status', 'active')->select('sales_order_id');
        if (Schema::hasTable('erp_purchase_request_sales_links')) {
            $query->unionAll(DB::table('erp_purchase_request_sales_links')->where('status', 'active')->select('sales_order_id'));
        }
        $query->unionAll(DB::table('erp_purchase_requests')->where('source_type', 'sales')->whereNotNull('source_id')
            ->whereNull('deleted_at')->where('request_status', '<>', 'cancelled')->select('source_id as sales_order_id'));
        return DB::query()->fromSub($query, 'active_sales_links');
    }

    private function salesRequestItems()
    {
        return DB::table('erp_purchase_request_items as i')->join('erp_purchase_requests as r', 'r.id', '=', 'i.request_id')
            ->where('r.source_type', 'sales')->whereNotNull('r.source_id')->whereNull('r.deleted_at');
    }

    private function purchaseOrderSources()
    {
        $query = DB::table('erp_sales_order_purchase_links')->where('status', 'active')->selectRaw('sales_order_id, purchase_order_id, NULL AS request_item_id');
        $query->unionAll(DB::table('erp_purchase_order_items as i')->leftJoin('erp_purchase_plan_items as p', 'p.id', '=', 'i.plan_item_id')
            ->join('erp_purchase_request_items as ri', 'ri.id', '=', DB::raw('COALESCE(i.request_item_id, p.request_item_id)'))
            ->join('erp_purchase_requests as r', 'r.id', '=', 'ri.request_id')
            ->whereColumn('ri.item_id', 'i.item_id')->where('r.source_type', 'sales')->whereNotNull('r.source_id')
            ->whereNull('r.deleted_at')->where('r.request_status', '<>', 'cancelled')
            ->select('r.source_id as sales_order_id', 'i.order_id as purchase_order_id', 'ri.id as request_item_id'));
        if (Schema::hasTable('erp_purchase_request_sales_links')) {
            // 数量关联只沿精确需求行追溯订单；不把整张计划或需求下的其他物料算进来。
            $query->unionAll(DB::table('erp_purchase_order_items as i')->leftJoin('erp_purchase_plan_items as p', 'p.id', '=', 'i.plan_item_id')
                ->join('erp_purchase_request_sales_links as l', 'l.purchase_request_item_id', '=', DB::raw('COALESCE(i.request_item_id, p.request_item_id)'))
                ->join('erp_purchase_request_items as r', 'r.id', '=', 'l.purchase_request_item_id')
                ->whereColumn('r.item_id', 'i.item_id')->where('l.status', 'active')
                ->select('l.sales_order_id', 'i.order_id as purchase_order_id', 'l.purchase_request_item_id as request_item_id'));
        }
        return DB::query()->fromSub($query, 'purchase_sources');
    }
}
