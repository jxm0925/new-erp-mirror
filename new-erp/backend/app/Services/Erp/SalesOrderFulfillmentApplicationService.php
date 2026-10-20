<?php

namespace App\Services\Erp;

use App\Models\Erp\{
    SalesOrder,
    SalesOrderAttachment,
    SalesOrderCommand,
    SalesOrderFulfillment,
    SalesOrderLine,
    SalesOrderLog,
    Unit
};
use App\Exceptions\Erp\WorkOrderDomainException;
use Illuminate\Database\QueryException;
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
        private readonly SalesOrderSnapshotService $snapshots,
    ) {}

    public function confirmOrderAndFulfill(int $orderId, string $operatorName, object $operator): SalesOrder
    {
        return DB::transaction(function () use ($orderId, $operatorName, $operator): SalesOrder {
            $current = SalesOrder::query()->with('fulfillments')->whereKey($orderId)->lockForUpdate()->firstOrFail();
            if ($current->order_status === 'confirmed'
                && in_array($current->production_confirm_status, ['confirmed', 'blocked'], true)
                && $current->fulfillments->where('demand_status', 'confirmed')->isNotEmpty()) {
                return $current->fresh(['lines', 'fulfillments']);
            }
            if ($current->order_status === 'draft') {
                $this->confirmOrder($orderId, $operatorName);
            } elseif ($current->order_status !== 'confirmed') {
                throw ValidationException::withMessages(['order_status' => '当前销售订单状态不能执行正式确认。']);
            }
            $preview = $this->preview($orderId);
            $decisions = collect($preview['lines'])->map(fn (array $line): array => [
                'sales_order_line_id' => $line['sales_order_line_id'],
                'confirm_qty' => $line['confirm_qty'],
                'inventory_qty' => $line['system_suggested_inventory_qty'],
                'production_qty' => $line['system_suggested_production_qty'],
                'purchase_qty' => $line['system_suggested_purchase_qty'],
                'service_qty' => $line['service_qty'],
                'no_delivery_qty' => $line['no_delivery_qty'],
            ])->all();

            return $this->confirmProduction(
                $orderId,
                $decisions,
                '销售订单正式确认后由系统实时锁定库存并建立生产层级。',
                $operatorName,
                null,
                true,
                $operator,
            );
        }, 5);
    }

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
            SalesOrderFulfillmentPolicy::assertConfirmationRequirements($order);

            // Drafts may remain open while finance masters change. Formal
            // confirmation revalidates and freezes the selected records while
            // this order row is locked, so later master edits cannot rewrite history.
            $order = $this->snapshots->freezeFinancialTermsForConfirmation($order);
            $order->load(['lines.sku.salesUnit.standardUnit', 'lines.item.unit.standardUnit']);

            foreach ($order->lines as $line) {
                $price = (float) $line->unit_price;
                if ($line->commercial_role === 'gift' ? abs($price) >= 0.00000001 : $price <= 0) {
                    if ($line->commercial_role === 'gift') {
                        throw ValidationException::withMessages(['unit_price' => '赠品行销售单价必须为 0。']);
                    }
                    throw ValidationException::withMessages(['unit_price' => "第 {$line->line_no} 行销售单价必须大于 0。"]);
                }
                // Service and no-delivery identities do not require a physical
                // SKU-Item relation, even if a historical SKU defaults to physical.
                $requirement = $this->isPhysicalLine($line)
                    ? $this->conversions->calculateSalesRequirement($line->sku, $line->order_qty, true)
                    : ['item' => null, 'factor' => null, 'base_qty' => 0.0];
                $item = $requirement['item'];
                if ($this->isPhysicalLine($line) && (! $item || ! $item->is_stock_item)) {
                    throw ValidationException::withMessages(['item_id' => "第 {$line->line_no} 行实物交付必须关联启用库存管理的物料。"]);
                }
                $baseUnit = $requirement['base_unit'] ?? null;
                $matchSnapshot = $item ? [
                    'relation_id' => $requirement['relation']?->id,
                    'relation_effective_at' => $requirement['relation']?->effective_at,
                    'item_id' => $item->id,
                    'fulfillment_factor' => $requirement['factor'],
                    'item_base_required_qty' => $requirement['base_qty'],
                    'fulfillment_strategy' => $item->isPurchaseFulfilledItem() ? 'purchase' : 'production',
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

            $order->update([
                'order_status' => 'confirmed',
                'confirm_status' => 'confirmed',
                'fulfillment_status' => 'pending',
                'production_confirm_status' => 'pending',
                'confirmed_at' => now(),
                'confirmed_by' => $operatorName,
            ]);
            $this->log($order, 'confirm', 'draft', 'confirmed', '销售订单已确认并锁定单位换算信息；尚未生成库存备货或生产需求。', $operatorName);
            return $order->fresh(['lines.product', 'lines.sku', 'lines.item']);
        });
    }

    public function preview(int $orderId): array
    {
        $order = SalesOrder::with(['lines.product', 'lines.sku', 'lines.item', 'fulfillments'])->findOrFail($orderId);
        $existing = $order->fulfillments->groupBy('sales_order_line_id');
        $plannedByBalance = [];
        $lines = $order->lines->sortBy('line_no')->values()->map(function (SalesOrderLine $line) use ($existing, $order, &$plannedByBalance) {
            $lineFulfillments = $existing->get($line->id, collect());
            $confirmed = $lineFulfillments->where('demand_status', 'confirmed');
            $pending = $lineFulfillments->where('demand_status', 'pending');
            // A confirmed fulfillment row is an allocated/locked fulfillment plan. It is
            // not proof that the goods were produced, shipped or otherwise fulfilled.
            // Keep the allocated quantity separate so the UI and downstream consumers do
            // not report a production requirement as an actually fulfilled quantity.
            $confirmedAllocatedQty = $confirmed->sum(fn (SalesOrderFulfillment $row) => $this->fulfillmentSalesQuantity($row, $line));
            $remainingSalesQty = max(0, $this->requiredQuantity($line) - $confirmedAllocatedQty);
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
                        'suggested_purchase_qty' => (float) ($planningSnapshot['suggested_purchase_qty'] ?? $quantities['purchase_qty']),
                        'suggestion_reason' => $planningSnapshot['suggestion_reason'] ?? '已确认备货方案按历史记录展示',
                    ];
                }
            } else {
                $confirmQty = $remainingSalesQty;
                if ($this->isPhysicalLine($line)) {
                    $analysis = $this->inventoryAvailability->analyzeSalesOrderLine($line, $confirmQty, false, $plannedByBalance);
                    $quantities = [
                        'inventory_qty' => $analysis['suggested_inventory_qty'],
                        'production_qty' => $analysis['suggested_production_qty'],
                        'purchase_qty' => $analysis['suggested_purchase_qty'],
                        'service_qty' => 0.0,
                        'no_delivery_qty' => 0.0,
                        'undetermined_qty' => 0.0,
                    ];
                    $this->addPlannedAllocations($plannedByBalance, $this->inventoryAvailability->allocateBaseQuantity(
                        $analysis, $this->salesToBaseQuantity($line, $quantities['inventory_qty']),
                    ));
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
                        'suggested_purchase_qty' => (float) $quantities['purchase_qty'],
                        'suggestion_reason' => '历史备货方案已锁定',
                    ]
                    : $this->inventoryAvailability->analyzeSalesOrderLine($line, $confirmQty);
            }
            $productionQty = (float) $quantities['production_qty'];
            $bom = $productionQty > 0 ? $this->bomMatcher->match($line->product_id, $line->sku_id, $line->item_id, $line->configuration_snapshot) : null;
            $drawing = $line->is_special_customized ? ($this->hasTechnicalAttachment($line) ? 'ready' : 'missing') : 'not_required';
            $blocking = [];
            // 技术条件在自动生成的工单中处理；销售确认只确定商业需求及履约数量。
            return [
                'sales_order_line_id' => $line->id,
                'line_no' => $line->line_no,
                'product_name' => $line->product_name,
                'sku_name' => $line->sku_name,
                'item_id' => $line->item_id,
                'item_name' => $line->item_name,
                'line_type' => $line->line_type,
                'is_purchase_fulfilled' => SalesOrderFulfillmentPolicy::isPurchaseLine($line),
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
                'system_suggested_purchase_qty' => (float) ($analysis['suggested_purchase_qty'] ?? 0),
                'system_suggestion_reason' => $analysis['suggestion_reason'] ?? ($line->line_type === 'service' ? '服务类订单行直接登记为服务项目' : '无需发货订单行不进入库存或生产'),
                'inventory_calculated_at' => $analysis['calculated_at'] ?? now()->toDateTimeString(),
                'plan_locked' => $order->production_confirm_status === 'confirmed',
                'confirmed_allocated_qty' => $confirmedAllocatedQty,
                // Backward compatibility for older clients. New clients must use
                // confirmed_allocated_qty because this is a planning quantity.
                'already_fulfilled_qty' => $confirmedAllocatedQty,
                'remaining_sales_qty' => $remainingSalesQty,
                'remaining_confirm_qty' => $remainingSalesQty,
                'confirm_qty' => $confirmQty,
                'item_base_confirm_qty' => $this->isPhysicalLine($line) ? $this->salesToBaseQuantity($line, $confirmQty) : null,
                'inventory_base_qty' => $this->isPhysicalLine($line) ? $this->salesToBaseQuantity($line, $quantities['inventory_qty']) : null,
                'production_base_qty' => $this->isPhysicalLine($line) ? $this->salesToBaseQuantity($line, $quantities['production_qty']) : null,
                'purchase_base_qty' => $this->isPhysicalLine($line) ? $this->salesToBaseQuantity($line, $quantities['purchase_qty']) : null,
                'undetermined_base_qty' => $this->isPhysicalLine($line) ? $this->salesToBaseQuantity($line, $quantities['undetermined_qty']) : null,
                ...$quantities,
                'blocking_reason' => implode('；', $blocking),
                'generated_result' => $this->resultTextFromQuantities($quantities),
                'data_readiness' => [
                    'bom' => $productionQty <= 0 ? 'not_required' : (($bom['status'] ?? null) === 'matched' ? 'ready' : 'missing'),
                    'routing' => $productionQty <= 0 ? 'not_required' : 'pending_next_stage',
                    'drawing' => $drawing,
                    'inspection' => 'ready',
                ],
            ];
        });
        $counts = [
            'inventory' => $lines->where('inventory_qty', '>', 0)->count(),
            'production' => $lines->where('production_qty', '>', 0)->count(),
            'purchase' => $lines->where('purchase_qty', '>', 0)->count(),
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

    public function confirmProduction(
        int $orderId,
        array $decisions,
        ?string $remark,
        string $operatorName,
        ?string $adjustmentReason = null,
        bool $automatic = false,
        ?object $operator = null,
        ?string $clientCommandId = null,
        ?int $expectedVersion = null,
    ): SalesOrder
    {
        if (! $automatic && (blank($clientCommandId) || $expectedVersion === null || $expectedVersion < 1)) {
            throw ValidationException::withMessages(['client_command_id' => '分批确认必须提供操作命令号和当前订单版本。']);
        }
        // 自动确认同样要记录真实操作账号，不能把工单创建人、状态日志记成 0。
        if ((int) ($operator?->legacy_id ?? $operator?->id ?? 0) < 1) {
            throw new WorkOrderDomainException('actor_required', $automatic ? '自动确认需要真实操作账号。' : '分批确认需要真实操作账号。', 403);
        }
        if ($clientCommandId !== null) {
            $clientCommandId = trim($clientCommandId);
            if ($clientCommandId === '' || strlen($clientCommandId) > 120) throw ValidationException::withMessages(['client_command_id' => '操作命令号不能为空且不能超过 120 个字符。']);
        }
        $requestHash = SalesOrderFulfillmentPolicy::confirmationHash($decisions, $remark, $adjustmentReason, $expectedVersion, $automatic);
        try {
            return DB::transaction(function () use ($orderId, $decisions, $remark, $operatorName, $adjustmentReason, $automatic, $operator, &$clientCommandId, $expectedVersion, $requestHash) {
                $order = SalesOrder::with(['lines.product', 'lines.sku', 'lines.item'])->lockForUpdate()->findOrFail($orderId);
                $clientCommandId ??= 'sales-confirmation:order:'.$orderId.':v'.((int) $order->business_version ?: 1);
                $existingCommand = SalesOrderCommand::where('client_command_id', $clientCommandId)->lockForUpdate()->first();
                if ($existingCommand) return $this->replayConfirmation($existingCommand, $orderId, $requestHash);
                if ($expectedVersion !== null && (int) $order->business_version !== $expectedVersion) {
                    throw new WorkOrderDomainException('version_conflict', '订单版本已变化，请刷新备货方案后重新确认。', 409, ['current_version' => (int) $order->business_version]);
                }
                // Funding is a production-release/start gate, not a sales-order
                // planning gate. The production hierarchy must remain visible while
                // funds are short so the exact condition can be monitored and fixed.
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
                if (count($decisions) !== $byLine->count()) {
                    throw ValidationException::withMessages(['lines' => '同一订单行不能在一个确认批次中重复提交。']);
                }
                $ledger = SalesOrderCommand::create([
                    'client_command_id' => $clientCommandId, 'command_type' => 'confirm_production',
                    'sales_order_id' => $orderId, 'request_hash' => $requestHash, 'status' => 'processing',
                    'initiated_by_legacy_id' => $operator?->legacy_id ?? $operator?->id,
                    'processing_started_at' => now(),
                ]);
                $batchFulfillmentIds = [];

                SalesOrderFulfillment::where('sales_order_id', $order->id)
                    ->where('demand_status', 'pending')->delete();
                $prepared = [];
                $plannedByBalance = [];
                foreach ($order->lines->sortBy('line_no') as $line) {
                    $row = $byLine->get($line->id);
                    if (!$row) throw ValidationException::withMessages(['lines' => "缺少第 {$line->line_no} 行的备货确认结果。"]);
                    if ($this->isPhysicalLine($line) && ! in_array(data_get($line->item_match_snapshot, 'fulfillment_strategy'), ['purchase', 'production'], true)) {
                        // Legacy or newly approved lines acquire their choice only in the
                        // new confirmation transaction. Existing confirmed batches stay intact.
                        $item = \App\Models\Erp\Item::whereKey($line->item_id)->lockForUpdate()->first();
                        if (! $item || $item->status !== 'enabled' || ! $item->is_stock_item) {
                            throw ValidationException::withMessages(['item_id' => "第 {$line->line_no} 行实物交付物料已失效或未启用库存管理。"]);
                        }
                        $snapshot = (array) $line->item_match_snapshot;
                        $snapshot['fulfillment_strategy'] = $item->isPurchaseFulfilledItem() ? 'purchase' : 'production';
                        $line->update(['item_match_snapshot' => $snapshot]);
                        $line->setRelation('item', $item);
                    }
                    $priorFulfillments = SalesOrderFulfillment::where('sales_order_line_id', $line->id)
                        ->where('demand_status', 'confirmed')->lockForUpdate()->get();
                    $alreadyFulfilled = $priorFulfillments
                        ->sum(fn (SalesOrderFulfillment $record) => $this->fulfillmentSalesQuantity($record, $line));
                    $remainingSalesQty = max(0, $this->requiredQuantity($line) - $alreadyFulfilled);
                    $quantities = $this->validateDecisionQuantities($line, $row, $remainingSalesQty);
                    $currentConfirmQty = (float) $quantities['inventory_qty'] + (float) $quantities['production_qty']
                        + (float) $quantities['purchase_qty'] + (float) $quantities['service_qty'] + (float) $quantities['no_delivery_qty'];
                    $inventoryAnalysis = $this->isPhysicalLine($line)
                        ? $this->inventoryAvailability->analyzeSalesOrderLine($line, $currentConfirmQty, true, $plannedByBalance)
                        : null;
                    if ($automatic && $inventoryAnalysis) {
                        // 自动确认在锁内按逐行剩余库存重算；同物料不同 SKU 不能重复借用同一批现货。
                        $quantities['inventory_qty'] = $inventoryAnalysis['suggested_inventory_qty'];
                        $quantities['production_qty'] = $inventoryAnalysis['suggested_production_qty'];
                        $quantities['purchase_qty'] = $inventoryAnalysis['suggested_purchase_qty'];
                    }
                    if ($inventoryAnalysis && (float) $quantities['inventory_qty'] > (float) $inventoryAnalysis['available_sales_qty'] + 0.00000001) {
                        throw ValidationException::withMessages([
                            'lines' => "第 {$line->line_no} 行提交时可用成品库存已不足，请重新计算备货方案。",
                        ]);
                    }
                    $isAdjusted = $inventoryAnalysis
                        && (abs((float) $quantities['inventory_qty'] - (float) $inventoryAnalysis['suggested_inventory_qty']) > 0.00000001
                            || abs((float) $quantities['production_qty'] - (float) $inventoryAnalysis['suggested_production_qty']) > 0.00000001
                            || abs((float) $quantities['purchase_qty'] - (float) $inventoryAnalysis['suggested_purchase_qty']) > 0.00000001);
                    if ($isAdjusted && blank($adjustmentReason)) {
                        throw ValidationException::withMessages([
                            'adjustment_reason' => '手工修改系统备货建议时必须填写调整原因。',
                        ]);
                    }
                    $inventoryAllocations = $inventoryAnalysis && (float) $quantities['inventory_qty'] > 0
                        ? $this->inventoryAvailability->allocateBaseQuantity(
                            $inventoryAnalysis,
                            $this->salesToBaseQuantity($line, (float) $quantities['inventory_qty'])
                        )
                        : [];
                    $this->addPlannedAllocations($plannedByBalance, $inventoryAllocations);
                    $productionQty = (float) $quantities['production_qty'];
                    $bom = $productionQty > 0 ? $this->bomMatcher->match($line->product_id, $line->sku_id, $line->item_id, $line->configuration_snapshot) : null;
                    // 与生产需求状态同一口径：只有 BOM 未匹配才算阻塞；缺图纸由工单发布检查拦截，
                    // 避免履约记录显示“阻塞”而生产需求显示“就绪”。
                    $lineBlocked = $productionQty > 0 && (($bom['status'] ?? null) !== 'matched');
                    $prepared[$line->id] = compact(
                        'quantities', 'bom', 'lineBlocked', 'alreadyFulfilled', 'remainingSalesQty',
                        'inventoryAnalysis', 'inventoryAllocations', 'isAdjusted', 'currentConfirmQty'
                    );
                }
                if (collect($prepared)->sum('currentConfirmQty') <= 0.00000001) {
                    throw ValidationException::withMessages(['lines' => '本次至少需要确认一项剩余数量；重试请使用原操作命令号。']);
                }

                foreach ($order->lines as $line) {
                    $quantities = $prepared[$line->id]['quantities'];
                    foreach (SalesOrderFulfillmentPolicy::TYPES as $type) {
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
                            'suggested_purchase_qty' => $analysis['suggested_purchase_qty'],
                            'suggestion_reason' => $analysis['suggestion_reason'],
                            'final_inventory_qty' => (float) $quantities['inventory_qty'],
                            'final_production_qty' => (float) $quantities['production_qty'],
                            'final_purchase_qty' => (float) $quantities['purchase_qty'],
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
                            $fulfillment = SalesOrderFulfillment::create([
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
                                'reservation_status' => $type === 'inventory' ? 'pending' : ($type === 'purchase' ? 'awaiting_purchase' : 'not_required'),
                                'production_requirement_status' => $type === 'production'
                                    ? ($prepared[$line->id]['lineBlocked'] ? 'blocked' : 'confirmed')
                                    : 'not_required',
                                'demand_status' => 'confirmed',
                                'match_snapshot' => [
                                    'source' => 'order_production_confirmation',
                                    'confirmation_command_id' => $clientCommandId,
                                    'fulfillment_strategy' => SalesOrderFulfillmentPolicy::isPurchaseLine($line) ? 'purchase' : 'production',
                                    'snapshot_locked' => true,
                                    'commercial_role' => $line->commercial_role ?: 'sale',
                                    'sales_qty' => $allocatedSalesQty,
                                    'fulfillment_factor' => $this->isPhysicalLine($line) ? $this->fulfillmentFactor($line) : null,
                                    'item_base_qty' => $allocation ? $allocatedBaseQty : $itemBaseQuantity,
                                    'planning' => $planningSnapshot,
                                    'inventory_allocation' => $allocation,
                                ],
                                'remark' => $remark,
                            ]);
                            $batchFulfillmentIds[] = (int) $fulfillment->id;
                        }
                    }

                    $lineTotals = $this->fulfillmentSalesQuantities(
                        SalesOrderFulfillment::where('sales_order_line_id', $line->id)
                            ->where('demand_status', 'confirmed')->get(),
                        $line
                    );
                    $effectiveLineQty = max(0, (float) $line->order_qty - (float) $line->cancelled_qty);
                    $confirmedLineQty = $lineTotals['inventory_qty'] + $lineTotals['production_qty']
                        + $lineTotals['purchase_qty'] + $lineTotals['service_qty'] + $lineTotals['no_delivery_qty'];
                    $lineTotals['undetermined_qty'] = max(0, $effectiveLineQty - $confirmedLineQty);
                    $line->update([
                        'fulfillment_type' => $this->lineFulfillmentType($lineTotals),
                        'line_status' => $prepared[$line->id]['lineBlocked'] ? 'fulfillment_blocked' : ($lineTotals['undetermined_qty'] > 0 ? 'partially_confirmed' : 'demand_confirmed'),
                        'inventory_fulfilled_qty' => $lineTotals['inventory_qty'],
                        'production_required_qty' => $lineTotals['production_qty'],
                        'service_fulfilled_qty' => $lineTotals['service_qty'],
                        'no_delivery_qty' => $lineTotals['no_delivery_qty'],
                        'undetermined_qty' => $lineTotals['undetermined_qty'],
                    ]);
                }

                $remainingAfterConfirmation = $order->lines->sum(function (SalesOrderLine $line) {
                    $confirmedSalesQty = SalesOrderFulfillment::where('sales_order_line_id', $line->id)
                        ->where('demand_status', 'confirmed')->get()
                        ->sum(fn (SalesOrderFulfillment $row) => $this->fulfillmentSalesQuantity($row, $line));
                    $effectiveQty = max(0, (float) $line->order_qty - (float) $line->cancelled_qty);
                    return max(0, $effectiveQty - $confirmedSalesQty);
                });
                // 分配完成即为销售生产需求确认完成。资料不足只阻断对应工单发布，
                // 不要求销售再执行一次“生产确认”，也不连带阻断其他产品的工单。
                $productionConfirmStatus = $remainingAfterConfirmation > 0.00000001 ? 'pending' : 'confirmed';
                $this->inventoryReservations->reserveForSalesOrder($order->fresh('fulfillments'));
                $order->update([
                    // Production confirmation allocates the fulfillment plan only. Actual
                    // fulfillment starts when downstream execution posts a real result.
                    'fulfillment_status' => 'pending',
                    'production_confirm_status' => $productionConfirmStatus,
                    'shipment_status' => SalesOrderFulfillmentPolicy::shipmentStatus($order->lines->map(fn ($line) => $line->getAttributes())->all()),
                    'business_version' => (int) $order->business_version + 1,
                ]);
                $this->log($order, 'production_confirm', 'pending', $productionConfirmStatus, $automatic
                    ? '销售订单已按实时库存自动锁定备货数量，并记录需生产数量。'
                    : '订单生产确认已保存库存备货和需生产数量。', $operatorName);
                $ledger->update(['status' => 'succeeded', 'processing_finished_at' => now(), 'response_snapshot' => [
                    'sales_order_id' => $orderId, 'business_version' => (int) $order->business_version,
                    'fulfillment_ids' => $batchFulfillmentIds,
                ]]);
                $result = $order->fresh(['lines', 'fulfillments']);
                $result->setAttribute('confirmation_command_result', $ledger->response_snapshot);
                return $result;
            }, 5);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062 || ! $clientCommandId) throw $exception;
            $existing = SalesOrderCommand::where('client_command_id', $clientCommandId)->first();
            if (! $existing) throw $exception;
            return $this->replayConfirmation($existing, $orderId, $requestHash);
        }
    }

    private function replayConfirmation(SalesOrderCommand $command, int $orderId, string $hash): SalesOrder
    {
        if ($command->command_type !== 'confirm_production' || (int) $command->sales_order_id !== $orderId || ! hash_equals((string) $command->request_hash, $hash)) {
            throw new WorkOrderDomainException('command_conflict', '该操作命令号已用于不同的订单确认内容。', 409);
        }
        if ($command->status !== 'succeeded' || ! is_array($command->response_snapshot)) {
            throw new WorkOrderDomainException('command_processing', '相同确认命令尚未完成，请稍后继续原操作。', 409);
        }
        $result = SalesOrder::with(['lines', 'fulfillments'])->findOrFail($orderId);
        $result->setAttribute('confirmation_command_result', $command->response_snapshot);
        return $result;
    }

    private function addPlannedAllocations(array &$plannedByBalance, array $allocations): void
    {
        // 这是当前订单事务内的预分配账，不写库存。正式占用仍由统一库存服务落账；
        // 任一行校验失败时整单回滚，不会留下只有部分订单行被占用的中间状态。
        foreach ($allocations as $allocation) {
            $id = (int) $allocation['inventory_balance_id'];
            $plannedByBalance[$id] = round(($plannedByBalance[$id] ?? 0) + (float) $allocation['base_qty'], 8);
        }
    }

    private function requiredQuantity(SalesOrderLine $line): float
    {
        return SalesOrderFulfillmentPolicy::effectiveQuantity((float) $line->order_qty, (float) $line->cancelled_qty);
    }

    private function suggestQuantities(SalesOrderLine $line, float $confirmQty): array
    {
        $result = ['inventory_qty' => 0.0, 'production_qty' => 0.0, 'purchase_qty' => 0.0, 'service_qty' => 0.0, 'no_delivery_qty' => 0.0, 'undetermined_qty' => 0.0];
        if ($line->line_type === 'service') {
            $result['service_qty'] = $confirmQty;
        } elseif (in_array($line->line_type, ['no_delivery', 'fee', 'auxiliary'], true)) {
            $result['no_delivery_qty'] = $confirmQty;
        } else {
            $analysis = $this->inventoryAvailability->analyzeSalesOrderLine($line, $confirmQty);
            $result['inventory_qty'] = $analysis['suggested_inventory_qty'];
            $result['production_qty'] = $analysis['suggested_production_qty'];
            $result['purchase_qty'] = $analysis['suggested_purchase_qty'];
        }
        return $result;
    }

    private function validateDecisionQuantities(SalesOrderLine $line, array $row, float $remainingSalesQty): array
    {
        $keys = array_map(fn (string $type): string => $type.'_qty', SalesOrderFulfillmentPolicy::TYPES);
        $quantities = [];
        foreach ($keys as $key) {
            $quantities[$key] = round((float) ($row[$key] ?? 0), 8);
            if (! is_finite($quantities[$key]) || $quantities[$key] < 0) {
                throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行备货或生产数量不能小于 0。"]);
            }
        }
        $confirmQty = round((float) ($row['confirm_qty'] ?? $remainingSalesQty), 8);
        if (! is_finite($confirmQty) || $confirmQty < 0) throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行本次确认数量必须是有效非负数。"]);
        if (abs(array_sum($quantities) - $confirmQty) > 0.00000001) {
            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行库存、生产、外购、服务及无需发货数量之和必须等于本次确认数量。"]);
        }
        if ($confirmQty > $remainingSalesQty + 0.00000001) {
            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行本次确认数量不能超过剩余可确认数量。"]);
        }
        $quantities['undetermined_qty'] = max(0, round($remainingSalesQty - $confirmQty, 8));
        if ($line->line_type === 'service' && ($quantities['inventory_qty'] > 0 || $quantities['production_qty'] > 0 || $quantities['purchase_qty'] > 0 || $quantities['no_delivery_qty'] > 0)) {
            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行为服务行，只能填写服务项目或尚未确定数量。"]);
        }
        if (in_array($line->line_type, ['no_delivery', 'fee', 'auxiliary'], true)
            && ($quantities['inventory_qty'] > 0 || $quantities['production_qty'] > 0 || $quantities['purchase_qty'] > 0 || $quantities['service_qty'] > 0)) {
            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行为无需发货行，不能进入库存备货、生产安排或服务项目。"]);
        }
        if (!in_array($line->line_type, ['service', 'no_delivery', 'fee', 'auxiliary'], true)
            && ($quantities['service_qty'] > 0 || $quantities['no_delivery_qty'] > 0)) {
            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行为实物行，只能拆分库存、生产或尚未确定数量。"]);
        }
        if ($this->isPhysicalLine($line)) {
            $purchase = SalesOrderFulfillmentPolicy::isPurchaseLine($line);
            if ($purchase && $quantities['production_qty'] > 0) throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行为外购物料，缺口只能等待外购，不能生成车间工单。"]);
            if (! $purchase && $quantities['purchase_qty'] > 0) throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行不是外购物料，不能填写外购备货数量。"]);
        }
        if ($line->unit_id && ($salesUnit = Unit::find($line->unit_id))) {
            foreach (array_merge($keys, ['undetermined_qty']) as $key) $this->conversions->assertUnitPrecision($quantities[$key], $salesUnit, 'lines.'.$line->line_no.'.'.$key);
            $this->conversions->assertUnitPrecision($confirmQty, $salesUnit, 'lines.'.$line->line_no.'.confirm_qty');
        }
        if ($this->isPhysicalLine($line) && $line->item_base_unit_id && ($baseUnit = Unit::find($line->item_base_unit_id))) {
            foreach (['inventory_qty', 'production_qty', 'purchase_qty', 'undetermined_qty'] as $key) {
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
        $result = ['inventory_qty' => 0.0, 'production_qty' => 0.0, 'purchase_qty' => 0.0, 'service_qty' => 0.0, 'no_delivery_qty' => 0.0, 'undetermined_qty' => 0.0];
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
            throw ValidationException::withMessages(['lines' => "第 {$line->line_no} 行缺少有效单位换算比例。"]);
        }
        return $factor > 0 ? $factor : 1.0;
    }

    private function salesToBaseQuantity(SalesOrderLine $line, float $salesQuantity): float
    {
        return round($salesQuantity * $this->fulfillmentFactor($line), 8);
    }

    private function isPhysicalLine(SalesOrderLine $line): bool
    {
        return SalesOrderFulfillmentPolicy::isPhysical((string) $line->line_type);
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
        if ($quantities['inventory_qty'] > 0) $rows[] = '库存备货';
        if ($quantities['production_qty'] > 0) $rows[] = '生产需求契约';
        if ($quantities['purchase_qty'] > 0) $rows[] = '外购备货';
        if ($quantities['service_qty'] > 0) $rows[] = '服务项目';
        if ($quantities['no_delivery_qty'] > 0) $rows[] = '无需发货';
        if ($quantities['undetermined_qty'] > 0) $rows[] = '尚未确定';
        return $rows ? implode(' + ', $rows) : '无生成结果';
    }

    private function fulfillmentPlanSummary(SalesOrder $order): array
    {
        $rows = $order->fulfillments->where('demand_status', 'confirmed');
        $quantities = collect(SalesOrderFulfillmentPolicy::TYPES)
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
            ->filter(fn (SalesOrderFulfillment $row) => in_array($row->fulfillment_type, SalesOrderFulfillmentPolicy::TYPES, true)
                && (float) ($row->sales_qty ?? $row->fulfillment_qty) > 0)
            ->pluck('fulfillment_type')->unique()->values();
        if ($types->isEmpty()) return '尚未安排备货';
        if ($types->count() === 1 && $types->first() === 'inventory' && $plan['status'] === 'allocated') return '全部库存';
        if ($types->count() === 1 && $types->first() === 'production' && $plan['status'] === 'allocated') return '全部生产';
        if ($types->contains('inventory') && $types->contains('production')) return '部分库存 + 部分生产';
        $names = ['inventory' => '库存', 'production' => '生产', 'purchase' => '外购备货', 'service' => '服务', 'no_delivery' => '无需发货'];
        return $types->map(fn ($type) => $names[$type] ?? $type)->implode(' + ');
    }

    private function submitResults(array $counts): array
    {
        $rows = ['保存订单生产确认结果', '锁定本次确认使用的订单行和生产资料版本'];
        if ($counts['inventory']) $rows[] = '生成库存备货需求';
        if ($counts['production']) $rows[] = '生成生产需求契约';
        if ($counts['purchase']) $rows[] = '登记外购待到货数量';
        if ($counts['service']) $rows[] = '生成服务项目记录';
        if ($counts['undetermined']) $rows[] = '保留尚未确认数量，订单交付状态为部分交付';
        return $rows;
    }

    private function hasTechnicalAttachment(SalesOrderLine $line): bool
    {
        return SalesOrderAttachment::where('sales_order_line_id', $line->id)->where('status', 'active')
            ->whereIn('attachment_type', ['design_drawing', 'technical_file', 'customer_technical_agreement'])->exists();
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
