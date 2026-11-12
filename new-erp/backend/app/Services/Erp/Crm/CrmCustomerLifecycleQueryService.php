<?php

namespace App\Services\Erp\Crm;

use App\Domain\Finance\FinanceConstants as Finance;
use App\Models\Erp\AftersalesCase;
use App\Models\Erp\CrmLead;
use App\Models\Erp\CrmOpportunity;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesShipment;
use App\Services\Erp\FinanceCashDocumentReadAccessService;
use App\Services\Erp\Manufacturing\WorkOrderQueryService;
use App\Services\Erp\Manufacturing\WorkOrderReadAccessService;
use App\Services\Erp\SalesOrderVisibilityService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** 客户关联只读投影：每条业务记录仍取原模块权限和订单范围的交集。 */
final class CrmCustomerLifecycleQueryService
{
    public function __construct(
        private readonly CrmCustomerAccessService $customers,
        private readonly CrmLeadApplicationService $leads,
        private readonly CrmOpportunityApplicationService $opportunities,
        private readonly CrmFollowupApplicationService $followups,
        private readonly SalesOrderVisibilityService $orders,
        private readonly FinanceCashDocumentReadAccessService $finance,
        private readonly WorkOrderReadAccessService $workOrders,
    ) {}

    public function abilities(object $actor): array
    {
        $orders = $this->customers->can($actor, 'sales_order.view');
        return [
            'view_opportunities' => $this->customers->can($actor, 'crm.opportunity.view'),
            'view_leads' => $this->customers->can($actor, 'crm.lead.view'),
            'view_receipts' => $orders && $this->finance->canView($actor),
            'view_shipments' => $orders && $this->customers->can($actor, 'sales_order.shipment.view'),
            'view_aftersales' => $orders && $this->customers->can($actor, 'aftersales.view'),
            'view_delivery' => $orders && $this->workOrders->canView($actor),
        ];
    }

    public function opportunitySummaries(array $customerIds, object $actor): array
    {
        if (!$customerIds || !$this->customers->can($actor, 'crm.opportunity.view')) return [];
        $customers = SalesCustomer::query()->whereIn('id', $customerIds);
        $this->customers->applyVisible($customers, $actor);
        $opportunities = CrmOpportunity::query()->whereIn('customer_id', $customers->select('id'))->where('status', 'open');
        $this->opportunities->applyVisible($opportunities, $actor);
        $rows = $opportunities->select('customer_id', 'currency')
            ->selectRaw('COUNT(*) AS opportunity_count, SUM(expected_amount) AS known_expected_amount, SUM(expected_amount IS NULL) AS unknown_amount_count')
            ->groupBy('customer_id', 'currency')->orderBy('currency')->get()->groupBy('customer_id');
        $summaries = [];
        foreach ($customerIds as $id) {
            $currencies = $rows->get($id, collect())->map(fn ($row) => [
                'currency' => $row->currency, 'opportunity_count' => (int) $row->opportunity_count,
                'expected_amount' => (int) $row->unknown_amount_count > 0 ? null : $row->known_expected_amount,
                'known_expected_amount' => $row->known_expected_amount, 'unknown_amount_count' => (int) $row->unknown_amount_count,
            ])->values()->all();
            $summaries[$id] = ['open_count' => array_sum(array_column($currencies, 'opportunity_count')), 'currencies' => $currencies];
        }
        return $summaries;
    }

    public function overview(SalesCustomer $customer, object $actor): array
    {
        $this->customers->assert($customer, $actor, 'view', '该客户不在你的可见范围内。');
        $abilities = $this->abilities($actor);
        $opportunitySummary = $this->opportunitySummaries([$customer->id], $actor)[$customer->id] ?? null;
        $pools = $customerTags = $customTags = [];
        if ($abilities['view_leads']) {
            $query = CrmLead::query()->where('customer_id', $customer->id);
            $this->leads->applyVisible($query, $actor);
            // 标签按可见线索汇总，分批读取而不把客户的历史线索全部载入详情。
            foreach ($query->select(['id', 'created_by_legacy_id', 'customer_pool', 'customer_tags', 'custom_tags'])->lazyById(200) as $lead) {
                if (in_array($lead->customer_pool, CrmLeadApplicationService::POOLS, true)) $pools[$lead->customer_pool] = true;
                foreach ($lead->customer_tags ?? [] as $tag) $customerTags[$tag] = $tag;
                // 个人标签只供原录入人汇总，客户/线索可见不等于共享个人标签。
                if ((int) $lead->created_by_legacy_id === (int) $actor->legacy_id) {
                    foreach ($lead->custom_tags ?? [] as $tag) $customTags[$tag] = $tag;
                }
            }
        }
        return [
            'opportunity_summary' => $opportunitySummary,
            'latest_followups' => $this->followups->latestForCustomer((int) $customer->id, $actor),
            'customer_pools' => array_values(array_filter(CrmLeadApplicationService::POOLS, fn ($pool) => isset($pools[$pool]))),
            'customer_tags' => array_values($customerTags), 'custom_tags' => array_values($customTags), 'abilities' => $abilities,
        ];
    }

