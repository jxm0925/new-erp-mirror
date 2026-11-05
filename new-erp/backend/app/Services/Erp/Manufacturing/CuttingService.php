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
use App\Models\Erp\MfgWorkOrderUnit;
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

    public function context(int $workOrderId, array $filters, bool $amounts, object $actor): array
    {
        $wo = MfgWorkOrder::query()->with(['materials', 'operations'])->findOrFail($workOrderId);
        if (($filters['scope'] ?? null) === 'units') {
            $operationId = (int) $filters['operation_id'];
            if (!$wo->operations->contains(fn ($op) => (int) $op->id === $operationId && $op->execution_context === 'production'))
                $this->fail('operation_invalid', '请选择本工单实际存在的车间工序。');
            if (!empty($filters['batch_id'])) {
                $batch = MfgCuttingBatch::where('work_order_id', $wo->id)->where('work_order_operation_id', $operationId)->findOrFail($filters['batch_id']);
                $unitIds = array_map('intval', data_get($batch->input_lines, '0.unit_ids', []));
            } else $unitIds = $this->remainingUnits($wo, $operationId)['ids'];
            $query = MfgWorkOrderUnit::where('work_order_id', $wo->id)->whereIn('id', $unitIds);
            if ($keyword = trim((string) ($filters['keyword'] ?? ''))) $query->where('serial_no', 'like', '%'.$keyword.'%');
            return $query->orderBy('unit_no')->paginate($filters['per_page'] ?? 10,
                ['id', 'unit_no', 'serial_no', 'status'], 'page', $filters['page'] ?? 1)->toArray();
        }
        $requirements = [];
        $remaining = [];
        foreach ($wo->operations->where('execution_context', 'production') as $op) {
            $remaining[$op->id] = $this->remainingUnits($wo, (int) $op->id)['qty'];
            $requirements = array_merge($requirements, $this->requirements($wo, (int) $op->id));
        }
        $batches = MfgCuttingBatch::query()->where('work_order_id', $wo->id)
            ->when(! empty($filters['operation_id']), fn ($q) => $q->where('work_order_operation_id', (int) $filters['operation_id']))->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 10, ['*'], 'page', $filters['page'] ?? 1);
        $batches->getCollection()->transform(fn ($batch) => $this->batch($batch, $amounts, $actor));
        return ['work_order_id' => (int) $wo->id, 'business_version' => (int) $wo->business_version,
            'workshop_reference' => app(WorkshopReferenceService::class)->forWorkOrder($wo),
            'operations' => $wo->operations->where('execution_context', 'production')->map(fn ($op) => ['id' => (int) $op->id, 'operation_name' => $op->operation_name])->values()->all(),
            'requirements' => $requirements, 'remaining_unit_qty_by_operation' => $remaining, 'batches' => $batches->toArray(),
            'can_issue' => in_array($wo->status, ['released', 'in_progress'], true) && count($requirements) > 0];
    }

    public function sources(int $workOrderId, array $filters, bool $amounts): array
    {
        $wo = MfgWorkOrder::query()->with('materials')->findOrFail($workOrderId);
        $materials = $wo->materials->filter(fn ($m) => in_array($m->cutting_mode, ['sheet', 'length'], true) && (float) $m->required_qty > 0);
        if (! empty($filters['operation_id'])) {
            $operationId = (int) $filters['operation_id'];
            if (! $wo->operations()->whereKey($operationId)->where('execution_context', 'production')->exists())
                $this->fail('operation_invalid', '请选择本工单实际存在的车间工序。');
            $materials = $materials->where('work_order_operation_id', $operationId);
        }
        $itemIds = $materials->pluck('component_item_id')->unique()->sort()->values();
        if (! empty($filters['item_id'])) {
            if (! $itemIds->contains((int) $filters['item_id'])) $this->fail('item_invalid', '所选物料不在当前开料需求中。');
            $itemIds = collect([(int) $filters['item_id']]);
        }
        $items = []; $stocks = []; $union = null;
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        foreach ($itemIds as $itemId) {
            $item = $items[$itemId] = $this->sourceItem($wo, (int) $itemId);
            $snapshot = data_get(collect(data_get($wo->bom_snapshot, 'items', []))->firstWhere('component_item_id', (int) $itemId), 'cutting_quantity_snapshot', []);
            $stocks[$itemId] = array_replace(['thickness_mm' => data_get($snapshot, 'dimensions.thickness_mm')], $snapshot['stock_dimensions'] ?? []);
            $query = $this->sourceQuery($item);
            if ($keyword !== '') {
                $query->where(function ($q) use ($keyword, $item) {
                    $q->where('erp_inventory_balances.batch_no', 'like', '%'.$keyword.'%')
                        ->orWhere('erp_locations.location_name', 'like', '%'.$keyword.'%')
                        ->orWhere('erp_warehouses.warehouse_name', 'like', '%'.$keyword.'%');
                    if ($item->materialManagementMode() === 'physical') $q->orWhere('cut_physical.physical_no', 'like', '%'.$keyword.'%');
                    if (str_contains($item->item_code.' '.$item->item_name.' '.$item->spec.' '.$item->model.' '.$item->material_grade, $keyword)) $q->orWhereRaw('1=1');
                });
            }
            if (! empty($filters['category_id']) && (int) $item->category_id !== (int) $filters['category_id']) $query->whereRaw('1=0');
            // 实物和数量库存保留各自来源规则，合并后统一分页，避免把所有库存拉到小程序。
            if ($item->materialManagementMode() !== 'physical') $query->selectRaw('NULL AS cutting_physical_id, NULL AS cutting_physical_no, NULL AS cutting_dimensions, NULL AS cutting_shape, NULL AS cutting_physical_cost, NULL AS cutting_physical_version');
            $union = $union ? $union->unionAll($query->toBase()) : $query->toBase();
        }
        $query = $union ? InventoryBalance::query()->fromSub($union, 'cut_sources')->select('cut_sources.*')
            ->orderBy('item_id')->orderBy('id')->orderBy('cutting_physical_id') : InventoryBalance::query()->whereRaw('1=0');
        $page = $query
            ->paginate($filters['per_page'] ?? 10, ['*'], 'page', $filters['page'] ?? 1);
        $page->getCollection()->transform(fn ($balance) => $this->publicData($this->source($balance, $items[$balance->item_id], $stocks[$balance->item_id]), $amounts));
        return $page->toArray() + ['categories' => ItemCategory::query()->where(fn ($q) => $q->whereNull('management_scope')->orWhere('management_scope', 'factory'))
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'parent_id', 'category_name'])->toArray()];
    }

    public function preview(int $workOrderId, array $data, bool $amounts): array
    {
        $wo = MfgWorkOrder::query()->with(['materials', 'operations'])->findOrFail($workOrderId);
        return $this->publicData($this->plan($wo, $data), $amounts);
    }

    public function recommend(int $workOrderId, array $data, bool $amounts): array
    {
        $wo = MfgWorkOrder::query()->with(['materials', 'operations'])->findOrFail($workOrderId);
        $operationId = (int) $data['operation_id'];
        $units = (int) $data['unit_qty'];
        if (! $wo->operations->contains(fn ($op) => (int) $op->id === $operationId && $op->execution_context === 'production'))
            $this->fail('operation_invalid', '请选择本工单实际存在的车间工序。');
        $this->selectedUnits($wo, $operationId, $data);
        $requirements = $this->requirements($wo, $operationId);
        if (! $requirements) $this->fail('no_cutting_material', '这道工序没有冻结的板材或管材开料需求。');
        $pending = [];
        foreach ($requirements as $part) $pending[$part['part_key']] = $part['quantity_basis'] === 'total_length'
            ? bcmul($part['per_unit_measure'], (string) $units, 8) : (string) ($part['per_unit_piece_qty'] * $units);
        $sources = []; $assignments = []; $originalKeys = []; $balanceAvailable = [];
        foreach (collect($requirements)->groupBy('item_id') as $itemId => $parts) {
            $item = $this->sourceItem($wo, (int) $itemId);
            $snapshot = data_get(collect($wo->bom_snapshot['items'])->firstWhere('component_item_id', (int) $itemId), 'cutting_quantity_snapshot', []);
            $stock = array_replace(['thickness_mm' => data_get($snapshot, 'dimensions.thickness_mm')], $snapshot['stock_dimensions'] ?? []);
            // shortcut: 余料优先的顺序选料不保证全局最省料，需要最优套裁时升级排料算法。
            $query = $this->sourceQuery($item)->orderByRaw("CASE WHEN cut_lot.material_form = 'REMNANT' THEN 0 ELSE 1 END")
                ->orderBy('erp_inventory_balances.id')->when($item->materialManagementMode() === 'physical', fn ($q) => $q->orderBy('cut_physical.id'));
            foreach ($query->lazy(100) as $balance) {
                $source = $this->source($balance, $item, $stock);
                $balanceId = (int) $source['inventory_balance_id'];
                $available = $balanceAvailable[$balanceId] ?? $source['inventory_available_qty'];
                $index = 0;
                while (bccomp($available, '0', 8) > 0) {
                    if (! $parts->contains(fn ($part) => bccomp($pending[$part['part_key']], '0', 8) > 0)) break 2;
                    $whole = $source['physical_material_id'] || in_array($source['unit_kind'], ['root', 'sheet'], true);
                    if ($whole && bccomp($available, $source['inventory_qty'], 8) < 0) break;
                    $qty = $whole ? '1.0000' : $available;
                    $key = in_array($source['unit_kind'], ['root', 'sheet'], true) && ! $source['physical_material_id'] ? $source['source_key'].':'.(++$index) : $source['source_key'];
                    $candidate = array_replace($source, ['source_key' => $key, 'qty' => $qty, 'input_measure' => bcmul($source['measure_qty'], $qty, 8)]);
                    $rows = $this->suggest($parts->values()->all(), [$candidate], $units, $pending);
                    if (! $rows) break;
                    // 整根／整张实际领出后登记余料；按米／面积的数量来源只领所需量。
                    if (! $whole && in_array($source['unit_kind'], ['m', 'm2'], true)) {
                        $used = '0';
                        foreach ($rows as $row) $used = bcadd($used, bcmul($row['qty'], $parts->firstWhere('part_key', $row['part_key'])['per_piece_measure'], 8), 8);
                        $qty = bcdiv($used, $source['measure_qty'], 4);
                        if (bccomp(bcmul($qty, $source['measure_qty'], 8), $used, 8) < 0) $qty = bcadd($qty, '0.0001', 4);
                    }
                    $this->assertSourceQuantity($source, $qty);
                    if (count($sources) >= 1000) $this->fail('source_limit', '本次需要的原料来源超过 1000 条，请减少套数分批开料。');
                    $sources[] = ['source_key' => $key, 'item_id' => (int) $itemId, 'inventory_balance_id' => $source['inventory_balance_id'],
                        'physical_material_id' => $source['physical_material_id'], 'source_version' => $source['source_version'], 'qty' => $qty];
                    $originalKeys[$key] = $source['source_key'];
                    foreach ($rows as $row) $pending[$row['part_key']] = bcsub($pending[$row['part_key']], $row['qty'], 8);
                    $assignments = array_merge($assignments, $rows);
                    $available = bcsub($available, $this->inventoryQuantity($source, $qty), 8);
                    $balanceAvailable[$balanceId] = $available;
                    if ($source['physical_material_id'] || ! in_array($source['unit_kind'], ['root', 'sheet'], true)) break;
                }
            }
            $missing = $parts->filter(fn ($part) => bccomp($pending[$part['part_key']], '0', 8) > 0);
            if ($missing->isNotEmpty()) $this->fail('source_short', $item->item_name.' '.($item->spec ?: $item->model).' 的可用来源未能满足本次下料需求，请手动选料或减少套数。');
        }
        // 推荐只读；确认领料时仍按来源版本、库存余额和排料守恒重新校验。
        $plan = $this->plan($wo, $data + ['sources' => $sources, 'assignments' => $assignments]);
        foreach ($plan['sources'] as &$source) $source['original_source_key'] = $originalKeys[$source['source_key']];
        unset($source);
        return $this->publicData($plan, $amounts);
    }

    public function issue(int $workOrderId, array $data, object $actor, bool $amounts): array
    {
        return DB::transaction(function () use ($workOrderId, $data, $actor, $amounts) {
            $wo = $this->lockedWorkOrder($workOrderId);
            $hash = $this->hash($data);
            $old = MfgCuttingBatch::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($old) {
                if ((int) $old->work_order_id !== (int) $wo->id || ! hash_equals($old->request_hash, $hash)) $this->fail('idempotency_conflict', '这个领料请求编号已被其他内容使用，请刷新后重新确认。', 409);
                return $this->batch($old, $amounts, $actor);
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
            return $this->batch($batch->fresh(), $amounts, $actor);
        }, 5);
    }

    public function settle(int $batchId, array $data, object $actor, bool $amounts): array
    {
        return DB::transaction(function () use ($batchId, $data, $actor, $amounts) {
            $workOrderId = MfgCuttingBatch::query()->whereKey($batchId)->value('work_order_id');
            if (! $workOrderId) $this->fail('not_found', '随单开料批次不存在。', 404);
            $wo = $this->lockedWorkOrder((int) $workOrderId);
            $batch = MfgCuttingBatch::query()->lockForUpdate()->findOrFail($batchId);
            $this->assertActor((int) $batch->work_order_operation_id, $actor);
            $hash = $this->hash($data);
            $key = $data['idempotency_key'] ?? (isset($data['client_command_id']) ? hash('sha256', $data['client_command_id']) : null);
            if (! $key) $this->fail('command_required', '请提供本次登记的稳定请求编号。');
            if ($batch->status === 'settled') {
                if ($batch->settlement_key !== $key || ! hash_equals((string) $batch->settlement_hash, $hash)) $this->fail('already_settled', '这批已经结算，不能改写原料和产出事实。', 409);
                return $this->batch($batch, $amounts, $actor);
            }
            if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true) || ! in_array($batch->status, ['issued', 'awaiting_confirm'], true))
                $this->fail('status_invalid', '当前工单或开料批次不能登记余料。');
            if ((int) $batch->business_version !== (int) ($data['expected_version'] ?? 0)) $this->fail('version_conflict', '开料批次已变化，请重新打开后确认。', 409);
            if (! ($data['confirmed'] ?? false)) $this->fail('remnant_confirmation_required', '请人工核实每个来源的真实余料，无余料明确填零。');
            $registered = $batch->status === 'awaiting_confirm';
            $actualUnits = (int) ($data['unit_qty'] ?? ($registered ? $batch->completed_unit_qty : 0));
            $unitIds = data_get($batch->input_lines, '0.unit_ids');
            $completedIds = null;
            if ($unitIds !== null) {
                $completedIds = $data['completed_unit_ids'] ?? ($registered ? data_get($batch->input_lines, '0.completed_unit_ids')
                    : ($actualUnits === (int) $batch->unit_qty ? $unitIds : null));
                if ($completedIds === null) $this->fail('completed_unit_selection_required', '本批只完成部分套数，请明确选择已经完成的设备编号。');
                $completedIds = array_map('intval', $completedIds);
                if (!$completedIds || count(array_unique($completedIds)) !== count($completedIds) || array_diff($completedIds, array_map('intval', $unitIds)))
                    $this->fail('completed_unit_invalid', '实际完成编号必须从本批原领入名单中选择，且不能重复。');
                sort($completedIds);
                $actualUnits = count($completedIds);
                $frozenIds = array_map('intval', data_get($batch->input_lines, '0.completed_unit_ids', []));
                sort($frozenIds);
                if ($registered && $completedIds !== $frozenIds) $this->fail('outputs_frozen', '本批已登记的实际完成编号不能改动。', 409);
            } elseif (isset($data['completed_unit_ids'])) $this->fail('legacy_batch_scope', '本批原记录没有设备编号，继续按原共同套数结算，不能补猜历史归属。');
            if ($actualUnits < 1 || $actualUnits > (int) $batch->unit_qty) $this->fail('qty_invalid', '实际合格套数必须大于零，且不能超过本批计划套数。');
            $rows = $data['assignments'] ?? ($registered ? $batch->actual_outputs : ($actualUnits === (int) $batch->unit_qty ? $batch->planned_outputs : null));
            if ($rows === null) $this->fail('output_required', '实际套数改变后，请登记实际板件或管件分配。');
            [$outputs] = $this->outputs($this->requirements($wo, (int) $batch->work_order_operation_id), $batch->input_lines, $rows, $actualUnits, true);
            if ($registered) {
                $before = $batch->actual_outputs;
                $sort = fn ($a, $b) => [$a['source_key'], $a['part_key']] <=> [$b['source_key'], $b['part_key']];
                usort($before, $sort); usort($outputs, $sort);
                if ($actualUnits !== (int) $batch->completed_unit_qty || $before !== $outputs)
                    $this->fail('outputs_frozen', '批次 '.$batch->batch_no.' 的实际产出已经登记，重提只能更正余料，不能改动已供车间加工的件数或分配。', 409);
            } else {
                $doneUnits = (int) MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('work_order_operation_id', $batch->work_order_operation_id)
                    ->whereIn('status', ['settled', 'awaiting_confirm'])->lockForUpdate()->get()->sum('completed_unit_qty') + $actualUnits;
                foreach ($wo->materials->where('work_order_operation_id', $batch->work_order_operation_id) as $material) {
                    if (! in_array($material->cutting_mode, ['sheet', 'length'], true)) continue;
                    // 实际产出只满足一次数量；旧批次重核余料不能重复增加 issued_qty。
                    $increase = bcdiv(bcmul((string) $material->required_qty, (string) $actualUnits, 12), (string) $wo->planned_qty, 4);
                    $newQty = $doneUnits >= (int) $wo->planned_qty ? (string) $material->required_qty : bcadd((string) $material->issued_qty, $increase, 4);
                    if (bccomp($newQty, (string) $material->required_qty, 4) > 0) $this->fail('qty_invalid', '开料已满足量超过工单冻结需求。');
                    $material->update(['issued_qty' => $newQty]);
                }
            }
            $inputs = $batch->input_lines;
            if ($completedIds !== null) foreach ($inputs as &$input) $input['completed_unit_ids'] = $completedIds;
            unset($input);
            $batch->update(['actual_outputs' => $outputs, 'completed_unit_qty' => $actualUnits, 'input_lines' => $inputs, 'status' => 'awaiting_confirm',
                'remnant_cost_amount' => '0.0000', 'net_cost_amount' => '0.0000', 'pool_measure' => '0.00000000',
                'business_version' => (int) $batch->business_version + 1, 'settlement_key' => $key, 'settlement_hash' => $hash,
                'remark' => $data['remark'] ?? $batch->remark]);
            app(CuttingRemnantReceiptService::class)->submitForBatch($batch, $data['remnants'] ?? [], $actor);
            $wo->update(['business_version' => (int) $wo->business_version + 1]);
            if (! $registered) $this->workOrders->log($wo, 'cutting_outputs_registered', $wo->status, $wo->status,
                '开料批次 '.$batch->batch_no.' 已登记 '.$actualUnits.' 套合格产出及实际余料并结算', $actor, ['cutting_batch_id' => $batch->id]);
            return $this->batch($batch->fresh(), $amounts, $actor);
        }, 5);
    }

    public function batch(MfgCuttingBatch $batch, bool $amounts, object $actor): array
    {
        $row = $batch->only(['id', 'batch_no', 'work_order_id', 'work_order_operation_id', 'status', 'business_version', 'unit_qty', 'completed_unit_qty',
            'input_lines', 'planned_outputs', 'actual_outputs', 'remnants', 'input_cost_amount', 'remnant_cost_amount', 'net_cost_amount',
            'issued_by_name', 'settled_by_name', 'issued_at', 'settled_at', 'remark']);
        $row += app(CuttingRemnantReceiptService::class)->batchConfirmation($batch, $amounts, $actor);
        $row['unit_ids'] = array_map('intval', data_get($batch->input_lines, '0.unit_ids', []));
        $row['completed_unit_ids'] = array_map('intval', data_get($batch->input_lines, '0.completed_unit_ids', []));
        $row['units'] = MfgWorkOrderUnit::where('work_order_id', $batch->work_order_id)->whereIn('id', $row['unit_ids'])
            ->orderBy('unit_no')->get(['id', 'unit_no', 'serial_no'])->toArray();
        $row['status_label'] = ['issued' => '切割中，待登记', 'awaiting_confirm' => '待下料人登记结算', 'settled' => '已结算'][$batch->status] ?? $batch->status;
        if ($batch->status !== 'settled') foreach (['remnant_cost_amount', 'net_cost_amount'] as $field) $row[$field] = null;
        $row['summaries'] = array_map(fn ($input) => array_intersect_key($input, array_flip(['source_key', 'input_measure', 'output_measure', 'suggested_remnant', 'measure_unit'])), $batch->input_lines);
        return $this->publicData($row, $amounts);
    }

    private function plan(MfgWorkOrder $wo, array $data, bool $lock = false): array
    {
        $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->where('execution_context', 'production')->whereKey($data['operation_id'])
            ->when($lock, fn ($q) => $q->lockForUpdate())->first();
        if (! $op) $this->fail('operation_invalid', '请选择本工单实际存在的车间工序。');
        $unitIds = $this->selectedUnits($wo, (int) $op->id, $data, $lock);
        $unitQty = count($unitIds);
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
            $snapshot = data_get(collect(data_get($wo->bom_snapshot, 'items', []))->firstWhere('component_item_id', (int) $item->id), 'cutting_quantity_snapshot', []);
            $stock = array_replace(['thickness_mm' => data_get($snapshot, 'dimensions.thickness_mm')], $snapshot['stock_dimensions'] ?? []);
            $source = $this->source($balance, $item, $stock);
            if (! hash_equals($source['source_version'], (string) $row['source_version'])) $this->fail('source_changed', '来源库存、实物尺寸或版本已变化，请重新选料。', 409);
            $qty = CuttingDecimal::value($row['qty']);
            $this->assertSourceQuantity($source, $qty);
            $inventoryQty = $this->inventoryQuantity($source, $qty);
            if ($source['physical_material_id']) {
                if (in_array($source['physical_material_id'], $physicalIds, true)) $this->fail('source_duplicate', '同一张板不能重复选择。');
                $physicalIds[] = $source['physical_material_id'];
                if ($lock) $this->physicals->assertPhysicalNotInOpenDocument($source['physical_material_id']);
            }
            $id = $source['inventory_balance_id'];
            $balanceRemaining[$id] ??= $source['inventory_available_qty'];
            $costRemaining[$id] ??= ['qty' => (string) $balance->quantity_on_hand, 'cost' => (string) $balance->inventory_value];
            if (bccomp($inventoryQty, $balanceRemaining[$id], 8) > 0) $this->fail('stock_short', '同一库存来源的累计领入数量超过可用量。');
            $cost = $source['physical_material_id'] ? $source['cost_amount'] : CuttingDecimal::share($costRemaining[$id]['cost'], $costRemaining[$id]['qty'], $inventoryQty);
            if (bccomp($cost, $costRemaining[$id]['cost'], 4) > 0) $this->fail('source_cost_invalid', '所选实物成本超过来源余额，请先核对库存成本。');
            $costRemaining[$id] = ['qty' => bcsub($costRemaining[$id]['qty'], $inventoryQty, 8), 'cost' => bcsub($costRemaining[$id]['cost'], $cost, 4)];
            $balanceRemaining[$id] = bcsub($balanceRemaining[$id], $inventoryQty, 8);
            $sources[] = array_replace($source, ['source_key' => $key, 'qty' => $qty, 'inventory_qty' => $inventoryQty, 'cost_amount' => $cost, 'unit_ids' => $unitIds,
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
            'unit_qty' => $unitQty, 'unit_ids' => $unitIds, 'requirements' => $requirements, 'sources' => $sources, 'assignments' => $outputs,
            'summaries' => array_values($summaries), 'input_cost_amount' => $total];
    }

    public function assertSourceQuantity(array $source, string $qty): void
    {
        if (in_array($source['unit_kind'], ['m', 'm2'], true) && bccomp($qty, bcadd($qty, '0', 4), 8) !== 0)
            $this->fail('stock_precision_invalid', '领用的库存数量最多保留四位小数。');
        if ((! empty($source['physical_material_id']) || in_array($source['unit_kind'], ['sheet', 'root'], true)) && bccomp($qty, '1', 8) !== 0)
            $this->fail('whole_source_required', '整板、整根和实物余料须逐件登记，每条来源数量必须为一。');
    }

    /** 张／根是领用数量，平方米／米是库存数量；余料按实际面积／长度领用。 */
    public function inventoryQuantity(array $source, string $qty): string
    {
        return in_array($source['unit_kind'], ['sheet', 'root'], true) && in_array($source['inventory_unit_kind'] ?? null, ['m2', 'm'], true)
            ? bcmul((string) $source['measure_qty'], $qty, 8) : $qty;
    }

    private function requirements(MfgWorkOrder $wo, int $operationId): array
    {
        $lines = collect(data_get($wo->bom_snapshot, 'items', []))->keyBy('id');
        $remainingQty = $this->remainingUnits($wo, $operationId)['qty'];
        // 当前主档规格仅用于区分同名原料；加工尺寸和数量仍完全采用工单冻结资料。
        $items = Item::query()->whereIn('id', $wo->materials->where('work_order_operation_id', $operationId)->pluck('component_item_id')->unique())
            ->get(['id', 'spec', 'model'])->keyBy('id');
        $out = [];
        foreach ($wo->materials->where('work_order_operation_id', $operationId) as $material) {
            // 下料人自选真实原料或余料；数量来源与实物来源共用冻结的加工要求。
            if (! in_array($material->cutting_mode, ['sheet', 'length'], true) || (float) $material->required_qty <= 0) continue;
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
                    'remaining_unit_qty' => $remainingQty];
            }
        }
        return $out;
    }

    private function suggest(array $requirements, array $sources, int $units, ?array $pending = null): array
    {
        $rows = [];
        $used = array_fill_keys(array_column($sources, 'source_key'), '0.00000000');
        $placed = [];
        foreach ($requirements as $part) {
            $left = $pending[$part['part_key']] ?? ($part['quantity_basis'] === 'total_length' ? bcmul($part['per_unit_measure'], (string) $units, 8) : (string) ($part['per_unit_piece_qty'] * $units));
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
            if ($pending === null && bccomp($left, '0', 8) > 0) $this->fail('source_short', '所选真实来源不足以满足 '.$part['part_name'].'，请增加来源或减少本批套数。');
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
        if (! $wo->materials->contains(fn ($row) => (int) $row->component_item_id === $itemId && in_array($row->cutting_mode, ['sheet', 'length'], true))) $this->fail('item_invalid', '所选物料不在本工单冻结的开料需求中。');
        $item = Item::query()->with('unit.standardUnit')->where('status', 'enabled')->when($lock, fn ($q) => $q->lockForUpdate())->find($itemId);
        if (! $item || ($item->management_scope && $item->management_scope !== 'factory')) $this->fail('item_invalid', '开料必须选择启用的工厂物料。');
        if ($wo->materials->where('component_item_id', $itemId)->contains(fn ($material) => $material->cutting_mode !== $item->cuttingMode()))
            $this->fail('material_policy_changed', '原料当前下料模式与工单冻结需求不一致，请先核实资料。');
        if ($item->materialManagementMode() !== 'physical' && $item->serialTrackingMode() !== 'none') $this->fail('serial_source_required', '按序列号管理的原料暂不能从普通开料来源领入，请先核实实物身份。');
        $unit = $this->units->canonicalUnit($item->unit);
        foreach (collect(data_get($wo->bom_snapshot, 'items', []))->where('component_item_id', $itemId) as $line) {
            $snapshot = $line['cutting_quantity_snapshot'] ?? null;
            if (! $snapshot || ($snapshot['mode'] ?? null) !== $item->cuttingMode() || ! $unit || (int) data_get($snapshot, 'unit.id') !== (int) $unit->id)
                $this->fail('material_policy_changed', '原料当前下料模式或库存单位与工单冻结资料不一致，请先核实，不能重新解释原来的数量。');
        }
        return $item;
    }

    /** 可领入开料的真实来源（集中开料也用）。 */
    public function sourceQuery(Item $item)
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
                'cut_lot.material_form as cutting_material_form', 'cut_lot.cut_length_mm as cutting_lot_length_mm',
                'cut_lot.source_type as cutting_lot_source_type', 'cut_lot.source_id as cutting_lot_source_id']);
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

    public function source(InventoryBalance $balance, Item $item, array $stockDimensions = []): array
    {
        $unit = $this->units->canonicalUnit($item->unit);
        if (! $unit) $this->fail('unit_missing', '原料必须维护真实的库存基本单位。');
        $kind = $this->quantities->unitKind($unit);
        $mode = $item->cuttingMode();
        $whole = ! empty($balance->cutting_physical_id) || $balance->cutting_material_form !== 'REMNANT';
        $issueKind = $whole ? ($mode === 'sheet' ? 'sheet' : 'root') : $kind;
        $receiptMeasure = ! $balance->cutting_physical_id && $whole && in_array($kind, ['m2', 'm'], true)
            ? $this->receiptStockMeasure($balance, $item, $issueKind) : null;
        $dimensions = $balance->cutting_dimensions ? json_decode($balance->cutting_dimensions, true, 512, JSON_THROW_ON_ERROR) : [];
        $shape = strtoupper((string) ($balance->cutting_shape ?: ($mode === 'length' ? 'LENGTH' : '')));
        if ($mode === 'sheet' && $item->materialManagementMode() !== 'physical') {
            if ($balance->cutting_material_form === 'REMNANT') {
                $origin = $balance->cutting_lot_source_type === 'mfg_cutting_batch' ? MfgCuttingBatch::query()->find($balance->cutting_lot_source_id) : null;
                $remnant = collect($origin?->remnants ?? [])->firstWhere('material_lot_id', (int) $balance->material_lot_id);
                if (! $remnant) $this->fail('remnant_dimensions_missing', '这块板材余料缺少已登记的实际面积，不能套用原板尺寸。');
                $dimensions = $remnant['dimensions']; $shape = $remnant['shape'];
                // 无逐件身份的面积余料可部分领用，不能把整块外形当作这次领用部分的矩形。
                if ($kind === 'm2') {
                    $area = $this->physicals->areaQuantity(['shape' => $shape, 'dimensions' => $dimensions]);
                    $dimensions = array_intersect_key($dimensions, array_flip(['thickness_mm', 'nominal_thickness_mm'])) + ['area_m2' => $area];
                    $shape = 'AREA_ONLY';
                }
            } else {
                $dimensions = $stockDimensions;
                $shape = (float) ($dimensions['length_mm'] ?? 0) > 0 && (float) ($dimensions['width_mm'] ?? 0) > 0 ? 'RECTANGLE' : 'AREA_ONLY';
                if ($receiptMeasure !== null && ($shape !== 'RECTANGLE' || bccomp($receiptMeasure, $this->physicals->areaQuantity(['shape' => $shape, 'dimensions' => $dimensions]), 8) !== 0)) {
                    $dimensions = array_intersect_key($dimensions, array_flip(['thickness_mm', 'nominal_thickness_mm'])) + ['area_m2' => $receiptMeasure];
                    $shape = 'AREA_ONLY';
                }
                if ($shape === 'AREA_ONLY') {
                    $dimensions['area_m2'] = $receiptMeasure ?? $this->purchaseStockMeasure($item, 'sheet');
                    if ($dimensions['area_m2'] === null) $this->fail('source_dimensions_missing', '整板缺少冻结长宽或正式按张采购的面积换算，不能按张领料。');
                }
            }
        }
        if ($mode === 'sheet') {
            if (! in_array($kind, ['sheet', 'm2'], true)) $this->fail('unit_invalid', '板材库存基本单位必须为张或平方米。');
            if (! in_array($shape, ['RECTANGLE', 'AREA_ONLY'], true) || ! isset($dimensions['thickness_mm'])) $this->fail('source_dimensions_missing', '板材缺少可确认的真实形状或厚度。');
            $measure = ! $whole && $kind === 'm2' ? '1.00000000'
                : $this->physicals->areaQuantity(['shape' => $shape, 'dimensions' => $dimensions]);
        } else {
            if (! in_array($kind, ['root', 'm'], true)) $this->fail('unit_invalid', '管材库存单位必须明确为根或米。');
            $length = $dimensions['length_mm'] ?? $balance->cutting_lot_length_mm;
            if ($receiptMeasure !== null) $length = bcmul($receiptMeasure, '1000', 2);
            if ($balance->cutting_material_form === 'REMNANT' && (float) $length <= 0) $this->fail('remnant_length_missing', '这条管材余料缺少实际余长，不能套用整根标准长度。');
            if ((float) $length <= 0 && ! $balance->cutting_physical_id) $length = $stockDimensions['length_mm'] ?? $item->standard_stock_length_mm;
            if ((float) $length <= 0 && $whole && ! $balance->cutting_physical_id) {
                $standardMeasure = $this->purchaseStockMeasure($item, 'root');
                if ($standardMeasure !== null) $length = bcmul($standardMeasure, '1000', 2);
            }
            if ($whole && (float) $length <= 0) $this->fail('source_dimensions_missing', '按根领料必须有真实实物长度、冻结标准原长或正式按根采购换算。');
            $dimensions = $length ? ['length_mm' => bcadd((string) $length, '0', 2)] : [];
            $measure = $whole || $kind === 'root' ? bcdiv((string) $length, '1000', 8) : '1.00000000';
        }
        $available = bcsub(bcsub(bcsub((string) $balance->quantity_on_hand, (string) $balance->quantity_locked, 8), (string) $balance->quantity_defective, 8), (string) $balance->quantity_pending, 8);
        $available = $this->minimum($available, (string) $balance->quantity_available);
        $held = MaterialDeliveryService::holds()['balances'][(int) $balance->id] ?? 0;
        $available = bcsub($available, sprintf('%.4F', $held), 8);
        if (bccomp($available, '0', 8) < 0) $available = '0.00000000';
        $inventoryQty = $balance->cutting_physical_id
            ? $this->physicals->inventoryQuantity($item, ['shape' => $shape, 'dimensions' => $dimensions])
            : ($whole && in_array($kind, ['m2', 'm'], true) ? $measure : '1.00000000');
        if (bccomp($inventoryQty, bcadd($inventoryQty, '0', 4), 8) !== 0)
            $this->fail('stock_precision_invalid', '整张或整根的面积／长度无法按库存四位小数无损保存，请核对来源尺寸。');
        $issueAvailable = $balance->cutting_physical_id ? (bccomp($available, $inventoryQty, 8) >= 0 ? '1.00000000' : '0.00000000')
            : ($whole ? bcdiv($available, $inventoryQty, 0) : $available);
        $version = $this->hash([(int) $balance->id, (string) $balance->quantity_on_hand, (string) $balance->quantity_locked,
            (string) $balance->quantity_defective, (string) $balance->quantity_pending, (string) $balance->inventory_value,
            (string) $balance->last_transaction_at, $balance->material_lot_id, $balance->cutting_physical_id, $balance->cutting_physical_version, $dimensions, $held, (int) $unit->id, $inventoryQty]);
        return ['source_key' => $balance->cutting_physical_id ? 'physical:'.$balance->cutting_physical_id : 'balance:'.$balance->id,
            'item_id' => (int) $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name, 'specification' => $item->spec ?: $item->model,
            'material_grade' => $item->material_grade,
            'category_id' => $item->category_id, 'inventory_balance_id' => (int) $balance->id, 'physical_material_id' => $balance->cutting_physical_id ? (int) $balance->cutting_physical_id : null,
            'physical_no' => $balance->cutting_physical_no, 'source_version' => $version,
            'warehouse_id' => (int) $balance->warehouse_id, 'warehouse_name' => $balance->cutting_warehouse_name,
            'location_id' => (int) $balance->location_id, 'location_name' => $balance->cutting_location_name,
            'batch_no' => $balance->batch_no, 'material_lot_id' => $balance->material_lot_id ? (int) $balance->material_lot_id : null,
            'mode' => $mode, 'unit_kind' => $issueKind, 'unit_id' => (int) $unit->id,
            'available_qty' => $issueAvailable, 'inventory_available_qty' => $available, 'inventory_unit_kind' => $kind, 'inventory_qty' => $inventoryQty, 'measure_qty' => $measure,
            'measure_unit' => $mode === 'sheet' ? 'm2' : 'm', 'dimensions' => $dimensions, 'shape' => $shape,
            'material_form' => $balance->cutting_material_form ?: 'FULL_STOCK',
            'cost_amount' => $balance->cutting_physical_id ? (string) $balance->cutting_physical_cost : null];
    }

    /** 采购批次优先用到货时冻结的实际换算；同批不同换算不能混算成当前标准整料。 */
    private function receiptStockMeasure(InventoryBalance $balance, Item $item, string $kind): ?string
    {
        $batch = DB::table('erp_inventory_batches')->where('item_id', $item->id)->where('batch_no', $balance->batch_no)->first(['source_type']);
        $facts = DB::table('erp_inventory_transaction_items as fact')->join('erp_inventory_transactions as tx', 'tx.id', '=', 'fact.transaction_id')
            ->where('fact.item_id', $item->id)->where('fact.batch_no', $balance->batch_no)->where('fact.unit_id', $balance->unit_id)
            ->where('fact.source_type', 'purchase_receipt')->where('fact.change_qty', '>', 0)->where('tx.posting_status', 'posted')
            ->whereIn('tx.transaction_type', ['purchase_receipt_posting', 'purchase_receipt_quality_release'])
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get(['fact.source_id', 'fact.source_item_id']);
        if ($facts->isEmpty()) {
            if ($batch?->source_type === 'purchase_receipt' || $balance->cutting_lot_source_type === 'purchase_receipt_item')
                $this->fail('source_conversion_missing', '采购库存缺少可追溯的到货换算事实，不能套用当前采购换算重新计算张／根数。');
            return null;
        }
        $ids = $facts->pluck('source_item_id')->filter()->unique()->sort()->values();
        if ($ids->isEmpty() || $facts->contains(fn ($fact) => ! $fact->source_item_id))
            $this->fail('source_conversion_missing', '采购库存缺少到货明细身份，不能套用当前采购换算。');
        $lines = \App\Models\Erp\PurchaseReceiptItem::query()->with(['purchaseUnit.standardUnit', 'baseUnit.standardUnit'])
            ->whereIn('id', $ids)->where('item_id', $item->id)->where('batch_no', $balance->batch_no)->orderBy('id')
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->get();
        if ($lines->count() !== $ids->count()) $this->fail('source_conversion_missing', '采购库存来源与到货明细不一致，请先核对原入库事实。');
        $measure = null;
        foreach ($lines as $line) {
            $purchase = $this->units->canonicalUnit($line->purchaseUnit);
            if (! $purchase || (int) $this->units->canonicalUnit($line->baseUnit)?->id !== (int) $balance->unit_id
                || (strtoupper(trim((string) $purchase->unit_code)) !== ($kind === 'sheet' ? 'SHEET' : 'ROOT') && trim((string) ($purchase->symbol ?: $purchase->unit_symbol)) !== ($kind === 'sheet' ? '张' : '根'))
                || $facts->contains(fn ($fact) => (int) $fact->source_item_id === (int) $line->id && (int) $fact->source_id !== (int) $line->receipt_id))
                $this->fail('source_conversion_missing', '采购来源没有冻结为对应张／根到当前库存单位的换算，不能按当前主档补算。');
            $factor = bccomp((string) ($line->actual_base_qty ?? '0'), '0', 8) > 0 && bccomp((string) $line->receipt_qty, '0', 4) > 0
                ? bcdiv((string) $line->actual_base_qty, (string) $line->receipt_qty, 8) : (string) $line->conversion_factor_snapshot;
            if (bccomp($factor, '0', 8) <= 0 || (bccomp((string) ($line->actual_base_qty ?? '0'), '0', 8) > 0
                && bccomp(bcmul($factor, (string) $line->receipt_qty, 12), (string) $line->actual_base_qty, 12) !== 0))
                $this->fail('source_conversion_invalid', '原到货实际面积／长度不能无损换算为每张／每根数量，请先核对采购记录。');
            if (bccomp($factor, bcadd($factor, '0', 4), 8) !== 0)
                $this->fail('stock_precision_invalid', '原到货每张／每根的面积／长度超出库存四位小数精度，不能舍入领用。');
            if ($measure !== null && bccomp($measure, $factor, 8) !== 0)
                $this->fail('source_conversion_conflict', '同一库存批次混有不同到货换算，不能合并成统一张／根数，请先核对来源。');
            $measure = $factor;
        }
        return $measure;
    }

    private function purchaseStockMeasure(Item $item, string $kind): ?string
    {
        $base = $this->units->canonicalUnit($item->unit);
        if (! $base || $this->quantities->unitKind($base) !== ($kind === 'sheet' ? 'm2' : 'm')) return null;
        foreach ($this->units->activePurchaseConversions((int) $item->id)->with(['purchaseUnit.standardUnit', 'baseUnit.standardUnit'])
            ->orderByDesc('is_default')->orderBy('id')->get() as $conversion) {
            $unit = $this->units->canonicalUnit($conversion->purchaseUnit);
            if (! $unit || $unit->status !== 'enabled' || (int) $this->units->canonicalUnit($conversion->baseUnit)?->id !== (int) $base?->id) continue;
            if (strtoupper(trim((string) $unit->unit_code)) !== ($kind === 'sheet' ? 'SHEET' : 'ROOT') && trim((string) ($unit->symbol ?: $unit->unit_symbol)) !== ($kind === 'sheet' ? '张' : '根')) continue;
            $measure = CuttingDecimal::value((string) $conversion->factor);
            if (bccomp($measure, bcadd($measure, '0', 4), 8) !== 0)
                $this->fail('stock_precision_invalid', '按张／根采购换算超出库存四位小数精度，不能舍入领用。');
            return $measure;
        }
        return null;
    }

    private function selectedUnits(MfgWorkOrder $wo, int $operationId, array $data, bool $lock = false): array
    {
        $submitted = array_map('intval', (array) ($data['unit_ids'] ?? []));
        $ids = array_values(array_unique($submitted));
        sort($ids);
        if (!$ids || count($ids) !== count($submitted) || count($ids) !== (int) $data['unit_qty'])
            $this->fail('unit_selection_required', '请选择本次实际开料的设备编号，套数必须与所选编号一致。');
        CancellationProductionService::assertProductionAllowed((int) $wo->id, $ids);
        $remaining = $this->remainingUnits($wo, $operationId, $lock);
        if (count($ids) > $remaining['qty'] || array_diff($ids, $remaining['ids']))
            $this->fail('unit_selection_changed', '所选编号已安排开料、已取消或不在本道当前需求中，请刷新后重新选择。', 409);
        return $ids;
    }

    private function remainingUnits(MfgWorkOrder $wo, int $operationId, bool $lock = false): array
    {
        $units = MfgWorkOrderUnit::where('work_order_id', $wo->id)->whereIn('id', CancellationProductionService::productionUnitIds((int) $wo->id))
            ->whereNotIn('status', ['completed', 'pending_receipt', 'stored'])
            ->whereNotIn('id', DB::table('erp_mfg_unit_reports')->where('work_order_operation_id', $operationId)->where('result', 'passed')->select('unit_id'))
            ->orderBy('unit_no')->when($lock, fn ($q) => $q->lockForUpdate())->get(['id']);
        $batches = MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('work_order_operation_id', $operationId)
            ->where('status', '!=', 'cancelled')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        $arranged = (int) $batches->sum(fn ($batch) => in_array($batch->status, ['settled', 'awaiting_confirm'], true) ? $batch->completed_unit_qty : $batch->unit_qty);
        $materials = $wo->materials->where('work_order_operation_id', $operationId)->filter(fn ($material) => in_array($material->cutting_mode, ['sheet', 'length'], true) && (float) $material->required_qty > 0);
        $covered = $materials->isEmpty() ? 0 : $materials->map(fn ($material) => (int) bcdiv(bcmul((string) $material->issued_qty, (string) $wo->planned_qty, 12), (string) $material->required_qty, 0))->min();
        $assigned = [];
        foreach ($batches as $batch) {
            $cohort = data_get($batch->input_lines, '0.unit_ids');
            if ($cohort === null) continue;
            $done = in_array($batch->status, ['settled', 'awaiting_confirm'], true)
                ? data_get($batch->input_lines, '0.completed_unit_ids', (int) $batch->completed_unit_qty === (int) $batch->unit_qty ? $cohort : []) : $cohort;
            $assigned = array_merge($assigned, array_map('intval', $done));
        }
        $allocated = [];
        foreach (['erp_mfg_cutting_run_allocations' => 'material_qty', 'erp_mfg_cutting_allocations' => 'qty'] as $table => $field) {
            foreach (DB::table($table)->whereIn('work_order_material_id', $materials->pluck('id'))->get() as $allocation) {
                $cohort = OrderChangeLaborService::recordedUnitIds($allocation);
                if (!$cohort) continue;
                foreach ($cohort as $id) $allocated[$id][$allocation->work_order_material_id]
                    = ($allocated[$id][$allocation->work_order_material_id] ?? 0) + (float) $allocation->$field / count($cohort);
            }
        }
        foreach ($allocated as $id => $quantities) {
            if ($materials->isNotEmpty() && $materials->every(fn ($material) => ($quantities[$material->id] ?? 0) + 0.00001
                >= (float) $material->required_qty / max(1, (float) $wo->planned_qty))) $assigned[] = (int) $id;
        }
        $ids = $units->pluck('id')->map(fn ($id) => (int) $id)->diff($assigned)->values()->all();
        // 旧批次只有共同套数，仍只抵总量，不按编号顺序猜测历史产出归属。
        $qty = max(0, min(count($ids), (int) $wo->planned_qty - max($arranged, $covered)));
        return ['ids' => $qty > 0 ? $ids : [], 'qty' => $qty];
    }

    public function lockedWorkOrder(int $id): MfgWorkOrder
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

    public function publicData(array $data, bool $amounts): array
    {
        foreach ($data as $key => &$value) {
            if (! $amounts && ! in_array($key, ['cost_status', 'cost_status_label', 'cost_source'], true) && (str_contains((string) $key, 'cost') || in_array($key, ['request_hash', 'settlement_hash'], true))) $value = null;
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
