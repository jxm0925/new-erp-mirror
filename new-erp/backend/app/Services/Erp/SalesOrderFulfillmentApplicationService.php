<?php

namespace App\Services\Erp;

use App\Models\Erp\{
    SalesOrder,
    SalesOrderAttachment,
    SalesOrderFulfillment,
    SalesOrderLine,
    SalesOrderLog,
    SalesOrderProductionRequirement,
    WorkOrder,
    Unit
};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesOrderFulfillmentApplicationService
{
    public function __construct(
        private readonly UnitConversionDomainService $conversions,
        private readonly BomMatcher $bomMatcher,
        private readonly DocumentNumberService $numbers,
        private readonly InventoryAvailabilityService $inventoryAvailability,
        private readonly InventoryReservationService $inventoryReservations,
        private readonly SalesOrderFundingGateService $fundingGates,
    ) {}

    public function confirmOrder(int $orderId, string $operatorName): SalesOrder
    {
        return DB::transaction(function () use ($orderId, $operatorName) {
            $order = SalesOrder::with(['lines.sku.salesUnit.standardUnit', 'lines.item.unit.standardUnit'])
                ->lockForUpdate()->findOrFail($orderId);
            if ($order->order_status !== 'draft') {
                throw ValidationException::withMessages(['order_status' => '只有草稿状态的销售订单可以提交确认。']);
            }
            if ($order->lines->isEmpty()) {
                throw ValidationException::withMessages(['lines' => '销售订单至少需要一行明细。']);
            }
            if (blank($order->carrier_id)) {
                throw ValidationException::withMessages(['carrier_id' => '提交确认前必须选择快递。']);
            }

            foreach ($order->lines as $line) {
                if ((float) $line->unit_price <= 0) {
                    throw ValidationException::withMessages(['unit_price' => "第 {$line->line_no} 行销售单价必须大于 0。"]);
                }
                if ($line->is_special_customized && !$this->hasTechnicalAttachment($line)) {
                    throw ValidationException::withMessages(['attachments' => "第 {$line->line_no} 行为特殊定制，必须上传设计图纸或技术附件。"]);
                }
                $requirement = $this->conversions->calculateSalesRequirement($line->sku, $line->order_qty, true);
                $item = $requirement['item'];
                $baseUnit = $requirement['base_unit'] ?? null;
                $matchSnapshot = $item ? [
                    'relation_id' => $requirement['relation']?->id,
                    'relation_effective_at' => $requirement['relation']?->effective_at,
                    'item_id' => $item->id,
                    'fulfillment_factor' => $requirement['factor'],
                    'item_base_required_qty' => $requirement['base_qty'],
                ] : null;
                $line->update([
                    'item_id' => $item?->id,
                    'item_name' => $item?->item_name,
                    'item_match_status' => $item ? 'matched' : 'not_required',
                    'item_match_rule' => $item ? 'sku_active_primary_relation' : 'line_type_not_required',
                    'item_match_block_reason' => null,
                    'item_base_unit_id' => $baseUnit?->id,
                    'item_base_unit_name_snapshot' => $baseUnit?->unit_name,
                    'item_base_unit_code_snapshot' => $baseUnit?->unit_code,
                    'fulfillment_factor_snapshot' => $requirement['factor'],
                    'item_base_required_qty' => $requirement['base_qty'],
                    'item_match_snapshot' => $matchSnapshot,
                    'fulfillment_type' => 'pending_confirmation',
                    'line_status' => 'confirmed_pending_fulfillment',
                ]);
            }

            SalesOrderFulfillment::where('sales_order_id', $order->id)->delete();
            SalesOrderProductionRequirement::where('sales_order_id', $order->id)
                ->where('is_active', true)->whereIn('requirement_status', ['draft', 'blocked'])
                ->update(['requirement_status' => 'cancelled', 'is_active' => false, 'closed_qty' => DB::raw('remaining_qty')]);

            $order->update([
                'order_status' => 'confirmed',
                'confirm_status' => 'confirmed',
                'fulfillment_status' => 'pending',
                'production_confirm_status' => 'pending',
                'confirmed_at' => now(),
                'confirmed_by' => $operatorName,
            ]);
            $this->log($order, 'confirm', 'draft', 'confirmed', '销售订单已确认并锁定单位及履约换算快照；尚未生成履约需求。', $operatorName);
            return $order->fresh(['lines.product', 'lines.sku', 'lines.item']);
        });
    }

    public function preview(int $orderId): array
    {
        $order = SalesOrder::with(['lines.product', 'lines.sku', 'lines.item', 'fulfillments'])->findOrFail($orderId);
        $existing = $order->fulfillments->groupBy('sales_order_line_id');
        $lines = $order->lines->map(function (SalesOrderLine $line) use ($existing, $order) {
            $lineFulfillments = $existing->get($line->id, collect());
            $confirmed = $lineFulfillments->where('demand_status', 'confirmed');
            $pending = $lineFulfillments->where('demand_status', 'pending');
            // A confirmed fulfillment row is an allocated/locked fulfillment plan. It is
            // not proof that the goods were produced, shipped or otherwise fulfilled.
            // Keep the allocated quantity separate so the UI and downstream consumers do
            // not report a production requirement as an actually fulfilled quantity.
            $confirmedAllocatedQty = $confirmed->sum(fn (SalesOrderFulfillment $row) => $this->fulfillmentSalesQuantity($row, $line));
            $remainingSalesQty = max(0, (float) $line->order_qty - $confirmedAllocatedQty);
            $display = $pending->isNotEmpty() ? $pending : ($remainingSalesQty <= 0 ? $confirmed : collect());
            $analysis = null;
            if ($display->isNotEmpty()) {
                $quantities = $this->fulfillmentSalesQuantities($display, $line);
                $confirmQty = array_sum($quantities);
                $quantities['undetermined_qty'] = max(0, $remainingSalesQty - $confirmQty);
                $planningSnapshot = (array) data_get($display->first()?->match_snapshot, 'planning', []);
                if ($this->isPhysicalLine($line) && $planningSnapshot) {
                    $analysis = [
                        'calculated_at' => $planningSnapshot['calculated_at'] ?? null,
                        'available_base_qty' => (float) ($planningSnapshot['available_base_qty'] ?? 0),
                        'available_sales_qty' => (float) ($planningSnapshot['available_sales_qty'] ?? 0),
                        'suggested_inventory_qty' => (float) ($planningSnapshot['suggested_inventory_qty'] ?? $quantities['inventory_qty']),
                        'suggested_production_qty' => (float) ($planningSnapshot['suggested_production_qty'] ?? $quantities['production_qty']),
                        'suggestion_reason' => $planningSnapshot['suggestion_reason'] ?? '已确认履约方案按历史快照展示',
                    ];
                }
            } else {
                $confirmQty = $remainingSalesQty;
                if ($this->isPhysicalLine($line)) {
                    $analysis = $this->inventoryAvailability->analyzeSalesOrderLine($line, $confirmQty);
                    $quantities = [
                        'inventory_qty' => $analysis['suggested_inventory_qty'],
                        'production_qty' => $analysis['suggested_production_qty'],
                        'service_qty' => 0.0,
                        'no_delivery_qty' => 0.0,
                        'undetermined_qty' => 0.0,
                    ];
                } else {
                    $quantities = $this->suggestQuantities($line, $confirmQty);
                }
            }
            if ($this->isPhysicalLine($line) && !$analysis) {
                $analysis = $order->production_confirm_status === 'confirmed'
                    ? [
                        'calculated_at' => null,
                        'available_base_qty' => 0.0,
                        'available_sales_qty' => 0.0,
                        'suggested_inventory_qty' => (float) $quantities['inventory_qty'],
                        'suggested_production_qty' => (float) $quantities['production_qty'],
                        'suggestion_reason' => '历史履约方案已锁定',
                    ]
                    : $this->inventoryAvailability->analyzeSalesOrderLine($line, $confirmQty);
            }
            $productionQty = (float) $quantities['production_qty'];
            $bom = $this->isPhysicalLine($line) ? $this->bomMatcher->match($line->product_id, $line->sku_id, $line->item_id) : null;
            $drawing = $line->is_special_customized ? ($this->hasTechnicalAttachment($line) ? 'ready' : 'missing') : 'not_required';
            $blocking = [];
            if ($productionQty > 0 && $bom && $bom['status'] !== 'matched') $blocking[] = $bom['block_reason'] ?: '未匹配到可用 BOM';
            if ($productionQty > 0 && $drawing === 'missing') $blocking[] = '特殊定制订单行缺少设计图纸或技术附件';
            return [
                'sales_order_line_id' => $line->id,
                'line_no' => $line->line_no,
                'product_name' => $line->product_name,
                'sku_name' => $line->sku_name,
                'item_id' => $line->item_id,
                'item_name' => $line->item_name,
                'line_type' => $line->line_type,
                'order_qty' => (float) $line->order_qty,
                'sales_unit' => $line->unit_name_snapshot,
                'sales_unit_precision' => (int) (Unit::find($line->unit_id)?->decimal_places ?? 0),
                'fulfillment_factor' => $line->fulfillment_factor_snapshot === null ? null : (float) $line->fulfillment_factor_snapshot,
                'item_base_required_qty' => (float) $line->item_base_required_qty,
                'base_unit' => $line->item_base_unit_name_snapshot,
                'default_item_name' => $line->item_name,
                'available_base_qty' => (float) ($analysis['available_base_qty'] ?? 0),
                'available_sales_qty' => (float) ($analysis['available_sales_qty'] ?? 0),
                'system_suggested_inventory_qty' => (float) ($analysis['suggested_inventory_qty'] ?? 0),
                'system_suggested_production_qty' => (float) ($analysis['suggested_production_qty'] ?? 0),
                'system_suggestion_reason' => $analysis['suggestion_reason'] ?? ($line->line_type === 'service' ? '服务类订单行直接进入服务履约' : '无需发货订单行不进入库存或生产'),
                'inventory_calculated_at' => $analysis['calculated_at'] ?? now()->toDateTimeString(),
                'plan_locked' => $order->production_confirm_status === 'confirmed',
                'confirmed_allocated_qty' => $confirmedAllocatedQty,
                // Backward compatibility for older clients. New clients must use
                // confirmed_allocated_qty because this is a planning quantity.
                'already_fulfilled_qty' => $confirmedAllocatedQty,
                'remaining_sales_qty' => $remainingSalesQty,
                'confirm_qty' => $confirmQty,
                'item_base_confirm_qty' => $this->isPhysicalLine($line) ? $this->salesToBaseQuantity($line, $confirmQty) : null,
                'inventory_base_qty' => $this->isPhysicalLine($line) ? $this->salesToBaseQuantity($line, $quantities['inventory_qty']) : null,
                'production_base_qty' => $this->isPhysicalLine($line) ? $this->salesToBaseQuantity($line, $quantities['production_qty']) : null,
                'undetermined_base_qty' => $this->isPhysicalLine($line) ? $this->salesToBaseQuantity($line, $quantities['undetermined_qty']) : null,
                ...$quantities,
                'blocking_reason' => implode('；', $blocking),
                'generated_result' => $this->resultTextFromQuantities($quantities),
                'data_readiness' => [
                    'bom' => !$this->isPhysicalLine($line) ? 'not_required' : (($bom['status'] ?? null) === 'matched' ? 'ready' : 'missing'),
                    'routing' => $productionQty <= 0 ? 'not_required' : 'pending_next_stage',
                    'drawing' => $drawing,
                    'inspection' => 'ready',
                ],
            ];
        });
        $counts = [
            'inventory' => $lines->where('inventory_qty', '>', 0)->count(),
            'production' => $lines->where('production_qty', '>', 0)->count(),
            'service' => $lines->where('service_qty', '>', 0)->count(),
            'no_delivery' => $lines->where('no_delivery_qty', '>', 0)->count(),
            'undetermined' => $lines->where('undetermined_qty', '>', 0)->count(),
        ];
        $plan = $this->fulfillmentPlanSummary($order);
        $order->setAttribute('fulfillment_plan_status', $plan['status']);
        $order->setAttribute('fulfillment_plan_status_label', $plan['status_label']);
        $order->setAttribute('fulfillment_composition_label', $this->fulfillmentCompositionLabel($order, $plan));
        $order->setAttribute('fulfillment_composition', $plan['quantities']);
        return [
            'order' => $order,
            'lines' => $lines->values(),
            'summary' => ['total' => $lines->count()] + $counts,
            'base_unit_groups' => $this->conversions->groupBaseRequirements($order->lines),
            'service_count' => $counts['service'],
            'submit_results' => $this->submitResults($counts),
        ];
    }

    public function confirmProduction(int $orderId, array $decisions, ?string $remark, string $operatorName, ?string $adjustmentReason = null): SalesOrder
    {
        return DB::transaction(function () use ($orderId, $decisions, $remark, $operatorName, $adjustmentReason) {
            $order = SalesOrder::with(['lines.product', 'lines.sku', 'lines.item'])->lockForUpdate()->findOrFail($orderId);
            $this->fundingGates->assertCanStartProduction($order);
            if ($order->order_status !== 'confirmed') {
                throw ValidationException::withMessages(['order_status' => '销售订单确认后才能进行订单生产确认。']);
            }
            if (!in_array($order->production_confirm_status, ['pending', 'blocked'], true)) {
                throw ValidationException::withMessages(['production_confirm_status' => '当前订单已经完成生产确认，不能重复提交。']);
            }
            $byLine = collect($decisions)->keyBy('sales_order_line_id');
            if ($byLine->count() !== $order->lines->count()) {
                throw ValidationException::withMessages(['lines' => '必须确认当前订单的全部订单行。']);
            }

            SalesOrderFulfillment::where('sales_order_id', $order->id)
                ->where('demand_status', 'pending')->delete();
            $activeDemandsByLine = SalesOrderProductionRequirement::query()
                ->where('sales_order_id', $order->id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->get()
                ->groupBy('sales_order_line_id');
            $referencedDemandIds = \Illuminate\Support\Facades\Schema::hasTable('erp_work_orders')
                ? WorkOrder::query()
                    ->whereIn('production_demand_id', $activeDemandsByLine->flatten()->pluck('id'))
                    ->pluck('production_demand_id')->unique()->map(fn ($id) => (int) $id)->all()
                : [];
            // Supersession is deferred until the replacement demand is known.
            // Existing active demands with work-order references are preserved.
            /*
                // 混合履约时订单整体可能仍是 blocked，但匹配行已落成 ready；重试前
                // 必须一并失效旧 ready 快照，否则同一需求会留下两个有效生产需求。
                ->where('is_active', true)->whereIn('requirement_status', ['draft', 'blocked', 'ready'])
                 // Supersession is deferred until the replacement demand is known.
            */

            $prepared = [];
            $blocked = false;
            foreach ($order->lines as $line) {
                $row = $byLine->get($line->id);
                if (!$row) throw ValidationException::withMessages(['lines' => "缺少第 {$line->line_no} 行的履约确认结果。"]);
                $alreadyFulfilled = SalesOrderFulfillment::where('sales_order_line_id', $line->id)
                    ->where('demand_status', 'confirmed')->get()
                    ->sum(fn (SalesOrderFulfillment $record) => $this->fulfillmentSalesQuantity($record, $line));
                $remainingSalesQty = max(0, (float) $line->order_qty - $alreadyFulfilled);
                $quantities = $this->validateDecisionQuantities($line, $row, $remainingSalesQty);
                $currentConfirmQty = (float) $quantities['inventory_qty'] + (float) $quantities['production_qty']
                    + (float) $quantities['service_qty'] + (float) $quantities['no_delivery_qty'];
                $inventoryAnalysis = $this->isPhysicalLine($line)
                    ? $this->inventoryAvailability->analyzeSalesOrderLine($line, $currentConfirmQty, true)
                    : null;
                if ($inventoryAnalysis && (float) $quantities['inventory_qty'] > (float) $inventoryAnalysis['available_sales_qty'] + 0.00000001) {
                    throw ValidationException::withMessages([
                        'lines' => "第 {$line->line_no} 行提交时可用成品库存已不足，请重新计算履约方案。",
                    ]);
                }
                $isAdjusted = $inventoryAnalysis
                    && (abs((float) $quantities['inventory_qty'] - (float) $inventoryAnalysis['suggested_inventory_qty']) > 0.00000001
                        || abs((float) $quantities['production_qty'] - (float) $inventoryAnalysis['suggested_production_qty']) > 0.00000001);
                if ($isAdjusted && blank($adjustmentReason)) {
                    throw ValidationException::withMessages([
                        'adjustment_reason' => '手工修改系统履约建议时必须填写调整原因。',
                    ]);
                }
                $inventoryAllocations = $inventoryAnalysis && (float) $quantities['inventory_qty'] > 0
                    ? $this->inventoryAvailability->allocateBaseQuantity(
                        $inventoryAnalysis,
                        $this->salesToBaseQuantity($line, (float) $quantities['inventory_qty'])
                    )
                    : [];
                $productionQty = (float) $quantities['production_qty'];
                $bom = $productionQty > 0 ? $this->bomMatcher->match($line->product_id, $line->sku_id, $line->item_id) : null;
                $lineBlocked = ($productionQty > 0 && (($bom['status'] ?? null) !== 'matched'))
                    || ($productionQty > 0 && $line->is_special_customized && !$this->hasTechnicalAttachment($line));
                $blocked = $blocked || $lineBlocked;
                $prepared[$line->id] = compact(
                    'quantities', 'bom', 'lineBlocked', 'alreadyFulfilled', 'remainingSalesQty',
                    'inventoryAnalysis', 'inventoryAllocations', 'isAdjusted'
                );
            }

            foreach ($order->lines as $line) {
                $quantities = $prepared[$line->id]['quantities'];
                foreach (['inventory', 'production', 'service', 'no_delivery'] as $type) {
                    $salesQuantity = $quantities[$type.'_qty'];
                    if ($salesQuantity <= 0) continue;
                    $itemBaseQuantity = $this->isPhysicalLine($line)
                        ? $this->salesToBaseQuantity($line, $salesQuantity)
                        : null;
                    $executionQuantity = $itemBaseQuantity ?? $salesQuantity;
                    $analysis = $prepared[$line->id]['inventoryAnalysis'];
                    $planningSnapshot = $analysis ? [
                        'calculated_at' => $analysis['calculated_at'],
                        'available_base_qty' => $analysis['available_base_qty'],
                        'available_sales_qty' => $analysis['available_sales_qty'],
                        'suggested_inventory_qty' => $analysis['suggested_inventory_qty'],
                        'suggested_production_qty' => $analysis['suggested_production_qty'],
                        'suggestion_reason' => $analysis['suggestion_reason'],
                        'final_inventory_qty' => (float) $quantities['inventory_qty'],
                        'final_production_qty' => (float) $quantities['production_qty'],
                        'adjusted' => (bool) $prepared[$line->id]['isAdjusted'],
                        'adjustment_reason' => $prepared[$line->id]['isAdjusted'] ? trim((string) $adjustmentReason) : null,
                        'confirmed_by' => $operatorName,
                        'confirmed_at' => now()->toDateTimeString(),
                    ] : null;

                    $allocations = $type === 'inventory' ? $prepared[$line->id]['inventoryAllocations'] : [null];
                    $remainingInventorySales = (float) $salesQuantity;
                    foreach ($allocations as $index => $allocation) {
                        $allocatedBaseQty = $allocation ? (float) $allocation['base_qty'] : $itemBaseQuantity;
                        $allocatedSalesQty = $allocation
                            ? ($index === array_key_last($allocations)
                                ? $remainingInventorySales
                                : round($allocatedBaseQty / $this->fulfillmentFactor($line), 8))
                            : (float) $salesQuantity;
                        $remainingInventorySales = max(0, $remainingInventorySales - $allocatedSalesQty);
                        SalesOrderFulfillment::create([
                            'sales_order_id' => $order->id,
                            'sales_order_line_id' => $line->id,
                            'fulfillment_type' => $type,
                            'fulfillment_qty' => $allocation ? $allocatedBaseQty : $executionQuantity,
                            'sales_qty' => $allocatedSalesQty,
                            'sales_unit_id' => $line->unit_id,
                            'sales_unit_code_snapshot' => $line->unit_code_snapshot,
                            'sales_unit_name_snapshot' => $line->unit_name_snapshot,
                            'fulfillment_factor_snapshot' => $this->isPhysicalLine($line) ? $this->fulfillmentFactor($line) : null,
                            'item_base_qty' => $allocation ? $allocatedBaseQty : $itemBaseQuantity,
                            'item_id' => $this->isPhysicalLine($line) ? $line->item_id : null,
                            'base_unit_id' => $this->isPhysicalLine($line) ? $line->item_base_unit_id : null,
                            'base_unit_name_snapshot' => $this->isPhysicalLine($line) ? $line->item_base_unit_name_snapshot : ($type === 'service' ? '项' : $line->unit_name_snapshot),
                            'inventory_balance_id' => $allocation['inventory_balance_id'] ?? null,
                            'warehouse_id' => $allocation['warehouse_id'] ?? null,
                            'location_id' => $allocation['location_id'] ?? null,
                            'batch_no' => $allocation['batch_no'] ?? null,
                            'reservation_status' => $type === 'inventory' ? 'pending' : 'not_required',
                            'production_requirement_status' => $type === 'production'
                                ? ($prepared[$line->id]['lineBlocked'] ? 'blocked' : ($blocked ? 'pending' : 'confirmed'))
                                : 'not_required',
                            'demand_status' => $blocked ? 'pending' : 'confirmed',
                            'match_snapshot' => [
                                'source' => 'order_production_confirmation',
                                'snapshot_locked' => true,
                                'sales_qty' => $allocatedSalesQty,
                                'fulfillment_factor' => $this->isPhysicalLine($line) ? $this->fulfillmentFactor($line) : null,
                                'item_base_qty' => $allocation ? $allocatedBaseQty : $itemBaseQuantity,
                                'planning' => $planningSnapshot,
                                'inventory_allocation' => $allocation,
                            ],
                            'remark' => $remark,
                        ]);
                    }
                }

                $lineTotals = $this->fulfillmentSalesQuantities(
                    SalesOrderFulfillment::where('sales_order_line_id', $line->id)
                        ->where('demand_status', 'confirmed')->get(),
                    $line
                );
                $effectiveLineQty = max(0, (float) $line->order_qty - (float) $line->cancelled_qty);
                $confirmedLineQty = $lineTotals['inventory_qty'] + $lineTotals['production_qty']
                    + $lineTotals['service_qty'] + $lineTotals['no_delivery_qty'];
                $lineTotals['undetermined_qty'] = max(0, $effectiveLineQty - $confirmedLineQty);
                $line->update([
                    'fulfillment_type' => $this->lineFulfillmentType($lineTotals),
                    'line_status' => $blocked ? 'fulfillment_blocked' : ($lineTotals['undetermined_qty'] > 0 ? 'partially_confirmed' : 'demand_confirmed'),
                    'inventory_fulfilled_qty' => $lineTotals['inventory_qty'],
                    'production_required_qty' => $lineTotals['production_qty'],
                    'service_fulfilled_qty' => $lineTotals['service_qty'],
                    'no_delivery_qty' => $lineTotals['no_delivery_qty'],
                    'undetermined_qty' => $lineTotals['undetermined_qty'],
                ]);

                if ($quantities['production_qty'] > 0) {
                    if ($line->is_special_customized && !$this->hasTechnicalAttachment($line)) {
                        throw ValidationException::withMessages(['attachments' => "第 {$line->line_no} 行为特殊定制，补齐设计图纸或技术附件后才能确认生产履约。"]);
                    }
                    $bom = $prepared[$line->id]['bom'] ?? [];
                    $status = ($bom['status'] ?? null) === 'matched' ? 'ready' : 'blocked';
                    $productionBaseQty = $this->salesToBaseQuantity($line, $quantities['production_qty']);
                    $demandAttributes = [
                        'requirement_no' => $this->numbers->next('sales_production_requirement', 'SOPR'),
                        'requirement_version' => 1,
                        'sales_order_id' => $order->id,
                        'sales_order_line_id' => $line->id,
                        'product_id' => $line->product_id,
                        'sku_id' => $line->sku_id,
                        'item_id' => $line->item_id,
                        'base_unit_id' => $line->item_base_unit_id,
                        'base_unit_name_snapshot' => $line->item_base_unit_name_snapshot,
                        'production_qty' => $quantities['production_qty'],
                        'item_base_required_qty' => $productionBaseQty,
                        'allocated_qty' => 0,
                        'consumed_qty' => 0,
                        'remaining_qty' => $quantities['production_qty'],
                        'closed_qty' => 0,
                        'requirement_status' => $status,
                        'is_active' => true,
                        'item_match_status' => $line->item_match_status,
                        'configuration_snapshot' => $line->configuration_snapshot,
                        'bom_snapshot' => $bom['bom_snapshot'] ?? null,
                        'bom_match_status' => $bom['status'] ?? 'blocked',
                        'bom_block_reason' => $bom['block_reason'] ?? null,
                        'bom_id' => $bom['bom_id'] ?? null,
                        'bom_version_id' => $bom['bom_version_id'] ?? null,
                        'bom_version' => $bom['bom_version'] ?? null,
                        'routing_snapshot' => null,
                        'routing_match_status' => 'not_required_current_stage',
                        'routing_block_reason' => null,
                        'routing_id' => null,
                        'routing_version_id' => null,
                        'drawing_snapshot' => $line->drawing_snapshot,
                        'technical_attachment_snapshot' => $this->attachmentSnapshot($line),
                        'inspection_snapshot' => $line->inspection_snapshot,
                        'required_delivery_date' => $order->required_delivery_date,
                        'is_urgent' => $order->is_urgent,
                        'is_delay' => $order->is_delay,
                        'delay_date' => $order->delay_date,
                        'is_ready_for_work_order' => false,
                        'confirmed_at' => now(),
                        'confirmed_by' => $operatorName,
                        'remark' => $remark,
                    ];
                    $candidates = $activeDemandsByLine->get($line->id, collect());
                    $referenced = $candidates->filter(fn (SalesOrderProductionRequirement $candidate) => in_array((int) $candidate->id, $referencedDemandIds, true));
                    $unboundExecutionFacts = $candidates->filter(fn (SalesOrderProductionRequirement $candidate) =>
                        ! in_array((int) $candidate->id, $referencedDemandIds, true)
                        && $this->productionDemandHasExecutionFacts($candidate)
                    );
                    if ($unboundExecutionFacts->isNotEmpty()) {
                        throw ValidationException::withMessages([
                            'production_requirements' => "第 {$line->line_no} 行存在无法绑定到 WorkOrder 的生产执行事实，重试时不能自动替换，请先修复历史关联。",
                        ]);
                    }
                    if ($referenced->count() === 1) {
                        $existing = $referenced->first();
                        if (! $this->sameProductionDemandSnapshot($existing, $demandAttributes)) {
                            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行已有工单引用的生产需求，本次重试不能修改数量或 BOM 快照。"]);
                        }
                        foreach ($candidates as $candidate) {
                            if ((int) $candidate->id === (int) $existing->id
                                || in_array((int) $candidate->id, $referencedDemandIds, true)) continue;
                            $candidate->update([
                                'requirement_status' => 'superseded',
                                'is_active' => false,
                                'superseded_by_id' => $existing->id,
                                'superseded_reason' => '重试时将历史重复有效需求收敛到已被工单引用的生产需求。',
                                'business_version' => (int) ($candidate->business_version ?: 1) + 1,
                            ]);
                        }
                        continue;
                    }
                    if ($referenced->count() > 1) {
                        throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行存在多个已被工单引用的有效生产需求，请先修复重复数据。"]);
                    }

                    $newDemand = SalesOrderProductionRequirement::create($demandAttributes);
                    foreach ($candidates as $candidate) {
                        $candidate->update([
                            'requirement_status' => 'superseded',
                            'is_active' => false,
                            'superseded_by_id' => $newDemand->id,
                            'superseded_reason' => '由同一销售订单行的重试生产需求替代。',
                            'business_version' => (int) ($candidate->business_version ?: 1) + 1,
                        ]);
                    }
                }
            }

            // A line that no longer has a production quantity has no replacement
            // demand.  Close only unreferenced active candidates; referenced
            // demands remain as historical execution anchors.
            foreach ($activeDemandsByLine->flatten() as $candidate) {
                $linePrepared = $prepared[$candidate->sales_order_line_id] ?? null;
                if (($linePrepared['quantities']['production_qty'] ?? 0) <= 0
                    && ! in_array((int) $candidate->id, $referencedDemandIds, true)) {
                    // Removing the planning quantity must not erase an execution
                    // fact merely because no WorkOrder FK was reconstructed. The
                    // demand is the only durable history anchor in that case, so
                    // reject the retry and roll back the whole confirmation.
                    if ($this->productionDemandHasExecutionFacts($candidate)) {
                        throw ValidationException::withMessages([
                            'production_requirements' => "第 {$candidate->sales_order_line_id} 行生产需求已有执行事实但没有可绑定的 WorkOrder，不能移除生产数量；请先补齐历史关联。",
                        ]);
                    }
                    $candidate->update([
                        'requirement_status' => 'superseded',
                        'is_active' => false,
                        'superseded_by_id' => null,
                        'superseded_reason' => 'Production quantity was removed and no WorkOrder references this demand.',
                        'business_version' => (int) ($candidate->business_version ?: 1) + 1,
                    ]);
                }
            }

            $remainingAfterConfirmation = $order->lines->sum(function (SalesOrderLine $line) {
                $confirmedSalesQty = SalesOrderFulfillment::where('sales_order_line_id', $line->id)
                    ->where('demand_status', 'confirmed')->get()
                    ->sum(fn (SalesOrderFulfillment $row) => $this->fulfillmentSalesQuantity($row, $line));
                $effectiveQty = max(0, (float) $line->order_qty - (float) $line->cancelled_qty);
                return max(0, $effectiveQty - $confirmedSalesQty);
            });
            $productionConfirmStatus = $blocked ? 'blocked' : ($remainingAfterConfirmation > 0.00000001 ? 'pending' : 'confirmed');
            if (!$blocked) {
                $this->inventoryReservations->reserveForSalesOrder($order->fresh('fulfillments'));
            }
            $order->update([
                // Production confirmation allocates the fulfillment plan only. Actual
                // fulfillment starts when downstream execution posts a real result.
                'fulfillment_status' => 'pending',
                'production_confirm_status' => $productionConfirmStatus,
            ]);
            $this->log($order, 'production_confirm', 'pending', $productionConfirmStatus, '订单生产确认已保存销售数量与Item基本数量双口径履约需求；未创建工单、工序任务或排程。', $operatorName);
            return $order->fresh(['lines', 'fulfillments', 'productionRequirements']);
        });
    }

    private function productionDemandHasExecutionFacts(SalesOrderProductionRequirement $demand): bool
    {
        $epsilon = 0.00000001;
        if ((float) ($demand->consumed_qty ?? 0) > $epsilon || (float) ($demand->closed_qty ?? 0) > $epsilon) return true;
        if (in_array(strtolower(trim((string) $demand->requirement_status)), [
            'partially_consumed', 'consumed', 'closed', 'in_progress', 'completed', 'fulfilled', 'executing',
        ], true)) return true;

        // Compatibility rows may carry execution facts introduced by a later
        // execution migration. Treat any positive actual/executed quantity or
        // execution timestamp as immutable history, even before a WorkOrder FK
        // can be reconstructed.
        foreach (['actual_qty', 'executed_qty', 'fulfilled_qty', 'completed_qty', 'posted_qty'] as $column) {
            if (array_key_exists($column, $demand->getAttributes()) && (float) $demand->{$column} > $epsilon) return true;
        }
        foreach (['consumed_at', 'closed_at', 'executed_at', 'completed_at', 'posted_at'] as $column) {
            if (array_key_exists($column, $demand->getAttributes()) && $demand->{$column} !== null) return true;
        }
        return false;
    }

    private function requiredQuantity(SalesOrderLine $line): float
    {
        return (float) $line->order_qty;
    }

    private function suggestQuantities(SalesOrderLine $line, float $confirmQty): array
    {
        $result = ['inventory_qty' => 0.0, 'production_qty' => 0.0, 'service_qty' => 0.0, 'no_delivery_qty' => 0.0, 'undetermined_qty' => 0.0];
        if ($line->line_type === 'service') {
            $result['service_qty'] = $confirmQty;
        } elseif (in_array($line->line_type, ['no_delivery', 'fee', 'auxiliary'], true)) {
            $result['no_delivery_qty'] = $confirmQty;
        } else {
            $analysis = $this->inventoryAvailability->analyzeSalesOrderLine($line, $confirmQty);
            $result['inventory_qty'] = $analysis['suggested_inventory_qty'];
            $result['production_qty'] = $analysis['suggested_production_qty'];
        }
        return $result;
    }

    private function validateDecisionQuantities(SalesOrderLine $line, array $row, float $remainingSalesQty): array
    {
        $keys = ['inventory_qty', 'production_qty', 'service_qty', 'no_delivery_qty'];
        $quantities = [];
        foreach ($keys as $key) {
            $quantities[$key] = round((float) ($row[$key] ?? 0), 8);
            if ($quantities[$key] < 0) {
                throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行履约数量不能小于 0。"]);
            }
        }
        $confirmQty = round((float) ($row['confirm_qty'] ?? $remainingSalesQty), 8);
        if (abs(array_sum($quantities) - $confirmQty) > 0.00000001) {
            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行各履约数量之和必须等于本次确认数量。"]);
        }
        if ($confirmQty > $remainingSalesQty + 0.00000001) {
            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行本次确认数量不能超过剩余可确认数量。"]);
        }
        $quantities['undetermined_qty'] = max(0, round($remainingSalesQty - $confirmQty, 8));
        if ($line->line_type === 'service' && ($quantities['inventory_qty'] > 0 || $quantities['production_qty'] > 0 || $quantities['no_delivery_qty'] > 0)) {
            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行为服务行，只能填写服务履约或尚未确定数量。"]);
        }
        if (in_array($line->line_type, ['no_delivery', 'fee', 'auxiliary'], true)
            && ($quantities['inventory_qty'] > 0 || $quantities['production_qty'] > 0 || $quantities['service_qty'] > 0)) {
            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行为无需发货行，不能进入库存、生产或服务履约。"]);
        }
        if (!in_array($line->line_type, ['service', 'no_delivery', 'fee', 'auxiliary'], true)
            && ($quantities['service_qty'] > 0 || $quantities['no_delivery_qty'] > 0)) {
            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行为实物行，只能拆分库存、生产或尚未确定数量。"]);
        }
        if ($line->unit_id && ($salesUnit = Unit::find($line->unit_id))) {
            foreach (array_merge($keys, ['undetermined_qty']) as $key) $this->conversions->assertUnitPrecision($quantities[$key], $salesUnit, 'lines.'.$line->line_no.'.'.$key);
            $this->conversions->assertUnitPrecision($confirmQty, $salesUnit, 'lines.'.$line->line_no.'.confirm_qty');
        }
        if ($this->isPhysicalLine($line) && $line->item_base_unit_id && ($baseUnit = Unit::find($line->item_base_unit_id))) {
            foreach (['inventory_qty', 'production_qty', 'undetermined_qty'] as $key) {
                $this->conversions->assertUnitPrecision(
                    $this->salesToBaseQuantity($line, $quantities[$key]),
                    $baseUnit,
                    'lines.'.$line->line_no.'.'.$key.'_base'
                );
            }
        }
        return $quantities;
    }

    private function fulfillmentSalesQuantities($rows, SalesOrderLine $line): array
    {
        $result = ['inventory_qty' => 0.0, 'production_qty' => 0.0, 'service_qty' => 0.0, 'no_delivery_qty' => 0.0, 'undetermined_qty' => 0.0];
        foreach ($rows as $row) {
            $key = $row->fulfillment_type.'_qty';
            if (array_key_exists($key, $result)) $result[$key] += $this->fulfillmentSalesQuantity($row, $line);
        }
        return $result;
    }

    private function fulfillmentSalesQuantity(SalesOrderFulfillment $fulfillment, SalesOrderLine $line): float
    {
        if ($fulfillment->sales_qty !== null) return (float) $fulfillment->sales_qty;
        if (!$this->isPhysicalLine($line)) return (float) $fulfillment->fulfillment_qty;
        return (float) $fulfillment->fulfillment_qty / $this->fulfillmentFactor($line);
    }

    private function fulfillmentFactor(SalesOrderLine $line): float
    {
        $factor = (float) ($line->fulfillment_factor_snapshot ?: 0);
        if ($this->isPhysicalLine($line) && $factor <= 0) {
            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行缺少有效履约换算因子。"]);
        }
        return $factor > 0 ? $factor : 1.0;
    }

    private function salesToBaseQuantity(SalesOrderLine $line, float $salesQuantity): float
    {
        return round($salesQuantity * $this->fulfillmentFactor($line), 8);
    }

    private function isPhysicalLine(SalesOrderLine $line): bool
    {
        return !in_array($line->line_type, ['service', 'no_delivery', 'fee', 'auxiliary'], true);
    }

    private function lineFulfillmentType(array $quantities): string
    {
        $positive = collect($quantities)->except('undetermined_qty')->filter(fn ($value) => (float) $value > 0)->keys()
            ->map(fn ($key) => str_replace('_qty', '', $key))->values();
        if ($positive->isEmpty()) return 'undetermined';
        return $positive->count() === 1 ? $positive->first() : 'mixed';
    }

    private function resultTextFromQuantities(array $quantities): string
    {
        $rows = [];
        if ($quantities['inventory_qty'] > 0) $rows[] = '库存履约';
        if ($quantities['production_qty'] > 0) $rows[] = '生产需求契约';
        if ($quantities['service_qty'] > 0) $rows[] = '服务履约';
        if ($quantities['no_delivery_qty'] > 0) $rows[] = '无需发货';
        if ($quantities['undetermined_qty'] > 0) $rows[] = '尚未确定';
        return $rows ? implode(' + ', $rows) : '无生成结果';
    }

    private function fulfillmentPlanSummary(SalesOrder $order): array
    {
        $rows = $order->fulfillments->where('demand_status', 'confirmed');
        $quantities = collect(['inventory', 'production', 'service', 'no_delivery'])
            ->mapWithKeys(fn (string $type) => [
                $type => round($rows->where('fulfillment_type', $type)
                    ->sum(fn (SalesOrderFulfillment $row) => (float) ($row->sales_qty ?? $row->fulfillment_qty)), 8),
            ]);
        $effectiveQty = $order->lines->sum(fn (SalesOrderLine $line) => max(0, (float) $line->order_qty - (float) $line->cancelled_qty));
        $allocatedQty = (float) $quantities->sum();
        $unallocatedQty = max(0, $effectiveQty - $allocatedQty);
        $status = $allocatedQty <= 0.00000001
            ? 'unallocated'
            : ($unallocatedQty > 0.00000001 ? 'partially_allocated' : 'allocated');

        return [
            'status' => $status,
            'status_label' => [
                'unallocated' => '未分配',
                'partially_allocated' => '部分分配',
                'allocated' => '已分配完成',
            ][$status],
            'quantities' => $quantities->put('undetermined', round($unallocatedQty, 8))->all(),
        ];
    }

    private function fulfillmentCompositionLabel(SalesOrder $order, ?array $plan = null): string
    {
        $plan ??= $this->fulfillmentPlanSummary($order);
        $types = $order->fulfillments->where('demand_status', 'confirmed')
            ->filter(fn (SalesOrderFulfillment $row) => in_array($row->fulfillment_type, ['inventory', 'production', 'service', 'no_delivery'], true)
                && (float) ($row->sales_qty ?? $row->fulfillment_qty) > 0)
            ->pluck('fulfillment_type')->unique()->values();
        if ($types->isEmpty()) return '尚未形成履约明细';
        if ($types->count() === 1 && $types->first() === 'inventory' && $plan['status'] === 'allocated') return '全部库存';
        if ($types->count() === 1 && $types->first() === 'production' && $plan['status'] === 'allocated') return '全部生产';
        if ($types->contains('inventory') && $types->contains('production')) return '部分库存 + 部分生产';
        $names = ['inventory' => '库存', 'production' => '生产', 'service' => '服务', 'no_delivery' => '无需发货'];
        return $types->map(fn ($type) => $names[$type] ?? $type)->implode(' + ');
    }

    private function submitResults(array $counts): array
    {
        $rows = ['保存订单生产确认结果', '锁定本次确认使用的订单行和生产资料版本'];
        if ($counts['inventory']) $rows[] = '生成库存履约需求';
        if ($counts['production']) $rows[] = '生成生产需求契约';
        if ($counts['service']) $rows[] = '生成服务履约需求';
        if ($counts['undetermined']) $rows[] = '保留尚未确认数量，订单履约进度为部分履约';
        return $rows;
    }

    private function hasTechnicalAttachment(SalesOrderLine $line): bool
    {
        return SalesOrderAttachment::where('sales_order_line_id', $line->id)->where('status', 'active')
            ->whereIn('attachment_type', ['design_drawing', 'technical_file', 'customer_technical_agreement'])->exists();
    }

    private function attachmentSnapshot(SalesOrderLine $line): array
    {
        return SalesOrderAttachment::where('sales_order_line_id', $line->id)->where('status', 'active')
            ->get(['id', 'attachment_type', 'original_name', 'url', 'version_no'])->toArray();
    }

    private function sameProductionDemandSnapshot(SalesOrderProductionRequirement $existing, array $replacement): bool
    {
        $sameNumber = static fn (mixed $left, mixed $right): bool => abs((float) $left - (float) $right) <= 0.00000001;
        $sameJson = static fn (mixed $left, mixed $right): bool => json_encode($left, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
            === json_encode($right, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        return $sameNumber($existing->production_qty, $replacement['production_qty'] ?? 0)
            && $existing->bom_match_status === ($replacement['bom_match_status'] ?? null)
            && (int) ($existing->bom_id ?? 0) === (int) ($replacement['bom_id'] ?? 0)
            && (int) ($existing->bom_version_id ?? 0) === (int) ($replacement['bom_version_id'] ?? 0)
            && (string) ($existing->bom_version ?? '') === (string) ($replacement['bom_version'] ?? '')
            && $sameJson($existing->bom_snapshot, $replacement['bom_snapshot'] ?? null);
    }

    private function log(SalesOrder $order, string $action, ?string $before, ?string $after, string $content, string $operator): void
    {
        SalesOrderLog::create([
            'sales_order_id' => $order->id,
            'action' => $action,
            'before_status' => $before,
            'after_status' => $after,
            'operator' => $operator,
            'content' => $content,
        ]);
    }
}
