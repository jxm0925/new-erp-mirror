<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\InventorySerial;
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
use App\Services\Erp\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 工序收料：车间按台数收本道要用的料（总装收半成品和外购件，下料组收原料），收料时才扣库存。
 * 本道的料没收够就不能开工；仓库没货时总装可以给下料组开半成品工单。
 * 按实物管理的板料走开料领用，不在这里收。
 */
final class MaterialIssueService
{
    use ProductionActor;

    private const EPS = 0.00001;

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly InventoryAvailabilityService $availability,
        private readonly WorkOrderService $workOrders,
        private readonly DocumentNumberService $numbers,
    ) {}

    /** 开始前检查：有新台要开工时，本道的料必须已经收够到这些台。 */
    public function assertReceivedForStart(MfgWorkOrder $wo, MfgWorkOrderOperation $op, Collection $units): void
    {
        $materials = MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->where('participates_in_kitting', true)
            ->where('required_qty', '>', 0)->orderBy('id')->lockForUpdate()->get();
        if ($materials->isEmpty()) return;
        $reached = $this->reachedUnits($op);
        $missing = [];
        foreach ($materials as $m) {
            $need = $this->neededFor($m, $wo, $reached);
            if ((float) $m->issued_qty + self::EPS < $need) $missing[] = $m->component_item_name.' 还差 '.$this->num($need - (float) $m->issued_qty).($m->unit_name ?: '');
        }
        if ($missing) $this->fail('material_not_received', $op->operation_name.'的料还没收齐：'.implode('、', $missing).'。板材和管材请先完成集中开料，其余物料请收料；仓库没货的先开半成品工单或找采购。');
    }

    /**
     * 收料：按台数收本道的料，从可用库存扣减。$units 为空就收齐整张工单。
     * 任何一种料库存不够就整单不收，提示缺多少。
     */
    public function receive(int $workOrderId, int $operationId, ?int $units, ?string $remark, object $actor): MfgMaterialIssue
    {
        return DB::transaction(function () use ($workOrderId, $operationId, $units, $remark, $actor) {
            $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($workOrderId);
            if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true)) $this->fail('status_invalid', '工单不在生产中，不能收料。');
            $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->lockForUpdate()->find($operationId);
            if (! $op || $op->execution_context !== 'production') $this->fail('operation_invalid', '这道不是本工单的车间工序。');
            $materials = $this->receivable($op, true);
            if ($materials->isEmpty()) $this->fail('no_material', $op->operation_name.'没有需要收的料。');
            $planned = (float) $wo->planned_qty;
            $done = $materials->map(fn ($m) => $this->coveredUnits($m, $wo))->min();
            $target = $units ? min($planned, $done + max(1, $units)) : $planned;
            $plan = [];
            $short = [];
            foreach ($materials as $m) {
                $want = $this->neededFor($m, $wo, $target);
                $qty = round($want - (float) $m->issued_qty, 4);
                if ($qty <= self::EPS) continue;
                [$picks, $left] = $this->allocate($m, $qty, $wo);
                if ($left > self::EPS) {
                    $short[] = $m->component_item_name.' 要 '.$this->num($qty).'，仓库可用 '.$this->num($qty - $left).($m->unit_name ?: '');
                    continue;
                }
                $plan[] = ['material' => $m, 'qty' => $qty, 'picks' => $picks];
            }
            if ($short) $this->fail('stock_short', '库存不够，这次不能收料：'.implode('、', $short).'。半成品可以在缺料处点“开半成品工单”，外购件请找采购。');
            if (! $plan) $this->fail('already_received', $op->operation_name.'这些台的料已经收齐了。');
            $issue = MfgMaterialIssue::create([
                'issue_no' => $this->numbers->next('material_issue', 'MI'), 'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id,
                'unit_qty' => round($target - $done, 4),
                'issue_lines' => array_map(fn ($p) => ['material_id' => $p['material']->id, 'component_item_id' => (int) $p['material']->component_item_id,
                    'component_item_name' => $p['material']->component_item_name, 'unit_name' => $p['material']->unit_name, 'qty' => $p['qty'],
                    'picks' => array_map(fn ($pick) => ['balance_id' => $pick['balance_id'], 'qty' => $pick['qty'], 'serial_ids' => $pick['serial_ids']], $p['picks'])], $plan),
                'operator_legacy_id' => $this->actorId($actor), 'operator_name' => $this->actorName($actor), 'remark' => $remark ? mb_substr($remark, 0, 500) : null,
            ]);
            $allocations = [];
            foreach ($plan as $p) {
                foreach ($p['picks'] as $pick) $allocations[] = $pick + ['item_id' => (int) $p['material']->component_item_id, 'material_id' => $p['material']->id];
                $p['material']->update(['issued_qty' => round((float) $p['material']->issued_qty + $p['qty'], 4)]);
            }
            $this->inventory->postProductionMaterialIssue($issue, $wo, $allocations, $this->actorId($actor));
            $text = collect($plan)->map(fn ($p) => $p['material']->component_item_name.' '.$this->num($p['qty']).($p['material']->unit_name ?: ''))->implode('、');
            $this->workOrders->log($wo, 'material_received', $wo->status, $wo->status, "{$op->operation_name}收料（{$issue->issue_no}）：{$text}，已扣库存", $actor, ['issue_id' => $issue->id, 'operation_id' => $op->id]);
            return $issue->fresh();
        }, 5);
    }

    /** 本工单下给下料组开的半成品工单，按半成品汇总还在做、还没入库的数量。 */
    public static function openChildQty(int $parentId): array
    {
        $links = MfgWorkOrderLink::query()->where('parent_work_order_id', $parentId)->get();
        if ($links->isEmpty()) return [];
        $children = MfgWorkOrder::query()->whereIn('id', $links->pluck('child_work_order_id'))->whereIn('status', ['planned', 'released', 'in_progress', 'paused'])
            ->get(['id', 'planned_qty', 'stored_qty'])->keyBy('id');
        $out = [];
        foreach ($links as $link) {
            $child = $children->get($link->child_work_order_id);
            if (! $child) continue;
            $out[(int) $link->component_item_id] = round(($out[(int) $link->component_item_id] ?? 0) + max(0, (float) $child->planned_qty - (float) $child->stored_qty), 4);
        }
        return $out;
    }

    /** 本道要收的料：参与齐套、按数量管理（板料按实物走开料，不在这里收）。 */
    private function receivable(MfgWorkOrderOperation $op, bool $lock = false): Collection
    {
        $query = MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->where('participates_in_kitting', true)->orderBy('id');
        if ($lock) $query->lockForUpdate();
        $rows = $query->get();
        $physical = Item::query()->whereIn('id', $rows->pluck('component_item_id')->unique())->get()
            ->filter(fn (Item $item) => $item->materialManagementMode() === 'physical')->pluck('id')->map(fn ($id) => (int) $id)->all();
        return $rows->reject(fn ($m) => in_array($m->cutting_mode, ['sheet', 'length'], true)
            || in_array((int) $m->component_item_id, $physical, true) || (float) $m->required_qty <= 0)->values();
    }

    /** 已经到过本道的台数：本道首次合格过的 + 现在停在本道还没合格过的。 */
    private function reachedUnits(MfgWorkOrderOperation $op): float
    {
        $here = MfgWorkOrderUnit::query()->where('current_operation_id', $op->id)->whereIn('status', TaskService::SELECTABLE)->pluck('id');
        $passed = $here->isEmpty() ? 0 : MfgUnitReport::query()->where('work_order_operation_id', $op->id)->where('result', 'passed')
            ->whereIn('unit_id', $here)->distinct()->count('unit_id');
        return (float) $op->completed_qty + $here->count() - $passed;
    }

    /** 做到第 N 台时这种料应收多少：按整单用量平均到每台，收到最后一台时按整单用量收齐。 */
    private function neededFor(MfgWorkOrderMaterial $m, MfgWorkOrder $wo, float $units): float
    {
        $planned = (float) $wo->planned_qty;
        if ($planned <= 0 || $units >= $planned - self::EPS) return (float) $m->required_qty;
        return round((float) $m->required_qty * $units / $planned, 4);
    }

    private function coveredUnits(MfgWorkOrderMaterial $m, MfgWorkOrder $wo): float
    {
        $planned = (float) $wo->planned_qty;
        if ((float) $m->issued_qty + self::EPS >= (float) $m->required_qty) return $planned;
        return floor((float) $m->issued_qty / ((float) $m->required_qty / $planned) + self::EPS);
    }

    /** 从可用库存挑库存行：先挑工单仓库，再挑其他工厂仓库；按序列号管理的自动挑先入库的号。 */
    private function allocate(MfgWorkOrderMaterial $m, float $qty, MfgWorkOrder $wo): array
    {
        $item = Item::query()->findOrFail($m->component_item_id);
        $mode = $item->serialTrackingMode();
        $balances = $this->availability->outboundBalanceQuery([(int) $item->id])
            ->where(fn ($q) => $q->whereNull('erp_warehouses.management_scope')->orWhere('erp_warehouses.management_scope', '!=', 'office'))
            ->orderByRaw('erp_inventory_balances.warehouse_id = ? DESC', [(int) $wo->warehouse_id])->orderBy('erp_inventory_balances.id')->get();
        $left = $qty;
        $picks = [];
        foreach ($balances as $balance) {
            if ($left <= self::EPS) break;
            $available = $this->availability->availableForOutbound($balance);
            if ($available <= self::EPS) continue;
            $take = min($available, $left);
            $serialIds = [];
            if ($mode !== 'none') {
                $serials = InventorySerial::query()->where('inventory_balance_id', $balance->id)->where('serial_status', 'available')->orderBy('id')->lockForUpdate()->pluck('id');
                $unnumbered = max(0, $available - $serials->count());
                if ($mode === 'required') {
                    $take = min(floor($take + self::EPS), $serials->count());
                    $serialIds = $serials->take((int) $take)->all();
                } elseif ($take > $unnumbered + self::EPS) {
                    $serialIds = $serials->take((int) ceil($take - $unnumbered - self::EPS))->all();
                }
            }
            if ($take <= self::EPS) continue;
            $picks[] = ['balance_id' => (int) $balance->id, 'qty' => round($take, 4), 'serial_ids' => array_map('intval', $serialIds)];
            $left = round($left - $take, 4);
        }
        return [$picks, max(0, $left)];
    }

    private function num(float $v): string { return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.'); }
}
