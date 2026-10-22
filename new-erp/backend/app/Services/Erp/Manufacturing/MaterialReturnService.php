<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\MfgMaterialIssue;
use App\Models\Erp\MfgMaterialReturn;
use App\Models\Erp\MfgMaterialReturnLine;
use App\Models\Erp\MfgUnitComponent;
use App\Models\Erp\MfgUnitReport;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderMaterial;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\InventoryService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 生产退料：车间把工序收料领出的料退回仓库，按原收料出库的批次和单价冲回库存、冲减工单材料成本。
 * 工人提申请、库管审核通过才入库；库管可直接退（申请 + 审核一步完成）。申请时和审核时各校验一次可退数量。
 * - surplus 余料/错料：冲减已领，按数量最多退到“已领 − 已到本道的台应用量”；
 * - defective 不良退回：冲减已领，按数量最多退到“已领 − 已入库台应用量”，退后要重新收料；
 * - scrapped 报废台拆回：不冲减已领（报废台的名额仍算已领），按数量最多退报废台的单台用量。
 * 启用单件追溯的料只能按编号退：编号须是本道收料领出、仍为生产已领状态，装在未入库的台上（报废台拆回则须在报废台上）或没登记到台。
 * 板料、管料走开料余料，不在这里退；已入库的台、已完工工单不能退。
 */
final class MaterialReturnService
{
    use ProductionActor;

    private const EPS = 0.00001;
    public const TYPES = ['surplus' => '余料/错料退回', 'defective' => '不良退回', 'scrapped' => '报废台拆回'];
    public const STATUS_LABELS = ['pending' => '待审核', 'approved' => '已入库', 'rejected' => '已驳回', 'cancelled' => '已撤回'];
    private const OPEN_WO = ['released', 'in_progress', 'paused'];
    private const ACTIVE_UNIT = ['waiting', 'in_progress', 'rework', 'completed'];

    public function __construct(
        private readonly InventoryService $inventory,
        private readonly WorkOrderService $workOrders,
        private readonly DocumentNumberService $numbers,
        private readonly MaterialIssueService $issues,
    ) {}

