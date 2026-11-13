<?php

namespace App\Services\Erp\Crm;

use App\Models\Erp\AftersalesCase;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Services\Erp\SalesOrderFulfillmentPolicy;
use App\Services\Erp\SalesOrderVisibilityService;
use Illuminate\Database\Eloquent\Builder;

/** 工作台只收窄本人原订单范围，金额和发运事实继续使用原业务口径。 */
final class CrmWorkbenchBusinessQueryService
{
    private const CASE_STATUSES = ['pending' => '待受理', 'processing' => '处理中', 'ready_to_close' => '待关闭'];

    public function __construct(
        private readonly CrmDashboardScopeService $scope,
        private readonly CrmCustomerAccessService $customers,
        private readonly CrmOrderAmountQueryService $amounts,
        private readonly SalesOrderVisibilityService $visibility,
    ) {}

    public function results(object $actor): array
    {
        $period = $this->period();
        $abilities = $this->abilities($actor);
        $orders = $this->ownOrders($actor, ['date_from' => $period['from'], 'date_to' => $period['to']]);
        $followups = $this->scope->followups($actor, ['contact_operator' => true, 'sales_user_legacy_id' => (int) $actor->legacy_id]);
        $amounts = $orders && $abilities['amounts'] ? $this->amounts->summaryForQuery($orders, $actor) : null;
        return [
            'period' => $period,
            'amounts' => $amounts, 'order_count' => $amounts['order_count'] ?? $orders?->count(),
            'today_followup_count' => $followups?->where('contacted_at', '>=', today())->where('contacted_at', '<', today()->addDay())->count(),
            'aftersales_count' => $abilities['aftersales'] ? $this->cases($actor)->count() : null,
            'abilities' => $abilities,
            'current_user' => ['id' => (int) $actor->legacy_id, 'name' => ($actor->nickname ?? null) ?: ($actor->username ?? '')],
        ];
    }

    public function business(object $actor, array $filters): array
    {
        $type = $filters['type'] ?? 'delivery';
        $abilities = $this->abilities($actor);
        $ability = $type === 'month_orders' ? 'orders' : $type;
        abort_unless(in_array($type, ['delivery', 'outstanding', 'aftersales', 'month_orders'], true), 422, '工作台业务类型无效。');
        abort_unless($abilities[$ability], 403, '没有查看该业务的权限。');
        if ($type === 'aftersales') return $this->aftersales($actor, $filters, $abilities);

        $period = $type === 'month_orders' ? $this->period() : null;
        $query = $this->ownOrders($actor, $period ? ['date_from' => $period['from'], 'date_to' => $period['to']] : []);
        if (!empty($filters['currency'])) $query->where('currency', $filters['currency']);
        if (!empty($filters['currency_missing'])) $query->where(fn ($q) => $q->whereNull('currency')->orWhere('currency', ''));
        if ($type === 'delivery') $this->pendingDelivery($query);
        if ($type === 'outstanding') $filters['outstanding_status'] = 'outstanding';
        $page = $this->amounts->scopedOrders($query, $filters, $actor);
        $orders = SalesOrder::query()->whereIn('id', array_column($page['data'], 'id'))->with('lines')->get()->keyBy('id');
        $shipped = $this->shippedLines()->whereIn('sales_order_line_id', $orders->flatMap(fn ($order) => $order->lines->pluck('id')))
            ->selectRaw('sales_order_line_id, SUM(sales_qty) AS qty')->groupBy('sales_order_line_id')->pluck('qty', 'sales_order_line_id');
        $customerIds = $this->visibleCustomerIds($actor, $orders->pluck('customer_id')->all());
        $page['data'] = array_map(function (array $row) use ($orders, $shipped, $customerIds, $abilities): array {
            $order = $orders[$row['id']];
            $delivery = $this->deliverySummary($order, $shipped->all());
            return [
                'id' => (int) $order->id, 'order_id' => (int) $order->id, 'sales_order_no' => $row['sales_order_no'],
                'customer_id' => $order->customer_id ? (int) $order->customer_id : null, 'customer_name' => $row['customer_name'],
                'order_status' => $row['order_status'], 'order_date' => $row['order_date'],
                'delivery_status_label' => $delivery['status_label'], 'delivery_summary' => $delivery['summary'],
                'currency' => $row['currency'],
                'amounts' => $abilities['amounts'] ? array_intersect_key($row, array_flip([
                    'deal_amount', 'received_amount', 'outstanding_amount', 'needs_verification', 'verification_reasons',
                ])) : null,
                'can_view' => true, 'can_view_customer' => in_array((int) $order->customer_id, $customerIds, true),
                'can_view_delivery' => true,
            ];
        }, $page['data']);
        $page['abilities'] = $abilities;
        if ($period) $page['period'] = $period;
        return $page;
    }

    private function abilities(object $actor): array
    {
        $base = $this->scope->abilities($actor);
        return ['orders' => $base['orders'], 'amounts' => $base['amounts'], 'delivery' => $base['orders'],
            'outstanding' => $base['amounts'], 'aftersales' => $base['orders'] && $this->customers->can($actor, 'aftersales.view'),
            'followups' => $base['customers'] || $base['leads'] || $base['opportunities']];
    }

    private function ownOrders(object $actor, array $filters = []): ?Builder
    {
        return $this->scope->orders($actor, $filters + ['sales_user_legacy_id' => (int) $actor->legacy_id]);
    }

    private function period(): array
    {
        $month = today()->startOfMonth();
        return ['from' => $month->toDateString(), 'to' => $month->copy()->endOfMonth()->toDateString(), 'label' => '本月'];
    }

