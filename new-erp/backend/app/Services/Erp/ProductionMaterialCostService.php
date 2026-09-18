<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use Illuminate\Support\Facades\DB;

/** Material lots keep exact amounts from cutting through production; warehouse remains the stock authority. */
final class ProductionMaterialCostService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    /** Move one traceable upstream output directly into the next operation without touching warehouse stock. */
    public function acceptHandover(object $handover, ?float $acceptedQuantity, int $actor): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Production handover material transfer requires a transaction.');
        $output = DB::table('erp_production_output_records')->where('id', $handover->output_record_id)->lockForUpdate()->first();
        if (! $output || $output->material_total_cost === null) return;
        if (DB::table('erp_production_input_holdings')->where('operation_handover_id', $handover->id)->exists()) return;
        $quantity = CuttingDecimal::value($acceptedQuantity ?? $output->output_base_qty, 8, true);
        if (bccomp($quantity, (string) $output->output_base_qty, 8) !== 0) {
            $this->fail('production_handover_partial_trace_not_supported', '可追溯工序产出必须整批交接，禁止留下没有去向的金额或数量。');
        }
        $source = DB::table('erp_material_holdings')->where('id', $output->material_holding_id)->lockForUpdate()->first();
        if (! $source || $source->position_type !== 'OUTPUT_WIP' || (int) $source->position_id !== (int) $output->id
            || $source->status !== 'ACTIVE' || bccomp($quantity, (string) $source->quantity, 8) > 0) {
            $this->fail('production_handover_output_holding_invalid', '上道产出持有份额不足或已转出，不能重复交接。');
        }
        $this->validateTargetRequirement($handover->target_material_requirement_id, $handover->target_target_type,
            (int) $handover->target_target_id, (int) $output->output_item_id);
        $cost = CuttingDecimal::share((string) $source->total_cost, (string) $source->quantity, $quantity);
        $bridge = DB::table('erp_production_input_holdings')->insertGetId([
            'target_type' => $handover->target_target_type, 'target_id' => $handover->target_target_id,
            'target_material_requirement_id' => $handover->target_material_requirement_id,
            'source_output_record_id' => $output->id, 'source_holding_id' => $source->id,
            'operation_handover_id' => $handover->id, 'quantity' => $quantity, 'total_cost' => $cost,
            'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $input = DB::table('erp_material_holdings')->insertGetId([
            'material_lot_id' => $output->material_lot_id, 'position_type' => 'PRODUCTION_INPUT', 'position_id' => $bridge,
            'quantity' => $quantity, 'total_cost' => $cost, 'status' => 'ACTIVE', 'business_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('erp_production_input_holdings')->where('id', $bridge)->update(['input_holding_id' => $input, 'updated_at' => now()]);
        $this->reduceHolding($source, $quantity, $cost);
        DB::table('erp_material_movements')->insert([
            'movement_no' => $this->numbers->next('material_movement', 'MM'), 'source_holding_id' => $source->id,
            'target_holding_id' => $input, 'action' => 'PRODUCTION_HANDOVER', 'quantity' => $quantity,
            'total_cost' => $cost, 'operator_legacy_id' => $actor, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    /** Convert a formal warehouse outbound line back into the same next-operation input fact. */
    public function recordInternalIssue(object $issue, iterable $lines, object $transaction, int $actor): void
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Production internal issue material transfer requires a transaction.');
        foreach ($lines as $line) {
            if (! $line->output_record_id) continue;
            $output = DB::table('erp_production_output_records')->where('id', $line->output_record_id)->lockForUpdate()->first();
            if (! $output || $output->material_total_cost === null) continue;
            if (DB::table('erp_production_input_holdings')->where('internal_issue_line_id', $line->id)->exists()) continue;
            if ((int) $line->item_id !== (int) $output->output_item_id) {
                $this->fail('production_issue_output_item_invalid', '内部领用物料与绑定的上道产出不一致。');
            }
            $this->validateTargetRequirement($line->target_material_requirement_id, $issue->target_type,
                (int) $issue->target_id, (int) $line->item_id);
            $balance = DB::table('erp_inventory_balances')->where('id', $line->inventory_balance_id)->lockForUpdate()->first();
            if (! $balance || (int) $balance->material_lot_id !== (int) $output->material_lot_id) {
                $this->fail('production_issue_material_lot_invalid', '内部领用库存批次不属于绑定的上道产出。');
            }
            $posted = collect($transaction->items)->first(fn ($item) => (int) $item->source_item_id === (int) $line->id);
            if (! $posted || bccomp((string) $posted->change_qty, bcsub('0', (string) $line->issue_base_qty, 8), 8) !== 0
                || bccomp((string) $posted->cost_amount, '0', 4) >= 0) {
                $this->fail('production_issue_posting_fact_invalid', '内部领用必须引用本次正式库存出库的数量和金额。');
            }
            $source = DB::table('erp_material_holdings')->where('inventory_balance_id', $balance->id)
                ->where('material_lot_id', $output->material_lot_id)->first();
            if (! $source) $this->fail('production_issue_warehouse_holding_missing', '上道产出缺少正式入库持有事实，不能建立下道投入。');
            $quantity = CuttingDecimal::value($line->issue_base_qty, 8, true);
            $cost = bcsub('0', (string) $posted->cost_amount, 4);
            $bridge = DB::table('erp_production_input_holdings')->insertGetId([
                'target_type' => $issue->target_type, 'target_id' => $issue->target_id,
                'target_material_requirement_id' => $line->target_material_requirement_id,
                'source_output_record_id' => $output->id, 'source_holding_id' => $source->id,
                'internal_issue_line_id' => $line->id, 'quantity' => $quantity, 'total_cost' => $cost,
                'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now(),
            ]);
            $input = DB::table('erp_material_holdings')->insertGetId([
                'material_lot_id' => $output->material_lot_id, 'position_type' => 'PRODUCTION_INPUT', 'position_id' => $bridge,
                'quantity' => $quantity, 'total_cost' => $cost, 'status' => 'ACTIVE', 'business_version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('erp_production_input_holdings')->where('id', $bridge)->update(['input_holding_id' => $input, 'updated_at' => now()]);
            DB::table('erp_material_movements')->insert([
                'movement_no' => $this->numbers->next('material_movement', 'MM'), 'source_holding_id' => $source->id,
                'target_holding_id' => $input, 'action' => 'PROD_INTERNAL_ISSUE', 'quantity' => $quantity,
                'total_cost' => $cost, 'inventory_transaction_id' => $transaction->id,
                'operator_legacy_id' => $actor, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }

    public function consume(object $output, object $target, string $type, array $payload, int $actor, array $permissions): object
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Material consumption requires the completion transaction.');
        $requirements = DB::table('erp_production_target_material_requirements')
            ->where('target_type', $type)->where('target_id', $target->id)->orderBy('id')->lockForUpdate()->get();
        $requirementHoldings = DB::table('erp_material_holdings')->where('position_type', 'PRODUCTION_WIP')
            ->whereIn('position_id', $requirements->pluck('id'))->where('status', 'ACTIVE')
            ->where('quantity', '>', 0)->orderBy('id')->lockForUpdate()->get();
        $inputHoldings = DB::table('erp_production_input_holdings as input')
            ->join('erp_material_holdings as holding', 'holding.id', '=', 'input.input_holding_id')
            ->where('input.target_type', $type)->where('input.target_id', $target->id)->where('input.status', 'ACTIVE')
            ->where('holding.status', 'ACTIVE')->where('holding.quantity', '>', 0)->orderBy('holding.id')->lockForUpdate()
            ->get(['holding.*', 'input.id as input_bridge_id', 'input.target_material_requirement_id as input_requirement_id',
                'input.source_output_record_id']);
        $holdings = $requirementHoldings->map(function ($holding) {
            $holding->input_bridge_id = null; $holding->input_requirement_id = $holding->position_id;
            $holding->source_output_record_id = null; return $holding;
        })->concat($inputHoldings)->values();
        if ($output->material_total_cost !== null) {
            // Rework reuses the same output and already-consumed input. New material requires a cost revision,
            // never silently consume it twice or ignore its amount when the old snapshot is reused.
            if ($holdings->isNotEmpty()) $this->fail('production_rework_material_cost_revision_required', '返工新增材料须先完成正式成本更正，不能沿用原产出金额。');
            return $output;
        }
        if ($holdings->isEmpty()) return $output; // Ordinary, non-lot production retains its existing accounting policy.
        foreach ($inputHoldings as $holding) {
            $parent = DB::table('erp_production_output_records')->where('id', $holding->source_output_record_id)->first();
            $lot = DB::table('erp_material_lots')->where('id', $holding->material_lot_id)->first();
            if (! $parent || $parent->material_total_cost === null || (int) $parent->material_lot_id !== (int) $holding->material_lot_id
                || ! $lot || $lot->source_type !== 'production_output_record' || (int) $lot->source_id !== (int) $parent->id
                || (int) $lot->item_id !== (int) $parent->output_item_id
                || ! DB::table('erp_material_movements')->where('target_holding_id', $holding->id)
                    ->where('source_holding_id', DB::table('erp_production_input_holdings')->where('id', $holding->input_bridge_id)->value('source_holding_id'))
                    ->whereIn('action', ['PRODUCTION_HANDOVER', 'PROD_INTERNAL_ISSUE'])->exists()) {
                $this->fail('production_input_ancestry_invalid', '跨工序投入缺少与上道产出一致的批次、金额或正式转移事实。');
            }
        }
        $total = '0.0000';
        foreach ($requirements as $requirement) {
            $required = bcsub((string) $requirement->required_base_qty, (string) $requirement->consumed_base_qty, 8);
            $received = '0.00000000';
            foreach ($holdings->filter(fn ($holding) => (int) $holding->input_requirement_id === (int) $requirement->id) as $holding) {
                $lot = DB::table('erp_material_lots')->where('id', $holding->material_lot_id)->first();
                if (! $lot || (int) $lot->item_id !== (int) $requirement->component_item_id
                    || ! DB::table('erp_material_movements')->where('target_holding_id', $holding->id)
                        ->whereIn('action', ['RECEIVE', 'INTERNAL_ISSUE', 'PRODUCTION_HANDOVER', 'PROD_INTERNAL_ISSUE'])->exists()) {
                    $this->fail('production_material_holding_source_invalid', '生产材料持有记录必须来自同物料的真实交接接收或正式领用。');
                }
                app(CuttingRecordService::class)->configuration($lot->configuration_id,
                    \App\Models\Erp\Item::findOrFail($lot->item_id), (int) $requirement->work_order_id);
                $received = bcadd($received, (string) $holding->quantity, 8);
            }
            // A mixed traced/unpriced input cannot produce a deceptively complete zero/partial cost.
            if (bccomp($required, $received, 8) !== 0) $this->fail('production_material_cost_coverage_incomplete', '可追溯材料到位数量与尚未消耗需求不一致，禁止缺失材料成本的完工。');
        }
        foreach ($holdings as $holding) $total = bcadd($total, (string) $holding->total_cost, 4);
        $hasLoss = bccomp((string) ($target->scrapped_base_qty ?? '0'), '0', 8) > 0
            || bccomp((string) ($target->unqualified_base_qty ?? '0'), '0', 8) > 0;
        $allocation = $payload['material_cost_allocation'] ?? null;
        if ($allocation !== null && ! in_array('production.output.cost.allocate', $permissions, true)) {
            throw new WorkOrderDomainException('permission_denied', '当前用户没有分配生产材料良品及损失金额的权限。', 403);
        }
        if ($hasLoss && ! is_array($allocation)) $this->fail('production_material_cost_allocation_required', '存在不合格或报废时须明确分配良品和损失总金额，禁止按数量猜测成本。');
        $outputCost = $allocation ? CuttingDecimal::value($allocation['output_total_cost'] ?? null, 4, true) : $total;
        $lossCost = $allocation ? CuttingDecimal::value($allocation['loss_total_cost'] ?? null, 4, true) : '0.0000';
        if (bccomp(bcadd($outputCost, $lossCost, 4), $total, 4) !== 0 || (! $hasLoss && bccomp($lossCost, '0', 4) !== 0)) {
            $this->fail('production_material_cost_allocation_invalid', '良品金额与损失金额必须精确等于实际材料投入总金额。');
        }
        $now = now();
        $lot = DB::table('erp_material_lots')->insertGetId(['lot_no' => $this->numbers->next('material_lot', 'ML'),
            'item_id' => $output->output_item_id, 'stage_id' => $target->routing_operation_id_snapshot,
            'material_form' => 'PRODUCT', 'source_type' => 'production_output_record', 'source_id' => $output->id,
            'created_at' => $now, 'updated_at' => $now]);
        $outputHolding = DB::table('erp_material_holdings')->insertGetId(['material_lot_id' => $lot,
            'position_type' => 'OUTPUT_WIP', 'position_id' => $output->id, 'quantity' => $output->output_base_qty,
            'total_cost' => $outputCost, 'status' => 'ACTIVE', 'business_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $unallocatedInput = $total; $unallocatedOutput = $outputCost;
        foreach ($holdings as $holding) {
            DB::table('erp_production_material_consumptions')->insert(['output_record_id' => $output->id,
                'target_material_requirement_id' => $holding->input_requirement_id, 'source_holding_id' => $holding->id,
                'source_material_lot_id' => $holding->material_lot_id, 'quantity' => $holding->quantity,
                'total_cost' => $holding->total_cost, 'operator_legacy_id' => $actor, 'occurred_at' => $now,
                'created_at' => $now, 'updated_at' => $now]);
            DB::table('erp_material_holdings')->where('id', $holding->id)->update(['quantity' => '0', 'total_cost' => '0',
                'status' => 'CONSUMED', 'business_version' => (int) $holding->business_version + 1, 'updated_at' => $now]);
            if ($holding->input_bridge_id) DB::table('erp_production_input_holdings')->where('id', $holding->input_bridge_id)
                ->update(['status' => 'CONSUMED', 'updated_at' => $now]);
            $share = bccomp((string) $holding->total_cost, '0', 4) === 0 ? '0.0000'
                : (bccomp((string) $holding->total_cost, $unallocatedInput, 4) === 0 ? $unallocatedOutput
                    : bcdiv(bcmul($unallocatedOutput, (string) $holding->total_cost, 8), $unallocatedInput, 4));
            $unallocatedInput = bcsub($unallocatedInput, (string) $holding->total_cost, 4);
            $unallocatedOutput = bcsub($unallocatedOutput, $share, 4);
            // The remaining amount is the output's explicit immutable material_loss_cost fact,
            // not a stock movement or a fictitious scrap inventory holding.
            DB::table('erp_material_movements')->insert(['movement_no' => $this->numbers->next('material_movement', 'MM'),
                'source_holding_id' => $holding->id, 'target_holding_id' => $outputHolding, 'action' => 'PRODUCTION_CONSUME',
                'quantity' => $holding->quantity, 'total_cost' => $share, 'operator_legacy_id' => $actor,
                'created_at' => $now, 'updated_at' => $now]);
        }
        foreach ($requirements as $requirement) DB::table('erp_production_target_material_requirements')->where('id', $requirement->id)
            ->update(['consumed_base_qty' => $requirement->required_base_qty, 'business_version' => (int) $requirement->business_version + 1, 'updated_at' => $now]);
        DB::table('erp_production_output_records')->where('id', $output->id)->update(['material_lot_id' => $lot,
            'material_holding_id' => $outputHolding, 'material_total_cost' => $outputCost, 'material_loss_cost' => $lossCost, 'updated_at' => $now]);
        return DB::table('erp_production_output_records')->find($output->id);
    }

    /** Reserve the next real receipt's share by reducing output WIP inside the posting transaction. */
    public function receiptAmount(object $output, string $quantity): ?array
    {
        if ($output->material_total_cost === null) return null;
        if (DB::transactionLevel() < 1) throw new \LogicException('Production receipt amount requires a transaction.');
        $holding = DB::table('erp_material_holdings')->where('id', $output->material_holding_id)->lockForUpdate()->first();
        if (! $holding || $holding->position_type !== 'OUTPUT_WIP' || (int) $holding->position_id !== (int) $output->id
            || (int) $holding->material_lot_id !== (int) $output->material_lot_id || $holding->status !== 'ACTIVE'
            || bccomp($quantity, '0', 8) <= 0 || bccomp($quantity, (string) $holding->quantity, 8) > 0) {
            $this->fail('production_output_material_holding_invalid', '生产产出持有份额或待入库数量不一致。');
        }
        $cost = CuttingDecimal::share((string) $holding->total_cost, (string) $holding->quantity, $quantity);
        $left = bcsub((string) $holding->quantity, $quantity, 8);
        DB::table('erp_material_holdings')->where('id', $holding->id)->update(['quantity' => $left,
            'total_cost' => bcsub((string) $holding->total_cost, $cost, 4), 'status' => bccomp($left, '0', 8) === 0 ? 'TRANSFERRED' : 'ACTIVE',
            'business_version' => (int) $holding->business_version + 1, 'updated_at' => now()]);
        return ['material_lot_id' => $output->material_lot_id, 'cost_amount' => $cost,
            'unit_cost' => bcdiv($cost, $quantity, 8), 'cost_source_type' => 'production_material_total'];
    }

    public function recordReceipt(object $output, object $transaction, int $actor): void
    {
        if ($output->material_total_cost === null) return;
        foreach ($transaction->items as $line) {
            $balance = DB::table('erp_inventory_balances')->where('item_id', $line->item_id)->where('warehouse_id', $line->warehouse_id)
                ->where('location_id', $line->location_id)->where('batch_no', $line->batch_no)->first();
            $holding = DB::table('erp_material_holdings')->where('inventory_balance_id', $balance->id)->value('id');
            if (! $holding) $holding = DB::table('erp_material_holdings')->insertGetId(['material_lot_id' => $output->material_lot_id,
                'position_type' => 'WAREHOUSE', 'position_id' => $balance->warehouse_id, 'inventory_balance_id' => $balance->id,
                'status' => 'ACTIVE', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('erp_material_movements')->insert(['movement_no' => $this->numbers->next('material_movement', 'MM'),
                'source_holding_id' => $output->material_holding_id, 'target_holding_id' => $holding, 'action' => 'PRODUCTION_RECEIPT',
                'quantity' => $line->change_qty, 'total_cost' => $line->cost_amount, 'inventory_transaction_id' => $transaction->id,
                'operator_legacy_id' => $actor, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function fail(string $code, string $message): never { throw new WorkOrderDomainException($code, $message, 409); }

    private function validateTargetRequirement(mixed $requirementId, string $targetType, int $targetId, int $itemId): void
    {
        if (! $requirementId) return;
        $requirement = DB::table('erp_production_target_material_requirements')->where('id', $requirementId)->lockForUpdate()->first();
        if (! $requirement || $requirement->target_type !== $targetType || (int) $requirement->target_id !== $targetId
            || (int) $requirement->component_item_id !== $itemId) {
            $this->fail('production_input_requirement_invalid', '跨工序投入与目标物料需求、执行目标或物料不一致。');
        }
    }

    private function reduceHolding(object $holding, string $quantity, string $cost): void
    {
        $leftQty = bcsub((string) $holding->quantity, $quantity, 8);
        $leftCost = bcsub((string) $holding->total_cost, $cost, 4);
        DB::table('erp_material_holdings')->where('id', $holding->id)->update([
            'quantity' => $leftQty, 'total_cost' => $leftCost,
            'status' => bccomp($leftQty, '0', 8) === 0 ? 'TRANSFERRED' : 'ACTIVE',
            'business_version' => (int) $holding->business_version + 1, 'updated_at' => now(),
        ]);
    }

    public static function presentOutput(object $output, array $permissions): array
    {
        $data = (array) $output;
        unset($data['material_holding_id']);
        if (! in_array('production.output.cost.view', $permissions, true)) unset($data['material_total_cost'], $data['material_loss_cost']);
        return $data;
    }
}
