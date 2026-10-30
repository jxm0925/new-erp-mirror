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
            ->where('m.required_qty', '>', 0)->whereColumn('m.issued_qty', '<', 'm.required_qty')->whereNotNull('m.work_order_operation_id')
            ->when(! empty($filters['item_id']), fn ($q) => $q->where('m.component_item_id', (int) $filters['item_id']))
            ->select(['m.id', 'm.work_order_id', 'm.work_order_operation_id', 'm.component_item_id', 'm.component_item_code', 'm.component_item_name',
                'm.unit_name', 'm.required_qty', 'm.issued_qty', 'm.bom_item_id', 'm.qty_ratio', 'w.work_order_no', 'w.planned_qty', 'w.product_name',
                'w.item_name as wo_item_name', 'w.customer_name', 'w.is_urgent', 'w.priority', 'w.planned_start_date', 'w.planned_finish_date', 'w.status as wo_status',
                'o.operation_name', 'i.spec', 'i.model', 'i.material_grade'])
            ->orderByDesc('w.is_urgent')->orderBy('w.priority')->orderBy('w.planned_start_date')->orderBy('w.id')->orderBy('m.id')->get();
        $snapshots = MfgWorkOrder::query()->whereIn('id', $rows->pluck('work_order_id')->unique())->get(['id', 'bom_snapshot'])
            ->mapWithKeys(fn ($wo) => [$wo->id => collect(data_get($wo->bom_snapshot, 'items', []))->keyBy('id')]);
        $pool = $this->poolAvailable($rows->pluck('component_item_id')->unique()->all());
        $open = MfgCuttingBatch::query()->where('batch_scope', 'shared')->where('status', 'issued')
            ->selectRaw('item_id, COUNT(*) AS n')->groupBy('item_id')->pluck('n', 'item_id');
        $items = [];
        foreach ($rows as $row) {
            $line = $snapshots[$row->work_order_id]->get($row->bom_item_id) ?? [];
            $snapshot = $line['cutting_quantity_snapshot'] ?? [];
            $dim = $snapshot['dimensions'] ?? [];
            $left = bcsub((string) $row->required_qty, (string) $row->issued_qty, 4);
            $factor = $this->factor($snapshot);
            $planned = (float) $row->planned_qty;
            $doneUnits = (float) $row->required_qty > 0 ? (int) floor((float) $row->issued_qty * $planned / (float) $row->required_qty + 1e-6) : 0;
            $leftUnits = max(0, (int) $planned - $doneUnits);
            $perUnitPieces = (int) ($dim['piece_qty'] ?? 0) * (float) $row->qty_ratio;
            $key = (int) $row->component_item_id;
            $items[$key] ??= ['item_id' => $key, 'item_code' => $row->component_item_code, 'item_name' => $row->component_item_name,
                'specification' => $row->spec ?: $row->model, 'material_grade' => $row->material_grade, 'unit_name' => $row->unit_name,
                'measure_unit' => 'm2', 'need_qty' => '0.0000', 'need_measure' => '0.00000000', 'pool_measure' => $pool[$key] ?? '0.00000000',
                'open_batches' => (int) ($open[$key] ?? 0), 'piece_count' => 0, 'lines' => []];
            $items[$key]['need_qty'] = bcadd($items[$key]['need_qty'], $left, 4);
            if ($factor) $items[$key]['need_measure'] = bcadd($items[$key]['need_measure'], bcmul($left, $factor, 8), 8);
            $pieces = (int) round($perUnitPieces * $leftUnits);
            $items[$key]['piece_count'] += $pieces;
            $items[$key]['lines'][] = ['material_id' => (int) $row->id, 'work_order_id' => (int) $row->work_order_id, 'work_order_no' => $row->work_order_no,
                'operation_id' => (int) $row->work_order_operation_id, 'operation_name' => $row->operation_name,
                'product_name' => $row->product_name ?: $row->wo_item_name, 'customer_name' => $row->customer_name, 'is_urgent' => (bool) $row->is_urgent,
                'planned_start_date' => $row->planned_start_date, 'planned_finish_date' => $row->planned_finish_date,
                'planned_qty' => $planned, 'left_units' => $leftUnits, 'left_qty' => $left, 'left_measure' => $factor ? bcmul($left, $factor, 8) : null,
                'part_name' => ($line['remark'] ?? null) ?: '板件 '.($line['line_no'] ?? ''), 'length_mm' => $dim['length_mm'] ?? null,
                'width_mm' => $dim['width_mm'] ?? null, 'thickness_mm' => $dim['thickness_mm'] ?? null, 'allow_rotation' => (bool) ($dim['allow_rotation'] ?? false),
                'pieces_per_unit' => $perUnitPieces, 'left_pieces' => $pieces];
        }
        foreach ($items as &$item) $item['short_measure'] = bccomp($item['need_measure'], $item['pool_measure'], 8) > 0 ? bcsub($item['need_measure'], $item['pool_measure'], 8) : '0.00000000';
        unset($item);
        return ['items' => array_values($items)];
    }

    public function index(array $filters, bool $amounts): array
    {
        $page = MfgCuttingBatch::query()->where('batch_scope', 'shared')
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']))
            ->when(! empty($filters['item_id']), fn ($q) => $q->where('item_id', (int) $filters['item_id']))
            ->orderByRaw("status = 'issued' DESC")->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        $names = Item::query()->whereIn('id', $page->getCollection()->pluck('item_id')->unique())->get(['id', 'item_code', 'item_name', 'spec', 'model'])->keyBy('id');
        $page->getCollection()->transform(fn ($batch) => $this->summary($batch, $names->get($batch->item_id), $amounts));
        return $page->toArray();
    }

    public function show(int $id, bool $amounts): array
    {
        $batch = MfgCuttingBatch::query()->where('batch_scope', 'shared')->findOrFail($id);
        return $this->detail($batch, $amounts);
    }

    public function sources(array $filters, bool $amounts): array
    {
        $item = $this->sharedItem((int) $filters['item_id']);
        $query = $this->cutting->sourceQuery($item);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(fn ($q) => $q->where('cut_physical.physical_no', 'like', '%'.$keyword.'%')->orWhere('erp_inventory_balances.batch_no', 'like', '%'.$keyword.'%')
                ->orWhere('erp_locations.location_name', 'like', '%'.$keyword.'%')->orWhere('erp_warehouses.warehouse_name', 'like', '%'.$keyword.'%'));
        }
        // 先用余板（面积小的先用），再用整板。
        $page = $query->orderByRaw("cut_lot.material_form = 'REMNANT' DESC")->orderBy('erp_inventory_balances.id')->orderBy('cut_physical.id')
            ->paginate($filters['per_page'] ?? 20, ['*'], 'page', $filters['page'] ?? 1);
        $page->getCollection()->transform(fn ($balance) => $this->cutting->publicData($this->cutting->source($balance, $item), $amounts));
        return $page->toArray();
    }

    /** 领板：整张领入开料，原板离开仓库进入开料中。 */
    public function issue(array $data, object $actor, bool $amounts): array
    {
        return DB::transaction(function () use ($data, $actor, $amounts) {
            $hash = $this->hash($data);
            $old = MfgCuttingBatch::query()->where('idempotency_key', $data['idempotency_key'])->lockForUpdate()->first();
            if ($old) {
                if ($old->batch_scope !== 'shared' || ! hash_equals($old->request_hash, $hash)) $this->fail('idempotency_conflict', '这个领料请求编号已被其他内容使用，请刷新后重新确认。', 409);
                return $this->detail($old, $amounts);
            }
            $item = $this->sharedItem((int) $data['item_id'], true);
            $requested = $data['sources'];
            usort($requested, fn ($a, $b) => [(int) $a['inventory_balance_id'], (int) $a['physical_material_id']] <=> [(int) $b['inventory_balance_id'], (int) $b['physical_material_id']]);
            $sources = []; $seen = []; $total = '0.0000';
            foreach ($requested as $row) {
                $physicalId = (int) $row['physical_material_id'];
                if (isset($seen[$physicalId])) $this->fail('source_duplicate', '同一张板不能重复选择。');
                $seen[$physicalId] = true;
                $balance = $this->cutting->sourceQuery($item)->where('erp_inventory_balances.id', (int) $row['inventory_balance_id'])
                    ->where('cut_physical.id', $physicalId)->lockForUpdate()->first();
                if (! $balance) $this->fail('source_unavailable', '所选板材已被占用或不在可用仓库中，请重新选。');
                $source = $this->cutting->source($balance, $item);
                if (! hash_equals($source['source_version'], (string) $row['source_version'])) $this->fail('source_changed', '板材库存、尺寸或版本已变化，请重新选。', 409);
                $this->physicals->assertPhysicalNotInOpenDocument($physicalId);
                $sources[] = array_replace($source, ['qty' => '1.00000000', 'input_measure' => $source['measure_qty']]);
                $total = bcadd($total, (string) $source['cost_amount'], 4);
            }
            if (! $sources) $this->fail('source_required', '请选择要切的板材。');
            $batch = MfgCuttingBatch::create([
                'batch_no' => $this->numbers->next('mfg_cutting_batch', 'MCB'), 'batch_scope' => 'shared', 'item_id' => $item->id,
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
                $physical = DB::table('erp_material_physicals')->where('id', $input['physical_material_id'])->lockForUpdate()->first();
                $holding = DB::table('erp_material_holdings')->where('id', $physical->current_holding_id)->lockForUpdate()->first();
                $target = $this->physicals->nonWarehouseHolding((int) $physical->material_lot_id, 'MFG_CUTTING', (int) $batch->id, $input['cost_amount'], 'ACTIVE');
                $this->physicals->movePhysical($physical, $holding, $target, 'MFG_CUTTING_ISSUE', 'CUTTING', (int) $posted['transaction']->id, $this->actorId($actor) ?? 0);
                $input['cutting_holding_id'] = $target;
            }
            unset($input);
            $batch->update(['input_lines' => $inputs]);
            return $this->detail($batch->fresh(), $amounts);
        }, 5);
    }

    /** 切完登记：每张板的余料（平方米，没有填 0）及放回的库位；净耗进开料池。 */
    public function settle(int $id, array $data, object $actor, bool $amounts): array
    {
        return DB::transaction(function () use ($id, $data, $actor, $amounts) {
            $batch = MfgCuttingBatch::query()->where('batch_scope', 'shared')->lockForUpdate()->findOrFail($id);
            $hash = $this->hash($data);
            if ($batch->status === 'settled') {
                if ($batch->settlement_key !== $data['idempotency_key'] || ! hash_equals((string) $batch->settlement_hash, $hash)) $this->fail('already_settled', '这批已经登记完，不能再改。', 409);
                return $this->detail($batch, $amounts);
            }
            if ($batch->status !== 'issued') $this->fail('status_invalid', '这批不能登记余料。');
            if ((int) $batch->business_version !== (int) $data['expected_version']) $this->fail('version_conflict', '这批已有变化，请刷新后再登记。', 409);
            if (! ($data['confirmed'] ?? false)) $this->fail('remnant_confirmation_required', '请核实每张板的余料后再确认。');
            Item::query()->whereKey($batch->item_id)->lockForUpdate()->first(['id']);
            $rows = collect($data['remnants'])->keyBy('source_key');
            $inputs = collect($batch->input_lines);
            if ($rows->count() !== count($data['remnants']) || $rows->count() !== $inputs->count() || $rows->keys()->diff($inputs->pluck('source_key'))->isNotEmpty())
                $this->fail('remnant_invalid', '每张领入的板都要登记一次余料，没有余料填 0。');
            $remnants = []; $returned = '0.0000'; $pool = '0.00000000';
            foreach ($inputs as $input) {
                $row = $rows->get($input['source_key']);
                $measure = CuttingDecimal::value($row['measure_qty'], 8, true);
                if (bccomp($measure, (string) $input['input_measure'], 8) > 0) $this->fail('remnant_exceeds_source', ($input['physical_no'] ?? '板').' 的余料超过了这张板的面积。');
                $warehouseName = $input['warehouse_name']; $locationName = $input['location_name'];
                if (bccomp($measure, '0', 8) > 0) {
                    $location = DB::table('erp_locations')->where('id', (int) $row['location_id'])->lockForUpdate()->first();
                    if (! $location || (int) $location->warehouse_id !== (int) $row['warehouse_id'] || ! in_array($location->status, ['enabled', 'active'], true)
                        || $location->location_purpose !== 'general') $this->fail('remnant_location_invalid', '余料必须放回所选仓库内启用的普通库位。');
                    $warehouse = DB::table('erp_warehouses')->where('id', (int) $row['warehouse_id'])->sharedLock()->first();
                    if (! $warehouse || ! in_array($warehouse->status, ['enabled', 'active'], true) || ($warehouse->management_scope && $warehouse->management_scope !== 'factory'))
                        $this->fail('remnant_warehouse_invalid', '余料必须放回启用的工厂仓库。');
                    $warehouseName = $warehouse->warehouse_name; $locationName = $location->location_name;
                }
                $output = bcsub((string) $input['input_measure'], $measure, 8);
                $cost = bccomp($measure, '0', 8) === 0 ? '0.0000' : CuttingDecimal::share((string) $input['cost_amount'], (string) $input['input_measure'], $measure);
                $remnants[] = ['source_key' => $input['source_key'], 'item_id' => $input['item_id'], 'measure_qty' => $measure, 'output_measure' => $output,
                    'measure_unit' => $input['measure_unit'], 'scrap_measure' => '0.00000000',
                    'warehouse_id' => (int) $row['warehouse_id'], 'location_id' => (int) $row['location_id'],
                    'warehouse_name' => $warehouseName, 'location_name' => $locationName, 'cost_amount' => $cost];
                $returned = bcadd($returned, $cost, 4);
                $pool = bcadd($pool, $output, 8);
            }
            $net = bcsub((string) $batch->input_cost_amount, $returned, 4);
            if (bccomp($net, '0', 4) < 0) $this->fail('cost_invalid', '余料成本不能超过投入总成本。');
            $batch->update(['actual_outputs' => [], 'remnants' => $remnants, 'remnant_cost_amount' => $returned, 'net_cost_amount' => $net, 'pool_measure' => $pool]);
            $posted = $this->inventory->postManufacturingCuttingSettlement($batch, null, $this->actorId($actor));
            foreach ($remnants as &$remnant) $remnant = array_replace($remnant, $posted['lines'][$remnant['source_key']] ?? []);
            unset($remnant);
            $batch->refresh();
            foreach ($batch->input_lines as $input) {
                $return = collect($remnants)->firstWhere('source_key', $input['source_key']);
                $this->physicals->completeManufacturingCut($batch, $input, bcsub((string) $input['cost_amount'], (string) $return['cost_amount'], 4), $this->actorId($actor) ?? 0);
            }
            $batch->update(['remnants' => $remnants, 'status' => 'settled', 'business_version' => (int) $batch->business_version + 1,
                'settlement_key' => $data['idempotency_key'], 'settlement_hash' => $hash,
                'settled_by_legacy_id' => $this->actorId($actor), 'settled_by_name' => $this->actorName($actor), 'settled_at' => now(),
                'remark' => $data['remark'] ?? $batch->remark]);
            return $this->detail($batch->fresh(), $amounts);
        }, 5);
    }

    /**
     * 工单下料「已备料」：本道逐张板材按定额补到做到第 $upTo 台，从开料池先进先出扣，成本按各批每平方米净耗分摊。
     * 调用方须在事务内并已锁定工单和工序。池里不够就不让备料（说明切了没登记）。
     */
    public function allocate(MfgWorkOrder $wo, MfgWorkOrderOperation $op, float $upTo, int $unitQty, object $actor): array
    {
        $materials = MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->where('cutting_mode', 'sheet')
            ->where('required_qty', '>', 0)->orderBy('id')->lockForUpdate()->get()->filter(fn ($m) => MaterialIssueService::isCutting($m));
        if ($materials->isEmpty()) return [];
        if (MfgCuttingBatch::query()->where('work_order_operation_id', $op->id)->where('status', 'issued')->lockForUpdate()->first(['id']))
            $this->fail('cutting_not_settled', $op->operation_name.'还有随单开料批次没登记产出和余料，请先登记完再备料。');
        $lines = collect(data_get($wo->bom_snapshot, 'items', []))->keyBy('id');
        $out = [];
        foreach ($materials as $m) {
            $need = bcsub(sprintf('%.4F', $this->materials->neededFor($m, $wo, $upTo)), (string) $m->issued_qty, 4);
            if (bccomp($need, '0', 4) <= 0) continue;
            $factor = $this->factor($lines->get($m->bom_item_id)['cutting_quantity_snapshot'] ?? []);
            if (! $factor) $this->fail('cutting_snapshot_missing', $m->component_item_name.' 缺少冻结的标准板尺寸，不能按定额折算面积。');
            $measure = bcmul($need, $factor, 8);
            $batches = MfgCuttingBatch::query()->where('batch_scope', 'shared')->where('item_id', $m->component_item_id)->where('status', 'settled')
                ->whereColumn('allocated_measure', '<', 'pool_measure')->orderBy('settled_at')->orderBy('id')->lockForUpdate()->get();
            $available = '0.00000000';
            foreach ($batches as $b) $available = bcadd($available, bcsub((string) $b->pool_measure, (string) $b->allocated_measure, 8), 8);
            if (bccomp($available, $measure, 6) < 0)
                $this->fail('cutting_pool_short', $m->component_item_name.' 集中开料只登记了够用 '.$this->num($available).' m²，这次备料按定额要 '.$this->num($measure).' m²（'.$this->num($need).' '.$m->unit_name.'）。请先在「集中开料」里把切这种板的批次登记完。');
            $left = $measure; $qtyLeft = $need;
            foreach ($batches as $b) {
                if (bccomp($left, '0', 8) <= 0) break;
                $room = bcsub((string) $b->pool_measure, (string) $b->allocated_measure, 8);
                $take = bccomp($left, $room, 8) < 0 ? $left : $room;
                // 一批被扣完时把剩下的成本全部带走，避免四舍五入留尾差。
                $cost = bccomp($take, $room, 8) === 0 ? bcsub((string) $b->net_cost_amount, (string) $b->allocated_cost_amount, 4)
                    : CuttingDecimal::share(bcsub((string) $b->net_cost_amount, (string) $b->allocated_cost_amount, 4), $room, $take);
                $qty = bccomp($take, $left, 8) === 0 ? $qtyLeft : bcdiv($take, $factor, 4);
                MfgCuttingAllocation::create(['cutting_batch_id' => $b->id, 'item_id' => $m->component_item_id, 'work_order_id' => $wo->id,
                    'work_order_operation_id' => $op->id, 'work_order_material_id' => $m->id, 'unit_qty' => $unitQty, 'qty' => $qty,
                    'measure' => $take, 'measure_unit' => 'm2', 'cost_amount' => $cost,
                    'allocated_by_legacy_id' => $this->actorId($actor), 'allocated_by_name' => $this->actorName($actor)]);
                $b->update(['allocated_measure' => bcadd((string) $b->allocated_measure, $take, 8), 'allocated_cost_amount' => bcadd((string) $b->allocated_cost_amount, $cost, 4)]);
                $left = bcsub($left, $take, 8); $qtyLeft = bcsub($qtyLeft, $qty, 4);
                $out[] = ['material_id' => (int) $m->id, 'batch_no' => $b->batch_no, 'qty' => $qty, 'measure' => $take];
            }
            $m->update(['issued_qty' => bcadd((string) $m->issued_qty, $need, 4)]);
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

    private function sharedItem(int $itemId, bool $lock = false): Item
    {
        $item = Item::query()->with('unit.standardUnit')->where('status', 'enabled')->when($lock, fn ($q) => $q->lockForUpdate())->find($itemId);
        if (! $item || ($item->management_scope && $item->management_scope !== 'factory')) $this->fail('item_invalid', '请选择启用的工厂板材。');
        if ($item->cuttingMode() !== 'sheet' || $item->materialManagementMode() !== 'physical')
            $this->fail('item_invalid', '集中开料只用于逐张管理的板材；管材按 BOM 定额在工单下料里收料或点「已备料」。');
        return $item;
    }

    private function summary(MfgCuttingBatch $batch, ?Item $item, bool $amounts): array
    {
        $inputs = collect($batch->input_lines);
        $input = '0.00000000';
        foreach ($inputs as $row) $input = bcadd($input, (string) $row['input_measure'], 8);
        $row = $batch->only(['id', 'batch_no', 'item_id', 'status', 'business_version', 'input_cost_amount', 'remnant_cost_amount', 'net_cost_amount',
            'issued_by_name', 'settled_by_name', 'issued_at', 'settled_at', 'remark']) + [
            'item_code' => $item?->item_code, 'item_name' => $item?->item_name, 'specification' => $item?->spec ?: $item?->model,
            'sheet_count' => $inputs->count(), 'input_measure' => $input, 'pool_measure' => (string) $batch->pool_measure,
            'allocated_measure' => (string) $batch->allocated_measure, 'allocated_cost_amount' => (string) $batch->allocated_cost_amount,
            'left_measure' => bcsub((string) $batch->pool_measure, (string) $batch->allocated_measure, 8),
            'status_label' => $batch->status === 'issued' ? '切割中' : '已登记'];
        return $this->cutting->publicData($row, $amounts);
    }

    private function detail(MfgCuttingBatch $batch, bool $amounts): array
    {
        $item = Item::query()->find($batch->item_id, ['id', 'item_code', 'item_name', 'spec', 'model']);
        $row = $this->summary($batch, $item, $amounts);
        $row['inputs'] = $this->cutting->publicData(array_map(fn ($i) => array_intersect_key($i, array_flip(['source_key', 'physical_material_id', 'physical_no',
            'inventory_balance_id', 'warehouse_id', 'warehouse_name', 'location_id', 'location_name', 'batch_no', 'shape', 'dimensions', 'material_form',
            'input_measure', 'measure_unit', 'cost_amount'])), $batch->input_lines), $amounts);
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