    /** 退料弹窗：本道每种料的可退数量（按三种退料类型）和可退编号。 */
    public function options(int $workOrderId, int $operationId): array
    {
        [$wo, $op] = $this->context($workOrderId, $operationId, false);
        $rows = [];
        foreach ($this->issues->receivable($op) as $m) {
            if ((float) $m->issued_qty <= self::EPS && ! $this->sources($wo, $m)->count()) continue;
            $item = Item::query()->find($m->component_item_id);
            $byNumber = $item && $item->serialTrackingMode() !== 'none';
            $row = ['material_id' => (int) $m->id, 'component_item_id' => (int) $m->component_item_id, 'component_item_name' => $m->component_item_name,
                'component_item_code' => $m->component_item_code, 'unit_name' => $m->unit_name, 'required_qty' => (float) $m->required_qty,
                'issued_qty' => (float) $m->issued_qty, 'by_number' => $byNumber, 'limits' => [], 'serials' => []];
            if ($byNumber) {
                $row['serials'] = array_map(fn ($s) => array_intersect_key($s, array_flip(['id', 'serial_no', 'batch_no', 'unit_serial_no', 'unit_status', 'kind'])),
                    array_values($this->serialCandidates($wo, $op, $m)));
            } else {
                foreach (array_keys(self::TYPES) as $type) $row['limits'][$type] = $this->quantityLimit($wo, $op, $m, $type);
            }
            if (! $row['serials'] && ! array_filter($row['limits'], fn ($v) => $v > self::EPS)) continue;
            $rows[] = $row;
        }
        return ['work_order_id' => (int) $wo->id, 'operation_id' => (int) $op->id, 'operation_name' => $op->operation_name,
            'types' => collect(self::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(), 'materials' => $rows];
    }

    /**
     * 提交退料申请。$lines：[{material_id, qty}]（按数量）或 [{material_id, serial_ids: []}]（按编号）。
     * $direct 为库管直接退：建单后立即审核入库，$locationId 为退入库位（空则退回原库位）。
     */
    public function request(int $workOrderId, int $operationId, string $type, array $lines, string $reason, object $actor, bool $direct = false, ?int $locationId = null): MfgMaterialReturn
    {
        return DB::transaction(function () use ($workOrderId, $operationId, $type, $lines, $reason, $actor, $direct, $locationId) {
            if (! isset(self::TYPES[$type])) $this->fail('return_type_invalid', '请选择退料类型。');
            [$wo, $op] = $this->context($workOrderId, $operationId, true);
            $rows = $this->buildLines($wo, $op, $type, $lines, null);
            $return = MfgMaterialReturn::create([
                'return_no' => $this->numbers->next('material_return', 'MRT'), 'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id,
                'return_type' => $type, 'status' => 'pending', 'reason' => mb_substr(trim($reason), 0, 500),
                'requested_by_legacy_id' => $this->actorId($actor), 'requested_by_name' => $this->actorName($actor), 'requested_at' => now(),
            ]);
            foreach ($rows as $row) MfgMaterialReturnLine::create($row + ['return_id' => $return->id, 'work_order_id' => $wo->id]);
            $this->workOrders->log($wo, 'material_return_requested', $wo->status, $wo->status,
                "{$op->operation_name}申请退料（{$return->return_no}，".self::TYPES[$type].'）：'.$this->summary($rows).'；原因：'.$return->reason, $actor, ['return_id' => $return->id]);
            if ($direct) $this->doApprove($return, $wo, $op, $locationId, '库管直接退料', $actor);
            return $return->fresh(['lines']);
        }, 5);
    }

    /**
     * 收回整张领料单（仓库配送被拒收后收回入库）：按原领料单的批次、单价、库位冲回，冲减已领，编号从台上拆下。
     * 调用方须在事务内并已锁定工单和工序。
     */
    public function recallIssue(MfgWorkOrder $wo, MfgWorkOrderOperation $op, MfgMaterialIssue $issue, string $reason, object $actor): MfgMaterialReturn
    {
        $payload = collect((array) $issue->issue_lines)->map(fn ($l) => ['material_id' => (int) $l['material_id'], 'qty' => (float) $l['qty'],
            'serial_ids' => collect((array) ($l['picks'] ?? []))->flatMap(fn ($p) => (array) ($p['serial_ids'] ?? []))->map(fn ($v) => (int) $v)->values()->all()])->values()->all();
        $rows = $this->buildLines($wo, $op, 'surplus', $payload, null, (int) $issue->id);
        $return = MfgMaterialReturn::create([
            'return_no' => $this->numbers->next('material_return', 'MRT'), 'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id,
            'return_type' => 'surplus', 'status' => 'pending', 'reason' => mb_substr(trim($reason), 0, 500),
            'requested_by_legacy_id' => $this->actorId($actor), 'requested_by_name' => $this->actorName($actor), 'requested_at' => now(),
        ]);
        foreach ($rows as $row) MfgMaterialReturnLine::create($row + ['return_id' => $return->id, 'work_order_id' => $wo->id]);
        $this->doApprove($return, $wo, $op, null, $reason, $actor, (int) $issue->id);
        return $return->fresh(['lines']);
    }

    /** 库管审核通过：再校验一次可退数量，入库冲回、冲减已领、从台上拆下编号。 */
    public function approve(int $id, ?int $locationId, ?string $remark, object $actor): MfgMaterialReturn
    {
        return DB::transaction(function () use ($id, $locationId, $remark, $actor) {
            $return = $this->lockedPending($id);
            [$wo, $op] = $this->context((int) $return->work_order_id, (int) $return->work_order_operation_id, true);
            $this->doApprove($return, $wo, $op, $locationId, $remark, $actor);
            return $return->fresh(['lines']);
        }, 5);
    }

    public function reject(int $id, string $remark, object $actor): MfgMaterialReturn
    {
        return DB::transaction(function () use ($id, $remark, $actor) {
            $return = $this->lockedPending($id);
            $return->update(['status' => 'rejected', 'reviewed_by_legacy_id' => $this->actorId($actor), 'reviewed_by_name' => $this->actorName($actor),
                'reviewed_at' => now(), 'review_remark' => mb_substr(trim($remark), 0, 500)]);
            $wo = MfgWorkOrder::query()->findOrFail($return->work_order_id);
            $this->workOrders->log($wo, 'material_return_rejected', $wo->status, $wo->status, "退料单 {$return->return_no} 被驳回：{$return->review_remark}", $actor, ['return_id' => $return->id]);
            return $return->fresh(['lines']);
        }, 5);
    }

    /** 撤回：申请人本人或有审核权限的人可撤回待审核的申请。 */
    public function cancel(int $id, object $actor, bool $canApprove): MfgMaterialReturn
    {
        return DB::transaction(function () use ($id, $actor, $canApprove) {
            $return = $this->lockedPending($id);
            if (! $canApprove && (int) $return->requested_by_legacy_id !== (int) $this->actorId($actor)) $this->fail('permission_denied', '只能撤回自己提交的退料申请。', 403);
            $return->update(['status' => 'cancelled', 'reviewed_by_legacy_id' => $this->actorId($actor), 'reviewed_by_name' => $this->actorName($actor), 'reviewed_at' => now()]);
            $wo = MfgWorkOrder::query()->findOrFail($return->work_order_id);
            $this->workOrders->log($wo, 'material_return_cancelled', $wo->status, $wo->status, "撤回退料申请 {$return->return_no}", $actor, ['return_id' => $return->id]);
            return $return->fresh(['lines']);
        }, 5);
    }

    /** 列表：审核人看全部，只有申请权限的只看自己提的；每页默认 10 条。 */
    public function paginate(array $filters, ?int $onlyRequester, bool $amounts): array
    {
        $query = MfgMaterialReturn::query()->with(['lines', 'workOrder:id,work_order_no,item_name,product_name,status', 'operation:id,operation_name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['work_order_id'] ?? null, fn ($q, $v) => $q->where('work_order_id', $v))
            ->when($onlyRequester !== null, fn ($q) => $q->where('requested_by_legacy_id', $onlyRequester))
            ->when(trim((string) ($filters['keyword'] ?? '')), function ($q, $kw) {
                $like = '%'.str_replace(['%', '_'], ['\%', '\_'], $kw).'%';
                $q->where(fn ($w) => $w->where('return_no', 'like', $like)->orWhere('requested_by_name', 'like', $like)
                    ->orWhereHas('workOrder', fn ($o) => $o->where('work_order_no', 'like', $like)->orWhere('item_name', 'like', $like))
                    ->orWhereHas('lines', fn ($l) => $l->where('component_item_name', 'like', $like)->orWhere('serial_no', 'like', $like)));
            })
            ->orderByRaw("FIELD(status, 'pending') DESC")->orderByDesc('id');
        $page = $query->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))));
        $page->getCollection()->transform(fn (MfgMaterialReturn $r) => $this->row($r, $amounts));
        return $page->toArray() + ['summary' => ['pending' => MfgMaterialReturn::query()->where('status', 'pending')
            ->when($onlyRequester !== null, fn ($q) => $q->where('requested_by_legacy_id', $onlyRequester))->count()]];
    }

    public function detail(int $id, bool $amounts): array
    {
        return $this->row(MfgMaterialReturn::query()->with(['lines', 'workOrder:id,work_order_no,item_name,product_name,status', 'operation:id,operation_name'])->findOrFail($id), $amounts);
    }

    /** 工单详情里显示的本工单退料记录（最近 20 张）。 */
    public function forWorkOrder(int $workOrderId, bool $amounts): array
    {
        return MfgMaterialReturn::query()->with(['lines', 'operation:id,operation_name'])->where('work_order_id', $workOrderId)->orderByDesc('id')->limit(20)->get()
            ->map(fn ($r) => $this->row($r, $amounts))->values()->all();
    }

    public function row(MfgMaterialReturn $r, bool $amounts): array
    {
        $locations = Location::query()->whereIn('id', $r->lines->pluck('location_id')->merge($r->lines->pluck('source_location_id'))->push($r->target_location_id)->filter()->unique())
            ->get(['id', 'location_code', 'location_name', 'warehouse_id'])->keyBy('id');
        $warehouses = Warehouse::query()->whereIn('id', $locations->pluck('warehouse_id')->unique())->pluck('warehouse_name', 'id');
        $place = fn ($id) => ($l = $locations->get($id)) ? trim(($warehouses[$l->warehouse_id] ?? '').' '.$l->location_code) : null;
        $groups = $r->lines->groupBy('material_id')->map(fn ($rows) => ['component_item_name' => $rows->first()->component_item_name,
            'unit_name' => $rows->first()->unit_name, 'qty' => round($rows->sum(fn ($l) => (float) $l->qty), 4),
            'serial_nos' => $rows->pluck('serial_no')->filter()->values()->all()])->values();
        return [
            'id' => $r->id, 'return_no' => $r->return_no, 'work_order_id' => $r->work_order_id, 'work_order_operation_id' => $r->work_order_operation_id,
            'work_order_no' => $r->workOrder?->work_order_no, 'item_name' => $r->workOrder?->item_name, 'product_name' => $r->workOrder?->product_name,
            'operation_name' => $r->operation?->operation_name, 'return_type' => $r->return_type, 'return_type_label' => self::TYPES[$r->return_type] ?? $r->return_type,
            'status' => $r->status, 'status_label' => self::STATUS_LABELS[$r->status] ?? $r->status, 'reason' => $r->reason,
            'summary' => $groups->map(fn ($g) => $g['component_item_name'].' '.$this->num($g['qty']).($g['unit_name'] ?: '').($g['serial_nos'] ? '（'.implode('、', $g['serial_nos']).'）' : ''))->implode('；'),
            'target_location' => $r->target_location_id ? $place($r->target_location_id) : null,
            'cost_amount' => $amounts && $r->status === 'approved' ? (string) $r->cost_amount : null,
            'requested_by_legacy_id' => $r->requested_by_legacy_id, 'requested_by_name' => $r->requested_by_name, 'requested_at' => $r->requested_at?->toDateTimeString(),
            'reviewed_by_name' => $r->reviewed_by_name, 'reviewed_at' => $r->reviewed_at?->toDateTimeString(), 'review_remark' => $r->review_remark,
            'lines' => $r->lines->map(fn (MfgMaterialReturnLine $l) => [
                'id' => $l->id, 'material_id' => $l->material_id, 'component_item_id' => $l->component_item_id, 'component_item_name' => $l->component_item_name,
                'unit_name' => $l->unit_name, 'qty' => (float) $l->qty, 'serial_no' => $l->serial_no, 'unit_serial_no' => $l->unit_serial_no, 'batch_no' => $l->batch_no,
                'source_location' => $place($l->source_location_id), 'location' => $l->location_id ? $place($l->location_id) : null,
                'cost_amount' => $amounts && $r->status === 'approved' ? (string) $l->cost_amount : null,
            ])->values()->all(),
        ];
    }

    private function doApprove(MfgMaterialReturn $return, MfgWorkOrder $wo, MfgWorkOrderOperation $op, ?int $locationId, ?string $remark, object $actor, ?int $onlyIssueId = null): void
    {
        $lines = MfgMaterialReturnLine::query()->where('return_id', $return->id)->orderBy('id')->lockForUpdate()->get();
        // 审核时按当前状态重算：其他已审核/待审核的退料、新的收料和报工都可能改变可退数量。
        $fresh = collect($this->buildLines($wo, $op, $return->return_type, $this->payloadOf($lines), (int) $return->id, $onlyIssueId))
            ->whereNotNull('inventory_serial_id')->keyBy('inventory_serial_id');
        // 申请后编号可能被互换到别的台，按审核时实际装的台拆下。
        foreach ($lines->whereNotNull('inventory_serial_id') as $line) {
            $now = $fresh->get($line->inventory_serial_id);
            $line->update(['component_id' => $now['component_id'] ?? null, 'unit_id' => $now['unit_id'] ?? null, 'unit_serial_no' => $now['unit_serial_no'] ?? null]);
        }
        [$warehouseId, $locationId] = $this->target($locationId);
        $return->update(['target_warehouse_id' => $warehouseId, 'target_location_id' => $locationId]);
        $document = ['type' => 'mfg_material_return', 'id' => $return->id, 'no' => $return->return_no];
        foreach ($lines->whereNotNull('component_id') as $line) {
            $component = MfgUnitComponent::query()->installed()->whereKey($line->component_id)->lockForUpdate()->first();
            if ($component) app(UnitComponentService::class)->detachForReturn($wo, $component, $actor, '退料单 '.$return->return_no, $document);
        }
        $this->inventory->postProductionMaterialReturn($return, $wo, $this->actorId($actor));
        if ($return->return_type !== 'scrapped') {
            foreach ($lines->groupBy('material_id') as $materialId => $rows) {
                $m = MfgWorkOrderMaterial::query()->lockForUpdate()->findOrFail($materialId);
                $m->update(['issued_qty' => max(0, round((float) $m->issued_qty - $rows->sum(fn ($l) => (float) $l->qty), 4))]);
            }
        }
        $return->update(['status' => 'approved', 'reviewed_by_legacy_id' => $this->actorId($actor), 'reviewed_by_name' => $this->actorName($actor),
            'reviewed_at' => now(), 'review_remark' => $remark ? mb_substr(trim($remark), 0, 500) : null]);
        $where = $locationId ? '退入 '.Location::query()->whereKey($locationId)->value('location_code') : '退回原库位';
        $after = $return->return_type === 'scrapped' ? '' : '，已冲减已领数量'.($return->return_type === 'defective' ? '，请重新收料' : '');
        $this->workOrders->log($wo, 'material_returned', $wo->status, $wo->status,
            "退料单 {$return->return_no} 已入库（{$where}）：".$this->summary($lines->map(fn ($l) => $l->toArray())->all()).$after, $actor, ['return_id' => $return->id]);
    }

    /**
     * 校验并生成退料明细。$exceptReturnId 为审核时排除本单自身占用。
     * 按数量的从最近的收料流水往前分配；按编号的每个编号一行。
     */
    private function buildLines(MfgWorkOrder $wo, MfgWorkOrderOperation $op, string $type, array $lines, ?int $exceptReturnId, ?int $onlyIssueId = null): array
    {
        $materials = $this->issues->receivable($op, true)->keyBy('id');
        $grouped = [];
        foreach ($lines as $line) {
            $id = (int) ($line['material_id'] ?? 0);
            $grouped[$id]['qty'] = round(($grouped[$id]['qty'] ?? 0) + (float) ($line['qty'] ?? 0), 4);
            $grouped[$id]['serial_ids'] = array_merge($grouped[$id]['serial_ids'] ?? [], array_map('intval', (array) ($line['serial_ids'] ?? [])));
        }
        if (! $grouped) $this->fail('return_empty', '请选择要退的料。');
        $rows = [];
        foreach ($grouped as $materialId => $want) {
            $m = $materials->get($materialId);
            if (! $m) $this->fail('return_material_invalid', '所选物料不是本道收料的料（板料、管料请走开料余料），请刷新后重选。');
            $item = Item::query()->findOrFail($m->component_item_id);
            if ($item->serialTrackingMode() !== 'none' && (! $onlyIssueId || $want['serial_ids'])) {
                $ids = array_values(array_unique($want['serial_ids']));
                if (! $ids) $this->fail('return_serial_required', $m->component_item_name.' 启用了单件追溯，请选择要退的编号。');
                $candidates = $this->serialCandidates($wo, $op, $m, $exceptReturnId, true);
                foreach ($ids as $serialId) {
                    $c = $candidates[$serialId] ?? null;
                    if ($c && $onlyIssueId && (int) $c['source']->source_id !== $onlyIssueId) $c = null;
                    if (! $c) $this->fail('return_serial_invalid', $m->component_item_name.' 的编号 #'.$serialId.' 不能退：不是本道领出的、已退过、已在其他退料申请里，或所在的台已入库。');
                    if (($type === 'scrapped') !== ($c['kind'] === 'scrapped'))
                        $this->fail('return_serial_type', '编号 '.$c['serial_no'].($c['kind'] === 'scrapped' ? ' 装在报废台上，请选“报废台拆回”。' : ' 不在报废台上，不能选“报废台拆回”。'));
                    $rows[] = $this->lineRow($m, $c['source'], 1.0) + ['inventory_serial_id' => $serialId, 'serial_no' => $c['serial_no'],
                        'unit_id' => $c['unit_id'], 'unit_serial_no' => $c['unit_serial_no'], 'component_id' => $c['component_id']];
                }
                continue;
            }
            $qty = $want['qty'];
            if ($qty <= self::EPS) $this->fail('return_qty_invalid', $m->component_item_name.' 退料数量要大于 0。');
            // 收回配送：整单按原领料单冲回，不受已开工台数限制（签收前不能开工）。
            $limit = $onlyIssueId ? $qty : $this->quantityLimit($wo, $op, $m, $type, $exceptReturnId);
            if ($qty > $limit + self::EPS) $this->fail('return_qty_exceeded', $m->component_item_name.' 最多可退 '.$this->num($limit).($m->unit_name ?: '').'（'.self::TYPES[$type].'）。');
            $left = $qty;
            foreach ($this->sources($wo, $m, $exceptReturnId, true, $onlyIssueId) as $source) {
                if ($left <= self::EPS) break;
                $take = min($left, $source['remaining']);
                if ($take <= self::EPS) continue;
                $rows[] = $this->lineRow($m, $source['item'], round($take, 4));
                $left = round($left - $take, 4);
            }
            if ($left > self::EPS) $this->fail('return_qty_exceeded', $m->component_item_name.' 原收料未退数量不足，最多可退 '.$this->num($qty - $left).($m->unit_name ?: '').'。');
        }
        return $rows;
    }

    private function lineRow(MfgWorkOrderMaterial $m, InventoryTransactionItem $source, float $qty): array
    {
        return ['material_id' => $m->id, 'component_item_id' => $m->component_item_id, 'component_item_name' => $m->component_item_name, 'unit_name' => $m->unit_name,
            'qty' => $qty, 'issue_id' => $source->source_id, 'source_transaction_item_id' => $source->id, 'source_warehouse_id' => $source->warehouse_id,
            'source_location_id' => $source->location_id, 'batch_no' => $source->batch_no,
            'unit_cost' => bcdiv(bcsub('0', (string) $source->cost_amount, 4), number_format(abs((float) $source->change_qty), 8, '.', ''), 4)];
    }

    /** 审核时把明细还原成提交格式重新校验。 */
    private function payloadOf(Collection $lines): array
    {
        return $lines->groupBy('material_id')->map(fn ($rows, $materialId) => ['material_id' => (int) $materialId,
            'qty' => round($rows->sum(fn ($l) => (float) $l->qty), 4), 'serial_ids' => $rows->pluck('inventory_serial_id')->filter()->map(fn ($v) => (int) $v)->values()->all()])->values()->all();
    }

    /**
     * 按数量的可退上限。占用 = 其他待审核申请；已审核的退料已体现在已领数量里（报废台拆回除外，单独累计）。
     */
    private function quantityLimit(MfgWorkOrder $wo, MfgWorkOrderOperation $op, MfgWorkOrderMaterial $m, string $type, ?int $exceptReturnId = null): float
    {
        $pending = fn (array $types, array $statuses) => (float) MfgMaterialReturnLine::query()->where('work_order_id', $wo->id)->where('material_id', $m->id)
            ->whereHas('materialReturn', fn ($q) => $q->whereIn('return_type', $types)->whereIn('status', $statuses)
                ->when($exceptReturnId, fn ($w, $v) => $w->where('id', '!=', $v)))->sum('qty');
        $unreturned = round($this->sources($wo, $m, $exceptReturnId)->sum('remaining'), 4);
        if ($type === 'scrapped') {
            $planned = (float) $wo->planned_qty;
            $scrapped = MfgUnitReport::query()->where('work_order_operation_id', $op->id)->whereIn('result', ['passed', 'scrap'])
                ->whereIn('unit_id', MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->where('status', 'scrapped')->select('id'))->distinct()->count('unit_id');
            $limit = $planned > 0 ? round((float) $m->required_qty * $scrapped / $planned, 4) - $pending(['scrapped'], ['pending', 'approved']) : 0;
        } else {
            $used = $type === 'surplus' ? $this->issues->reachedUnits($op)
                : (float) MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->where('status', 'stored')->count();
            $limit = (float) $m->issued_qty - $this->issues->neededFor($m, $wo, $used) - $pending(['surplus', 'defective'], ['pending']);
        }
        return max(0, round(min($limit, $unreturned), 4));
    }

    /**
     * 本工单这种料的收料出库流水（最近的在前），remaining = 原出库数量 − 已审核和待审核的退料。
     * @return Collection<int, array{item: InventoryTransactionItem, remaining: float}>
     */
    private function sources(MfgWorkOrder $wo, MfgWorkOrderMaterial $m, ?int $exceptReturnId = null, bool $lock = false, ?int $onlyIssueId = null): Collection
    {
        $issueIds = $onlyIssueId ? collect([$onlyIssueId]) : MfgMaterialIssue::query()->where('work_order_id', $wo->id)->pluck('id');
        if ($issueIds->isEmpty()) return collect();
        $query = InventoryTransactionItem::query()->where('source_type', 'mfg_material_issue')->whereIn('source_id', $issueIds)
            ->where('source_item_id', $m->id)->where('item_id', $m->component_item_id)->where('change_qty', '<', 0)->orderByDesc('id');
        if ($lock) $query->lockForUpdate();
        $items = $query->get();
        $used = MfgMaterialReturnLine::query()->whereIn('source_transaction_item_id', $items->pluck('id')->all() ?: [0])
            ->whereHas('materialReturn', fn ($q) => $q->whereIn('status', ['pending', 'approved'])->when($exceptReturnId, fn ($w, $v) => $w->where('id', '!=', $v)))
            ->selectRaw('source_transaction_item_id, SUM(qty) AS q')->groupBy('source_transaction_item_id')->pluck('q', 'source_transaction_item_id');
        return $items->map(fn ($item) => ['item' => $item, 'remaining' => max(0, round(abs((float) $item->change_qty) - (float) ($used[$item->id] ?? 0), 4))])
            ->filter(fn ($s) => $s['remaining'] > self::EPS)->values();
    }

    /**
     * 可退编号：本道收料领出、最近一次领用就是本工单、现在仍为生产已领、不在其他待审核申请里；
     * 装在未入库的台上（active）、报废台上（scrapped）或没登记到台（unbound）。键为库存序列号 ID。
     */
    private function serialCandidates(MfgWorkOrder $wo, MfgWorkOrderOperation $op, MfgWorkOrderMaterial $m, ?int $exceptReturnId = null, bool $lock = false): array
    {
        $issues = MfgMaterialIssue::query()->where('work_order_id', $wo->id)->where('work_order_operation_id', $op->id)->orderBy('id')->get();
        $picked = [];
        foreach ($issues as $issue) {
            foreach ((array) $issue->issue_lines as $line) {
                if ((int) ($line['material_id'] ?? 0) !== (int) $m->id) continue;
                foreach ((array) ($line['picks'] ?? []) as $pick) foreach ((array) ($pick['serial_ids'] ?? []) as $sid) $picked[(int) $sid] = $issue->id;
            }
        }
        if (! $picked) return [];
        $query = InventorySerial::query()->whereIn('id', array_keys($picked))->where('item_id', $m->component_item_id)->where('serial_status', 'production_consumed')->orderBy('serial_no');
        if ($lock) $query->lockForUpdate();
        $serials = $query->get();
        $lastIssue = InventorySerialEvent::query()->whereIn('inventory_serial_id', $serials->pluck('id')->all() ?: [0])->where('event_type', 'production_material_issued')
            ->selectRaw('inventory_serial_id, MAX(id) AS id')->groupBy('inventory_serial_id')->pluck('id', 'inventory_serial_id');
        $events = InventorySerialEvent::query()->whereIn('id', $lastIssue->values()->all() ?: [0])->get()->keyBy('inventory_serial_id');
        $busy = MfgMaterialReturnLine::query()->whereIn('inventory_serial_id', $serials->pluck('id')->all() ?: [0])
            ->whereHas('materialReturn', fn ($q) => $q->where('status', 'pending')->when($exceptReturnId, fn ($w, $v) => $w->where('id', '!=', $v)))
            ->pluck('inventory_serial_id')->map(fn ($v) => (int) $v)->flip();
        $components = MfgUnitComponent::query()->installed()->where('work_order_id', $wo->id)->where('component_item_id', $m->component_item_id)
            ->whereIn('component_serial_no', $serials->pluck('serial_no')->all() ?: [''])->get()->keyBy('component_serial_no');
        $units = MfgWorkOrderUnit::query()->whereIn('id', $components->pluck('unit_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $sourceItems = InventoryTransactionItem::query()->where('source_type', 'mfg_material_issue')->whereIn('source_id', array_values(array_unique($picked)))
            ->where('source_item_id', $m->id)->where('change_qty', '<', 0)->get();
        $out = [];
        foreach ($serials as $s) {
            $event = $events->get($s->id);
            if (isset($busy[(int) $s->id]) || ! $event || $event->document_type !== 'mfg_material_issue' || (int) $event->document_id !== (int) $picked[$s->id]) continue;
            $component = $components->get($s->serial_no);
            $unit = $component ? $units->get($component->unit_id) : null;
            if ($unit && ! in_array($unit->status, array_merge(self::ACTIVE_UNIT, ['scrapped']), true)) continue;
            $source = $sourceItems->first(fn ($i) => (int) $i->source_id === (int) $picked[$s->id] && (int) $i->warehouse_id === (int) $event->warehouse_id
                && (int) $i->location_id === (int) $event->location_id && (string) $i->batch_no === (string) $event->batch_no);
            if (! $source) continue;
            $out[(int) $s->id] = ['id' => (int) $s->id, 'serial_no' => $s->serial_no, 'batch_no' => $s->batch_no, 'source' => $source,
                'unit_id' => $unit?->id, 'unit_serial_no' => $unit?->serial_no, 'unit_status' => $unit?->status, 'component_id' => $component?->id,
                'kind' => ! $unit ? 'unbound' : ($unit->status === 'scrapped' ? 'scrapped' : 'active')];
        }
        return $out;
    }

    /** 退入库位：空 = 各行退回原库位；选定的须是启用的工厂仓库里启用的普通库存库位。 */
    private function target(?int $locationId): array
    {
        if (! $locationId) return [null, null];
        $location = Location::query()->find($locationId);
        $warehouse = $location ? Warehouse::query()->find($location->warehouse_id) : null;
        if (! $location || ! in_array($location->status, ['enabled', 'active'], true) || $location->location_purpose !== 'general'
            || ! $warehouse || ! in_array($warehouse->status, ['enabled', 'active'], true) || ($warehouse->management_scope && $warehouse->management_scope !== 'factory'))
            $this->fail('return_location_invalid', '退料只能退入启用的工厂仓库里启用的普通库存库位。');
        return [(int) $warehouse->id, (int) $location->id];
    }

    private function context(int $workOrderId, int $operationId, bool $lock): array
    {
        $query = MfgWorkOrder::query();
        if ($lock) $query->lockForUpdate();
        $wo = $query->findOrFail($workOrderId);
        if (! in_array($wo->status, self::OPEN_WO, true)) $this->fail('status_invalid', '工单不在生产中，不能退料（已完工的工单材料成本已结转到入库成本）。');
        $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->find($operationId);
        if (! $op || $op->execution_context !== 'production') $this->fail('operation_invalid', '这道不是本工单的车间工序。');
        return [$wo, $op];
    }

    private function lockedPending(int $id): MfgMaterialReturn
    {
        $return = MfgMaterialReturn::query()->lockForUpdate()->findOrFail($id);
        if ($return->status !== 'pending') $this->fail('status_invalid', '这张退料单已经处理过了（'.(self::STATUS_LABELS[$return->status] ?? $return->status).'）。');
        return $return;
    }

    private function summary(array $rows): string
    {
        return collect($rows)->groupBy('material_id')->map(fn ($g) => $g->first()['component_item_name'].' '.$this->num($g->sum(fn ($l) => (float) $l['qty'])).($g->first()['unit_name'] ?: '')
            .(($nos = $g->pluck('serial_no')->filter()->values()->all()) ? '（'.implode('、', $nos).'）' : ''))->implode('、');
    }

    private function num(float $v): string { return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.'); }
}
