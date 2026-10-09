<?php

namespace App\Services\Erp;

use App\Models\Erp\{ItemCategory, PurchaseOrder, PurchaseOrderItem, PurchasePlan, SalesOrder, SalesOrderPurchaseLink};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Arr;

class SalesOrderFinanceQueryService
{
    public function visibleOrders(object $user): Builder
    {
        $query = SalesOrder::query();
        app(SalesOrderVisibilityService::class)->apply($query, $user);
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
        $links = SalesOrderPurchaseLink::where('sales_order_id', $order->id)->where('status', 'active');
        $shipments = DB::table('erp_sales_shipments')->where('sales_order_id', $order->id)
            ->whereIn('shipment_status', ['outbound_posted', 'shipped', 'completed']);
        return [
            'sales_order_id' => $order->id, 'sales_order_no' => $order->sales_order_no,
            'order_status' => $order->order_status,
            'version' => (int) $order->purchase_link_version, 'currency' => $order->currency,
            'settlement' => app(SalesFinanceSettlementService::class)->status($order),
            'purchase_order_count' => (clone $links)->distinct()->count('purchase_order_id'),
            'posted_shipment_count' => (clone $shipments)->count(),
        ];
    }

    public function links(int $orderId, int $perPage)
    {
        $page = SalesOrderPurchaseLink::where('sales_order_id', $orderId)->orderByDesc('id')->paginate($perPage);
        $page->getCollection()->transform(fn (SalesOrderPurchaseLink $link) => $this->linkPayload($link));
        return $page;
    }

    /** Internal attribution prices remain stored; sales sees only the quantity relationship. */
    public function linkPayload(SalesOrderPurchaseLink $link): array
    {
        $data = Arr::only($link->toArray(), ['id', 'sales_order_id', 'purchase_order_id', 'purchase_order_item_id', 'purchase_qty',
            'status', 'reason', 'created_by', 'created_at', 'updated_at', 'reverse_reason', 'reversed_by', 'reversed_at']);
        $data['source_snapshot'] = Arr::only((array) $link->source_snapshot, ['order_no', 'supplier_id', 'supplier_name', 'item_id', 'item_code',
            'item_name', 'spec_model', 'purchase_unit_id', 'purchase_unit_name', 'source_purchase_qty']);
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
        $orders = $this->visibleOrders($user);
        if ($keyword = trim($filters['keyword'] ?? '')) $orders->where(fn ($q) => $q->where('sales_order_no', 'like', '%'.$keyword.'%')->orWhere('customer_name', 'like', '%'.$keyword.'%'));
        if (!empty($filters['currency'])) $orders->where('currency', $filters['currency']);
        if (!empty($filters['order_status'])) $orders->where('order_status', $filters['order_status']);
        if (!empty($filters['order_date_start'])) $orders->whereDate('order_date', '>=', $filters['order_date_start']);
        if (!empty($filters['order_date_end'])) $orders->whereDate('order_date', '<=', $filters['order_date_end']);
        if (($filters['purchase_link_status'] ?? '') === 'linked') $orders->whereIn('id', SalesOrderPurchaseLink::where('status', 'active')->select('sales_order_id'));
        if (($filters['purchase_link_status'] ?? '') === 'unlinked') $orders->whereNotIn('id', SalesOrderPurchaseLink::where('status', 'active')->select('sales_order_id'));
        $linked = DB::table('erp_sales_order_purchase_links')->where('status', 'active')->whereIn('sales_order_id', (clone $orders)->select('id'))
            ->selectRaw('COUNT(*) AS link_count, COUNT(DISTINCT sales_order_id) AS linked_order_count, COUNT(DISTINCT purchase_order_id) AS purchase_order_count')->first();
        $page = $orders->orderByDesc('id')->paginate($filters['per_page'] ?? 20);
        $page->getCollection()->transform(function ($order): array {
            $overview = $this->overview($order);
            return [...$overview, 'customer_name' => $order->customer_name, 'order_date' => $order->order_date?->toDateString(), 'order_status' => $order->order_status];
        });
        return [...$page->toArray(), 'summary' => ['order_count' => $page->total(), 'linked_order_count' => (int) $linked->linked_order_count,
            'purchase_order_count' => (int) $linked->purchase_order_count, 'link_count' => (int) $linked->link_count],
            'summary_scope' => '全部符合筛选条件的订单；采购关联仅统计单据数量。'];
    }
}
