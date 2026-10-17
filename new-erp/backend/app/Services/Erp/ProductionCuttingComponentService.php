<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use Illuminate\Support\Facades\DB;

/** Dimensional cut pieces retain the raw Item identity and never become finished-goods inventory. */
final class ProductionCuttingComponentService
{
    public const KIND = 'cutting_components';

    public function __construct(private readonly DocumentNumberService $numbers) {}

    public function link(string $type, int $targetId): ?object
    {
        $link = DB::table('erp_production_cutting_operations')->where('target_type', $type)->where('target_id', $targetId)->first();
        return $link && ($this->json($link->technical_snapshot)['output_kind'] ?? null) === self::KIND ? $link : null;
    }

    public function isOutput(object $output): bool
    {
        if ($output->output_item_id !== null) return false;
        $link = $this->link($output->source_target_type, (int) $output->source_target_id);
        return $link && (int) $link->work_order_id === (int) $output->work_order_id && $output->output_mode_snapshot === 'flow_only';
    }

    public function assertTarget(object $target, string $type): void
    {
        $code = strtoupper((string) (($target->stage_code_snapshot ?? null) ?: ($target->operation_code_snapshot ?? '')));
        if ($code !== 'CUTTING' || $target->output_mode_snapshot !== 'flow_only' || ! $this->next($type, $target)) {
            $this->fail('cutting_components_route_required', '定制下料明细必须由下料工序直接交接本工单下一工序，不能作为成品入库或终点备货。');
        }
    }

    public function next(string $type, object $target): ?object
    {
        $table = match ($type) { 'unit_operation' => 'erp_production_unit_operations', 'quantity_operation' => 'erp_production_quantity_operations', default => null };
        if (! $table) $this->fail('target_type_invalid', '下料执行目标类型无效。');
        $query = DB::table($table)->where('work_order_id', $target->work_order_id)->where('sequence_no_snapshot', '>', $target->sequence_no_snapshot);
        if ($type === 'unit_operation') $query->where('production_unit_id', $target->production_unit_id);
        return $query->orderBy('sequence_no_snapshot')->first();
    }

    /** This is a process bundle measured in WO reference units, not an Item/SN/stock receipt. */
    public function createOutput(string $type, object $target, int $actor, array $payload, $now, bool $terminal): ?object
    {
        if (! $this->link($type, (int) $target->id)) return null;
        $this->assertTarget($target, $type);
        if ($terminal || ($payload['disposition'] ?? 'direct_handover') !== 'direct_handover') $this->fail('cutting_components_warehouse_forbidden', '下料组件束只能按冻结路线交接，不能登记为可销售成品。');
        $existing = DB::table('erp_production_output_records')->where('source_target_type', $type)->where('source_target_id', $target->id)->lockForUpdate()->first();
        $qty = $type === 'unit_operation' ? '1.00000000' : (string) $target->completed_base_qty;
        $status = $target->quality_mode_snapshot !== 'none' ? 'WAIT_QUALITY' : 'CREATED';
        if ($existing) {
            if (! $this->isOutput($existing) || $existing->serial_id || $existing->inventory_serial_id || $existing->material_lot_id || $existing->material_holding_id) $this->fail('cutting_components_identity_conflict', '原下料产出不属于冻结的组件明细，不能改写历史成品身份。');
            if (in_array($existing->status, ['QUALITY_FAILED', 'HANDOVER_REJECTED'], true)) DB::table('erp_production_output_records')->where('id', $existing->id)->update([
                'output_base_qty' => $qty, 'status' => $status, 'disposition' => 'direct_handover', 'business_version' => (int) $existing->business_version + 1, 'updated_at' => $now]);
            return DB::table('erp_production_output_records')->where('id', $existing->id)->first();
        }
        $id = DB::table('erp_production_output_records')->insertGetId([
            'output_no' => $this->numbers->next('production_output', 'POU'), 'work_order_id' => $target->work_order_id,
            'source_target_type' => $type, 'source_target_id' => $target->id,
            'production_unit_id' => $type === 'unit_operation' ? $target->production_unit_id : null,
            'output_item_id' => null, 'output_base_qty' => $qty, 'output_mode_snapshot' => 'flow_only',
            'quality_mode_snapshot' => $target->quality_mode_snapshot, 'status' => $status, 'serial_id' => null,
            'disposition' => 'direct_handover', 'created_by_legacy_id' => $actor, 'produced_at' => $now,
            'business_version' => 1, 'created_at' => $now, 'updated_at' => $now,
        ]);
        return DB::table('erp_production_output_records')->where('id', $id)->first();
    }

