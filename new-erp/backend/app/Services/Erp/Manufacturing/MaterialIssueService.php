<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Item;
use App\Models\Erp\MfgMaterialIssue;
use App\Models\Erp\MfgUnitReport;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLink;
use App\Models\Erp\MfgWorkOrderMaterial;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\Inventory\ProductionInventoryPosting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 工序收料：车间按设备数收本道要用的料（总装收半成品和外购件，下料组收原料），收料时才扣库存。
 * 本道可等自制半成品期间先计时，完成前必须收齐；仓库没货时可开半成品工单。
 * 板材／管材由下料人在开料批次自选来源，不在这里自动收料。
 */
final class MaterialIssueService
{
    use ProductionActor;

    private const EPS = 0.00001;

    public function __construct(
        private readonly ProductionInventoryPosting $inventory,
        private readonly InventoryAvailabilityService $availability,
        private readonly WorkOrderService $workOrders,
        private readonly DocumentNumberService $numbers,
    ) {}

    /**
     * 管材不论按数量还是实物管理，都可随单选择原料并登记产出、余料；板材沿用实物开料规则。
     * 集中开料切好的件仍可通过「已备料」承接，报工统一校验已登记的可供套数。
     */
    public static function isCutting(MfgWorkOrderMaterial $m): bool
    {
        return $m->cutting_mode === 'length'
            || ($m->cutting_mode === 'sheet' && Item::query()->whereKey($m->component_item_id)->value('material_management_mode') === 'physical');
    }

    /** 使用管材或实物板材的车间工序：在本道随单开料，按已登记的开料套数报完成。 */
    public static function cuttingOperationIds(int $workOrderId): array
    {
        return self::cutRows($workOrderId, true)->distinct()->pluck('m.work_order_operation_id')->map(fn ($id) => (int) $id)->all();
    }

    /** 工单里用按数量管理的板材／管材的车间工序（下料）：可以计时报工，也可以「已备料」不计时直接交下道。 */
    public static function prepOperationIds(int $workOrderId): array
    {
        return self::cutRows($workOrderId, false)->distinct()->pluck('m.work_order_operation_id')->map(fn ($id) => (int) $id)->all();
    }

    private static function cutRows(int $workOrderId, bool $forCutting)
    {
        return DB::table('erp_mfg_work_order_materials as m')->join('erp_items as i', 'i.id', '=', 'm.component_item_id')
            ->where('m.work_order_id', $workOrderId)->whereIn('m.cutting_mode', ['sheet', 'length'])->where('m.required_qty', '>', 0)
            ->whereNotNull('m.work_order_operation_id')
            ->when($forCutting,
                fn ($q) => $q->where(fn ($w) => $w->where('m.cutting_mode', 'length')->orWhere('i.material_management_mode', 'physical')),
                fn ($q) => $q->where(fn ($w) => $w->whereNull('i.material_management_mode')->orWhere('i.material_management_mode', '!=', 'physical')));
    }

    /** 开料登记够做到第几套（各种板材／管材取最少）；本道不开料时返回 null。 */
    public function cutUnits(MfgWorkOrder $wo, MfgWorkOrderOperation $op, bool $lock = false): ?int
    {
        $rows = MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->whereIn('cutting_mode', ['sheet', 'length'])
            ->where('required_qty', '>', 0)->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get();
        if ($rows->isEmpty()) return null;
        return (int) $rows->map(fn ($m) => $this->coveredUnits($m, $wo))->min();
    }

    /** 下料按套数报完成：已完成 + 本次 不能超过开料已登记的套数。 */
    public function assertCutForPass(MfgWorkOrder $wo, MfgWorkOrderOperation $op, int $firstPass): void
    {
        $cut = $this->cutUnits($wo, $op, true);
        if ($cut === null || $firstPass <= 0) return;
        $done = MfgUnitReport::where('work_order_operation_id', $op->id)->where('result', 'passed')
            ->whereIn('unit_id', CancellationProductionService::productionUnitIds((int) $wo->id))->distinct()->count('unit_id');
        if ($done + $firstPass > $cut) $this->fail('cutting_not_settled', $op->operation_name.'开料只登记了 '.$cut.' 套，已完成 '.$done.' 套，这次最多报 '.max(0, $cut - $done).' 套。请先在「开料」里登记切出的件和余料。');
    }

    public function assertReceivedForStart(MfgWorkOrder $wo, MfgWorkOrderOperation $op, Collection $units): void
    {
        $this->assertReceived($wo, $op, $units, forStart: true);
    }

    public function assertReceivedForCompletion(MfgWorkOrder $wo, MfgWorkOrderOperation $op, Collection $units): void
    {
        $this->assertReceived($wo, $op, $units, forStart: false);
    }

