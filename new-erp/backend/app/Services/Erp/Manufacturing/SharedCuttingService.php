<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Item;
use App\Models\Erp\MfgCuttingAllocation;
use App\Models\Erp\MfgCuttingBatch;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderMaterial;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Services\Erp\CuttingDecimal;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\Inventory\ProductionInventoryPosting;
use App\Services\Erp\MaterialPhysicalService;
use Illuminate\Support\Facades\DB;

/**
 * 集中开料：不属于任何工单，下料员按「待切清单」挑一种板材、领几张整板／余板切，切完登记每张的余料。
 * 净耗（投入 − 余料）进这种板材的开料池；各工单下料点「已备料」时按 BOM 定额从池里先进先出扣量、分摊成本。
 * 池里扣完定额还剩的是切缝、边角废料和还没认领的件，留在批次上，看出材率。
 */
final class SharedCuttingService
{
    use ProductionActor;

    private const OPEN_WO = ['released', 'in_progress', 'paused'];

    public function __construct(
        private readonly CuttingService $cutting,
        private readonly ProductionInventoryPosting $inventory,
        private readonly MaterialPhysicalService $physicals,
        private readonly DocumentNumberService $numbers,
        private readonly MaterialIssueService $materials,
    ) {}

    /** 待切清单：未完工工单里还没备料的逐张板材需求，按板材汇总（含板件尺寸和件数），并给出开料池剩余。 */
    public function demand(array $filters): array
    {
        $rows = DB::table('erp_mfg_work_order_materials as m')
            ->join('erp_mfg_work_orders as w', 'w.id', '=', 'm.work_order_id')
            ->join('erp_items as i', 'i.id', '=', 'm.component_item_id')
            ->leftJoin('erp_mfg_work_order_operations as o', 'o.id', '=', 'm.work_order_operation_id')
            ->whereIn('w.status', self::OPEN_WO)->where('i.material_management_mode', 'physical')->where('m.cutting_mode', 'sheet')
            ->where('m.required_qty', '>', 0)->whereNotNull('m.work_order_operation_id')
            ->when(! empty($filters['item_id']), fn ($q) => $q->where('m.component_item_id', (int) $filters['item_id']))
            ->select(['m.id', 'm.work_order_id', 'm.work_order_operation_id', 'm.component_item_id', 'm.component_item_code', 'm.component_item_name',
                'm.unit_name', 'm.required_qty', 'm.issued_qty', 'm.bom_item_id', 'm.qty_ratio', 'm.loss_rate', 'm.fixed_qty', 'w.work_order_no', 'w.planned_qty', 'w.product_name',
                'w.item_name as wo_item_name', 'w.customer_name', 'w.is_urgent', 'w.priority', 'w.planned_start_date', 'w.planned_finish_date', 'w.status as wo_status',
                'o.operation_name', 'i.spec', 'i.model', 'i.material_grade'])
            ->orderByDesc('w.is_urgent')->orderBy('w.priority')->orderBy('w.planned_start_date')->orderBy('w.id')->orderBy('m.id')->get();
        $snapshots = MfgWorkOrder::query()->whereIn('id', $rows->pluck('work_order_id')->unique())->get(['id', 'bom_snapshot'])
            ->mapWithKeys(fn ($wo) => [$wo->id => collect(data_get($wo->bom_snapshot, 'items', []))->keyBy('id')]);
        $pool = $this->poolAvailable($rows->pluck('component_item_id')->unique()->all());
        $open = MfgCuttingBatch::query()->where('batch_scope', 'shared')->whereIn('status', ['issued', 'awaiting_confirm'])
            ->selectRaw('item_id, COUNT(*) AS n')->groupBy('item_id')->pluck('n', 'item_id');
        $awaiting = MfgCuttingBatch::query()->where('batch_scope', 'shared')->where('status', 'awaiting_confirm')
            ->selectRaw('item_id, COUNT(*) AS n')->groupBy('item_id')->pluck('n', 'item_id');
        $direct = MfgCuttingBatch::query()->whereIn('work_order_id', $rows->pluck('work_order_id')->unique())
            ->whereIn('work_order_operation_id', $rows->pluck('work_order_operation_id')->unique())->where('batch_scope', 'work_order')->whereIn('status', ['settled', 'awaiting_confirm'])
            ->selectRaw('work_order_operation_id, SUM(completed_unit_qty) AS units')->groupBy('work_order_operation_id')->pluck('units', 'work_order_operation_id');
        $allocated = MfgCuttingAllocation::query()->whereIn('work_order_material_id', $rows->pluck('id'))
            ->selectRaw('work_order_material_id, SUM(measure) AS measure')->groupBy('work_order_material_id')->pluck('measure', 'work_order_material_id');
        $items = [];
        foreach ($rows as $row) {
            $line = $snapshots[$row->work_order_id]->get($row->bom_item_id) ?? [];
            $snapshot = $line['cutting_quantity_snapshot'] ?? [];
            $dim = $snapshot['dimensions'] ?? [];
            $left = bcsub((string) $row->required_qty, (string) $row->issued_qty, 4);
            if (bccomp($left, '0', 4) < 0) $left = '0.0000';
            $planned = (float) $row->planned_qty;
            $covered = (string) max(0, $planned - (int) ($direct[$row->work_order_operation_id] ?? 0));
            $measure = $this->cumulativeMeasure($row, $snapshot, (string) $row->planned_qty, $covered);
            if ($measure !== null) {
                $measure = bcsub($measure, (string) ($allocated[$row->id] ?? '0'), 8);
                if (bccomp($measure, '0', 8) < 0) $measure = '0.00000000';
            }
            if (bccomp($left, '0', 4) <= 0 && ($measure === null || bccomp($measure, '0', 8) <= 0)) continue;
            $doneUnits = (float) $row->required_qty > 0 ? (int) floor((float) $row->issued_qty * $planned / (float) $row->required_qty + 1e-6) : 0;
            $leftUnits = max(0, (int) $planned - $doneUnits);
            $perUnitPieces = (int) ($dim['piece_qty'] ?? 0) * (float) $row->qty_ratio;
            $key = (int) $row->component_item_id;
            $items[$key] ??= ['item_id' => $key, 'item_code' => $row->component_item_code, 'item_name' => $row->component_item_name,
                'specification' => $row->spec ?: $row->model, 'material_grade' => $row->material_grade, 'unit_name' => $row->unit_name,
                'measure_unit' => 'm2', 'need_qty' => '0.0000', 'need_measure' => '0.00000000', 'pool_measure' => $pool[$key] ?? '0.00000000',
                'open_batches' => (int) ($open[$key] ?? 0), 'awaiting_confirmation_batches' => (int) ($awaiting[$key] ?? 0), 'piece_count' => 0, 'lines' => []];
            $items[$key]['need_qty'] = bcadd($items[$key]['need_qty'], $left, 4);
            if ($measure !== null) $items[$key]['need_measure'] = bcadd($items[$key]['need_measure'], $measure, 8);
            $pieces = (int) round($perUnitPieces * $leftUnits);
            $items[$key]['piece_count'] += $pieces;
            $items[$key]['lines'][] = ['material_id' => (int) $row->id, 'work_order_id' => (int) $row->work_order_id, 'work_order_no' => $row->work_order_no,
                'operation_id' => (int) $row->work_order_operation_id, 'operation_name' => $row->operation_name,
                'product_name' => $row->product_name ?: $row->wo_item_name, 'customer_name' => $row->customer_name, 'is_urgent' => (bool) $row->is_urgent,
                'planned_start_date' => $row->planned_start_date, 'planned_finish_date' => $row->planned_finish_date,
                'planned_qty' => $planned, 'left_units' => $leftUnits, 'left_qty' => $left, 'left_measure' => $measure,
                'part_name' => ($line['remark'] ?? null) ?: '板件 '.($line['line_no'] ?? ''), 'length_mm' => $dim['length_mm'] ?? null,
                'width_mm' => $dim['width_mm'] ?? null, 'thickness_mm' => $dim['thickness_mm'] ?? null, 'allow_rotation' => (bool) ($dim['allow_rotation'] ?? false),
                'pieces_per_unit' => $perUnitPieces, 'left_pieces' => $pieces];
        }
        foreach ($items as &$item) $item['short_measure'] = bccomp($item['need_measure'], $item['pool_measure'], 8) > 0 ? bcsub($item['need_measure'], $item['pool_measure'], 8) : '0.00000000';
        unset($item);
        return ['items' => array_values($items)];
    }