    /** 已核销到可见订单的真实收款；不把整张多订单收款单的总额带入客户订单。 */
    public function receipts(int $customerId, array $filters, object $actor): array
    {
        $this->assertCustomer($customerId, $actor, 'view_receipts');
        $this->finance->assertView($actor);
        $orders = $this->visibleOrders($customerId, $actor)->select('erp_sales_orders.id');
        $page = DB::table('erp_finance_allocations as allocation')
            ->join('erp_finance_cash_documents as cash', 'cash.id', '=', 'allocation.cash_document_id')
            ->join('erp_sales_orders as orders', 'orders.id', '=', 'allocation.source_document_id')
            ->whereIn('orders.id', $orders)->where('allocation.source_business_type', Finance::SOURCE_SALES_ORDER)
            ->where('allocation.status', Finance::ALLOCATION_ACTIVE)->where('cash.status', Finance::STATUS_CONFIRMED)
            ->where('cash.direction', Finance::DIRECTION_RECEIPT)
            ->where('allocation.party_type', Finance::PARTY_CUSTOMER)->where('allocation.party_id', $customerId)
            ->where('cash.party_type', Finance::PARTY_CUSTOMER)->where('cash.party_id', $customerId)
            ->orderByDesc('cash.business_date')->orderByDesc('allocation.id')
            ->select(['allocation.id', 'cash.id as cash_document_id', 'cash.document_no', 'orders.id as order_id', 'orders.sales_order_no',
                'allocation.allocated_amount as amount', 'allocation.currency', 'cash.business_date', 'cash.payment_method', 'cash.status'])
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $page->through(fn ($row) => (array) $row + ['source' => '财务入账', 'can_view' => true, 'order_can_view' => true]);
        return $page->toArray() + ['abilities' => $this->abilities($actor)];
    }

    public function shipments(int $customerId, array $filters, object $actor): array
    {
        $this->assertCustomer($customerId, $actor, 'view_shipments');
        $query = SalesShipment::query()->whereHas('order', function (Builder $orders) use ($customerId, $actor): void {
            $orders->where('customer_id', $customerId);
            $this->orders->apply($orders, $actor, true);
        })->with('order:id,sales_order_no');
        $page = $query->orderByDesc('id')->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $page->through(fn (SalesShipment $shipment) => [
            'id' => $shipment->id, 'shipment_no' => $shipment->shipment_no, 'order_id' => $shipment->sales_order_id,
            'sales_order_no' => $shipment->order?->sales_order_no, 'shipment_status' => $shipment->shipment_status,
            'delivery_mode' => $shipment->delivery_mode,
            'carrier_name' => $shipment->carrier_name_snapshot, 'tracking_no' => $shipment->tracking_no,
            'shipped_at' => $shipment->shipped_at?->toDateTimeString(), 'completed_at' => $shipment->completed_at?->toDateTimeString(),
            'created_at' => $shipment->created_at?->toDateTimeString(), 'can_view' => true, 'order_can_view' => true,
        ]);
        return $page->toArray() + ['abilities' => $this->abilities($actor)];
    }