    /** 板材／管材另按实际开料可供套数校验，不要求仓库配送。 */
    private function assertReceived(MfgWorkOrder $wo, MfgWorkOrderOperation $op, Collection $units, bool $forStart): void
    {
        $materials = MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->where('participates_in_kitting', true)
            ->where('required_qty', '>', 0)->orderBy('id')->lockForUpdate()->get()->reject(fn ($m) => in_array($m->cutting_mode, ['sheet', 'length'], true))->values();
        if ($materials->isEmpty()) return;
        if ($forStart) {
            // 只在计时时允许等自制件，与是否已经开了子工单无关。
            $childItems = Item::query()->whereIn('id', $materials->pluck('component_item_id')->unique())->where('status', 'enabled')->get()
                ->filter(fn (Item $item) => WorkOrderService::isChildOrderItem($item))->keyBy('id');
            $materials = $materials->reject(fn ($m) => $childItems->has($m->component_item_id));
            if ($materials->isEmpty()) return;
        }
        $unitIds = $units->pluck('id')->map(fn ($id) => (int) $id)->all();
        CancellationProductionService::assertProductionAllowed((int) $wo->id, $unitIds);
        $reached = count($unitIds);
        $unsigned = MaterialDeliveryService::unsignedQty((int) $op->id, $unitIds);
        $missing = [];
        $waiting = [];
        foreach ($materials as $m) {
            $need = $this->neededFor($m, $wo, $reached);
            $allocated = $this->quantityForUnits($m, $wo, $unitIds);
            $have = $allocated - (float) ($unsigned[$m->id] ?? 0);
            if ($allocated + self::EPS < $need) $missing[] = $m->component_item_name.' 还差 '.$this->num($need - $allocated).($m->unit_name ?: '');
            elseif ($have + self::EPS < $need) $waiting[] = $m->component_item_name;
        }
        $action = $forStart ? '开始计时' : '完成本工序';
        if ($waiting && ! $missing) $this->fail('material_not_signed', '仓库送来的料还没签收：'.implode('、', $waiting).'。请先核对实物并签收，再'.$action.'。');
        if ($missing) $this->fail('material_not_received', $op->operation_name.'的料还没收齐：'.implode('、', $missing).'。请先收料，再'.$action.'。');
    }

