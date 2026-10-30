<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Item;
use App\Models\Erp\ItemCategory;
use App\Models\Erp\MfgCuttingBatch;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLink;
use App\Models\Erp\MfgWorkOrderMaterial;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgTaskParticipant;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\CuttingDecimal;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\CuttingQuantityCalculator;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\Inventory\ProductionInventoryPosting;
use App\Services\Erp\MaterialPhysicalService;
use App\Services\Erp\SalesOrderFundingGateService;
use App\Services\Erp\UnitConversionDomainService;
use Illuminate\Support\Facades\DB;

/** 集中开料独立于制造路线；只有结算过的真实板件／管件才满足后续工序用料。 */
final class CuttingService
{
    use ProductionActor;

    public function __construct(
        private readonly ProductionInventoryPosting $inventory,
        private readonly MaterialPhysicalService $physicals,
        private readonly InventoryAvailabilityService $availability,
        private readonly CuttingQuantityCalculator $quantities,
        private readonly UnitConversionDomainService $units,
        private readonly WorkOrderService $workOrders,
        private readonly DocumentNumberService $numbers,
        private readonly SalesOrderFundingGateService $funding,
    ) {}

    public function context(int $workOrderId, array $filters, bool $amounts): array
    {
        $wo = MfgWorkOrder::query()->with(['materials', 'operations'])->findOrFail($workOrderId);
        $requirements = [];
        $remaining = [];
        foreach ($wo->operations->where('execution_context', 'production') as $op) {
            $remaining[$op->id] = $this->remainingUnits($wo, (int) $op->id);
            $requirements = array_merge($requirements, $this->requirements($wo, (int) $op->id));
        }
        $batches = MfgCuttingBatch::query()->where('work_order_id', $wo->id)
            ->when(! empty($filters['operation_id']), fn ($q) => $q->where('work_order_operation_id', (int) $filters['operation_id']))->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 10, ['*'], 'page', $filters['page'] ?? 1);
        $batches->getCollection()->transform(fn ($batch) => $this->batch($batch, $amounts));
        return ['work_order_id' => (int) $wo->id, 'business_version' => (int) $wo->business_version,
            'operations' => $wo->operations->where('execution_context', 'production')->map(fn ($op) => ['id' => (int) $op->id, 'operation_name' => $op->operation_name])->values()->all(),
            'requirements' => $requirements, 'remaining_unit_qty_by_operation' => $remaining, 'batches' => $batches->toArray(),
            'can_issue' => in_array($wo->status, ['released', 'in_progress'], true) && count($requirements) > 0];
    }

    public function sources(int $workOrderId, array $filters, bool $amounts): array
    {
        $wo = MfgWorkOrder::query()->with('materials')->findOrFail($workOrderId);
        $item = $this->sourceItem($wo, (int) $filters['item_id']);
        $query = $this->sourceQuery($item);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(function ($q) use ($keyword, $item) {
                $q->where('erp_inventory_balances.batch_no', 'like', '%'.$keyword.'%')
                    ->orWhere('erp_locations.location_name', 'like', '%'.$keyword.'%')
                    ->orWhere('erp_warehouses.warehouse_name', 'like', '%'.$keyword.'%');
                if ($item->materialManagementMode() === 'physical') $q->orWhere('cut_physical.physical_no', 'like', '%'.$keyword.'%');
                if (str_contains($item->item_code.' '.$item->item_name.' '.$item->spec.' '.$item->model.' '.$item->material_grade, $keyword)) $q->orWhereRaw('1=1');
            });
        }
        if (! empty($filters['category_id']) && (int) $item->category_id !== (int) $filters['category_id']) $query->whereRaw('1=0');
        $page = $query->orderBy('erp_inventory_balances.id')->when($item->materialManagementMode() === 'physical', fn ($q) => $q->orderBy('cut_physical.id'))
            ->paginate($filters['per_page'] ?? 10, ['*'], 'page', $filters['page'] ?? 1);
        $page->getCollection()->transform(fn ($balance) => $this->publicData($this->source($balance, $item), $amounts));
        return $page->toArray() + ['categories' => ItemCategory::query()->where(fn ($q) => $q->whereNull('management_scope')->orWhere('management_scope', 'factory'))
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'parent_id', 'category_name'])->toArray()];
    }

    public function preview(int $workOrderId, array $data, bool $amounts): array
    {
        $wo = MfgWorkOrder::query()->with(['materials', 'operations'])->findOrFail($workOrderId);
        return $this->publicData($this->plan($wo, $data), $amounts);
    }

    public function issue(int $workOrderId, array $data, object $actor, bool $amounts): array
    {
        return DB::transaction(function () use ($workOrderId, $data, $actor, $amounts) {
            $wo = $this->lockedWorkOrder($workOrderId);
            $hash = $this->hash($data);
            $old = MfgCuttingBatch::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($old) {
                if ((int) $old->work_order_id !== (int) $wo->id || ! hash_equals($old->request_hash, $hash)) $this->fail('idempotency_conflict', '这个领料请求编号已被其他内容使用，请刷新后重新确认。', 409);
                return $this->batch($old, $amounts);
            }
            if (! in_array($wo->status, ['released', 'in_progress'], true)) $this->fail('status_invalid', '只有已下达或生产中的工单可以领入开料。');
            if ((int) $wo->business_version !== (int) $data['expected_version']) $this->fail('version_conflict', '工单已经变更，请刷新后重新选料。', 409);
            $this->assertFunding($wo);
            $plan = $this->plan($wo, $data, true);
            $this->assertActor((int) $plan['operation_id'], $actor);
            $batch = MfgCuttingBatch::create([
                'batch_no' => $this->numbers->next('mfg_cutting_batch', 'MCB'), 'work_order_id' => $wo->id,
                'work_order_operation_id' => $plan['operation_id'], 'status' => 'issued', 'business_version' => 1,
                'unit_qty' => $plan['unit_qty'], 'completed_unit_qty' => 0, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'bom_snapshot' => $wo->bom_snapshot, 'input_lines' => $plan['sources'], 'planned_outputs' => $plan['assignments'],
                'input_cost_amount' => $plan['input_cost_amount'], 'issued_by_legacy_id' => $this->actorId($actor),
                'issued_by_name' => $this->actorName($actor), 'issued_at' => now(), 'remark' => $data['remark'] ?? null,
            ]);
            $posted = $this->inventory->postManufacturingCuttingIssue($batch, $wo, $this->actorId($actor));
            $inputs = $batch->input_lines;
            foreach ($inputs as &$input) {
                $input = array_replace($input, $posted['lines'][$input['source_key']]);
                if ($input['physical_material_id']) {
                    $physical = DB::table('erp_material_physicals')->where('id', $input['physical_material_id'])->lockForUpdate()->first();
                    $holding = DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->lockForUpdate()->first();
                    $target = $this->physicals->nonWarehouseHolding((int) $physical->material_lot_id, 'MFG_CUTTING', (int) $batch->id, $input['cost_amount'], 'ACTIVE');
                    $this->physicals->movePhysical($physical, $holding, $target, 'MFG_CUTTING_ISSUE', 'CUTTING', (int) $posted['transaction']->id, $this->actorId($actor) ?? 0);
                    $input['cutting_holding_id'] = $target;
                }
            }
            unset($input);
            $batch->update(['input_lines' => $inputs]);
            $wo->update(['business_version' => (int) $wo->business_version + 1]);
            $this->workOrders->log($wo, 'cutting_issued', $wo->status, $wo->status, '开料批次 '.$batch->batch_no.' 已领入真实原料，待登记产出和确认余料', $actor, ['cutting_batch_id' => $batch->id]);
            return $this->batch($batch->fresh(), $amounts);
        }, 5);
    }

    public function settle(int $batchId, array $data, object $actor, bool $amounts): array
    {
        return DB::transaction(function () use ($batchId, $data, $actor, $amounts) {
            $workOrderId = MfgCuttingBatch::query()->whereKey($batchId)->value('work_order_id');
            if (! $workOrderId) $this->fail('not_found', '开料批次不存在。', 404);
            $wo = $this->lockedWorkOrder((int) $workOrderId);
            $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->whereKey(MfgCuttingBatch::query()->whereKey($batchId)->value('work_order_operation_id'))->lockForUpdate()->firstOrFail();
            $batch = MfgCuttingBatch::query()->lockForUpdate()->findOrFail($batchId);
            $this->assertActor((int) $op->id, $actor);
            $hash = $this->hash($data);
            if ($batch->status === 'settled') {
                if ($batch->settlement_key !== $data['idempotency_key'] || ! hash_equals((string) $batch->settlement_hash, $hash)) $this->fail('already_settled', '这批已经结算，不能改写原料和产出事实。', 409);
                return $this->batch($batch, $amounts);
            }
            if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true) || $batch->status !== 'issued') $this->fail('status_invalid', '当前工单或开料批次不能结算。');
            if ((int) $batch->business_version !== (int) $data['expected_version']) $this->fail('version_conflict', '开料批次已变化，请重新打开后确认。', 409);
            if (! ($data['confirmed'] ?? false)) $this->fail('remnant_confirmation_required', '请人工核实各来源余料后再确认结算。');
            $actualUnits = (int) $data['unit_qty'];
            if ($actualUnits < 1 || $actualUnits > (int) $batch->unit_qty) $this->fail('qty_invalid', '实际合格套数必须大于零，且不能超过本批计划套数。');
            $requirements = $this->requirements($wo, (int) $op->id);
            Item::query()->whereIn('id', array_column($batch->input_lines, 'item_id'))->orderBy('id')->lockForUpdate()->get(['id']);
            $rows = $data['assignments'] ?? ($actualUnits === (int) $batch->unit_qty ? $batch->planned_outputs : null);
            if ($rows === null) $this->fail('output_required', '实际套数改变后，请重新登记实际板件或管件分配。');
            [$outputs, $summaries] = $this->outputs($requirements, $batch->input_lines, $rows, $actualUnits, true);
            $remainingRows = collect($data['remnants'])->keyBy('source_key');
            if ($remainingRows->count() !== count($data['remnants']) || $remainingRows->count() !== count($batch->input_lines)
                || $remainingRows->keys()->diff(collect($batch->input_lines)->pluck('source_key'))->isNotEmpty()) $this->fail('remnant_invalid', '每个真实来源都须且只能登记一份确认余料，无余料请填零。');
            $remnants = [];
            $returnedCost = '0.0000';
            foreach ($batch->input_lines as $input) {
                $this->sourceItem($wo, (int) $input['item_id'], true);
                $row = $remainingRows->get($input['source_key']);
                $measure = CuttingDecimal::value($row['measure_qty'], 8, true);
                if ($input['mode'] === 'length' && bccomp($measure, bcdiv(bcmul($measure, '1000', 2), '1000', 8), 8) !== 0)
                    $this->fail('remnant_precision_invalid', '管材余料以米登记，最多保留五位小数，避免实际余长在入库时被截断。');
                if (bccomp($measure, '0', 8) > 0) {
                    $location = DB::table('erp_locations')->where('id', (int) $row['location_id'])->lockForUpdate()->first();
                    if (! $location || (int) $location->warehouse_id !== (int) $row['warehouse_id'] || ! in_array($location->status, ['enabled', 'active'], true)
                        || $location->location_purpose !== 'general') $this->fail('remnant_location_invalid', '原料余料必须回到所选仓库内启用的普通库存库位。');
                    $warehouse = DB::table('erp_warehouses')->where('id', (int) $row['warehouse_id'])->sharedLock()->first();
                    if (! $warehouse || ! in_array($warehouse->status, ['enabled', 'active'], true) || ($warehouse->management_scope && $warehouse->management_scope !== 'factory'))
                        $this->fail('remnant_warehouse_invalid', '原料余料必须回到启用的工厂仓库。');
                }
                $summary = $summaries[$input['source_key']];
                if (bccomp($measure, $summary['suggested_remnant'], 8) > 0) $this->fail('remnant_exceeds_source', '确认余料超过投入减去实际产出的剩余量。');
                $cost = bccomp($measure, '0', 8) === 0 ? '0.0000' : CuttingDecimal::share($input['cost_amount'], $input['input_measure'], $measure);
                $remnants[] = ['source_key' => $input['source_key'], 'item_id' => $input['item_id'], 'measure_qty' => $measure, 'output_measure' => $summary['output_measure'],
                    'measure_unit' => $input['measure_unit'], 'scrap_measure' => bcsub($summary['suggested_remnant'], $measure, 8),
                    'warehouse_id' => (int) $row['warehouse_id'], 'location_id' => (int) $row['location_id'],
                    'warehouse_name' => bccomp($measure, '0', 8) > 0 ? $warehouse->warehouse_name : $input['warehouse_name'],
                    'location_name' => bccomp($measure, '0', 8) > 0 ? $location->location_name : $input['location_name'], 'cost_amount' => $cost];
                $returnedCost = bcadd($returnedCost, $cost, 4);
            }
            $net = bcsub((string) $batch->input_cost_amount, $returnedCost, 4);
            if (bccomp($net, '0', 4) < 0) $this->fail('cost_invalid', '余料成本不能超过投入总成本。');
            $batch->update(['actual_outputs' => $outputs, 'remnants' => $remnants, 'completed_unit_qty' => $actualUnits,
                'remnant_cost_amount' => $returnedCost, 'net_cost_amount' => $net]);
            $posted = $this->inventory->postManufacturingCuttingSettlement($batch, $wo, $this->actorId($actor));
            foreach ($remnants as &$remnant) $remnant = array_replace($remnant, $posted['lines'][$remnant['source_key']] ?? []);
            unset($remnant);
            $batch->refresh();
            foreach ($batch->input_lines as $input) {
                if ($input['physical_material_id']) {
                    $return = collect($remnants)->firstWhere('source_key', $input['source_key']);
                    $this->physicals->completeManufacturingCut($batch, $input, bcsub($input['cost_amount'], $return['cost_amount'], 4), $this->actorId($actor) ?? 0);
                }
            }
            // issued_qty 表达已满足的冻结理论需求，绝不能把实际整张／整根数量当成零件需求。
            foreach ($wo->materials->where('work_order_operation_id', $op->id) as $material) {
                if (! MaterialIssueService::isCutting($material)) continue;
                $increase = bcdiv(bcmul((string) $material->required_qty, (string) $actualUnits, 12), (string) $wo->planned_qty, 4);
                $doneUnits = (int) MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('work_order_operation_id', $op->id)->where('status', 'settled')->lockForUpdate()->get()->sum('completed_unit_qty') + $actualUnits;
                $newQty = $doneUnits >= (int) $wo->planned_qty ? (string) $material->required_qty : bcadd((string) $material->issued_qty, $increase, 4);
                if (bccomp($newQty, (string) $material->required_qty, 4) > 0) $this->fail('qty_invalid', '开料已满足量超过工单冻结需求。');
                $material->update(['issued_qty' => $newQty]);
            }
            $batch->update(['remnants' => $remnants, 'status' => 'settled', 'business_version' => (int) $batch->business_version + 1,
                'settlement_key' => $data['idempotency_key'], 'settlement_hash' => $hash,
                'settled_by_legacy_id' => $this->actorId($actor), 'settled_by_name' => $this->actorName($actor), 'settled_at' => now(),
                'remark' => $data['remark'] ?? $batch->remark]);
            $wo->update(['business_version' => (int) $wo->business_version + 1]);
            $this->workOrders->log($wo, 'cutting_settled', $wo->status, $wo->status, '开料批次 '.$batch->batch_no.' 已确认 '.$actualUnits.' 套板件／管件及实际余料，后续按路线加工', $actor, ['cutting_batch_id' => $batch->id]);
            return $this->batch($batch->fresh(), $amounts);
        }, 5);
    }

    public function batch(MfgCuttingBatch $batch, bool $amounts): array
    {
        $row = $batch->only(['id', 'batch_no', 'work_order_id', 'work_order_operation_id', 'status', 'business_version', 'unit_qty', 'completed_unit_qty',
            'input_lines', 'planned_outputs', 'actual_outputs', 'remnants', 'input_cost_amount', 'remnant_cost_amount', 'net_cost_amount',
            'issued_by_name', 'settled_by_name', 'issued_at', 'settled_at', 'remark']);
        $row['summaries'] = array_map(fn ($input) => array_intersect_key($input, array_flip(['source_key', 'input_measure', 'output_measure', 'suggested_remnant', 'measure_unit'])), $batch->input_lines);
        return $this->publicData($row, $amounts);
    }

    private function plan(MfgWorkOrder $wo, array $data, bool $lock = false): array
    {
        $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->where('execution_context', 'production')->whereKey($data['operation_id'])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $op) $this->fail('operation_invalid', '请选择本工单实际存在的车间工序。');
        $unitQty = (int) $data['unit_qty'];
        if ($unitQty < 1 || $unitQty > $this->remainingUnits($wo, (int) $op->id, $lock)) $this->fail('qty_invalid', '本次套数超过该工序尚未安排开料的数量。');
        $requirements = $this->requirements($wo, (int) $op->id);
        if (! $requirements) $this->fail('no_cutting_material', '这道工序没有冻结的板材或管材开料需求。');
        $allowed = array_unique(array_column($requirements, 'item_id'));
        $seen = []; $physicalIds = []; $sources = []; $balanceRemaining = []; $costRemaining = [];
        $requested = $data['sources'];
        usort($requested, fn ($a, $b) => [(int) $a['item_id'], (int) $a['inventory_balance_id'], (int) ($a['physical_material_id'] ?? 0), $a['source_key']]
            <=> [(int) $b['item_id'], (int) $b['inventory_balance_id'], (int) ($b['physical_material_id'] ?? 0), $b['source_key']]);
        if ($lock) Item::query()->whereIn('id', array_column($requested, 'item_id'))->orderBy('id')->lockForUpdate()->get(['id']);
        foreach ($requested as $row) {
            $key = (string) $row['source_key'];
            if (isset($seen[$key]) || ! in_array((int) $row['item_id'], $allowed, true)) $this->fail('source_invalid', '来源编号重复或不是本道工序冻结的原料。');
            $seen[$key] = true;
            $item = $this->sourceItem($wo, (int) $row['item_id'], $lock);
            $query = $this->sourceQuery($item)->where('erp_inventory_balances.id', $row['inventory_balance_id']);
            if ($item->materialManagementMode() === 'physical') $query->where('cut_physical.id', (int) ($row['physical_material_id'] ?? 0));
            elseif ($row['physical_material_id'] ?? null) $this->fail('source_invalid', '管材不能引用板材实物身份。');
            $balance = $query->when($lock, fn ($q) => $q->lockForUpdate())->first();
            if (! $balance) $this->fail('source_unavailable', '所选来源已被占用或不在可用仓库中，请重新选料。');
            $source = $this->source($balance, $item);
            if (! hash_equals($source['source_version'], (string) $row['source_version'])) $this->fail('source_changed', '来源库存、实物尺寸或版本已变化，请重新选料。', 409);
            $qty = CuttingDecimal::value($row['qty']);
            if (in_array($source['unit_kind'], ['sheet', 'root'], true) && bccomp($qty, '1', 8) !== 0) $this->fail('whole_source_required', '板材逐张、管材逐根登记，每条真实来源的基本数量必须为一。');
            if ($source['physical_material_id']) {
                if (in_array($source['physical_material_id'], $physicalIds, true)) $this->fail('source_duplicate', '同一张板不能重复选择。');
                $physicalIds[] = $source['physical_material_id'];
                if ($lock) $this->physicals->assertPhysicalNotInOpenDocument($source['physical_material_id']);
            }
            $id = $source['inventory_balance_id'];
            $balanceRemaining[$id] ??= $source['available_qty'];
            $costRemaining[$id] ??= ['qty' => (string) $balance->quantity_on_hand, 'cost' => (string) $balance->inventory_value];
            if (bccomp($qty, $balanceRemaining[$id], 8) > 0) $this->fail('stock_short', '同一库存来源的累计领入数量超过可用量。');
            $cost = $source['physical_material_id'] ? $source['cost_amount'] : CuttingDecimal::share($costRemaining[$id]['cost'], $costRemaining[$id]['qty'], $qty);
            $costRemaining[$id] = ['qty' => bcsub($costRemaining[$id]['qty'], $qty, 8), 'cost' => bcsub($costRemaining[$id]['cost'], $cost, 4)];
            $balanceRemaining[$id] = bcsub($balanceRemaining[$id], $qty, 8);
            $sources[] = array_replace($source, ['source_key' => $key, 'qty' => $qty, 'cost_amount' => $cost,
                'input_measure' => bcmul($source['measure_qty'], $qty, 8)]);
        }
        if (! $sources) $this->fail('source_required', '请选择实际领入的板材或管材来源。');
        $assignments = $data['assignments'] ?? $this->suggest($requirements, $sources, $unitQty);
        [$outputs, $summaries] = $this->outputs($requirements, $sources, $assignments, $unitQty);
        foreach ($sources as &$source) $source = array_replace($source, $summaries[$source['source_key']]);
        unset($source);
        $total = '0.0000';
        foreach ($sources as $source) $total = bcadd($total, $source['cost_amount'], 4);
        return ['work_order_id' => (int) $wo->id, 'business_version' => (int) $wo->business_version, 'operation_id' => (int) $op->id,
            'unit_qty' => $unitQty, 'requirements' => $requirements, 'sources' => $sources, 'assignments' => $outputs,
            'summaries' => array_values($summaries), 'input_cost_amount' => $total];
    }

    private function requirements(MfgWorkOrder $wo, int $operationId): array
    {
        $lines = collect(data_get($wo->bom_snapshot, 'items', []))->keyBy('id');
        // 当前主档规格仅用于区分同名原料；加工尺寸和数量仍完全采用工单冻结资料。
        $items = Item::query()->whereIn('id', $wo->materials->where('work_order_operation_id', $operationId)->pluck('component_item_id')->unique())
            ->get(['id', 'spec', 'model'])->keyBy('id');
        $out = [];
        foreach ($wo->materials->where('work_order_operation_id', $operationId) as $material) {
            // 只有按实物管理的板材／管材走开料批次；按数量管理的按定额收料或「已备料」确认。
            if (! MaterialIssueService::isCutting($material)) continue;
            $line = $lines->get($material->bom_item_id);
            $snapshot = $line['cutting_quantity_snapshot'] ?? null;
            if (! is_array($snapshot) || ($snapshot['mode'] ?? null) !== $material->cutting_mode) $this->fail('cutting_snapshot_missing', '开料需求缺少冻结尺寸与单位，请先通过正式工单资料补齐。');
            $dimension = $snapshot['dimensions'];
            $total = ($snapshot['quantity_basis'] ?? null) === 'total_length';
            $count = $total ? 1 : (int) ($dimension['piece_qty'] ?? 0);
            if ($count < 1 || $count > 1000) $this->fail('cutting_snapshot_invalid', '冻结板件／管件数不在支持范围内。');
            for ($index = 1; $index <= $count; $index++) {
                $measure = $total ? bcdiv((string) $snapshot['total_length_mm'], '1000', 8) : bcdiv((string) $dimension['length_mm'], '1000', 8);
                if ($material->cutting_mode === 'sheet') $measure = bcdiv(bcmul((string) $dimension['length_mm'], (string) $dimension['width_mm'], 8), '1000000', 8);
                $ratio = (string) $material->qty_ratio;
                if (! $total && bccomp($ratio, bcadd($ratio, '0', 0), 6) !== 0) $this->fail('cutting_ratio_invalid', '整件开料需求不能分配为不足一件的比例。');
                $out[] = ['material_id' => (int) $material->id, 'operation_id' => $operationId, 'item_id' => (int) $material->component_item_id,
                    'item_code' => $material->component_item_code, 'item_name' => $material->component_item_name, 'unit_name' => $material->unit_name,
                    'specification' => $items->get($material->component_item_id)?->spec ?: $items->get($material->component_item_id)?->model,
                    'mode' => $material->cutting_mode, 'unit_kind' => $snapshot['unit_kind'], 'quantity_basis' => $total ? 'total_length' : 'dimensions_pieces',
                    'part_key' => 'm'.$material->id.'p'.$index, 'piece_index' => $index,
                    'part_name' => $line['remark'] ?: (($material->cutting_mode === 'sheet' ? '板件 ' : '管件 ').$line['line_no'].'-'.$index),
                    'length_mm' => $dimension['length_mm'] ?? null, 'width_mm' => $dimension['width_mm'] ?? null,
                    'thickness_mm' => $dimension['thickness_mm'] ?? null, 'allow_rotation' => (bool) ($dimension['allow_rotation'] ?? false),
                    'per_piece_measure' => $total ? '1.00000000' : $measure,
                    'per_unit_measure' => bcmul($measure, $ratio, 8), 'per_unit_piece_qty' => $total ? null : (int) $ratio,
                    'remaining_unit_qty' => $this->remainingUnits($wo, $operationId)];
            }
        }
        return $out;
    }

    private function suggest(array $requirements, array $sources, int $units): array
    {
        $rows = [];
        $used = array_fill_keys(array_column($sources, 'source_key'), '0.00000000');
        $placed = [];
        foreach ($requirements as $part) {
            $left = $part['quantity_basis'] === 'total_length' ? bcmul($part['per_unit_measure'], (string) $units, 8) : (string) ($part['per_unit_piece_qty'] * $units);
            foreach ($sources as $source) {
                if ((int) $source['item_id'] !== (int) $part['item_id'] || bccomp($left, '0', 8) <= 0) continue;
                $room = bcsub($source['input_measure'], $used[$source['source_key']], 8);
                $take = $part['quantity_basis'] === 'total_length' ? $this->minimum($left, $room) : $this->minimum($left, bcdiv($room, $part['per_piece_measure'], 0));
                if ($part['mode'] === 'sheet') {
                    if (bccomp((string) $part['thickness_mm'], (string) ($source['dimensions']['nominal_thickness_mm'] ?? $source['dimensions']['thickness_mm']), 2) !== 0) continue;
                    if ($source['shape'] === 'RECTANGLE') {
                        $low = 0; $high = min((int) $take, 10000);
                        while ($low < $high) {
                            $middle = (int) ceil(($low + $high) / 2);
                            $candidate = array_merge($placed[$source['source_key']] ?? [], array_fill(0, $middle, $part));
                            if ($this->fitsRectangleLayout($source, $candidate)) $low = $middle; else $high = $middle - 1;
                        }
                        $take = (string) $low;
                    }
                }
                if (bccomp($take, '0', 8) <= 0) continue;
                if ($part['mode'] === 'sheet') $placed[$source['source_key']] = array_merge($placed[$source['source_key']] ?? [], array_fill(0, (int) $take, $part));
                $rows[] = ['source_key' => $source['source_key'], 'part_key' => $part['part_key'], 'qty' => $take];
                $used[$source['source_key']] = bcadd($used[$source['source_key']], bcmul($take, $part['per_piece_measure'], 8), 8);
                $left = bcsub($left, $take, 8);
            }
            if (bccomp($left, '0', 8) > 0) $this->fail('source_short', '所选真实来源不足以满足 '.$part['part_name'].'，请增加来源或减少本批套数。');
        }
        return $rows;
    }

    private function outputs(array $requirements, array $sources, array $rows, int $units, bool $allowUnused = false): array
    {
        $parts = collect($requirements)->keyBy('part_key');
        $inputs = collect($sources)->keyBy('source_key');
        $totals = []; $used = []; $seen = []; $outputs = []; $rectangles = [];
        foreach ($rows as $row) {
            $part = $parts->get($row['part_key']); $input = $inputs->get($row['source_key']);
            $key = $row['source_key'].'|'.$row['part_key'];
            if (! $part || ! $input || isset($seen[$key]) || (int) $part['item_id'] !== (int) $input['item_id']) $this->fail('output_invalid', '板件／管件分配与真实原料不匹配或重复。');
            $seen[$key] = true;
            $qty = CuttingDecimal::value($row['qty'], 8, true);
            if ($part['quantity_basis'] !== 'total_length' && bccomp($qty, bcadd($qty, '0', 0), 8) !== 0) $this->fail('whole_piece_required', '实际板件和管件数必须是非负整数。');
            if ($part['mode'] === 'sheet' && bccomp((string) $part['thickness_mm'], (string) ($input['dimensions']['nominal_thickness_mm'] ?? $input['dimensions']['thickness_mm']), 2) !== 0) $this->fail('thickness_mismatch', '板件要求厚度与所选真实板材不一致，不能替换。');
            $measure = bcmul($qty, $part['per_piece_measure'], 8);
            $used[$input['source_key']] = bcadd($used[$input['source_key']] ?? '0', $measure, 8);
            if (bccomp($used[$input['source_key']], $input['input_measure'], 8) > 0) $this->fail('output_exceeds_source', '多个规格的联合净用量超过所选真实来源。');
            $totals[$part['part_key']] = bcadd($totals[$part['part_key']] ?? '0', $qty, 8);
            $outputs[] = ['source_key' => $input['source_key'], 'part_key' => $part['part_key'], 'qty' => $qty,
                'material_id' => $part['material_id'], 'piece_index' => $part['piece_index'], 'part_name' => $part['part_name'],
                'per_piece_measure' => $part['per_piece_measure'], 'measure_qty' => $measure,
                'dimensions' => array_intersect_key($part, array_flip(['length_mm', 'width_mm', 'thickness_mm']))];
            if (bccomp($qty, '0', 8) > 0 && $part['quantity_basis'] !== 'total_length') {
                if ((int) $qty > 10000) $this->fail('qty_invalid', '单个板件分配数量过大，请分批开料。');
                for ($piece = 0; $piece < (int) $qty; $piece++) $rectangles[$input['source_key']][] = $part;
            }
        }
        foreach ($requirements as $part) {
            $needed = $part['quantity_basis'] === 'total_length' ? bcmul($part['per_unit_measure'], (string) $units, 8) : (string) ($part['per_unit_piece_qty'] * $units);
            if (bccomp($totals[$part['part_key']] ?? '0', $needed, 8) !== 0) $this->fail('output_incomplete', $part['part_name'].' 的分配合计必须等于本批实际套数所需数量。');
        }
        $summaries = [];
        foreach ($sources as $input) {
            $measure = $used[$input['source_key']] ?? '0.00000000';
            if (! $allowUnused && bccomp($measure, '0', 8) <= 0) $this->fail('source_unused', '每条领入来源须有实际产出，未使用来源请移除。');
            if ($input['shape'] === 'RECTANGLE') $this->assertRectangleLayout($input, $rectangles[$input['source_key']] ?? []);
            if ($input['mode'] === 'length') {
                foreach ($rectangles[$input['source_key']] ?? [] as $part) if (bccomp(bcdiv((string) $part['length_mm'], '1000', 8), $input['input_measure'], 8) > 0) $this->fail('piece_too_long', '单段管件不能超过实际来源长度；总米数用量不受单根容纳限制。');
            }
            $summaries[$input['source_key']] = ['source_key' => $input['source_key'], 'input_measure' => $input['input_measure'],
                'output_measure' => $measure, 'suggested_remnant' => bcsub($input['input_measure'], $measure, 8), 'measure_unit' => $input['measure_unit']];
        }
        return [$outputs, $summaries];
    }

    /** 保守的逐行矩形排布可证明不重叠；面积余板严格只按面积估算，不伪造坐标。 */
    private function assertRectangleLayout(array $source, array $parts): void
    {
        if (! $this->fitsRectangleLayout($source, $parts)) $this->fail('layout_not_fit', '当前矩形板件分配不能按逐行排布容纳，请调整每张板的件数或增加来源。');
    }

    private function fitsRectangleLayout(array $source, array $parts): bool
    {
        if ($source['mode'] !== 'sheet') return true;
        $stockL = (string) $source['dimensions']['length_mm']; $stockW = (string) $source['dimensions']['width_mm'];
        $x = '0'; $y = '0'; $rowHeight = '0';
        usort($parts, fn ($a, $b) => bccomp((string) $b['width_mm'], (string) $a['width_mm'], 2));
        foreach ($parts as $part) {
            $length = (string) $part['length_mm']; $width = (string) $part['width_mm'];
            if ((bccomp($length, $stockL, 2) > 0 || bccomp($width, $stockW, 2) > 0) && $part['allow_rotation']) [$length, $width] = [$width, $length];
            if (bccomp($length, $stockL, 2) > 0 || bccomp($width, $stockW, 2) > 0) return false;
            if (bccomp(bcadd($x, $length, 2), $stockL, 2) > 0) { $x = '0'; $y = bcadd($y, $rowHeight, 2); $rowHeight = '0'; }
            if (bccomp(bcadd($y, $width, 2), $stockW, 2) > 0) return false;
            $x = bcadd($x, $length, 2);
            if (bccomp($width, $rowHeight, 2) > 0) $rowHeight = $width;
        }
        return true;
    }

    private function sourceItem(MfgWorkOrder $wo, int $itemId, bool $lock = false): Item
    {
        if (! $wo->materials->contains(fn ($row) => (int) $row->component_item_id === $itemId && MaterialIssueService::isCutting($row))) $this->fail('item_invalid', '所选物料不在本工单冻结的开料需求中（按数量管理的板材／管材请直接收料或点「已备料」）。');
        $item = Item::query()->with('unit.standardUnit')->where('status', 'enabled')->when($lock, fn ($q) => $q->lockForUpdate())->find($itemId);
        if (! $item || ($item->management_scope && $item->management_scope !== 'factory')) $this->fail('item_invalid', '开料必须选择启用的工厂物料。');
        if ($wo->materials->where('component_item_id', $itemId)->contains(fn ($material) => $material->cutting_mode !== $item->cuttingMode()))
            $this->fail('material_policy_changed', '原料当前下料模式与工单冻结需求不一致，请先核实资料。');
        if ($item->cuttingMode() === 'sheet' && $item->materialManagementMode() !== 'physical') $this->fail('physical_required', '板材必须采用逐张实物管理，不能通过数量来源代替。');
        if ($item->materialManagementMode() !== 'physical' && $item->serialTrackingMode() !== 'none') $this->fail('serial_source_required', '按序列号管理的原料暂不能从普通开料来源领入，请先核实实物身份。');
        $unit = $this->units->canonicalUnit($item->unit);
        foreach (collect(data_get($wo->bom_snapshot, 'items', []))->where('component_item_id', $itemId) as $line) {
            $snapshot = $line['cutting_quantity_snapshot'] ?? null;
            if (! $snapshot || ($snapshot['mode'] ?? null) !== $item->cuttingMode() || ! $unit || (int) data_get($snapshot, 'unit.id') !== (int) $unit->id)
                $this->fail('material_policy_changed', '原料当前下料模式或库存单位与工单冻结资料不一致，请先核实，不能重新解释原来的数量。');
        }
        return $item;
    }

    private function sourceQuery(Item $item)
    {
        $query = $this->availability->outboundBalanceQuery([(int) $item->id])
            ->leftJoin('erp_material_lots as cut_lot', 'cut_lot.id', '=', 'erp_inventory_balances.material_lot_id')
            ->where(fn ($q) => $q->whereNull('erp_warehouses.management_scope')->orWhere('erp_warehouses.management_scope', 'factory'))
            ->where('erp_locations.location_purpose', 'general')
            ->where('erp_inventory_balances.unit_id', $this->units->canonicalUnit($item->unit)?->id)
            ->where('erp_inventory_balances.quantity_available', '>', 0)
            ->whereRaw('(erp_inventory_balances.quantity_on_hand - erp_inventory_balances.quantity_locked - erp_inventory_balances.quantity_defective - erp_inventory_balances.quantity_pending) > 0')
            ->where(function ($q) { $q->whereNull('erp_inventory_balances.material_lot_id')->orWhere(fn ($lot) => $lot->whereColumn('cut_lot.item_id', 'erp_inventory_balances.item_id')->whereNull('cut_lot.configuration_id')->whereNull('cut_lot.stage_id')->whereIn('cut_lot.material_form', ['FULL_STOCK', 'STANDARD_LENGTH', 'REMNANT'])); })
            ->addSelect(['erp_warehouses.warehouse_name as cutting_warehouse_name', 'erp_locations.location_name as cutting_location_name',
                'cut_lot.material_form as cutting_material_form', 'cut_lot.cut_length_mm as cutting_lot_length_mm']);
        if ($item->materialManagementMode() === 'physical') {
            $query->join('erp_material_holdings as cut_holding', 'cut_holding.inventory_balance_id', '=', 'erp_inventory_balances.id')
                ->join('erp_material_physicals as cut_physical', 'cut_physical.current_holding_id', '=', 'cut_holding.id')
                ->where('cut_holding.position_type', 'WAREHOUSE')->where('cut_holding.status', 'ACTIVE')->where('cut_physical.status', 'AVAILABLE')
                ->whereColumn('cut_physical.item_id', 'erp_inventory_balances.item_id')
                ->whereColumn('cut_physical.material_lot_id', 'cut_holding.material_lot_id')
                ->whereColumn('cut_physical.material_lot_id', 'erp_inventory_balances.material_lot_id')
                ->addSelect(['cut_physical.id as cutting_physical_id', 'cut_physical.physical_no as cutting_physical_no',
                    'cut_physical.dimensions as cutting_dimensions', 'cut_physical.shape as cutting_shape',
                    'cut_physical.total_cost as cutting_physical_cost', 'cut_physical.business_version as cutting_physical_version']);
        }
        return $query;
    }

    private function source(InventoryBalance $balance, Item $item): array
    {
        $unit = $this->units->canonicalUnit($item->unit);
        if (! $unit) $this->fail('unit_missing', '原料必须维护真实的库存基本单位。');
        $kind = $this->quantities->unitKind($unit);
        $mode = $item->cuttingMode();
        $dimensions = $balance->cutting_dimensions ? json_decode($balance->cutting_dimensions, true, 512, JSON_THROW_ON_ERROR) : [];
        $shape = strtoupper((string) ($balance->cutting_shape ?: ($mode === 'length' ? 'LENGTH' : '')));
        if ($mode === 'sheet') {
            if ($kind !== 'sheet') $this->fail('unit_invalid', '逐张实物板材的库存基本单位必须为张。');
            if (! in_array($shape, ['RECTANGLE', 'AREA_ONLY'], true) || ! isset($dimensions['thickness_mm'])) $this->fail('source_dimensions_missing', '板材缺少可确认的真实形状或厚度。');
            $measure = $shape === 'AREA_ONLY' ? CuttingDecimal::value($dimensions['area_m2'] ?? null)
                : bcdiv(bcmul(CuttingDecimal::value($dimensions['length_mm'] ?? null, 2), CuttingDecimal::value($dimensions['width_mm'] ?? null, 2), 8), '1000000', 8);
        } else {
            if (! in_array($kind, ['root', 'm'], true)) $this->fail('unit_invalid', '管材库存单位必须明确为根或米。');
            $length = $balance->cutting_lot_length_mm;
            if ($balance->cutting_material_form === 'REMNANT' && (float) $length <= 0) $this->fail('remnant_length_missing', '这条管材余料缺少实际余长，不能套用整根标准长度。');
            if ((float) $length <= 0) $length = $item->standard_stock_length_mm;
            if ($kind === 'root' && (float) $length <= 0) $this->fail('source_dimensions_missing', '按根领料必须有来源真实长度，不能默认补标准长度。');
            $dimensions = $length ? ['length_mm' => bcadd((string) $length, '0', 2)] : [];
            $measure = $kind === 'root' ? bcdiv((string) $length, '1000', 8) : '1.00000000';
        }
        $available = bcsub(bcsub(bcsub((string) $balance->quantity_on_hand, (string) $balance->quantity_locked, 8), (string) $balance->quantity_defective, 8), (string) $balance->quantity_pending, 8);
        $available = $this->minimum($available, (string) $balance->quantity_available);
        $version = $this->hash([(int) $balance->id, (string) $balance->quantity_on_hand, (string) $balance->quantity_locked,
            (string) $balance->quantity_defective, (string) $balance->quantity_pending, (string) $balance->inventory_value,
            (string) $balance->last_transaction_at, $balance->material_lot_id, $balance->cutting_physical_id, $balance->cutting_physical_version, $dimensions]);
        return ['source_key' => $balance->cutting_physical_id ? 'physical:'.$balance->cutting_physical_id : 'balance:'.$balance->id,
            'item_id' => (int) $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name, 'specification' => $item->spec ?: $item->model,
            'material_grade' => $item->material_grade,
            'category_id' => $item->category_id, 'inventory_balance_id' => (int) $balance->id, 'physical_material_id' => $balance->cutting_physical_id ? (int) $balance->cutting_physical_id : null,
            'physical_no' => $balance->cutting_physical_no, 'source_version' => $version,
            'warehouse_id' => (int) $balance->warehouse_id, 'warehouse_name' => $balance->cutting_warehouse_name,
            'location_id' => (int) $balance->location_id, 'location_name' => $balance->cutting_location_name,
            'batch_no' => $balance->batch_no, 'material_lot_id' => $balance->material_lot_id ? (int) $balance->material_lot_id : null,
            'mode' => $mode, 'unit_kind' => $kind, 'unit_id' => (int) $unit->id, 'available_qty' => $available, 'measure_qty' => $measure,
            'measure_unit' => $mode === 'sheet' ? 'm2' : 'm', 'dimensions' => $dimensions, 'shape' => $shape,
            'material_form' => $balance->cutting_material_form ?: 'FULL_STOCK',
            'cost_amount' => $balance->cutting_physical_id ? (string) $balance->cutting_physical_cost : null];
    }

    private function remainingUnits(MfgWorkOrder $wo, int $operationId, bool $lock = false): int
    {
        $batches = MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('work_order_operation_id', $operationId)->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $arranged = (int) $batches->sum(fn ($batch) => $batch->status === 'settled' ? $batch->completed_unit_qty : $batch->unit_qty);
        $materials = $wo->materials->where('work_order_operation_id', $operationId)->filter(fn ($material) => MaterialIssueService::isCutting($material) && (float) $material->required_qty > 0);
        $covered = $materials->isEmpty() ? 0 : $materials->map(fn ($material) => (int) bcdiv(bcmul((string) $material->issued_qty, (string) $wo->planned_qty, 12), (string) $material->required_qty, 0))->min();
        return max(0, (int) $wo->planned_qty - max($arranged, $covered));
    }

    private function lockedWorkOrder(int $id): MfgWorkOrder
    {
        $origin = MfgWorkOrder::query()->findOrFail($id);
        $seen = [];
        while (! $origin->sales_order_id && $origin->source_type === 'work_order') {
            if (isset($seen[$origin->id])) $this->fail('parent_cycle', '半成品工单来源存在循环，请核实关联。');
            $seen[$origin->id] = true;
            $parentId = MfgWorkOrderLink::query()->where('child_work_order_id', $origin->id)->value('parent_work_order_id');
            if (! $parentId) break;
            $origin = MfgWorkOrder::query()->findOrFail($parentId);
        }
        $orderId = $origin->sales_order_id;
        if ($orderId) SalesOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
        $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($id);
        $wo->setRelation('materials', MfgWorkOrderMaterial::query()->where('work_order_id', $wo->id)->orderBy('id')->lockForUpdate()->get());
        $wo->setRelation('operations', MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->orderBy('sequence')->orderBy('id')->lockForUpdate()->get());
        return $wo;
    }

    private function assertFunding(MfgWorkOrder $wo): void
    {
        $seen = [];
        while (! $wo->sales_order_id && $wo->source_type === 'work_order') {
            if (isset($seen[$wo->id])) $this->fail('parent_cycle', '半成品工单来源存在循环，请核实关联。');
            $seen[$wo->id] = true;
            $parentId = MfgWorkOrderLink::query()->where('child_work_order_id', $wo->id)->value('parent_work_order_id');
            if (! $parentId) break;
            $wo = MfgWorkOrder::query()->findOrFail($parentId);
        }
        if ($wo->sales_order_id) {
            $order = SalesOrder::query()->whereKey($wo->sales_order_id)->lockForUpdate()->firstOrFail();
            $status = $this->funding->status($order);
            if (! ($status['production_funds_satisfied'] ?? false)) $this->fail('funds_unsatisfied', $status['production_block_message'] ?? '生产收款条件未满足，不能领入开料。');
        }
    }

    private function assertActor(int $operationId, object $actor): void
    {
        $auth = app(AuthContextService::class);
        if ($auth->isSuperAdmin($actor) || in_array('production.work_order.edit', $auth->permissionCodes($actor), true)) return;
        if (! MfgTaskParticipant::query()->where('work_order_operation_id', $operationId)->where('employee_legacy_id', $this->actorId($actor))
            ->where('is_active', true)->lockForUpdate()->first(['id'])) $this->fail('permission_denied', '只能办理本人当前参与工序的开料。', 403);
    }

    private function publicData(array $data, bool $amounts): array
    {
        foreach ($data as $key => &$value) {
            if (! $amounts && (str_contains((string) $key, 'cost') || in_array($key, ['request_hash', 'settlement_hash'], true))) $value = null;
            elseif (is_array($value)) $value = $this->publicData($value, $amounts);
        }
        return $data;
    }

    private function hash(array $data): string
    {
        $sort = function (array $rows) use (&$sort): array {
            if (! array_is_list($rows)) ksort($rows);
            foreach ($rows as &$row) if (is_array($row)) $row = $sort($row);
            return $rows;
        };
        return hash('sha256', json_encode($sort($data), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }

    private function minimum(string $a, string $b): string { return bccomp($a, $b, 8) < 0 ? $a : $b; }
}