    public function aftersales(int $customerId, array $filters, object $actor): array
    {
        $this->assertCustomer($customerId, $actor, 'view_aftersales');
        $query = AftersalesCase::query()->whereHas('order', function (Builder $orders) use ($customerId, $actor): void {
            $orders->where('customer_id', $customerId);
            $this->orders->apply($orders, $actor, true);
        })->with('order:id,sales_order_no');
        $page = $query->orderByDesc('reported_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $page->through(fn (AftersalesCase $case) => [
            'id' => $case->id, 'case_no' => $case->case_no, 'order_id' => $case->sales_order_id,
            'sales_order_no' => $case->order?->sales_order_no, 'issue_type' => $case->issue_type,
            'description' => $case->description, 'status' => $case->status, 'result' => $case->result,
            'reported_at' => $case->reported_at?->toDateTimeString(), 'closed_at' => $case->closed_at?->toDateTimeString(),
            'can_view' => true, 'order_can_view' => true,
        ]);
        return $page->toArray() + ['abilities' => $this->abilities($actor)];
    }

    public function delivery(int $customerId, int $orderId, array $filters, object $actor): array
    {
        $this->assertCustomer($customerId, $actor, 'view_delivery');
        $this->workOrders->assertView($actor);
        $order = $this->visibleOrders($customerId, $actor)->findOrFail($orderId);
        // 客户归属沿生产履约和父子关系；返工原件来源不授予另一客户对返工单的可见性。
        $sql = <<<'SQL'
WITH RECURSIVE related (id, root_work_order_id, sales_order_line_id, sales_fulfillment_id) AS (
    SELECT root.id, root.id, line.id, fulfillment.id
    FROM erp_sales_order_lines AS line
    JOIN erp_sales_order_fulfillments AS fulfillment ON fulfillment.sales_order_line_id = line.id AND fulfillment.sales_order_id = line.sales_order_id
    JOIN erp_mfg_work_orders AS root ON root.sales_fulfillment_id = fulfillment.id AND root.sales_order_line_id = line.id AND root.sales_order_id = line.sales_order_id
    WHERE line.sales_order_id = ? AND fulfillment.fulfillment_type = 'production' AND root.source_type = 'sales_order'
    UNION DISTINCT
    SELECT child.id, related.root_work_order_id, related.sales_order_line_id, related.sales_fulfillment_id
    FROM related
    JOIN erp_mfg_work_order_links AS edge ON edge.parent_work_order_id = related.id
    JOIN erp_mfg_work_orders AS child ON child.id = edge.child_work_order_id
    WHERE child.source_type = 'work_order' AND child.sales_order_id IS NULL
)
SELECT related.*, work.work_order_no, work.source_type, work.rework_of_work_order_id,
    link.parent_work_order_id, work.product_name, work.sku_name, work.item_name, work.planned_qty,
    work.completed_qty, work.stored_qty, work.status, work.started_at, work.completed_at, work.released_at,
    line.line_no, line.product_name AS order_product_name, fulfillment.demand_status, fulfillment.production_requirement_status
FROM related
JOIN erp_mfg_work_orders AS work ON work.id = related.id
JOIN erp_sales_order_lines AS line ON line.id = related.sales_order_line_id
JOIN erp_sales_order_fulfillments AS fulfillment ON fulfillment.id = related.sales_fulfillment_id
LEFT JOIN erp_mfg_work_order_links AS link ON link.child_work_order_id = work.id
SQL;
        $page = DB::query()->fromRaw("({$sql}) as delivery", [$orderId])->orderBy('sales_order_line_id')->orderBy('root_work_order_id')->orderBy('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $operations = MfgWorkOrderOperation::query()->whereIn('work_order_id', $page->getCollection()->pluck('id'))
            ->where('execution_context', 'production')->orderBy('sequence')->orderBy('id')
            ->get(['work_order_id', 'operation_name', 'completed_qty'])->groupBy('work_order_id');
        $page->through(function ($work) use ($operations): array {
            $progress = $operations->get($work->id, collect())->map(fn ($operation) => [
                'operation_name' => $operation->operation_name, 'completed_qty' => $operation->completed_qty, 'planned_qty' => $work->planned_qty,
            ])->all();
            return (array) $work + [
                'status_label' => WorkOrderQueryService::STATUS_LABELS[$work->status] ?? $work->status,
                'relation' => $work->rework_of_work_order_id ? 'rework' : ((int) $work->id === (int) $work->root_work_order_id ? 'order' : 'child'),
                'progress' => $progress, 'can_view' => true,
            ];
        });
        $fulfillments = SalesOrderFulfillment::query()->where('sales_order_id', $orderId)->where('fulfillment_type', 'production');
        return $page->toArray() + [
            'order' => ['id' => $order->id, 'sales_order_no' => $order->sales_order_no, 'order_status' => $order->order_status,
                'fulfillment_status' => $order->fulfillment_status, 'confirmed_at' => $order->confirmed_at?->toDateTimeString()],
            'summary' => ['production_fulfillment_count' => $fulfillments->count()], 'abilities' => $this->abilities($actor),
        ];
    }

    private function assertCustomer(int $customerId, object $actor, string $ability): void
    {
        $customer = SalesCustomer::query()->findOrFail($customerId);
        $this->customers->assert($customer, $actor, 'view', '该客户不在你的可见范围内。');
        abort_unless($this->abilities($actor)[$ability], 403, '没有查看该业务记录的权限。');
    }

    private function visibleOrders(int $customerId, object $actor): Builder
    {
        $orders = SalesOrder::query()->where('customer_id', $customerId);
        $this->orders->apply($orders, $actor, true);
        return $orders;
    }
}