    /**
     * 收料：按设备数收本道的料，从可用库存扣减。$units 为空就收齐整张工单。
     * 任何一种料库存不够就整单不收，提示缺多少。
     * 追溯件默认按先入先用自动挑编号；$selections（物料行 ID => 库存序列号 ID，按装配顺序）为人工指定的编号。
     * 收到的编号按设备号顺序登记到每台的装配记录。
     */
    public function receive(int $workOrderId, int $operationId, ?int $units, ?string $remark, object $actor, array $selections = [], ?array $unitIds = null): MfgMaterialIssue
    {
        return DB::transaction(function () use ($workOrderId, $operationId, $units, $remark, $actor, $selections, $unitIds) {
            $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($workOrderId);
            CancellationProductionService::assertProductionAllowed((int) $wo->id);
            if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true)) $this->fail('status_invalid', '工单不在生产中，不能收料。');
            $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->lockForUpdate()->find($operationId);
            if (! $op || $op->execution_context !== 'production') $this->fail('operation_invalid', '这道不是本工单的车间工序。');
            $materials = $this->receivable($op, true);
            if ($materials->isEmpty()) $this->fail('no_material', $op->operation_name.'没有需要收的料。');
            // 在本道之前就报废的设备不会再到本道，不再为它们收料。
            $planned = $this->expectedUnits($op, $wo);
            $eligible = MfgWorkOrderUnit::where('work_order_id', $wo->id)->whereIn('id', CancellationProductionService::productionUnitIds((int) $wo->id))
                ->whereNotIn('status', ['stored', 'pending_receipt', 'completed'])->orderBy('unit_no')->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($unitIds === null) {
                abort_if($units !== null && $units < count($eligible), 422, '请先选择本次收料的具体设备编号。');
                $unitIds = $eligible;
            }
            abort_unless($unitIds && !array_diff($unitIds, $eligible), 422, '本次收料编号不在可生产范围内，请刷新。');
            CancellationProductionService::assertProductionAllowed((int) $wo->id, $unitIds);
            $prepared = MaterialDeliveryService::preparedQty((int) $op->id, $unitIds);
            $done = $materials->map(fn ($m) => $this->coveredUnits($m, $wo, (float) ($prepared[$m->id] ?? 0)))->min();
            $target = $units ? min($planned, $done + max(1, $units)) : $planned;
            $plan = [];
            $short = [];
            $selections = array_filter(array_map(fn ($ids) => array_values(array_unique(array_map('intval', (array) $ids))), $selections));
            foreach ($materials as $m) {
                $allocations = [];
                foreach ($unitIds as $unitId) {
                    $preparedForUnit = MaterialDeliveryService::preparedQty((int) $op->id, [$unitId]);
                    $need = round($this->neededFor($m, $wo, 1) - $this->quantityForUnits($m, $wo, [$unitId]) - (float) ($preparedForUnit[$m->id] ?? 0), 4);
                    if ($need > self::EPS) $allocations[] = ['unit_id' => (int) $unitId, 'qty' => $need];
                }
                $qty = round(array_sum(array_column($allocations, 'qty')), 4);
                if ($qty <= self::EPS) continue;
                [$picks, $left] = isset($selections[$m->id]) ? $this->selectedPicks($m, $qty, $wo, $selections[$m->id]) : $this->allocate($m, $qty, $wo);
                if ($left > self::EPS) {
                    $short[] = $m->component_item_name.' 要 '.$this->num($qty).'，仓库可用 '.$this->num($qty - $left).($m->unit_name ?: '');
                    continue;
                }
                $plan[] = ['material' => $m, 'qty' => $qty, 'picks' => $picks, 'unit_ids' => array_column($allocations, 'unit_id'), 'unit_allocations' => $allocations];
            }
            if ($short) $this->fail('stock_short', '库存不够，这次不能收料：'.implode('、', $short).'。半成品可以在缺料处点“开半成品工单”，外购件请找采购。');
            if (! $plan) $this->fail('already_received', $prepared ? $op->operation_name.'剩下的料仓库正在配，送到后签收即可。' : $op->operation_name.'这些设备的料已经收齐了。');
            if ($extra = array_diff(array_keys($selections), collect($plan)->map(fn ($p) => (int) $p['material']->id)->all()))
                $this->fail('serial_selection_invalid', '指定编号的物料不在本次收料范围内（物料行 '.implode('、', $extra).'），请刷新后重选。');
            $issue = $this->post($wo, $op, $plan, round($target - $done, 4), $remark, $actor, $selections, $unitIds);
            $text = collect($plan)->map(fn ($p) => $p['material']->component_item_name.' '.$this->num($p['qty']).($p['material']->unit_name ?: ''))->implode('、');
            $this->workOrders->log($wo, 'material_received', $wo->status, $wo->status, "{$op->operation_name}收料（{$issue->issue_no}）：{$text}，已扣库存", $actor, ['issue_id' => $issue->id, 'operation_id' => $op->id]);
            return $issue->fresh();
        }, 5);
    }

    /**
     * 「已备料」交下道前补收本道的料：本道要做到第 $upTo 台，还没收的按 BOM 定额从库存扣（集中开料领到的件已先记为已收，这里只补不够的）。
     * 都收够了返回 null。
     */
    public function receiveUpTo(MfgWorkOrder $wo, MfgWorkOrderOperation $op, float $upTo, ?string $remark, object $actor, ?array $unitIds = null): ?MfgMaterialIssue
    {
        $materials = $this->receivable($op);
        if ($materials->isEmpty()) return null;
        $prepared = MaterialDeliveryService::preparedQty((int) $op->id);
        $done = $materials->map(fn ($m) => $this->coveredUnits($m, $wo, (float) ($prepared[$m->id] ?? 0)))->min();
        $need = (int) ceil($upTo - $done - self::EPS);
        if ($unitIds !== null) {
            $need = $materials->contains(fn ($m) => $this->quantityForUnits($m, $wo, $unitIds) + self::EPS < $this->neededFor($m, $wo, count($unitIds)));
        }
        return $need > 0 ? $this->receive((int) $wo->id, (int) $op->id, null, $remark, $actor, [], $unitIds) : null;
    }

    /**
     * 发料过账：写领料单、冲减库存、累加已领、把编号登记到设备。调用方须在事务内并已锁定工单、工序和物料行。
     * @param array<int, array{material: MfgWorkOrderMaterial, qty: float, picks: array}> $plan
     */
    public function post(MfgWorkOrder $wo, MfgWorkOrderOperation $op, array $plan, float $unitQty, ?string $remark, object $actor, array $selections = [], ?array $unitIds = null): MfgMaterialIssue
    {
        $unitIds ??= CancellationProductionService::productionUnitIds((int) $wo->id);
        CancellationProductionService::assertProductionAllowed((int) $wo->id, $unitIds);
        $issue = MfgMaterialIssue::create([
            'issue_no' => $this->numbers->next('material_issue', 'MI'), 'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id,
            'unit_qty' => $unitQty,
            'issue_lines' => array_map(fn ($p) => ['material_id' => $p['material']->id, 'component_item_id' => (int) $p['material']->component_item_id,
                'component_item_name' => $p['material']->component_item_name, 'unit_name' => $p['material']->unit_name, 'qty' => $p['qty'],
                'unit_ids' => array_values(array_map('intval', $p['unit_ids'] ?? $unitIds)),
                'unit_allocations' => $p['unit_allocations'] ?? array_map(fn ($unitId) => ['unit_id' => (int) $unitId,
                    'qty' => $p['qty'] / max(1, count($p['unit_ids'] ?? $unitIds))], $p['unit_ids'] ?? $unitIds),
                'picks' => array_map(fn ($pick) => ['balance_id' => $pick['balance_id'], 'qty' => $pick['qty'], 'serial_ids' => $pick['serial_ids']], $p['picks'])], $plan),
            'operator_legacy_id' => $this->actorId($actor), 'operator_name' => $this->actorName($actor), 'remark' => $remark ? mb_substr($remark, 0, 500) : null,
        ]);
        $allocations = [];
        foreach ($plan as $p) {
            foreach ($p['picks'] as $pick) $allocations[] = $pick + ['item_id' => (int) $p['material']->component_item_id, 'material_id' => $p['material']->id];
            $p['material']->update(['issued_qty' => round((float) $p['material']->issued_qty + $p['qty'], 4)]);
        }
        $this->inventory->postProductionMaterialIssue($issue, $wo, $allocations, $this->actorId($actor));
        app(UnitComponentService::class)->bind($wo, $issue, $plan, $selections, $actor);
        app(DispositionMaterialService::class)->refreshWorkOrder($wo);
        return $issue;
    }

    /**
     * 本工单下给下料组开的半成品工单，按半成品汇总还在做、还没入库的数量：计划 − 已入库 − 已报废（报废生效的那几件不会再来，要算进缺口）。
     * 返工工单不算（返工的件已经冻结在库存里，单独按冻结量算）。
     */
    public static function openChildQty(int $parentId): array
    {
        $links = MfgWorkOrderLink::query()->where('parent_work_order_id', $parentId)->get();
        if ($links->isEmpty()) return [];
        $children = MfgWorkOrder::query()->whereIn('id', $links->pluck('child_work_order_id'))->whereIn('status', ['planned', 'released', 'in_progress', 'paused'])
            ->where('source_type', 'work_order')
            ->whereNull('rework_of_work_order_id')->get(['id', 'planned_qty', 'stored_qty'])->keyBy('id');
        $scrapped = MfgWorkOrderUnit::query()->whereIn('work_order_id', $children->keys()->all() ?: [0])->where('status', 'scrapped')
            ->selectRaw('work_order_id, COUNT(*) AS n')->groupBy('work_order_id')->pluck('n', 'work_order_id');
        $out = [];
        foreach ($links as $link) {
            $child = $children->get($link->child_work_order_id);
            if (! $child) continue;
            $left = (float) $child->planned_qty - (float) $child->stored_qty - (float) ($scrapped[$child->id] ?? 0);
            $out[(int) $link->component_item_id] = round(($out[(int) $link->component_item_id] ?? 0) + max(0, $left), 4);
        }
        return $out;
    }

    /**
     * 人工指定编号收料的候选：本道还没收齐、启用单件追溯的料，以及可出库的在库编号（每种最多 200 个，先入库的在前）。
     */
    public function serialOptions(int $workOrderId, int $operationId): array
    {
        $wo = MfgWorkOrder::query()->findOrFail($workOrderId);
        $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->find($operationId);
        if (! $op || $op->execution_context !== 'production') $this->fail('operation_invalid', '这道不是本工单的车间工序。');
        $planned = (float) $wo->planned_qty;
        $rows = [];
        foreach ($this->receivable($op) as $m) {
            $item = Item::query()->find($m->component_item_id);
            $remaining = round((float) $m->required_qty - (float) $m->issued_qty, 4);
            if (! $item || $item->serialTrackingMode() === 'none' || $remaining <= self::EPS) continue;
            $balanceIds = $this->balancesFor($item, $wo)->filter(fn ($b) => $this->availability->availableForOutbound($b) > self::EPS)->pluck('id')->all();
            $serials = InventorySerial::query()->with(['warehouse:id,warehouse_name', 'location:id,location_code,location_name'])
                ->whereIn('inventory_balance_id', $balanceIds ?: [0])->where('item_id', $item->id)->where('serial_status', 'available')
                ->whereNotIn('id', array_keys(MaterialDeliveryService::holds()['serials']) ?: [0])
                ->orderBy('id')->limit(200)->get();
            $ms = WorkOrderQueryService::materialSpecInfo($item);
            $rows[] = ['material_id' => (int) $m->id, 'component_item_id' => (int) $item->id, 'component_item_name' => $m->component_item_name,
                'component_item_code' => $m->component_item_code, 'component_item_spec' => $ms['component_item_spec'], 'material_grade' => $ms['material_grade'],
                'specification' => $ms['specification'], 'unit_name' => $m->unit_name, 'remaining_qty' => $remaining, 'per_unit_qty' => $planned > 0 ? round((float) $m->required_qty / $planned, 4) : 0,
                'serials' => $serials->map(fn (InventorySerial $s) => ['id' => (int) $s->id, 'serial_no' => $s->serial_no, 'batch_no' => $s->batch_no,
                    'warehouse_name' => $s->warehouse?->warehouse_name, 'location_code' => $s->location?->location_code,
                    'received_at' => $s->received_at?->toDateString()])->values()->all()];
        }
        return $rows;
    }

    /** 普通收料排除下料原料；退料兼容旧领料单，仍由退料服务按原出库来源限定可退数量。 */
    public function receivable(MfgWorkOrderOperation $op, bool $lock = false, bool $forReturn = false): Collection
    {
        $query = MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->where('participates_in_kitting', true)->orderBy('id');
        if ($lock) $query->lockForUpdate();
        $rows = $query->get();
        // 加锁时同时锁物料行：收料、配料、重新拣货、发出、退料都在物料锁下读库存和配料占用，与库存过账的物料锁一致。
        $physical = Item::query()->whereIn('id', $rows->pluck('component_item_id')->unique()->sort()->values())->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()
            ->filter(fn (Item $item) => $item->materialManagementMode() === 'physical')->pluck('id')->map(fn ($id) => (int) $id)->all();
        return $rows->reject(fn ($m) => in_array((int) $m->component_item_id, $physical, true)
            || (! $forReturn && in_array($m->cutting_mode, ['sheet', 'length'], true)) || (float) $m->required_qty <= 0)->values();
    }

    /** 按物料 ID 顺序锁物料行，作为分配库存和配料占用的互斥边界（须在本事务任何普通读取之前调用）。 */
    public function lockItems(iterable $itemIds): void
    {
        $ids = collect($itemIds)->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values()->all();
        if ($ids) Item::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->pluck('id');
    }

    /** 已经到过本道的设备数：本道首次合格过的 + 现在停在本道还没合格过的。 */
    public function reachedUnits(MfgWorkOrderOperation $op): float
    {
        $allowed = CancellationProductionService::productionUnitIds((int) $op->work_order_id);
        $here = MfgWorkOrderUnit::query()->whereIn('id', $allowed)->where('current_operation_id', $op->id)->whereIn('status', TaskService::SELECTABLE)->pluck('id');
        $passed = $here->isEmpty() ? 0 : MfgUnitReport::query()->where('work_order_operation_id', $op->id)->where('result', 'passed')
            ->whereIn('unit_id', $here)->distinct()->count('unit_id');
        $done = MfgUnitReport::where('work_order_operation_id', $op->id)->where('result', 'passed')->whereIn('unit_id', $allowed)->distinct()->count('unit_id');
        return (float) $done + $here->count() - $passed;
    }

    /** 这道工序最终要做多少台设备：计划数减去在本道之前就确认报废的设备。 */
    public function expectedUnits(MfgWorkOrderOperation $op, MfgWorkOrder $wo, bool $lock = false): float
    {
        return MfgWorkOrderUnit::where('work_order_id', $wo->id)->exists()
            ? (float) count(CancellationProductionService::productionUnitIds((int) $wo->id)) : (float) $wo->planned_qty;
    }

    public function quantityForUnits(MfgWorkOrderMaterial $m, MfgWorkOrder $wo, array $unitIds): float
    {
        $legacy = OrderChangeLaborService::legacyUnitIds((int) $wo->id);
        $total = 0.0; $scopes = [];
        foreach (MfgMaterialIssue::where('work_order_id', $wo->id)->get(['id', 'issue_lines']) as $issue) {
            foreach ($issue->issue_lines as $line) {
                if ((int) $line['material_id'] !== (int) $m->id) continue;
                $cohort = array_map('intval', $line['unit_ids'] ?? $legacy);
                $scopes[(int) $issue->id] = $line;
                if (isset($line['unit_allocations'])) $total += collect($line['unit_allocations'])->whereIn('unit_id', $unitIds)->sum('qty');
                elseif ($cohort) $total += (float) $line['qty'] * count(array_intersect($cohort, $unitIds)) / count($cohort);
            }
        }
        foreach (DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
            ->where('l.work_order_id', $wo->id)->where('l.material_id', $m->id)->where('r.status', 'approved')->where('r.return_type', '!=', 'scrapped')
            ->get(['l.unit_id', 'l.issue_id', 'l.qty']) as $line) {
            $ratio = $line->unit_id !== null ? (in_array((int) $line->unit_id, $unitIds, true) ? 1 : 0)
                : DispositionMaterialService::unitRatio($scopes[(int) $line->issue_id] ?? [], $unitIds, $legacy);
            $total -= (float) $line->qty * $ratio;
        }
        foreach (['erp_mfg_cutting_run_allocations' => 'material_qty', 'erp_mfg_cutting_allocations' => 'qty'] as $table => $field) {
            foreach (DB::table($table)->where('work_order_material_id', $m->id)->get() as $allocation) {
                $cohort = OrderChangeLaborService::recordedUnitIds($allocation) ?? $legacy;
                if ($cohort) $total += (float) $allocation->$field * count(array_intersect($cohort, $unitIds)) / count($cohort);
            }
        }
        if (in_array($m->cutting_mode, ['sheet', 'length'], true)) {
            foreach (\App\Models\Erp\MfgCuttingBatch::where('work_order_id', $wo->id)->where('work_order_operation_id', $m->work_order_operation_id)
                ->whereIn('status', ['settled', 'awaiting_confirm'])->get() as $batch) {
                $completed = data_get($batch->input_lines, '0.completed_unit_ids');
                $cohort = array_map('intval', $completed ?? data_get($batch->input_lines, '0.unit_ids') ?? $legacy);
                if (!$cohort) continue;
                $qty = (float) $m->required_qty * (int) $batch->completed_unit_qty / max(1, (float) $wo->planned_qty);
                $total += $qty * count(array_intersect($cohort, $unitIds)) / count($cohort);
            }
        }
        return max(0, round($total, 4));
    }

    /** 做到第 N 台时这种料应收多少：按整单用量平均到每台，收到最后一台时按整单用量收齐。 */
    public function neededFor(MfgWorkOrderMaterial $m, MfgWorkOrder $wo, float $units): float
    {
        $planned = (float) $wo->planned_qty;
        if ($planned <= 0 || $units >= $planned - self::EPS) return (float) $m->required_qty;
        return round((float) $m->required_qty * $units / $planned, 4);
    }

    /** 已收（含 $extra，如仓库正在配的量）够做到第几台。 */
    public function coveredUnits(MfgWorkOrderMaterial $m, MfgWorkOrder $wo, float $extra = 0): float
    {
        $planned = (float) $wo->planned_qty;
        $have = (float) $m->issued_qty - DispositionMaterialService::unavailableQuantity((int) $wo->id, (int) $m->id) + $extra;
        if ($have + self::EPS >= (float) $m->required_qty) return $planned;
        return floor($have / ((float) $m->required_qty / $planned) + self::EPS);
    }

    public function receivableUnits(int $workOrderId, int $operationId, array $filters = []): array
    {
        $wo = MfgWorkOrder::findOrFail($workOrderId);
        $op = MfgWorkOrderOperation::where('work_order_id', $workOrderId)->where('execution_context', 'production')->findOrFail($operationId);
        $materials = $this->receivable($op);
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        return MfgWorkOrderUnit::where('work_order_id', $workOrderId)->whereIn('id', CancellationProductionService::productionUnitIds($workOrderId))
            ->whereNotIn('status', ['stored', 'pending_receipt', 'completed'])
            ->when($keyword !== '', fn ($q) => $q->where('serial_no', 'like', '%'.$keyword.'%'))->orderBy('unit_no')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)))
            ->through(function ($unit) use ($materials, $wo) {
                $needed = $materials->contains(fn ($m) => $this->quantityForUnits($m, $wo, [(int) $unit->id]) + self::EPS < $this->neededFor($m, $wo, 1));
                return ['id' => (int) $unit->id, 'unit_no' => (int) $unit->unit_no, 'serial_no' => $unit->serial_no,
                    'status_label' => $needed ? '可收料' : '已收齐', 'selectable' => $needed, 'disabled_reason' => $needed ? null : '本编号本道物料已收齐'];
            })->toArray();
    }

    /**
     * 从可用库存挑库存行：先挑工单仓库，再挑其他工厂仓库；按序列号管理的自动挑先入库的号。
     * 仓库已配好、还没发出的配料单占住的数量和编号不再分给别人（$exceptDeliveryId 为重新拣货时排除自己）。
     */
    public function allocate(MfgWorkOrderMaterial $m, float $qty, MfgWorkOrder $wo, ?int $exceptDeliveryId = null, array $planned = []): array
    {
        $item = Item::query()->findOrFail($m->component_item_id);
        $mode = $item->serialTrackingMode();
        $balances = $this->balancesFor($item, $wo);
        $holds = $this->deliveryHolds($exceptDeliveryId, $planned);
        $left = $qty;
        $picks = [];
        foreach ($balances as $balance) {
            if ($left <= self::EPS) break;
            $available = round($this->availability->availableForOutbound($balance) - (float) ($holds['balances'][(int) $balance->id] ?? 0), 4);
            if ($available <= self::EPS) continue;
            $take = min($available, $left);
            $serialIds = [];
            if ($mode !== 'none') {
                $serials = InventorySerial::query()->where('inventory_balance_id', $balance->id)->where('serial_status', 'available')->orderBy('id')->lockForUpdate()->pluck('id')
                    ->reject(fn ($id) => isset($holds['serials'][(int) $id]))->values();
                $unnumbered = max(0, $available - $serials->count());
                if ($mode === 'required') {
                    $take = min(floor($take + self::EPS), $serials->count());
                    $serialIds = $serials->take((int) $take)->all();
                } elseif ($take > $unnumbered + self::EPS) {
                    // 一个编号对应一整件；分数部分只能来自真实无编号库存，不能为 1.5 件扣掉两个编号。
                    $take = min($take, floor($take + self::EPS) + $unnumbered);
                    $serialIds = $serials->take((int) ceil($take - $unnumbered - self::EPS))->all();
                }
            }
            if ($take <= self::EPS) continue;
            $picks[] = ['balance_id' => (int) $balance->id, 'qty' => round($take, 4), 'serial_ids' => array_map('intval', $serialIds)];
            $left = round($left - $take, 4);
        }
        return [$picks, max(0, $left)];
    }

    /**
     * 不属于工单的扣料（集中开料切完结束）：从工厂可出库库存行按先后挑，扣掉配料单占用；只用于按数量管理、不追溯编号的料。
     * 返回 [挑中的库存行, 还差多少]。调用方须先 lockItems()。
     */
    public function freeStockPicks(Item $item, float $qty): array
    {
        $holds = MaterialDeliveryService::holds();
        $left = $qty;
        $picks = [];
        $balances = $this->availability->outboundBalanceQuery([(int) $item->id])
            ->where(fn ($q) => $q->whereNull('erp_warehouses.management_scope')->orWhere('erp_warehouses.management_scope', '!=', 'office'))
            ->orderBy('erp_inventory_balances.id')->get();
        foreach ($balances as $balance) {
            if ($left <= self::EPS) break;
            $available = round($this->availability->availableForOutbound($balance) - (float) ($holds['balances'][(int) $balance->id] ?? 0), 4);
            if ($available <= self::EPS) continue;
            $take = round(min($available, $left), 4);
            $picks[] = ['balance_id' => (int) $balance->id, 'qty' => $take];
            $left = round($left - $take, 4);
        }
        return [$picks, max(0, $left)];
    }

    /** 工厂仓库里这种料现在能扣多少（扣掉配料单占用），集中开料结束前核对用。 */
    public function freeStockQty(Item $item): float
    {
        [, $left] = $this->freeStockPicks($item, 1000000000);
        return round(1000000000 - $left, 4);
    }

    /** 人工指定编号：编号必须是本物料、在库可用、所在库存行可出库且未被订单占用；数量须与本次应收一致。 */
    public function selectedPicks(MfgWorkOrderMaterial $m, float $qty, MfgWorkOrder $wo, array $serialIds): array
    {
        $item = Item::query()->findOrFail($m->component_item_id);
        if ($item->serialTrackingMode() === 'none') $this->fail('serial_not_tracked', $m->component_item_name.' 未启用单件追溯，不能指定编号。');
        if (abs($qty - count($serialIds)) > self::EPS)
            $this->fail('serial_count_mismatch', $m->component_item_name.' 本次要收 '.$this->num($qty).($m->unit_name ?: '').'，指定了 '.count($serialIds).' 个编号，请指定 '.$this->num($qty).' 个。');
        $balances = $this->balancesFor($item, $wo)->keyBy('id');
        $serials = InventorySerial::query()->whereIn('id', $serialIds)->lockForUpdate()->get()->keyBy('id');
        $holds = MaterialDeliveryService::holds();
        $groups = [];
        foreach ($serialIds as $id) {
            $serial = $serials->get($id);
            if (! $serial || (int) $serial->item_id !== (int) $item->id || $serial->serial_status !== 'available' || ! $balances->has((int) $serial->inventory_balance_id))
                $this->fail('serial_unavailable', '编号 '.($serial?->serial_no ?: '#'.$id).' 不是 '.$m->component_item_name.' 的可用库存，请刷新后重选。');
            if (isset($holds['serials'][(int) $id])) $this->fail('serial_unavailable', '编号 '.$serial->serial_no.' 仓库已配给别的工单（配料单待发出），请改选其他编号。');
            $groups[(int) $serial->inventory_balance_id][] = (int) $id;
        }
        $picks = [];
        foreach ($groups as $balanceId => $ids) {
            if (count($ids) > $this->availability->availableForOutbound($balances->get($balanceId)) - (float) ($holds['balances'][$balanceId] ?? 0) + self::EPS)
                $this->fail('serial_unavailable', $m->component_item_name.' 所选编号所在库位可用数量不足（可能已被订单占用），请改选其他编号。');
            $picks[] = ['balance_id' => (int) $balanceId, 'qty' => (float) count($ids), 'serial_ids' => $ids];
        }
        return [$picks, 0.0];
    }

    /**
     * 核对已保存的拣货来源现在还能不能照单出库（库存行可出库、扣掉其他配料单占用后数量够、编号仍在该库存行可用）。
     * 返回问题说明，空 = 可以照单过账。$lock 为发出时锁库存行和编号。
     */
    public function verifyPicks(MfgWorkOrderMaterial $m, MfgWorkOrder $wo, array $picks, ?int $exceptDeliveryId, bool $lock = false, array $planned = []): array
    {
        $item = Item::query()->findOrFail($m->component_item_id);
        $eligible = $this->balancesFor($item, $wo)->keyBy('id');
        $holds = $this->deliveryHolds($exceptDeliveryId, $planned);
        $problems = [];
        $seenBalances = []; $seenSerials = [];
        foreach ($picks as $pick) {
            $balanceId = (int) ($pick['balance_id'] ?? 0);
            $qty = (float) ($pick['qty'] ?? 0);
            if (isset($seenBalances[$balanceId]) || ! is_finite($qty) || $qty <= 0 || abs($qty - round($qty, 4)) > 0.00000001) {
                $problems[] = $m->component_item_name.' 拣货来源重复或数量无效'; continue;
            }
            $seenBalances[$balanceId] = true;
            $balance = \App\Models\Erp\InventoryBalance::query()->with('location:id,location_code')->when($lock, fn ($q) => $q->lockForUpdate())->find($balanceId);
            $where = $balance ? (($balance->location?->location_code ?: '库位').($balance->batch_no ? ' 批次 '.$balance->batch_no : '')) : '原库存行';
            if (! $balance || ! $eligible->has($balanceId)) { $problems[] = $m->component_item_name.' '.$where.' 已停用或不能出库'; continue; }
            $available = round($this->availability->availableForOutbound($balance) - (float) ($holds['balances'][$balanceId] ?? 0), 4);
            if ($available + self::EPS < (float) $pick['qty']) $problems[] = $m->component_item_name.' '.$where.' 现在可用 '.$this->num(max(0, $available)).'，不够 '.$this->num((float) $pick['qty']);
            $ids = array_map('intval', (array) ($pick['serial_ids'] ?? []));
            $mode = $item->serialTrackingMode();
            $freeSerialCount = $mode === 'optional' ? InventorySerial::query()->where('inventory_balance_id', $balanceId)->where('item_id', $item->id)->where('serial_status', 'available')
                ->whereNotIn('id', array_keys($holds['serials']) ?: [0])->when($lock, fn ($q) => $q->lockForUpdate())->get(['id'])->count() : 0;
            if (($mode === 'none' && $ids) || ($mode === 'required' && abs($qty - count($ids)) > self::EPS)
                || ($mode === 'optional' && (count($ids) > $qty + self::EPS || $qty - count($ids) > max(0, $available - $freeSerialCount) + self::EPS)))
                $problems[] = $m->component_item_name.' 所选编号与本批数量不一致，请重新选择';
            foreach ($ids as $sid) {
                if ($sid < 1 || isset($seenSerials[$sid])) $problems[] = $m->component_item_name.' 有重复或无效编号';
                $seenSerials[$sid] = true;
            }
            if ($ids) {
                $ok = InventorySerial::query()->whereIn('id', $ids)->where('inventory_balance_id', $balanceId)->where('item_id', $item->id)->where('serial_status', 'available')
                    ->when($lock, fn ($q) => $q->lockForUpdate())->pluck('serial_no', 'id');
                $bad = array_filter($ids, fn ($id) => ! isset($ok[$id]) || isset($holds['serials'][$id]));
                if ($bad) $problems[] = $m->component_item_name.' 有 '.count($bad).' 个编号已不在 '.$where.' 或已被占用';
            }
        }
        return $problems;
    }

    /** 可出库的库存行：工厂仓库优先工单仓库，按库存行先后。 */
    private function balancesFor(Item $item, MfgWorkOrder $wo): Collection
    {
        return $this->balanceQuery($item, $wo)->get();
    }

    private function balanceQuery(Item $item, MfgWorkOrder $wo)
    {
        return $this->availability->outboundBalanceQuery([(int) $item->id])
            ->where(fn ($q) => $q->whereNull('erp_warehouses.management_scope')->orWhere('erp_warehouses.management_scope', '!=', 'office'))
            ->orderByRaw('erp_inventory_balances.warehouse_id = ? DESC', [(int) $wo->warehouse_id])->orderBy('erp_inventory_balances.id')
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate());
    }

    private function deliveryHolds(?int $exceptDeliveryId, array $planned = []): array
    {
        $holds = MaterialDeliveryService::holds($exceptDeliveryId);
        foreach ($planned['balances'] ?? [] as $id => $qty) $holds['balances'][$id] = round(($holds['balances'][$id] ?? 0) + $qty, 4);
        $holds['serials'] += $planned['serials'] ?? [];
        return $holds;
    }

    /** 分页前须投影出库资格和占用量；SQL与 availableForOutbound 同口径，保存时仍由 verifyPicks 权威核验。 */
    private function deliveryBalanceQuery(Item $item, MfgWorkOrder $wo, array $holds)
    {
        $held = '0';
        if ($holds['balances']) {
            $held = 'CASE erp_inventory_balances.id';
            foreach ($holds['balances'] as $id => $qty) $held .= ' WHEN '.(int) $id.' THEN '.number_format((float) $qty, 4, '.', '');
            $held .= ' ELSE 0 END';
        }
        $available = 'ROUND(GREATEST(0, LEAST(erp_inventory_balances.quantity_available, erp_inventory_balances.quantity_on_hand - erp_inventory_balances.quantity_locked - erp_inventory_balances.quantity_defective - erp_inventory_balances.quantity_pending) - '.$held.'), 4)';
        if ($item->serialTrackingMode() !== 'none') {
            $heldSerials = implode(',', array_map('intval', array_keys($holds['serials']))) ?: '0';
            $count = '(SELECT COUNT(*) FROM erp_inventory_serials AS ds WHERE ds.inventory_balance_id = erp_inventory_balances.id AND ds.item_id = '.(int) $item->id." AND ds.serial_status = 'available' AND ds.id NOT IN (".$heldSerials.'))';
            $available = $item->serialTrackingMode() === 'required' ? 'LEAST(FLOOR('.$available.' + 0.00001), '.$count.')'
                : 'LEAST('.$available.', FLOOR('.$available.' + 0.00001) + GREATEST(0, '.$available.' - '.$count.'))';
        }
        return $this->balanceQuery($item, $wo)
            ->when($item->materialManagementMode() === 'physical', fn ($q) => $q->whereRaw('1 = 0'))
            ->where(function ($q) use ($item) {
                $q->whereNull('erp_inventory_balances.material_lot_id')->orWhereExists(function ($lot) use ($item) {
                    $lot->selectRaw('1')->from('erp_material_lots as dl')->whereColumn('dl.id', 'erp_inventory_balances.material_lot_id')
                        ->whereColumn('dl.item_id', 'erp_inventory_balances.item_id')->whereNull('dl.configuration_id')->whereNull('dl.stage_id')
                        ->where(fn ($form) => $form->whereIn('dl.material_form', ['FULL_STOCK', 'STANDARD_LENGTH'])
                            ->when($item->item_type === 'finished_product' && $item->materialManagementMode() === 'quantity', fn ($f) => $f->orWhere('dl.material_form', 'PRODUCT')));
                });
            })->selectRaw($available.' AS delivery_available_qty')->whereRaw($available.' > 0.00001');
    }

    public function deliveryAvailability(MfgWorkOrderMaterial $m, MfgWorkOrder $wo, array $planned = [], ?int $exceptDeliveryId = null): array
    {
        $item = Item::query()->findOrFail($m->component_item_id);
        return $this->deliveryBalanceQuery($item, $wo, $this->deliveryHolds($exceptDeliveryId, $planned))->get()
            ->mapWithKeys(fn ($b) => [(int) $b->id => (float) $b->delivery_available_qty])->all();
    }

    public function deliveryCandidates(MfgWorkOrderMaterial $m, MfgWorkOrder $wo, array $filters): array
    {
        $item = Item::query()->findOrFail($m->component_item_id);
        if (isset($filters['item_id']) && (int) $filters['item_id'] !== (int) $item->id)
            $this->fail('material_mismatch', '所选物料不属于本工单用料，请重新选择。');
        $holds = $this->deliveryHolds(null);
        $query = $this->deliveryBalanceQuery($item, $wo, $holds)
            ->with(['warehouse:id,warehouse_name', 'location:id,location_code,location_name'])
            ->when($filters['location_id'] ?? null, fn ($q, $id) => $q->where('erp_inventory_balances.location_id', $id));
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $size = min(100, max(1, (int) ($filters['per_page'] ?? 10)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $identity = ['material_id' => (int) $m->id, 'item_id' => (int) $item->id, 'component_item_id' => (int) $item->id,
            'item_name' => $m->component_item_name, 'item_code' => $m->component_item_code, 'spec' => $item->spec,
            'unit_name' => $m->unit_name, 'serial_tracking_mode' => $item->serialTrackingMode()];
        $row = fn ($b) => $identity + ['balance_id' => (int) $b->id, 'inventory_balance_id' => (int) $b->id,
            'warehouse_id' => (int) $b->warehouse_id, 'warehouse_name' => $b->warehouse?->warehouse_name,
            'location_id' => (int) $b->location_id, 'location_code' => $b->location?->location_code, 'location_name' => $b->location?->location_name,
            'batch_no' => $b->batch_no, 'quantity_available' => (float) $b->delivery_available_qty];
        if (($filters['mode'] ?? 'balance') === 'serial') {
            $balance = $query->where('erp_inventory_balances.id', (int) $filters['balance_id'])->first();
            if (! $balance || $item->serialTrackingMode() === 'none') $this->fail('stock_unavailable', '本批物料已不可用，请刷新后重选。');
            $serials = InventorySerial::query()->where('inventory_balance_id', $balance->id)->where('item_id', $item->id)->where('serial_status', 'available')
                ->whereNotIn('id', array_keys($holds['serials']) ?: [0])
                ->whereNotIn('id', $filters['exclude_serial_ids'] ?? [])
                ->when($filters['serial_id'] ?? null, fn ($q, $id) => $q->where('id', $id))
                ->when($keyword !== '', fn ($q) => $q->where('serial_no', 'like', '%'.$keyword.'%'))
                ->orderBy('id')->paginate($size, ['*'], 'page', $page);
            $serials->getCollection()->transform(fn ($s) => ['id' => (int) $s->id, 'serial_no' => $s->serial_no] + $row($balance));
            return $serials->toArray();
        }
        $query->when($filters['serial_id'] ?? null, fn ($q, $id) => $q->whereHas('serials', fn ($s) => $s->where('id', $id)->where('item_id', $item->id)
            ->where('serial_status', 'available')->whereNotIn('id', array_keys($holds['serials']) ?: [0])))
            ->when($keyword !== '', fn ($q) => $q->where(fn ($w) => $w->where('erp_inventory_balances.batch_no', 'like', '%'.$keyword.'%')
                ->orWhere('erp_locations.location_code', 'like', '%'.$keyword.'%')->orWhere('erp_locations.location_name', 'like', '%'.$keyword.'%')
                ->orWhere('erp_warehouses.warehouse_name', 'like', '%'.$keyword.'%')
                ->orWhereHas('item', fn ($i) => $i->where('item_code', 'like', '%'.$keyword.'%')->orWhere('item_name', 'like', '%'.$keyword.'%')->orWhere('spec', 'like', '%'.$keyword.'%'))));
        $balances = $query->paginate($size, ['*'], 'page', $page);
        $balances->getCollection()->transform(fn ($b) => ['id' => (int) $b->id] + $row($b));
        return $balances->toArray();
    }

    private function num(float $v): string { return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.'); }
}
