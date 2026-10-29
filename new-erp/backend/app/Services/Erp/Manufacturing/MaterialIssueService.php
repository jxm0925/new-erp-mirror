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
use App\Services\Erp\Inventory\ProductionInventoryPosting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 工序收料：车间按设备数收本道要用的料（总装收半成品和外购件，下料组收原料），收料时才扣库存。
 * 本道的料没收够就不能开工；仓库没货时总装可以给下料组开半成品工单。
 * 按实物管理的板料走开料领用，不在这里收。
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

    /** 板材／管材按开料满足，不走收料。 */
    public static function isCutting(MfgWorkOrderMaterial $m): bool
    {
        return in_array($m->cutting_mode, ['sheet', 'length'], true);
    }

    /** 工单里用板材／管材的车间工序：这些工序在本道直接开料。 */
    public static function cuttingOperationIds(int $workOrderId): array
    {
        return MfgWorkOrderMaterial::query()->where('work_order_id', $workOrderId)->whereIn('cutting_mode', ['sheet', 'length'])
            ->where('required_qty', '>', 0)->whereNotNull('work_order_operation_id')->distinct()->pluck('work_order_operation_id')->map(fn ($id) => (int) $id)->all();
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
        $done = (int) round((float) $op->completed_qty);
        if ($done + $firstPass > $cut) $this->fail('cutting_not_settled', $op->operation_name.'开料只登记了 '.$cut.' 套，已完成 '.$done.' 套，这次最多报 '.max(0, $cut - $done).' 套。请先在「开料」里登记切出的件和余料。');
    }

    /**
     * 本道的料必须已经收够到这些台。板材／管材不在这里查：下料工序开工后在本道开料，报完成时按开料登记的套数校验。
     */
    public function assertReceivedForStart(MfgWorkOrder $wo, MfgWorkOrderOperation $op, Collection $units): void
    {
        $materials = MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->where('participates_in_kitting', true)
            ->where('required_qty', '>', 0)->orderBy('id')->lockForUpdate()->get()->reject(fn ($m) => self::isCutting($m))->values();
        if ($materials->isEmpty()) return;
        $reached = $this->reachedUnits($op);
        $unsigned = MaterialDeliveryService::unsignedQty((int) $op->id);
        $missing = [];
        $waiting = [];
        foreach ($materials as $m) {
            $need = $this->neededFor($m, $wo, $reached);
            $have = (float) $m->issued_qty - (float) ($unsigned[$m->id] ?? 0);
            if ((float) $m->issued_qty + self::EPS < $need) $missing[] = $m->component_item_name.' 还差 '.$this->num($need - (float) $m->issued_qty).($m->unit_name ?: '');
            elseif ($have + self::EPS < $need) $waiting[] = $m->component_item_name;
        }
        if ($waiting && ! $missing) $this->fail('material_not_signed', '仓库送来的料还没签收：'.implode('、', $waiting).'。请先核对实物并签收，再开工。');
        if ($missing) $this->fail('material_not_received', $op->operation_name.'的料还没收齐：'.implode('、', $missing).'。请先收料；仓库没货的先开半成品工单或找采购。');
    }

    /**
     * 收料：按设备数收本道的料，从可用库存扣减。$units 为空就收齐整张工单。
     * 任何一种料库存不够就整单不收，提示缺多少。
     * 追溯件默认按先入先用自动挑编号；$selections（物料行 ID => 库存序列号 ID，按装配顺序）为人工指定的编号。
     * 收到的编号按设备号顺序登记到每台的装配记录。
     */
    public function receive(int $workOrderId, int $operationId, ?int $units, ?string $remark, object $actor, array $selections = []): MfgMaterialIssue
    {
        return DB::transaction(function () use ($workOrderId, $operationId, $units, $remark, $actor, $selections) {
            $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($workOrderId);
            if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true)) $this->fail('status_invalid', '工单不在生产中，不能收料。');
            $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->lockForUpdate()->find($operationId);
            if (! $op || $op->execution_context !== 'production') $this->fail('operation_invalid', '这道不是本工单的车间工序。');
            $materials = $this->receivable($op, true);
            if ($materials->isEmpty()) $this->fail('no_material', $op->operation_name.'没有需要收的料。');
            // 在本道之前就报废的设备不会再到本道，不再为它们收料。
            $planned = $this->expectedUnits($op, $wo);
            $prepared = MaterialDeliveryService::preparedQty((int) $op->id);
            $done = $materials->map(fn ($m) => $this->coveredUnits($m, $wo, (float) ($prepared[$m->id] ?? 0)))->min();
            $target = $units ? min($planned, $done + max(1, $units)) : $planned;
            $plan = [];
            $short = [];
            $selections = array_filter(array_map(fn ($ids) => array_values(array_unique(array_map('intval', (array) $ids))), $selections));
            foreach ($materials as $m) {
                $want = $this->neededFor($m, $wo, $target);
                $qty = round($want - (float) $m->issued_qty - (float) ($prepared[$m->id] ?? 0), 4);
                if ($qty <= self::EPS) continue;
                [$picks, $left] = isset($selections[$m->id]) ? $this->selectedPicks($m, $qty, $wo, $selections[$m->id]) : $this->allocate($m, $qty, $wo);
                if ($left > self::EPS) {
                    $short[] = $m->component_item_name.' 要 '.$this->num($qty).'，仓库可用 '.$this->num($qty - $left).($m->unit_name ?: '');
                    continue;
                }
                $plan[] = ['material' => $m, 'qty' => $qty, 'picks' => $picks];
            }
            if ($short) $this->fail('stock_short', '库存不够，这次不能收料：'.implode('、', $short).'。半成品可以在缺料处点“开半成品工单”，外购件请找采购。');
            if (! $plan) $this->fail('already_received', $prepared ? $op->operation_name.'剩下的料仓库正在配，送到后签收即可。' : $op->operation_name.'这些设备的料已经收齐了。');
            if ($extra = array_diff(array_keys($selections), collect($plan)->map(fn ($p) => (int) $p['material']->id)->all()))
                $this->fail('serial_selection_invalid', '指定编号的物料不在本次收料范围内（物料行 '.implode('、', $extra).'），请刷新后重选。');
            $issue = $this->post($wo, $op, $plan, round($target - $done, 4), $remark, $actor, $selections);
            $text = collect($plan)->map(fn ($p) => $p['material']->component_item_name.' '.$this->num($p['qty']).($p['material']->unit_name ?: ''))->implode('、');
            $this->workOrders->log($wo, 'material_received', $wo->status, $wo->status, "{$op->operation_name}收料（{$issue->issue_no}）：{$text}，已扣库存", $actor, ['issue_id' => $issue->id, 'operation_id' => $op->id]);
            return $issue->fresh();
        }, 5);
    }

    /**
     * 发料过账：写领料单、冲减库存、累加已领、把编号登记到设备。调用方须在事务内并已锁定工单、工序和物料行。
     * @param array<int, array{material: MfgWorkOrderMaterial, qty: float, picks: array}> $plan
     */
    public function post(MfgWorkOrder $wo, MfgWorkOrderOperation $op, array $plan, float $unitQty, ?string $remark, object $actor, array $selections = []): MfgMaterialIssue
    {
        $issue = MfgMaterialIssue::create([
            'issue_no' => $this->numbers->next('material_issue', 'MI'), 'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id,
            'unit_qty' => $unitQty,
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
        app(UnitComponentService::class)->bind($wo, $issue, $plan, $selections, $actor);
        return $issue;
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
            $rows[] = ['material_id' => (int) $m->id, 'component_item_id' => (int) $item->id, 'component_item_name' => $m->component_item_name,
                'unit_name' => $m->unit_name, 'remaining_qty' => $remaining, 'per_unit_qty' => $planned > 0 ? round((float) $m->required_qty / $planned, 4) : 0,
                'serials' => $serials->map(fn (InventorySerial $s) => ['id' => (int) $s->id, 'serial_no' => $s->serial_no, 'batch_no' => $s->batch_no,
                    'warehouse_name' => $s->warehouse?->warehouse_name, 'location_code' => $s->location?->location_code,
                    'received_at' => $s->received_at?->toDateString()])->values()->all()];
        }
        return $rows;
    }

    /** 本道要收的料：参与齐套、按数量管理（板料按实物走开料，不在这里收）。 */
    public function receivable(MfgWorkOrderOperation $op, bool $lock = false): Collection
    {
        $query = MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->where('participates_in_kitting', true)->orderBy('id');
        if ($lock) $query->lockForUpdate();
        $rows = $query->get();
        // 加锁时同时锁物料行：收料、配料、重新拣货、发出、退料都在物料锁下读库存和配料占用，与库存过账的物料锁一致。
        $physical = Item::query()->whereIn('id', $rows->pluck('component_item_id')->unique()->sort()->values())->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()
            ->filter(fn (Item $item) => $item->materialManagementMode() === 'physical')->pluck('id')->map(fn ($id) => (int) $id)->all();
        return $rows->reject(fn ($m) => in_array($m->cutting_mode, ['sheet', 'length'], true)
            || in_array((int) $m->component_item_id, $physical, true) || (float) $m->required_qty <= 0)->values();
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
        $here = MfgWorkOrderUnit::query()->where('current_operation_id', $op->id)->whereIn('status', TaskService::SELECTABLE)->pluck('id');
        $passed = $here->isEmpty() ? 0 : MfgUnitReport::query()->where('work_order_operation_id', $op->id)->where('result', 'passed')
            ->whereIn('unit_id', $here)->distinct()->count('unit_id');
        return (float) $op->completed_qty + $here->count() - $passed;
    }

    /** 这道工序最终要做多少台设备：计划数减去在本道之前就确认报废的设备。 */
    public function expectedUnits(MfgWorkOrderOperation $op, MfgWorkOrder $wo): float
    {
        $planned = (float) $wo->planned_qty;
        $before = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->where('execution_context', 'production')
            ->where(fn ($q) => $q->where('sequence', '<', $op->sequence)->orWhere(fn ($w) => $w->where('sequence', $op->sequence)->where('id', '<', $op->id)))
            ->pluck('id');
        if ($before->isEmpty()) return $planned;
        $scrapped = \App\Models\Erp\MfgUnitReport::query()->where('work_order_id', $wo->id)->where('result', 'scrap')->where('review_status', 'approved')
            ->whereIn('work_order_operation_id', $before)->distinct()->count('unit_id');
        return max(0, $planned - $scrapped);
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
        $have = (float) $m->issued_qty + $extra;
        if ($have + self::EPS >= (float) $m->required_qty) return $planned;
        return floor($have / ((float) $m->required_qty / $planned) + self::EPS);
    }

    /**
     * 从可用库存挑库存行：先挑工单仓库，再挑其他工厂仓库；按序列号管理的自动挑先入库的号。
     * 仓库已配好、还没发出的配料单占住的数量和编号不再分给别人（$exceptDeliveryId 为重新拣货时排除自己）。
     */
    public function allocate(MfgWorkOrderMaterial $m, float $qty, MfgWorkOrder $wo, ?int $exceptDeliveryId = null): array
    {
        $item = Item::query()->findOrFail($m->component_item_id);
        $mode = $item->serialTrackingMode();
        $balances = $this->balancesFor($item, $wo);
        $holds = MaterialDeliveryService::holds($exceptDeliveryId);
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
                    $serialIds = $serials->take((int) ceil($take - $unnumbered - self::EPS))->all();
                }
            }
            if ($take <= self::EPS) continue;
            $picks[] = ['balance_id' => (int) $balance->id, 'qty' => round($take, 4), 'serial_ids' => array_map('intval', $serialIds)];
            $left = round($left - $take, 4);
        }
        return [$picks, max(0, $left)];
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
    public function verifyPicks(MfgWorkOrderMaterial $m, MfgWorkOrder $wo, array $picks, ?int $exceptDeliveryId, bool $lock = false): array
    {
        $item = Item::query()->findOrFail($m->component_item_id);
        $eligible = $this->balancesFor($item, $wo)->keyBy('id');
        $holds = MaterialDeliveryService::holds($exceptDeliveryId);
        $problems = [];
        foreach ($picks as $pick) {
            $balanceId = (int) ($pick['balance_id'] ?? 0);
            $balance = \App\Models\Erp\InventoryBalance::query()->with('location:id,location_code')->when($lock, fn ($q) => $q->lockForUpdate())->find($balanceId);
            $where = $balance ? (($balance->location?->location_code ?: '库位').($balance->batch_no ? ' 批次 '.$balance->batch_no : '')) : '原库存行';
            if (! $balance || ! $eligible->has($balanceId)) { $problems[] = $m->component_item_name.' '.$where.' 已停用或不能出库'; continue; }
            $available = round($this->availability->availableForOutbound($balance) - (float) ($holds['balances'][$balanceId] ?? 0), 4);
            if ($available + self::EPS < (float) $pick['qty']) $problems[] = $m->component_item_name.' '.$where.' 现在可用 '.$this->num(max(0, $available)).'，不够 '.$this->num((float) $pick['qty']);
            $ids = array_map('intval', (array) ($pick['serial_ids'] ?? []));
            if ($ids) {
                $ok = InventorySerial::query()->whereIn('id', $ids)->where('inventory_balance_id', $balanceId)->where('serial_status', 'available')
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
        return $this->availability->outboundBalanceQuery([(int) $item->id])
            ->where(fn ($q) => $q->whereNull('erp_warehouses.management_scope')->orWhere('erp_warehouses.management_scope', '!=', 'office'))
            ->orderByRaw('erp_inventory_balances.warehouse_id = ? DESC', [(int) $wo->warehouse_id])->orderBy('erp_inventory_balances.id')->get();
    }

    private function num(float $v): string { return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.'); }
}