    public function attachCosts(object $output, object $target, string $type, int $actor): object
    {
        $this->transaction();
        $this->assertTarget($target, $type);
        $link = $this->link($type, (int) $target->id);
        $components = $this->components($output);
        $cost = '0.0000'; foreach ($components as $component) $cost = bcadd($cost, $component['total_cost'], 4);
        $loss = (string) DB::table('erp_cutting_cost_dispositions as disposition')->join('erp_cutting_results as result', 'result.id', '=', 'disposition.result_id')
            ->join('erp_cutting_settlement_batches as batch', 'batch.id', '=', 'result.settlement_batch_id')->where('batch.cutting_order_id', $link->cutting_order_id)
            ->where('disposition.disposition', 'LOSS_EXPENSE')->where('disposition.status', 'RECORDED')->sum('disposition.total_cost');
        if ($output->material_total_cost !== null && bccomp((string) $output->material_total_cost, $cost, 4) !== 0) $this->fail('cutting_components_cost_changed', '返工组件束不能覆盖已经冻结的材料金额。');
        $rawInputs = DB::table('erp_production_cutting_inputs as used')->join('erp_production_input_holdings as input', 'input.id', '=', 'used.production_input_holding_id')
            ->where('used.operation_id', $link->id)->selectRaw('input.target_material_requirement_id, input.input_holding_id, SUM(used.quantity) as quantity, SUM(used.total_cost) as total_cost')
            ->groupBy('input.target_material_requirement_id', 'input.input_holding_id')->get();
        foreach ($rawInputs as $raw) {
            $existing = DB::table('erp_production_material_consumptions')->where('output_record_id', $output->id)->where('source_holding_id', $raw->input_holding_id)->first();
            if ($existing && (bccomp((string) $existing->quantity, (string) $raw->quantity, 8) !== 0 || bccomp((string) $existing->total_cost, (string) $raw->total_cost, 4) !== 0)) $this->fail('cutting_components_consumption_changed', '原材料实际耗用金额与已冻结的组件束不一致。');
            if (! $existing) {
                $source = DB::table('erp_material_holdings')->where('id', $raw->input_holding_id)->first();
                if (! $source) $this->fail('cutting_components_consumption_source_missing', '组件束缺少实际整根或整张材料的耗用来源。');
                DB::table('erp_production_material_consumptions')->insert(['output_record_id' => $output->id, 'target_material_requirement_id' => $raw->target_material_requirement_id,
                    'source_holding_id' => $raw->input_holding_id, 'source_material_lot_id' => $source->material_lot_id,
                    'quantity' => $raw->quantity, 'total_cost' => $raw->total_cost, 'operator_legacy_id' => $actor, 'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
            }
        }
        foreach (DB::table('erp_production_target_material_requirements')->where('target_type', $type)->where('target_id', $target->id)
            ->whereIn('id', app(ProductionCuttingOperationService::class)->coveredRequirementIds($type, (int) $target->id))->lockForUpdate()->get() as $requirement) {
            // Requirements retain theoretical coverage; the consumption ledger retains actual whole stock.
            DB::table('erp_production_target_material_requirements')->where('id', $requirement->id)->update(['consumed_base_qty' => $requirement->required_base_qty,
                'business_version' => (int) $requirement->business_version + 1, 'updated_at' => now()]);
        }
        DB::table('erp_production_output_records')->where('id', $output->id)->update(['material_total_cost' => $cost, 'material_loss_cost' => $loss, 'updated_at' => now()]);
        DB::table('erp_cutting_orders')->where('id', $link->cutting_order_id)->where('status', '<>', 'CLOSED')->update(['status' => 'CLOSED', 'business_version' => DB::raw('business_version+1'), 'updated_at' => now()]);
        return DB::table('erp_production_output_records')->where('id', $output->id)->first();
    }

    public function handoverIdentity(object $output): array
    {
        return $this->isOutput($output) ? ['output_no' => $output->output_no, 'output_kind' => self::KIND, 'components' => $this->components($output)]
            : ['output_no' => $output->output_no ?? null, 'serial_no' => $output->serial_no_snapshot ?? null];
    }

    /** Read received components from immutable handover facts, including inputs already consumed downstream. */
    public function receivedForTask(object $task): array
    {
        $targets = collect($task->targets)->filter(fn ($target) => in_array($target->target_type, ['unit_operation', 'quantity_operation'], true));
        if ($targets->isEmpty()) return [];
        $inputs = DB::table('erp_production_input_holdings as input')
            ->join('erp_production_output_records as output', 'output.id', '=', 'input.source_output_record_id')
            ->join('erp_production_cutting_operations as cutting', fn ($join) => $join->on('cutting.target_type', '=', 'output.source_target_type')
                ->on('cutting.target_id', '=', 'output.source_target_id')->on('cutting.work_order_id', '=', 'output.work_order_id'))
            ->leftJoin('erp_production_operation_handovers as handover', fn ($join) => $join->on('handover.output_record_id', '=', 'output.id')
                ->on('handover.target_target_type', '=', 'input.target_type')->on('handover.target_target_id', '=', 'input.target_id')->where('handover.status', 'RECEIVED'))
            ->leftJoin('erp_material_holdings as holding', 'holding.id', '=', 'input.input_holding_id')
            ->leftJoin('erp_material_lots as lot', 'lot.id', '=', 'holding.material_lot_id')
            ->where('output.work_order_id', $task->work_order_id)->whereNull('output.output_item_id')->where('output.output_mode_snapshot', 'flow_only')
            ->where(function ($query) use ($targets): void {
                $query->where(fn ($q) => $q->where('input.target_type', 'unit_operation')->whereIn('input.target_id', $targets->where('target_type', 'unit_operation')->pluck('target_id')))
                    ->orWhere(fn ($q) => $q->where('input.target_type', 'quantity_operation')->whereIn('input.target_id', $targets->where('target_type', 'quantity_operation')->pluck('target_id')));
            })->orderBy('input.id')->get(['input.id', 'input.target_type', 'input.target_id', 'input.target_material_requirement_id', 'input.source_output_record_id',
                'input.source_holding_id', 'input.input_holding_id', 'input.quantity as received_qty', 'input.status as input_status',
                'output.output_no', 'output.source_target_type', 'output.source_target_id', 'cutting.technical_snapshot',
                'handover.handover_no', 'handover.received_at', 'handover.identity_snapshot', 'handover.work_order_id as handover_work_order_id',
                'holding.quantity as available_qty', 'holding.status as holding_status', 'holding.position_type', 'holding.position_id',
                'lot.id as material_lot_id', 'lot.item_id as lot_item_id', 'lot.source_type as lot_source_type', 'lot.source_id as lot_source_id']);
        if ($inputs->isEmpty()) return [];
        $components = [];
        foreach ($inputs as $input) {
            try {
                $identity = $this->json($input->identity_snapshot);
                $matches = collect($identity['components'] ?? [])->filter(fn ($component) => is_array($component) && (int) ($component['source_holding_id'] ?? 0) === (int) $input->source_holding_id);
                $component = $matches->first();
                if (($identity['output_kind'] ?? null) === self::KIND && $matches->count() === 1
                    && count(array_intersect(['source_requirement_id', 'result_id', 'route_id', 'raw_item_id', 'material_lot_id', 'requirement_snapshot', 'measurements', 'piece_qty'], array_keys($component))) === 8
                    && is_array($component['requirement_snapshot']) && is_array($component['measurements'])) $components[(int) $input->id] = $component;
            } catch (\JsonException) { /* Keep the received fact visible, but mark incomplete ancestry below. */ }
        }
        $facts = collect($components);
        $requirements = DB::table('erp_production_target_material_requirements as target')
            ->join('erp_work_order_material_requirements as material', 'material.id', '=', 'target.material_requirement_id')
            ->whereIn('target.id', $facts->pluck('source_requirement_id'))->get(['target.id', 'target.work_order_id', 'target.target_type', 'target.target_id', 'target.component_item_id',
                'material.component_item_code_snapshot', 'material.component_item_name_snapshot', 'material.component_spec_snapshot'])->keyBy('id');
        $results = DB::table('erp_cutting_results')->whereIn('id', $facts->pluck('result_id'))->get()->keyBy('id');
        $routes = DB::table('erp_cutting_result_routes')->whereIn('id', $facts->pluck('route_id'))->get()->keyBy('id');
        $movements = DB::table('erp_material_movements')->whereIn('target_holding_id', $inputs->pluck('input_holding_id'))->where('action', 'CUT_COMPONENT_HANDOVER')
            ->selectRaw('source_holding_id, target_holding_id, SUM(quantity) as quantity')->groupBy('source_holding_id', 'target_holding_id')
            ->get()->keyBy(fn ($row) => $row->source_holding_id.':'.$row->target_holding_id);
        $consumed = DB::table('erp_production_material_consumptions')->whereIn('source_holding_id', $inputs->pluck('input_holding_id'))
            ->selectRaw('source_holding_id, SUM(quantity) as quantity')->groupBy('source_holding_id')->get()->keyBy('source_holding_id');
        return $inputs->map(function ($input) use ($task, $components, $requirements, $results, $routes, $movements, $consumed): array {
            $row = ['target_type' => $input->target_type, 'target_id' => (int) $input->target_id, 'source_output_record_id' => (int) $input->source_output_record_id,
                'output_no' => $input->output_no, 'handover_no' => $input->handover_no, 'received_at' => $input->received_at,
                'raw_item_id' => null, 'raw_item_code' => null, 'raw_item_name' => null, 'raw_item_spec' => null, 'dimensions' => null,
                'quantity_basis' => null, 'quantity_unit' => null, 'total_length_mm' => null, 'piece_qty' => null,
                'received_qty' => null, 'available_qty' => null, 'consumed_qty' => null, 'trace_status' => 'inconsistent', 'trace_message' => '组件接收来源待核对，请联系生产负责人。'];
            try {
                $component = $components[(int) $input->id] ?? null;
                $requirement = $requirements->get((int) ($component['source_requirement_id'] ?? 0));
                $result = $results->get((int) ($component['result_id'] ?? 0));
                $route = $routes->get((int) ($component['route_id'] ?? 0));
                $movement = $movements->get($input->source_holding_id.':'.$input->input_holding_id);
                $quantity = $component ? $this->componentQuantity($component) : null;
                $used = (string) ($consumed->get((int) $input->input_holding_id)?->quantity ?? '0');
                if (! $component || ! $requirement || ! $result || ! $route || ! $movement || ! $quantity
                    || ! $input->input_holding_id || $input->available_qty === null
                    || ($this->json($input->technical_snapshot)['output_kind'] ?? null) !== self::KIND
                    || $input->target_material_requirement_id !== null || (int) $input->handover_work_order_id !== (int) $task->work_order_id
                    || (int) $requirement->work_order_id !== (int) $task->work_order_id || $requirement->target_type !== $input->source_target_type || (int) $requirement->target_id !== (int) $input->source_target_id
                    || $input->position_type !== 'PRODUCTION_INPUT' || (int) $input->position_id !== (int) $input->id
                    || (int) $input->material_lot_id !== (int) $component['material_lot_id'] || $input->lot_source_type !== 'cutting_result' || (int) $input->lot_source_id !== (int) $result->id
                    || (int) $input->lot_item_id !== (int) $component['raw_item_id'] || (int) $result->item_id !== (int) $component['raw_item_id'] || (int) $requirement->component_item_id !== (int) $component['raw_item_id']
                    || $result->status !== 'CONFIRMED' || (int) $result->material_lot_id !== (int) $input->material_lot_id
                    || ! $this->snapshotsEqual($this->json($result->cutting_requirement_snapshot), $component['requirement_snapshot']) || ! $this->snapshotsEqual($this->json($result->measurements), $component['measurements'])
                    || (int) $route->result_id !== (int) $result->id || (int) $route->holding_id !== (int) $input->source_holding_id || $route->status !== 'OPERATION_COMPLETED'
                    || ! in_array($input->input_status, ['ACTIVE', 'CONSUMED'], true) || ! in_array($input->holding_status, ['ACTIVE', 'CONSUMED'], true)
                    || bccomp((string) $result->actual_qty, $quantity, 8) !== 0 || bccomp((string) $route->received_qty, $quantity, 8) !== 0
                    || bccomp((string) $input->received_qty, $quantity, 8) !== 0 || bccomp((string) $movement->quantity, $quantity, 8) !== 0
                    || bccomp((string) $input->available_qty, '0', 8) < 0 || bccomp($used, '0', 8) < 0 || bccomp(bcadd((string) $input->available_qty, $used, 8), $quantity, 8) !== 0) return $row;
                $totalLength = ($component['quantity_basis'] ?? null) === 'total_length';
                $size = $component['requirement_snapshot'];
                return array_replace($row, ['raw_item_id' => (int) $component['raw_item_id'], 'raw_item_code' => $requirement->component_item_code_snapshot,
                    'raw_item_name' => $requirement->component_item_name_snapshot, 'raw_item_spec' => $requirement->component_spec_snapshot,
                    'dimensions' => $totalLength ? null : ['length_mm' => $size['required_length_mm'] ?? null, 'width_mm' => $size['required_width_mm'] ?? null, 'thickness_mm' => $size['required_thickness_mm'] ?? null],
                    'quantity_basis' => $totalLength ? 'total_length' : 'piece', 'quantity_unit' => $totalLength ? 'm' : 'piece',
                    'total_length_mm' => $totalLength ? $component['total_length_mm'] : null, 'piece_qty' => $totalLength ? null : $quantity,
                    'received_qty' => $quantity, 'available_qty' => bcadd((string) $input->available_qty, '0', 8), 'consumed_qty' => bcadd($used, '0', 8),
                    'trace_status' => 'verified', 'trace_message' => null]);
            } catch (\JsonException | \App\Exceptions\Erp\WorkOrderDomainException) { return $row; }
        })->all();
    }

    public function acceptHandover(object $handover, ?string $acceptedQuantity, int $actor): bool
    {
        $output = DB::table('erp_production_output_records')->where('id', $handover->output_record_id)->lockForUpdate()->first();
        if (! $output || ! $this->isOutput($output)) return false;
        $this->transaction();
        if ($handover->target_material_requirement_id || bccomp((string) $acceptedQuantity, (string) $output->output_base_qty, 8) !== 0) $this->fail('cutting_components_partial_handover_forbidden', '组件束必须按本工单冻结的完整规格和件数整批接收，不能套用单物料数量。');
        $source = DB::table($this->targetTable($output->source_target_type))->where('id', $output->source_target_id)->first();
        $next = $source ? $this->next($output->source_target_type, $source) : null;
        if (! $next || $handover->target_target_type !== $output->source_target_type || (int) $handover->target_target_id !== (int) $next->id
            || (int) $next->work_order_id !== (int) $output->work_order_id || $next->started_at) $this->fail('cutting_components_target_invalid', '下料组件只能由同一工单尚未开工的直接下游接收。');
        $identity = $this->json($handover->identity_snapshot);
        $components = $this->components($output);
        if (($identity['output_kind'] ?? null) !== self::KIND || ! $this->snapshotsEqual($identity['components'] ?? null, $components)) $this->fail('cutting_components_handover_changed', '交接的冻结规格、来源、件数或金额已变化，请核对。');
        if (DB::table('erp_production_input_holdings')->where('source_output_record_id', $output->id)->exists()) $this->fail('cutting_components_already_received', '本组件束已经形成下游持有事实，不能重复接收。');
        foreach ($components as $component) {
            $quantity = $this->componentQuantity($component);
            $sourceHolding = DB::table('erp_material_holdings')->where('id', $component['source_holding_id'])->lockForUpdate()->first();
            $bridge = DB::table('erp_production_input_holdings')->insertGetId(['target_type' => $handover->target_target_type, 'target_id' => $handover->target_target_id,
                'target_material_requirement_id' => null, 'source_output_record_id' => $output->id, 'source_holding_id' => $sourceHolding->id,
                'operation_handover_id' => null, 'quantity' => $quantity, 'total_cost' => $component['total_cost'], 'status' => 'ACTIVE', 'created_at' => now(), 'updated_at' => now()]);
            $holdingId = DB::table('erp_material_holdings')->insertGetId(MaterialHoldingInvariant::attributes(['material_lot_id' => $component['material_lot_id'],
                'position_type' => 'PRODUCTION_INPUT', 'position_id' => $bridge, 'quantity' => $quantity, 'total_cost' => $component['total_cost'],
                'status' => 'ACTIVE', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]));
            DB::table('erp_production_input_holdings')->where('id', $bridge)->update(['input_holding_id' => $holdingId]);
            DB::table('erp_material_holdings')->where('id', $sourceHolding->id)->update(['quantity' => '0', 'total_cost' => '0', 'status' => 'CONSUMED', 'business_version' => (int) $sourceHolding->business_version + 1, 'updated_at' => now()]);
            DB::table('erp_material_movements')->insert(['movement_no' => $this->numbers->next('material_movement', 'MM'), 'source_holding_id' => $sourceHolding->id,
                'target_holding_id' => $holdingId, 'action' => 'CUT_COMPONENT_HANDOVER', 'quantity' => $quantity, 'total_cost' => $component['total_cost'],
                'operator_legacy_id' => $actor, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('erp_cutting_result_routes')->where('id', $component['route_id'])->update(['status' => 'OPERATION_COMPLETED', 'received_qty' => $quantity, 'received_cost' => $component['total_cost'], 'updated_at' => now()]);
        }
        return true;
    }

    /** Validate a dimensional lot before its costs become a real welded Item output. */
    public function assertInputAncestry(object $holding, ?object $parent): bool
    {
        if (! $parent || ! $this->isOutput($parent)) return false;
        $bridge = DB::table('erp_production_input_holdings')->where('id', $holding->input_bridge_id)->first();
        $handover = DB::table('erp_production_operation_handovers')->where('output_record_id', $parent->id)->where('status', 'RECEIVED')
            ->where('target_target_type', $bridge?->target_type)->where('target_target_id', $bridge?->target_id)->first();
        $identity = $this->json($handover?->identity_snapshot);
        $component = collect($identity['components'] ?? [])->firstWhere('source_holding_id', (int) ($bridge?->source_holding_id ?? 0));
        $lot = DB::table('erp_material_lots')->where('id', $holding->material_lot_id)->first();
        $result = $component ? DB::table('erp_cutting_results')->where('id', $component['result_id'])->first() : null;
        $quantity = $component ? $this->componentQuantity($component) : '0';
        $resultQuantity = $result && ($component['quantity_basis'] ?? null) === 'total_length' ? $result->actual_qty : $result?->piece_qty;
        $target = $bridge ? DB::table($this->targetTable($bridge->target_type))->where('id', $bridge->target_id)->first() : null;
        if (! $bridge || ! $handover || ! $component || ! $lot || ! $result || ! $target || $identity['output_kind'] !== self::KIND
            || (int) $target->work_order_id !== (int) $parent->work_order_id || (int) $bridge->source_output_record_id !== (int) $parent->id
            || $lot->source_type !== 'cutting_result' || (int) $lot->source_id !== (int) $result->id || (int) $lot->item_id !== (int) $component['raw_item_id']
            || (int) $lot->id !== (int) $component['material_lot_id'] || (int) $result->item_id !== (int) $component['raw_item_id']
            || $result->status !== 'CONFIRMED' || ! $this->snapshotsEqual($this->json($result->cutting_requirement_snapshot), $component['requirement_snapshot'])
            || ! $this->snapshotsEqual($this->json($result->measurements), $component['measurements']) || (int) $result->material_lot_id !== (int) $component['material_lot_id']
            || bccomp((string) $resultQuantity, $quantity, 8) !== 0
            || bccomp((string) $holding->quantity, $quantity, 8) !== 0 || bccomp((string) $holding->total_cost, $component['total_cost'], 4) !== 0
            || ! DB::table('erp_material_movements')->where('source_holding_id', $component['source_holding_id'])->where('target_holding_id', $holding->id)
                ->where('action', 'CUT_COMPONENT_HANDOVER')->where('quantity', $quantity)->where('total_cost', $component['total_cost'])->exists()) {
            $this->fail('cutting_components_input_ancestry_invalid', '下游组件投入缺少本工单真实下料结果、冻结规格或完整交接金额。');
        }
        app(CuttingOutputEligibilityService::class)->assertMeasurements($component['requirement_snapshot'], $component['measurements'], $result->cut_length_mm, $result->piece_qty, (string) $result->actual_qty);
        return true;
    }

    public function components(object $output): array
    {
        $link = $this->link($output->source_target_type, (int) $output->source_target_id);
        if (! $link || $output->output_item_id !== null) $this->fail('cutting_components_output_invalid', '组件束产出身份无效。');
        $rows = DB::table('erp_cutting_result_routes as route')->join('erp_cutting_results as result', 'result.id', '=', 'route.result_id')
            ->join('erp_cutting_settlement_batches as batch', 'batch.id', '=', 'result.settlement_batch_id')
            ->where('batch.cutting_order_id', $link->cutting_order_id)->where('result.result_type', 'product')->where('result.status', 'CONFIRMED')
            ->where('route.status', 'WAIT_OPERATION')->orderBy('result.id')->orderBy('route.id')->lockForUpdate()->get(['route.*', 'result.item_id', 'result.configuration_id', 'result.actual_qty',
                'result.piece_qty', 'result.cut_length_mm', 'result.measurements', 'result.cutting_requirement_snapshot', 'result.material_lot_id']);
        if ($rows->isEmpty()) $this->fail('cutting_components_missing', '组件束没有已确认且尚未交接的逐规格真实下料结果。');
        $components = [];
        foreach ($rows as $row) {
            $r = $this->json($row->cutting_requirement_snapshot);
            $totalLength = FrozenCuttingStockPolicy::isTotalLength($r);
            $quantity = $totalLength ? (string) $row->actual_qty : (string) $row->piece_qty;
            $batchId = DB::table('erp_cutting_results')->where('id', $row->result_id)->value('settlement_batch_id');
            $used = DB::table('erp_production_cutting_inputs')->where('operation_id', $link->id)->where('settlement_batch_id', $batchId)->first();
            $proof = collect($this->json($used?->requirement_snapshot)['components'] ?? [])->firstWhere('target_material_requirement_id', (int) ($r['target_material_requirement_id'] ?? 0));
            $holding = DB::table('erp_material_holdings')->where('id', $row->holding_id)->lockForUpdate()->first();
            $lot = $holding ? DB::table('erp_material_lots')->where('id', $holding->material_lot_id)->first() : null;
            if (! $proof || ! $this->snapshotsEqual($proof, $r) || ($r['output_kind'] ?? null) !== self::KIND || (int) ($r['item_id'] ?? 0) !== (int) $row->item_id || (int) ($r['input']['item_id'] ?? 0) !== (int) $row->item_id
                || ! $holding || ! $lot || $holding->status !== 'ACTIVE' || $holding->position_type !== 'OPERATION_CUT_OUTPUT' || (int) $holding->position_id !== (int) $row->id
                || $lot->source_type !== 'cutting_result' || (int) $lot->source_id !== (int) $row->result_id || (int) $lot->item_id !== (int) $row->item_id
                || bccomp((string) $row->actual_qty, $quantity, 8) !== 0 || bccomp((string) $row->quantity, $quantity, 8) !== 0
                || bccomp((string) $holding->quantity, $quantity, 8) !== 0 || bccomp((string) $holding->total_cost, (string) $row->total_cost, 4) !== 0) {
                $this->fail('cutting_components_holding_invalid', '逐规格组件的原料、件数、实物来源或持有金额与冻结结果不一致。');
            }
            app(CuttingOutputEligibilityService::class)->assertMeasurements($r, $this->json($row->measurements), $row->cut_length_mm, $row->piece_qty, (string) $row->actual_qty);
            $components[] = ($totalLength ? ['quantity_basis' => 'total_length', 'quantity_unit' => 'm', 'quantity' => $quantity,
                'total_length_mm' => $this->json($row->measurements)['total_length_mm']] : []) + ['result_id' => (int) $row->result_id, 'route_id' => (int) $row->id, 'raw_item_id' => (int) $row->item_id,
                'bom_item_id' => (int) $r['bom_item_id'], 'source_requirement_id' => (int) $r['target_material_requirement_id'],
                'material_lot_id' => (int) $lot->id, 'source_holding_id' => (int) $holding->id,
                'piece_qty' => $totalLength ? null : bcadd((string) $row->piece_qty, '0', 8), 'total_cost' => bcadd((string) $row->total_cost, '0', 4),
                'measurements' => $this->json($row->measurements), 'requirement_snapshot' => $r];
        }
        return $components;
    }

    private function componentQuantity(array $component): string
    {
        if (($component['quantity_basis'] ?? null) !== 'total_length') {
            $quantity = FrozenCuttingStockPolicy::positiveDecimal($component['piece_qty'] ?? null);
            if (! $quantity) $this->fail('cutting_components_quantity_unit_invalid', '尺寸组件缺少有效的冻结件数。');
            return $quantity;
        }
        $quantity = FrozenCuttingStockPolicy::positiveDecimal($component['quantity'] ?? null);
        if (($component['quantity_unit'] ?? null) !== 'm' || ! $quantity || $component['piece_qty'] !== null
            || bccomp(bcmul($quantity, '1000', 8), (string) ($component['total_length_mm'] ?? '0'), 8) !== 0) $this->fail('cutting_components_quantity_unit_invalid', '管材组件持有数量必须明确为米，并与冻结总长度一致。');
        return $quantity;
    }

    private function snapshotsEqual(mixed $left, mixed $right): bool
    {
        return $this->canonicalSnapshot($left) === $this->canonicalSnapshot($right);
    }

    private function canonicalSnapshot(mixed $value): mixed
    {
        if (! is_array($value)) return $value;
        foreach ($value as $key => $item) $value[$key] = $this->canonicalSnapshot($item);
        // JSON object key order is immaterial; list order and scalar types remain frozen.
        if (! array_is_list($value)) ksort($value);
        return $value;
    }

    private function targetTable(string $type): string
    { return match ($type) { 'unit_operation' => 'erp_production_unit_operations', 'quantity_operation' => 'erp_production_quantity_operations', default => throw new WorkOrderDomainException('target_type_invalid', '组件目标类型无效。', 422) }; }
    private function json(mixed $value): array { return is_array($value) ? $value : ($value ? json_decode($value, true, 512, JSON_THROW_ON_ERROR) : []); }
    private function transaction(): void { if (DB::transactionLevel() < 1) throw new \LogicException('Cut component transfer requires a transaction.'); }
    private function fail(string $code, string $message): never { throw new WorkOrderDomainException($code, $message, 409); }
}
