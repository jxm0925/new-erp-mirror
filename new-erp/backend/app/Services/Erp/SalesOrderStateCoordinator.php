<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesShipmentLine;

/**
 * 销售订单状态唯一协调入口。下游业务（发运、退货、收款核销、订单改价、生产确认）
 * 只上报“发生了什么”，由这里按固定顺序重算订单投影：
 *   发运数量与交付状态 → 实际成本 → 资金放行 → 完成状态。
 * 各步骤幂等，必须在调用方的业务事务内同步执行。
 */
final class SalesOrderStateCoordinator
{
    public const SHIPMENT_CHANGED = 'shipment_changed';
    public const RETURN_CHANGED = 'return_changed';
    public const PAYMENT_CHANGED = 'payment_changed';
    public const ORDER_TERMS_CHANGED = 'order_terms_changed';
    public const PRODUCTION_CONFIRMED = 'production_confirmed';

    private const STEP_SHIPMENT = 'shipment';
    private const STEP_COST = 'cost';
    private const STEP_FUNDING = 'funding';
    private const STEP_COMPLETION = 'completion';
    private const STEP_ORDER = [self::STEP_SHIPMENT, self::STEP_COST, self::STEP_FUNDING, self::STEP_COMPLETION];

    private const EVENT_STEPS = [
        self::SHIPMENT_CHANGED => [self::STEP_SHIPMENT, self::STEP_COST, self::STEP_COMPLETION],
        self::RETURN_CHANGED => [self::STEP_COST, self::STEP_FUNDING, self::STEP_COMPLETION],
        self::PAYMENT_CHANGED => [self::STEP_FUNDING, self::STEP_COMPLETION],
        self::ORDER_TERMS_CHANGED => [self::STEP_FUNDING, self::STEP_COMPLETION],
        self::PRODUCTION_CONFIRMED => [self::STEP_COMPLETION],
    ];

    public function __construct(
        private readonly SalesOrderFundingGateService $fundingGates,
        private readonly SalesOrderActualCostService $actualCosts,
        private readonly SalesOrderCompletionService $completion,
    )
    {
    }

    /** 上报订单事件并重算；返回资金放行状态（本次未重算资金时为 null）。 */
    public function handle(string $event, SalesOrder|int|null $order): ?array
    {
        if ($order === null) return null;
        $steps = self::EVENT_STEPS[$event] ?? throw new \InvalidArgumentException("Unknown sales order event {$event}.");
        $orderId = $order instanceof SalesOrder ? (int) $order->id : (int) $order;
        $locked = SalesOrder::query()->lockForUpdate()->find($orderId);
        if (! $locked) return null;
        $funding = null;
        foreach (self::STEP_ORDER as $step) {
            if (! in_array($step, $steps, true)) continue;
            match ($step) {
                self::STEP_SHIPMENT => $this->refreshShipment($locked),
                self::STEP_COST => $this->actualCosts->refresh($orderId),
                self::STEP_FUNDING => $funding = $this->fundingGates->writeProjection($locked),
                self::STEP_COMPLETION => $this->completion->refresh($orderId),
            };
        }
        return $funding;
    }

    /** 实际发运后才累计销售已发货数量，并据此计算订单交付状态。 */
    private function refreshShipment(SalesOrder $order): void
    {
        $order->setRelation('lines', $order->lines()->orderBy('id')->lockForUpdate()->get());
        // 调用方可能已建立 RR 快照；发运单状态与数量必须在同一次当前读中取得。
        $shipments = SalesShipmentLine::query()
            ->join('erp_sales_shipments', 'erp_sales_shipments.id', '=', 'erp_sales_shipment_lines.shipment_id')
            ->where('erp_sales_shipment_lines.line_status', 'shipped')
            ->where('erp_sales_shipments.sales_order_id', $order->id)
            ->whereIn('erp_sales_shipments.shipment_status', ['partially_shipped', 'shipped', 'completed'])
            ->orderBy('erp_sales_shipment_lines.id')->lockForUpdate()
            ->get(['erp_sales_shipment_lines.sales_order_line_id', 'erp_sales_shipment_lines.sales_qty']);
        $shippedByLine = [];
        foreach ($shipments as $line) {
            $shippedByLine[$line->sales_order_line_id] = bcadd($shippedByLine[$line->sales_order_line_id] ?? '0', (string) $line->sales_qty, 8);
        }
        foreach ($order->lines as $line) $line->update(['shipped_qty' => $shippedByLine[$line->id] ?? 0]);
        $order->update(['shipment_status' => SalesOrderFulfillmentPolicy::shipmentStatus($order->lines->map(fn ($line) => $line->getAttributes())->all())]);
    }
}