    public function index(array $filters, bool $amounts, object $actor): array
    {
        $page = MfgCuttingBatch::query()->where('batch_scope', 'shared')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['item_id']), fn ($q) => $q->where('item_id', (int) $filters['item_id']))
            ->when(! empty($filters['cutting_run_id']), fn ($q) => $q->where('cutting_run_id', (int) $filters['cutting_run_id']))
            ->orderByRaw("status IN ('issued', 'awaiting_confirm') DESC")->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 10, ['*'], 'page', $filters['page'] ?? 1);
        $names = Item::query()->whereIn('id', $page->getCollection()->pluck('item_id')->unique())->get(['id', 'item_code', 'item_name', 'spec', 'model'])->keyBy('id');
        $page->getCollection()->transform(fn ($batch) => $this->summary($batch, $names->get($batch->item_id), $amounts, $actor));
        return $page->toArray();
    }

    public function show(int $id, bool $amounts, object $actor): array
    {
        $batch = MfgCuttingBatch::query()->where('batch_scope', 'shared')->findOrFail($id);
        return $this->detail($batch, $amounts, $actor);
    }

    public function sources(array $filters, bool $amounts): array
    {
        $item = $this->sharedItem((int) $filters['item_id']);
        $run = empty($filters['cutting_run_id']) ? null : \App\Models\Erp\MfgCuttingRun::query()->findOrFail((int) $filters['cutting_run_id']);
        $line = collect($run?->lines ?? [])->firstWhere('component_item_id', (int) $item->id);
        if ($run && ! $line) $this->fail('cutting_run_item_invalid', '这种原料不在所选开料记录中。');
        $query = $this->cutting->sourceQuery($item);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(function ($q) use ($keyword, $item) {
                $q->where('erp_inventory_balances.batch_no', 'like', '%'.$keyword.'%')->orWhere('erp_locations.location_name', 'like', '%'.$keyword.'%')->orWhere('erp_warehouses.warehouse_name', 'like', '%'.$keyword.'%');
                if ($item->materialManagementMode() === 'physical') $q->orWhere('cut_physical.physical_no', 'like', '%'.$keyword.'%');
            });
        }
        // 先用余板（面积小的先用），再用整板。
        $page = $query->orderByRaw("cut_lot.material_form = 'REMNANT' DESC")->orderBy('erp_inventory_balances.id')->when($item->materialManagementMode() === 'physical', fn ($q) => $q->orderBy('cut_physical.id'))
            ->paginate($filters['per_page'] ?? 10, ['*'], 'page', $filters['page'] ?? 1);
        $page->getCollection()->transform(fn ($balance) => $this->cutting->publicData($this->cutting->source($balance, $item, $line['stock_dimensions'] ?? []), $amounts));
        return $page->toArray();
    }

    /** 下料人选择原料或余料：整张／整根拆来源，按米／面积保留实际数量。 */
    public function issue(array $data, object $actor, bool $amounts): array
    {
        return DB::transaction(function () use ($data, $actor, $amounts) {
            $hash = $this->hash($data);
            $old = MfgCuttingBatch::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($old) {
                if ($old->batch_scope !== 'shared' || ! hash_equals($old->request_hash, $hash)) $this->fail('idempotency_conflict', '这个领料请求编号已被其他内容使用，请刷新后重新确认。', 409);
                return $this->detail($old, $amounts, $actor);
            }
            $item = $this->sharedItem((int) $data['item_id'], true);
            // 一次只开一个 BOM：领板必须挂在一条还在开的开料记录上，且这种板在那个 BOM 里。
            $run = \App\Models\Erp\MfgCuttingRun::query()->lockForUpdate()->find((int) ($data['cutting_run_id'] ?? 0));
            if (! $run || $run->status !== 'running') $this->fail('cutting_run_required', '请先在「集中开料」里选 BOM 开始一批，再从那一批里选择实际原料或余料。');
            $line = collect($run->lines)->firstWhere('component_item_id', (int) $item->id);
            if (! $line)
                $this->fail('cutting_run_item_invalid', $run->output_item_name.' 的 BOM 里不用这种原料，不能在这一批里领。');
            if (($line['mode'] ?? null) !== $item->cuttingMode()) $this->fail('material_policy_changed', '原料下料模式与本批冻结资料不一致，请先核对。');
            $requested = $data['sources'];
            usort($requested, fn ($a, $b) => [(int) $a['inventory_balance_id'], (int) ($a['physical_material_id'] ?? 0)] <=> [(int) $b['inventory_balance_id'], (int) ($b['physical_material_id'] ?? 0)]);
            $sources = []; $seen = []; $physicalIds = []; $remaining = []; $total = '0.0000';
            foreach ($requested as $row) {
                $physicalId = (int) ($row['physical_material_id'] ?? 0);
                $key = trim((string) ($row['source_key'] ?? ($physicalId ? 'physical:'.$physicalId : '')));
                if ($key === '' || isset($seen[$key]) || ($physicalId && isset($physicalIds[$physicalId]))) $this->fail('source_duplicate', '真实来源编号不能为空或重复，同一实物不能重复选择。');
                $seen[$key] = true; if ($physicalId) $physicalIds[$physicalId] = true;
                if ($item->materialManagementMode() !== 'physical' && $physicalId) $this->fail('source_invalid', '数量管理的原料不能冒用实物身份。');
                $query = $this->cutting->sourceQuery($item)->where('erp_inventory_balances.id', (int) $row['inventory_balance_id']);
                if ($item->materialManagementMode() === 'physical') $query->where('cut_physical.id', $physicalId);
                $balance = $query->lockForUpdate()->first();
                if (! $balance) $this->fail('source_unavailable', '所选原料已被占用或不在可用仓库中，请重新选。');
                $source = $this->cutting->source($balance, $item, $line['stock_dimensions'] ?? []);
                if (isset($line['stock_unit_id']) && (int) $line['stock_unit_id'] !== (int) $source['unit_id']) $this->fail('material_policy_changed', '原料库存单位与本批冻结资料不一致，请先核对。');
                if (! hash_equals($source['source_version'], (string) $row['source_version'])) $this->fail('source_changed', '原料库存、尺寸或版本已变化，请重新选。', 409);
                $qty = CuttingDecimal::value($row['qty'] ?? '1');
                $this->cutting->assertSourceQuantity($source, $qty);
                $inventoryQty = $this->cutting->inventoryQuantity($source, $qty);
                $id = (int) $balance->id;
                $remaining[$id] ??= ['available' => $source['inventory_available_qty'], 'qty' => (string) $balance->quantity_on_hand, 'cost' => (string) $balance->inventory_value];
                if (bccomp($inventoryQty, $remaining[$id]['available'], 8) > 0) $this->fail('stock_short', '同一来源累计领用数量超过当前可用库存。');
                if ($physicalId) $this->physicals->assertPhysicalNotInOpenDocument($physicalId);
                $cost = $physicalId ? $source['cost_amount'] : CuttingDecimal::share($remaining[$id]['cost'], $remaining[$id]['qty'], $inventoryQty);
                if (bccomp($cost, $remaining[$id]['cost'], 4) > 0) $this->fail('source_cost_invalid', '所选实物成本超过来源余额，请先核对库存成本。');
                $remaining[$id] = ['available' => bcsub($remaining[$id]['available'], $inventoryQty, 8), 'qty' => bcsub($remaining[$id]['qty'], $inventoryQty, 8), 'cost' => bcsub($remaining[$id]['cost'], $cost, 4)];
                $sources[] = array_replace($source, ['source_key' => $key, 'qty' => $qty, 'inventory_qty' => $inventoryQty, 'cost_amount' => $cost, 'input_measure' => bcmul($source['measure_qty'], $qty, 8)]);
                $total = bcadd($total, (string) $cost, 4);
            }
            if (! $sources) $this->fail('source_required', '请选择实际领入的原料或余料。');
            $batch = MfgCuttingBatch::create([
                'batch_no' => $this->numbers->next('mfg_cutting_batch', 'MCB'), 'batch_scope' => 'shared', 'item_id' => $item->id, 'cutting_run_id' => $run->id,
                'work_order_id' => null, 'work_order_operation_id' => null, 'status' => 'issued', 'business_version' => 1,
                'unit_qty' => 0, 'completed_unit_qty' => 0, 'idempotency_key' => $data['idempotency_key'], 'request_hash' => $hash,
                'bom_snapshot' => [], 'input_lines' => $sources, 'planned_outputs' => [], 'input_cost_amount' => $total,
                'issued_by_legacy_id' => $this->actorId($actor), 'issued_by_name' => $this->actorName($actor), 'issued_at' => now(),
                'remark' => $data['remark'] ?? null,
            ]);
            $posted = $this->inventory->postManufacturingCuttingIssue($batch, null, $this->actorId($actor));
            $inputs = $batch->input_lines;
            foreach ($inputs as &$input) {
                $input = array_replace($input, $posted['lines'][$input['source_key']]);
                if (! $input['physical_material_id']) continue;
                $physical = DB::table('erp_material_physicals')->where('id', $input['physical_material_id'])->lockForUpdate()->first();
                $holding = DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->lockForUpdate()->first();
                $target = $this->physicals->nonWarehouseHolding((int) $physical->material_lot_id, 'MFG_CUTTING', (int) $batch->id, $input['cost_amount'], 'ACTIVE');
                $this->physicals->movePhysical($physical, $holding, $target, 'MFG_CUTTING_ISSUE', 'CUTTING', (int) $posted['transaction']->id, $this->actorId($actor) ?? 0);
                $input['cutting_holding_id'] = $target;
            }
            unset($input);
            $batch->update(['input_lines' => $inputs]);
            return $this->detail($batch->fresh(), $amounts, $actor);
        }, 5);
    }

    /** 下料人登记实际余料；已有产出直接结算，否则切完结束时结算。 */
    public function settle(int $id, array $data, object $actor, bool $amounts): array
    {
        return DB::transaction(function () use ($id, $data, $actor, $amounts) {
            $origin = MfgCuttingBatch::query()->where('batch_scope', 'shared')->findOrFail($id);
            if ($origin->cutting_run_id) \App\Models\Erp\MfgCuttingRun::query()->lockForUpdate()->findOrFail($origin->cutting_run_id);
            $batch = MfgCuttingBatch::query()->lockForUpdate()->findOrFail($id);
            $hash = $this->hash($data);
            $key = $data['idempotency_key'] ?? (isset($data['client_command_id']) ? hash('sha256', $data['client_command_id']) : null);
            if (! $key) $this->fail('command_required', '请提供本次登记的稳定请求编号。');
            if ($batch->status === 'settled') {
                if ($batch->settlement_key !== $key || ! hash_equals((string) $batch->settlement_hash, $hash)) $this->fail('already_settled', '这批已经结算，不能改写原产出和余料。', 409);
                return $this->detail($batch, $amounts, $actor);
            }
            if (! in_array($batch->status, ['issued', 'awaiting_confirm'], true)) $this->fail('status_invalid', '这批不能登记余料。');
            if ((int) $batch->business_version !== (int) ($data['expected_version'] ?? 0)) $this->fail('version_conflict', '这批已有变化，请刷新后再登记。', 409);
            if (! ($data['confirmed'] ?? false)) $this->fail('remnant_confirmation_required', '请核实每张板的真实余料，没有余料明确填零。');
            $batch->update(['actual_outputs' => $batch->actual_outputs ?? [], 'status' => 'awaiting_confirm',
                'remnant_cost_amount' => '0.0000', 'net_cost_amount' => '0.0000', 'pool_measure' => '0.00000000',
                'business_version' => (int) $batch->business_version + 1, 'settlement_key' => $key, 'settlement_hash' => $hash,
                'remark' => $data['remark'] ?? $batch->remark]);
            app(CuttingRemnantReceiptService::class)->submitForBatch($batch, $data['remnants'] ?? [], $actor);
            if ($batch->cutting_run_id) app(CuttingRunService::class)->finalizeAwaitingRun((int) $batch->cutting_run_id, $actor);
            return $this->detail($batch->fresh(), $amounts, $actor);
        }, 5);
    }

    /**
     * 工单下料「已备料」：本道逐张板材按定额补到做到第 $upTo 台，从开料池先进先出扣，成本按各批每平方米净耗分摊。
     * 调用方须在事务内并已锁定工单和工序。池里不够就不让备料（说明切了没登记）。
     */
    public function allocate(MfgWorkOrder $wo, MfgWorkOrderOperation $op, float $upTo, int $unitQty, object $actor): array
    {
        CancellationProductionService::assertProductionAllowed((int) $wo->id);
        $materials = MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->where('cutting_mode', 'sheet')
            ->where('required_qty', '>', 0)->orderBy('id')->lockForUpdate()->get()->filter(fn ($m) => MaterialIssueService::isCutting($m));
        if ($materials->isEmpty()) return [];
        $unsettled = MfgCuttingBatch::query()->where('work_order_operation_id', $op->id)->whereIn('status', ['issued', 'awaiting_confirm'])->lockForUpdate()->first();
        $lines = collect(data_get($wo->bom_snapshot, 'items', []))->keyBy('id');
        $directUnits = (int) MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('work_order_operation_id', $op->id)
            ->where('batch_scope', 'work_order')->whereIn('status', ['settled', 'awaiting_confirm'])->lockForUpdate()->get(['completed_unit_qty'])->sum('completed_unit_qty');
        $allocated = MfgCuttingAllocation::query()->whereIn('work_order_material_id', $materials->pluck('id'))
            ->orderBy('id')->lockForUpdate()->get()->groupBy('work_order_material_id');
        $covered = (string) max(0, min($upTo, (float) $wo->planned_qty) - $directUnits);
        $totalCovered = (string) max(0, (float) $wo->planned_qty - $directUnits);
        $out = [];
        foreach ($materials as $m) {
            $need = bcsub(sprintf('%.4F', $this->materials->neededFor($m, $wo, $upTo)), (string) $m->issued_qty, 4);
            if (bccomp($need, '0', 4) < 0) $need = '0.0000';
            $snapshot = $lines->get($m->bom_item_id)['cutting_quantity_snapshot'] ?? [];
            $target = $this->cumulativeMeasure($m, $snapshot, (string) $wo->planned_qty, $covered);
            if ($target === null) $this->fail('cutting_snapshot_missing', $m->component_item_name.' 缺少冻结的板件或标准板尺寸，不能按定额计算面积。');
            $totalTarget = $this->cumulativeMeasure($m, $snapshot, (string) $wo->planned_qty, $totalCovered);
            $already = ($allocated->get($m->id) ?? collect())->reduce(fn ($sum, $row) => bcadd($sum, (string) $row->measure, 8), '0.00000000');
            $measure = bcsub($target, $already, 8);
            if (bccomp($already, $totalTarget, 8) > 0 || (bccomp($measure, '0', 8) <= 0 && bccomp($need, '0', 4) > 0))
                $this->fail('cutting_measure_conflict', $m->component_item_name.' 已收数量与既有开料面积不一致，请先核对历史开料及分配记录。', 409);
            if (bccomp($measure, '0', 8) <= 0) continue;
            if ($unsettled) $this->fail('cutting_not_settled', '批次 '.$unsettled->batch_no.($unsettled->status === 'awaiting_confirm'
                ? ' 请下料人先核对实际余料并登记结算。' : ' 尚未登记产出和余料，请先登记。'));
            $batches = MfgCuttingBatch::query()->where('batch_scope', 'shared')->where('item_id', $m->component_item_id)->where('status', 'settled')
                ->whereColumn('allocated_measure', '<', 'pool_measure')->orderBy('settled_at')->orderBy('id')->lockForUpdate()->get();
            $available = '0.00000000';
            foreach ($batches as $b) $available = bcadd($available, bcsub((string) $b->pool_measure, (string) $b->allocated_measure, 8), 8);
            if (bccomp($available, $measure, 8) < 0) {
                $waiting = MfgCuttingBatch::query()->where('batch_scope', 'shared')->where('item_id', $m->component_item_id)->where('status', 'awaiting_confirm')->orderBy('id')->pluck('batch_no')->implode('、');
                $this->fail('cutting_pool_short', $m->component_item_name.' 可分配开料池只有 '.$this->num($available).' m²，本次需要 '.$this->num($measure).' m²。'
                    .($waiting ? '请下料人补齐批次 '.$waiting.' 的产出和实际余料登记。' : '请先在「集中开料」登记真实产出和余料。'));
            }
            $left = $measure; $qtyLeft = $need;
            foreach ($batches as $b) {
                if (bccomp($left, '0', 8) <= 0) break;
                $room = bcsub((string) $b->pool_measure, (string) $b->allocated_measure, 8);
                $take = bccomp($left, $room, 8) < 0 ? $left : $room;
                // 一批被扣完时把剩下的成本全部带走，避免四舍五入留尾差。
                $cost = bccomp($take, $room, 8) === 0 ? bcsub((string) $b->net_cost_amount, (string) $b->allocated_cost_amount, 4)
                    : CuttingDecimal::share(bcsub((string) $b->net_cost_amount, (string) $b->allocated_cost_amount, 4), $room, $take);
                $qty = CuttingDecimal::share($qtyLeft, $left, $take);
                MfgCuttingAllocation::create(['cutting_batch_id' => $b->id, 'item_id' => $m->component_item_id, 'work_order_id' => $wo->id,
                    'work_order_operation_id' => $op->id, 'work_order_material_id' => $m->id, 'unit_qty' => $unitQty, 'qty' => $qty,
                    'measure' => $take, 'measure_unit' => 'm2', 'cost_amount' => $cost,
                    'allocated_by_legacy_id' => $this->actorId($actor), 'allocated_by_name' => $this->actorName($actor)]);
                $b->update(['allocated_measure' => bcadd((string) $b->allocated_measure, $take, 8), 'allocated_cost_amount' => bcadd((string) $b->allocated_cost_amount, $cost, 4)]);
                $left = bcsub($left, $take, 8); $qtyLeft = bcsub($qtyLeft, $qty, 4);
                $out[] = ['material_id' => (int) $m->id, 'batch_no' => $b->batch_no, 'qty' => $qty, 'measure' => $take];
            }
            if (bccomp($left, '0', 8) !== 0) $this->fail('cutting_pool_short', '集中开料剩余面积不足，请刷新后重试。');
            if (bccomp($need, '0', 4) > 0) $m->update(['issued_qty' => bcadd((string) $m->issued_qty, $need, 4)]);
        }
        return $out;
    }

    /** 各板材开料池剩余（已结算集中开料净耗 − 已分给工单）。 */
    public function poolAvailable(array $itemIds): array
    {
        if (! $itemIds) return [];
        return MfgCuttingBatch::query()->where('batch_scope', 'shared')->where('status', 'settled')->whereIn('item_id', $itemIds)
            ->selectRaw('item_id, COALESCE(SUM(pool_measure - allocated_measure), 0) AS left_measure')->groupBy('item_id')
            ->pluck('left_measure', 'item_id')->map(fn ($v) => bcadd((string) $v, '0', 8))->all();
    }

    /** 物料单位 1 个折合多少 m²（张按冻结的标准板长×宽，平方米为 1）。 */
    private function factor(array $snapshot): ?string
    {
        $stock = $snapshot['stock_dimensions'] ?? [];
        return match ($snapshot['unit_kind'] ?? null) {
            'm2' => '1',
            'sheet' => isset($stock['length_mm'], $stock['width_mm']) && (float) $stock['length_mm'] > 0 && (float) $stock['width_mm'] > 0
                ? bcdiv(bcmul((string) $stock['length_mm'], (string) $stock['width_mm'], 8), '1000000', 8) : null,
            default => null,
        };
    }

    /** 面积用冻结几何计算，不能从已四舍五入的四位张数反算。 */
    private function cumulativeMeasure(object $material, array $snapshot, string $planned, string $covered): ?string
    {
        $factor = $this->factor($snapshot);
        if ($factor === null || bccomp($planned, '0', 8) <= 0) return null;
        $dimensions = $snapshot['dimensions'] ?? [];
        if (isset($dimensions['length_mm'], $dimensions['width_mm'], $dimensions['piece_qty'])
            && (float) $dimensions['length_mm'] > 0 && (float) $dimensions['width_mm'] > 0 && (int) $dimensions['piece_qty'] > 0) {
            $perUnit = bcdiv(bcmul(bcmul((string) $dimensions['length_mm'], (string) $dimensions['width_mm'], 12), (string) $dimensions['piece_qty'], 12), '1000000', 12);
        } elseif (isset($snapshot['theoretical_qty'])) {
            // 历史快照缺几何时仅用冻结高精度理论量，不回读四位库存量。
            $perUnit = bcmul((string) $snapshot['theoretical_qty'], $factor, 12);
        } else return null;
        $loss = bcadd('1', bcdiv((string) ($material->loss_rate ?? 0), '100', 12), 12);
        $total = bcadd(bcmul(bcmul($perUnit, $planned, 12), $loss, 12), bcmul((string) ($material->fixed_qty ?? 0), $factor, 12), 12);
        $total = bcmul($total, (string) $material->qty_ratio, 12);
        return bccomp($covered, $planned, 8) >= 0 ? bcadd($total, '0', 8) : bcdiv(bcmul($total, $covered, 12), $planned, 8);
    }

    private function sharedItem(int $itemId, bool $lock = false): Item
    {
        $item = Item::query()->with('unit.standardUnit')->where('status', 'enabled')->when($lock, fn ($q) => $q->lockForUpdate())->find($itemId);
        if (! $item || ($item->management_scope && $item->management_scope !== 'factory')) $this->fail('item_invalid', '请选择启用的工厂板材。');
        if (! in_array($item->cuttingMode(), ['sheet', 'length'], true) || $item->serialTrackingMode() !== 'none')
            $this->fail('item_invalid', '请选择按下料管理且无需序列号追溯的板材或管材。');
        return $item;
    }

    private function summary(MfgCuttingBatch $batch, ?Item $item, bool $amounts, object $actor): array
    {
        $inputs = collect($batch->input_lines);
        $input = '0.00000000';
        foreach ($inputs as $row) $input = bcadd($input, (string) $row['input_measure'], 8);
        $singleUnit = $inputs->pluck('unit_kind')->unique()->count() === 1;
        $row = $batch->only(['id', 'batch_no', 'item_id', 'cutting_run_id', 'status', 'business_version', 'input_cost_amount', 'remnant_cost_amount', 'net_cost_amount',
            'issued_by_name', 'settled_by_name', 'issued_at', 'settled_at', 'remark']) + [
            'item_code' => $item?->item_code, 'item_name' => $item?->item_name, 'specification' => $item?->spec ?: $item?->model,
            'sheet_count' => $inputs->count(), 'input_measure' => $input, 'measure_unit' => $inputs->first()['measure_unit'] ?? 'm2', 'mode' => $inputs->first()['mode'] ?? 'sheet',
            'input_qty' => $singleUnit ? $inputs->sum('qty') : $input,
            'unit_kind' => $singleUnit ? ($inputs->first()['unit_kind'] ?? null) : ($inputs->first()['measure_unit'] ?? null),
            'inventory_qty' => $inputs->reduce(fn ($sum, $input) => bcadd($sum, (string) ($input['inventory_qty'] ?? $input['qty']), 8), '0.00000000'),
            'inventory_unit_kind' => $inputs->first()['inventory_unit_kind'] ?? $inputs->first()['unit_kind'] ?? null, 'pool_measure' => (string) $batch->pool_measure,
            'net_measure' => collect($batch->remnants ?? [])->reduce(fn ($sum, $r) => bcadd($sum, (string) ($r['consumed_measure'] ?? '0'), 8), '0.00000000'),
            'pool_tracked' => ! empty($inputs->first()['physical_material_id']) && ($inputs->first()['mode'] ?? null) === 'sheet',
            'allocated_measure' => (string) $batch->allocated_measure, 'allocated_cost_amount' => (string) $batch->allocated_cost_amount,
            'left_measure' => bcsub((string) $batch->pool_measure, (string) $batch->allocated_measure, 8),
            'status_label' => ['issued' => '切割中', 'awaiting_confirm' => '待下料人登记结算', 'settled' => '已结算'][$batch->status] ?? $batch->status];
        $row += app(CuttingRemnantReceiptService::class)->batchConfirmation($batch, $amounts, $actor);
        if ($batch->status !== 'settled') foreach (['remnant_cost_amount', 'net_cost_amount'] as $field) $row[$field] = null;
        return $this->cutting->publicData($row, $amounts);
    }

    private function detail(MfgCuttingBatch $batch, bool $amounts, object $actor): array
    {
        $item = Item::query()->find($batch->item_id, ['id', 'item_code', 'item_name', 'spec', 'model']);
        $row = $this->summary($batch, $item, $amounts, $actor);
        $row['inputs'] = $this->cutting->publicData(array_map(fn ($i) => array_intersect_key($i, array_flip(['source_key', 'physical_material_id', 'physical_no',
            'inventory_balance_id', 'warehouse_id', 'warehouse_name', 'location_id', 'location_name', 'batch_no', 'shape', 'dimensions', 'material_form',
            'input_measure', 'measure_unit', 'mode', 'unit_kind', 'qty', 'inventory_qty', 'inventory_unit_kind', 'measure_qty', 'cost_amount'])), $batch->input_lines), $amounts);
        $row['remnants'] = $this->cutting->publicData($batch->remnants ?? [], $amounts);
        $row['allocations'] = $this->cutting->publicData(MfgCuttingAllocation::query()->from('erp_mfg_cutting_allocations as a')
            ->join('erp_mfg_work_orders as w', 'w.id', '=', 'a.work_order_id')
            ->leftJoin('erp_mfg_work_order_operations as o', 'o.id', '=', 'a.work_order_operation_id')
            ->where('a.cutting_batch_id', $batch->id)->orderBy('a.id')
            ->get(['a.id', 'a.work_order_id', 'w.work_order_no', 'w.product_name', 'o.operation_name', 'a.unit_qty', 'a.qty', 'a.measure', 'a.cost_amount',
                'a.allocated_by_name', 'a.created_at'])->toArray(), $amounts);
        return $row;
    }

    private function num(string $v): string { return rtrim(rtrim(bcadd($v, '0', 4), '0'), '.') ?: '0'; }

    private function hash(array $data): string
    {
        $sort = function (array $rows) use (&$sort): array {
            if (! array_is_list($rows)) ksort($rows);
            foreach ($rows as &$row) if (is_array($row)) $row = $sort($row);
            return $rows;
        };
        return hash('sha256', json_encode($sort($data), JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR));
    }
}