    private function pendingDelivery(Builder $orders): void
    {
        $shipped = $this->shippedLines()
            ->selectRaw('sales_order_line_id, SUM(sales_qty) AS shipped_qty')->groupBy('sales_order_line_id');
        $pending = SalesOrderLine::query()->leftJoinSub($shipped, 'workbench_shipped', 'workbench_shipped.sales_order_line_id', '=', 'erp_sales_order_lines.id')
            ->where('line_status', '!=', 'removed')->where('line_type', 'physical')->whereNotIn('fulfillment_type', ['service', 'no_delivery'])
            ->whereRaw('GREATEST(order_qty - cancelled_qty, 0) > COALESCE(workbench_shipped.shipped_qty, 0) + 0.00000001')
            ->select('sales_order_id');
        $orders->where('order_status', 'confirmed')->where(function (Builder $query) use ($pending): void {
            $query->where(function (Builder $current) use ($pending): void {
                $current->where(fn ($q) => $q->where('order_source', '!=', 'legacy')->orWhereNull('order_source')->orWhere('legacy_order_id', '<=', 0)->orWhereNull('legacy_order_id'))
                    ->whereIn('id', $pending);
            })->orWhere(function (Builder $history): void {
                // 历史单没有完整发运明细；保留待核实状态，不能解释为已发 0 件。
                $history->where('order_source', 'legacy')->where('legacy_order_id', '>', 0)
                    ->where(fn ($q) => $q->whereNotIn('shipment_status', ['shipped', 'not_required'])->orWhereNull('shipment_status'));
            });
        });
    }

    private function deliverySummary(SalesOrder $order, array $shipped): array
    {
        if ($order->isLegacyHistory()) return ['status_label' => '待核实', 'summary' => '历史订单交付数量待核实'];
        $lines = $order->lines->filter(fn ($line) => SalesOrderFulfillmentPolicy::isPhysical((string) $line->line_type)
            && !in_array($line->fulfillment_type, ['service', 'no_delivery'], true)
            && SalesOrderFulfillmentPolicy::effectiveQuantity((float) $line->order_qty, (float) $line->cancelled_qty) > 0.00000001);
        $facts = $lines->map(fn ($line) => ['line_type' => $line->line_type, 'order_qty' => $line->order_qty,
            'cancelled_qty' => $line->cancelled_qty, 'shipped_qty' => $shipped[$line->id] ?? 0])->all();
        $status = SalesOrderFulfillmentPolicy::shipmentStatus($facts);
        $labels = ['not_required' => '无需发货', 'not_shipped' => '未发货', 'partially_shipped' => '部分发货', 'shipped' => '已发货'];
        $summary = $lines->map(function ($line) use ($shipped): string {
            $required = SalesOrderFulfillmentPolicy::effectiveQuantity((float) $line->order_qty, (float) $line->cancelled_qty);
            $quantity = $this->quantity((float) ($shipped[$line->id] ?? 0)).'/'.$this->quantity($required);
            return '第'.$line->line_no.'行 已发 '.$quantity.($line->unit_name_snapshot ?: '（单位待核实）');
        })->implode('；');
        return ['status_label' => $labels[$status], 'summary' => $summary ?: $labels[$status]];
    }

    private function shippedLines(): Builder
    {
        // 原已发事实还保护历史销售出库；交付摘要进一步要求实际发运，待发移位和仅过账不计已发。
        return SalesOrderFulfillmentPolicy::shippedLines()->where(fn ($query) => $query->where('line_status', 'shipped')->orWhereNotNull('dispatched_at'))
            ->whereHas('shipment', fn ($query) => $query->where('shipment_status', '!=', 'cancelled'));
    }

    private function quantity(float $quantity): string
    {
        return rtrim(rtrim(number_format($quantity, 8, '.', ''), '0'), '.');
    }

    private function cases(object $actor): Builder
    {
        // 售后按原订单关联收窄；订单取消、关闭或历史归期不消除仍在处理的售后事实。
        return AftersalesCase::query()->whereIn('status', array_keys(self::CASE_STATUSES))
            ->whereHas('order', function (Builder $orders) use ($actor): void {
                $orders->where('sales_user_legacy_id', (int) $actor->legacy_id);
                $this->visibility->apply($orders, $actor, true);
            });
    }

    private function aftersales(object $actor, array $filters, array $abilities): array
    {
        $page = $this->cases($actor)->with('order:id,sales_order_no,customer_id,customer_name,customer_name_snapshot')
            ->orderByDesc('reported_at')->orderByDesc('id')
            ->paginate((int) ($filters['per_page'] ?? 10), ['*'], 'page', (int) ($filters['page'] ?? 1));
        $customerIds = $this->visibleCustomerIds($actor, $page->getCollection()->pluck('order.customer_id')->all());
        $page->through(fn (AftersalesCase $case) => [
            'id' => (int) $case->id, 'case_no' => $case->case_no, 'order_id' => (int) $case->sales_order_id,
            'sales_order_no' => $case->order?->sales_order_no, 'customer_id' => $case->order?->customer_id,
            'customer_name' => $case->order?->customer_name_snapshot ?: $case->order?->customer_name,
            'status' => $case->status, 'status_label' => self::CASE_STATUSES[$case->status], 'description' => $case->description,
            'reported_at' => $case->reported_at?->toDateTimeString(), 'can_view' => true,
            'can_view_customer' => in_array((int) $case->order?->customer_id, $customerIds, true),
        ]);
        return $page->toArray() + ['abilities' => $abilities];
    }

    private function visibleCustomerIds(object $actor, array $ids): array
    {
        $query = $this->scope->customers($actor);
        return $query ? $query->whereIn('id', $ids)->pluck('id')->map(fn ($id) => (int) $id)->all() : [];
    }
}
