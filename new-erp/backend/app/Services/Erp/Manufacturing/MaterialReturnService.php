<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransactionItem;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\MfgLaborSession;
use App\Models\Erp\MfgMaterialIssue;
use App\Models\Erp\MfgMaterialDelivery;
use App\Models\Erp\MfgMaterialReturn;
use App\Models\Erp\MfgMaterialReturnDisposition;
use App\Models\Erp\MfgMaterialReturnLine;
use App\Models\Erp\MfgReceipt;
use App\Models\Erp\MfgUnitComponent;
use App\Models\Erp\MfgUnitReport;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderMaterial;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionMediaService;
use App\Services\Erp\Inventory\ProductionInventoryPosting;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 生产退料：车间把工序收料领出的料退回仓库，按原收料出库的批次和单价冲回库存、冲减工单材料成本。
 * 工人提申请、仓库实际接收才入库；直接退也必须具备入库确认权限、明确库位并完整核对编号。
 * - surplus 余料/错料：冲减已领，按数量最多退到“已领 − 已到本道的设备应用量”；
 * - defective 不良退回：冲减已领，按数量最多退到“已领 − 已入库设备应用量”，退后要重新收料；
 * - scrapped 报废设备拆回：不冲减已领（报废设备的名额仍算已领），按数量最多退报废设备的单台用量。
 * 必须按编号的料只能按编号退；可选编号的料有编号领出的按编号退、无编号领出的按数量退。编号须是本道收料领出、仍为生产已领状态，装在未入库的设备上（报废设备拆回则须在报废设备上）或没登记到设备。
 * 板料、管料走开料余料，不在这里退；已入库的设备、已完工工单不能退。
 */
final class MaterialReturnService
{
    use ProductionActor;

    private const EPS = 0.00001;
    public const TYPES = ['surplus' => '余料/错料退回', 'defective' => '不良退回', 'scrapped' => '报废设备拆回'];
    public const STATUS_LABELS = ['pending' => '待仓库确认', 'approved' => '已入库', 'rejected' => '已驳回', 'cancelled' => '已撤回'];
    public const DISPOSITIONS = ['release' => '判定可用', 'scrap' => '报废', 'supplier_return' => '退供应商'];
    /** 自制半成品的处置决定按负责人规则审核或免审，仓库接收入库后才执行。 */
    public const PLAN_ACTIONS = ['rework' => '返工', 'scrap' => '报废', 'release' => '判定可用'];
    private const OPEN_WO = ['released', 'in_progress', 'paused'];
    private const ACTIVE_UNIT = ['waiting', 'in_progress', 'rework', 'completed'];

    public function __construct(
        private readonly ProductionInventoryPosting $inventory,
        private readonly WorkOrderService $workOrders,
        private readonly DocumentNumberService $numbers,
        private readonly MaterialIssueService $issues,
    ) {}

    /** 退料弹窗：本道每种料的可退数量（按三种退料类型）和可退编号；自制半成品附不良处置可选的返工起点。 */
    public function options(int $workOrderId, int $operationId, ?int $actorId = null): array
    {
        [$wo, $op] = $this->context($workOrderId, $operationId, false);
        $rows = [];
        foreach ($this->issues->receivable($op, false, true) as $m) {
            if ((float) $m->issued_qty <= self::EPS && ! $this->sources($wo, $m)->count()) continue;
            $item = Item::query()->find($m->component_item_id);
            $mode = $item ? $item->serialTrackingMode() : 'none';
            $ms = WorkOrderQueryService::materialSpecInfo($item);
            // 可选编号的料：有编号领出的按编号退，无编号领出的按数量退，两部分分开算。
            $row = ['material_id' => (int) $m->id, 'component_item_id' => (int) $m->component_item_id, 'component_item_name' => $m->component_item_name,
                'component_item_code' => $m->component_item_code, 'component_item_spec' => $ms['component_item_spec'], 'material_grade' => $ms['material_grade'],
                'specification' => $ms['specification'], 'unit_name' => $m->unit_name, 'required_qty' => (float) $m->required_qty,
                'issued_qty' => (float) $m->issued_qty, 'serial_mode' => $mode, 'by_number' => $mode !== 'none', 'by_qty' => $mode !== 'required', 'limits' => [], 'serials' => [],
                'made' => $item && $this->isMade($item), 'rework_operations' => $item && $this->isMade($item) ? $this->reworkStarts($wo, $m)['operations'] : []];
            if ($mode !== 'none') {
                $row['serials'] = array_map(fn ($s) => array_intersect_key($s, array_flip(['id', 'serial_no', 'batch_no', 'unit_serial_no', 'unit_status', 'kind'])),
                    array_values($this->serialCandidates($wo, $op, $m)));
            }
            if ($mode !== 'required') {
                foreach (array_keys(self::TYPES) as $type) $row['limits'][$type] = $this->quantityLimit($wo, $op, $m, $type);
            }
            if (! $row['serials'] && ! array_filter($row['limits'], fn ($v) => $v > self::EPS)) continue;
            $rows[] = $row;
        }
        return ['work_order_id' => (int) $wo->id, 'operation_id' => (int) $op->id, 'operation_name' => $op->operation_name,
            'types' => collect(self::TYPES)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(), 'materials' => $rows,
            'plan_actions' => collect(self::PLAN_ACTIONS)->map(fn ($label, $value) => ['value' => $value, 'label' => $label])->values()->all(),
            'plan_needs_review' => ProductionReviewers::needsReview($wo, $actorId), 'plan_reviewers' => ProductionReviewers::names($wo)];
    }

    /** 处置免审只确定方案；普通直接退也必须完成同一次正式仓库接收。 */
    public function request(int $workOrderId, int $operationId, array $data, object $actor): MfgMaterialReturn
    {
        $this->assertPermission($actor, ['production.material_return.request', 'production.material_return.approve', 'inventory.inbound.confirm']);
        return DB::transaction(function () use ($workOrderId, $operationId, $data, $actor) {
            $type = (string) $data['return_type'];
            if (! isset(self::TYPES[$type])) $this->fail('return_type_invalid', '请选择退料类型。');
            [$wo, $op] = $this->context($workOrderId, $operationId, true);
            $rows = $this->buildLines($wo, $op, $type, $data['lines'], null);
            $plan = ! empty($data['disposition_plan']) ? $this->buildPlan($wo, $type, $rows, $data['disposition_plan'], $actor) : null;
            $return = MfgMaterialReturn::create([
                'return_no' => $this->numbers->next('material_return', 'MRT'), 'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id,
                'return_type' => $type, 'status' => 'pending', 'business_version' => 1,
                'reason' => mb_substr(trim((string) $data['reason']), 0, 500), 'disposition_plan' => $plan,
                'requested_by_legacy_id' => $this->actorId($actor), 'requested_by_name' => $this->actorName($actor), 'requested_at' => now(),
            ]);
            foreach ($rows as $row) MfgMaterialReturnLine::create($row + ['return_id' => $return->id, 'work_order_id' => $wo->id]);
            $planText = $plan ? '，处置：'.self::PLAN_ACTIONS[$plan['action']].($plan['action'] === 'rework' ? '（从'.$plan['start_operation_name'].'开始）' : '')
                .($plan['needs_review'] ? '，等 '.$plan['reviewers'].' 审核方案' : '，方案免审通过，待仓库接收') : '';
            $this->workOrders->log($wo, 'material_return_requested', $wo->status, $wo->status,
                "{$op->operation_name}申请退料（{$return->return_no}，".self::TYPES[$type].'）：'.$this->summary($rows).'；原因：'.$return->reason.$planText, $actor, ['return_id' => $return->id]);
            if (! empty($data['direct']) && ! $plan) {
                $this->assertPermission($actor, ['inventory.inbound.confirm']);
                $this->confirmPhysical($return, $data);
                $this->doApprove($return, $wo, $op, (int) $data['location_id'], '库管直接接收退料', $actor);
                $this->workOrders->refreshCompletion($wo, $actor);
            }
            return $return->fresh(['lines']);
        }, 5);
    }

    /**
     * 收回整张领料单：默认待仓库确认，完整核对且有确认权限才同动作入库。
     * 调用方须在事务内并已锁定工单和工序。
     */
    public function recallIssue(MfgWorkOrder $wo, MfgWorkOrderOperation $op, MfgMaterialIssue $issue, string $reason, object $actor, array $confirmation = []): MfgMaterialReturn
    {
        $this->assertPermission($actor, ['production.material_delivery.manage']);
        $this->assertRecallable($wo, $op, $issue);
        $payload = collect((array) $issue->issue_lines)->map(function ($l) {
            $serialIds = collect((array) ($l['picks'] ?? []))->flatMap(fn ($p) => (array) ($p['serial_ids'] ?? []))->map(fn ($v) => (int) $v)->values()->all();
            return ['material_id' => (int) $l['material_id'], 'qty' => max(0, round((float) $l['qty'] - count($serialIds), 4)), 'serial_ids' => $serialIds];
        })->values()->all();
        $rows = $this->buildLines($wo, $op, 'surplus', $payload, null, (int) $issue->id);
        $return = MfgMaterialReturn::create([
            'return_no' => $this->numbers->next('material_return', 'MRT'), 'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id,
            'return_type' => 'surplus', 'status' => 'pending', 'business_version' => 1, 'reason' => mb_substr(trim($reason), 0, 500),
            'requested_by_legacy_id' => $this->actorId($actor), 'requested_by_name' => $this->actorName($actor), 'requested_at' => now(),
        ]);
        foreach ($rows as $row) MfgMaterialReturnLine::create($row + ['return_id' => $return->id, 'work_order_id' => $wo->id]);
        if (! empty($confirmation['confirm_inbound']) && $this->hasPermission($actor, 'inventory.inbound.confirm')) {
            $this->confirmPhysical($return, $confirmation);
            $this->doApprove($return, $wo, $op, (int) $confirmation['location_id'], $reason, $actor, (int) $issue->id);
        }
        return $return->fresh(['lines']);
    }

    /** 库管审核通过：再校验一次可退数量，入库冲回、冲减已领、从设备上拆下编号。 */
    public function approve(int $id, array $data, object $actor): MfgMaterialReturn
    {
        $this->assertPermission($actor, ['inventory.inbound.confirm']);
        return DB::transaction(function () use ($id, $data, $actor) {
            // 收回确认继续使用原配送来源，且与收回动作保持配送→工单→退料的锁序。
            $delivery = MfgMaterialDelivery::query()->where('material_return_id', $id)->lockForUpdate()->first();
            $return = $this->lockedPending($id, $data);
            if (! self::receiptReady($return)) $this->fail('plan_review_required', '处置方案尚未通过生产负责人审核，不能接收入库。');
            [$wo, $op] = $this->context((int) $return->work_order_id, (int) $return->work_order_operation_id, true, (bool) $delivery);
            $issueId = null;
            if ($delivery) {
                if ($delivery->status !== 'recalled') $this->fail('delivery_status_invalid', '原配送尚未整单收回，不能确认退料。');
                $issue = MfgMaterialIssue::query()->lockForUpdate()->findOrFail($delivery->material_issue_id);
                $this->assertRecallable($wo, $op, $issue);
                if (MfgMaterialReturnLine::query()->where('return_id', $id)->where('issue_id', '!=', $issue->id)->exists())
                    $this->fail('delivery_issue_invalid', '收回退料的明细与原配送领料来源不一致。');
                $issueId = (int) $issue->id;
            }
            $this->confirmPhysical($return, $data);
            $this->doApprove($return, $wo, $op, (int) $data['location_id'], $data['remark'] ?? null, $actor, $issueId);
            if ($return->disposition_plan) $this->executePlan($return->fresh(), $wo, $actor);
            // 待审退料会挡住完工，审完再判断一次。
            $this->workOrders->refreshCompletion($wo, $actor);
            return $return->fresh(['lines']);
        }, 5);
    }

    public function reject(int $id, array $data, object $actor): MfgMaterialReturn
    {
        $this->assertPermission($actor, ['inventory.inbound.confirm']);
        $remark = trim((string) ($data['remark'] ?? ''));
        if ($remark === '') $this->fail('reject_reason_required', '请填写驳回原因。');
        return DB::transaction(function () use ($id, $data, $remark, $actor) {
            $return = $this->lockedPending($id, $data);
            if (! self::receiptReady($return)) $this->fail('plan_review_required', '处置方案尚未审核，请由生产负责人处理。');
            $return->update(['status' => 'rejected', 'reviewed_by_legacy_id' => $this->actorId($actor), 'reviewed_by_name' => $this->actorName($actor),
                'reviewed_at' => now(), 'review_remark' => mb_substr($remark, 0, 500), 'business_version' => (int) $return->business_version + 1]);
            $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($return->work_order_id);
            $this->workOrders->log($wo, 'material_return_rejected', $wo->status, $wo->status, "退料单 {$return->return_no} 被驳回：{$return->review_remark}", $actor, ['return_id' => $return->id]);
            $this->workOrders->refreshCompletion($wo, $actor);
            return $return->fresh(['lines']);
        }, 5);
    }

    /** 撤回：申请人本人或有审核权限的人可撤回待审核的申请。 */
    public function cancel(int $id, array $data, object $actor): MfgMaterialReturn
    {
        $this->assertPermission($actor, ['production.material_return.request', 'production.material_return.approve', 'inventory.inbound.confirm']);
        return DB::transaction(function () use ($id, $data, $actor) {
            $return = $this->lockedPending($id, $data);
            $canApprove = $this->hasPermission($actor, 'inventory.inbound.confirm') || $this->hasPermission($actor, 'production.material_return.approve');
            if (! $canApprove && (int) $return->requested_by_legacy_id !== (int) $this->actorId($actor)) $this->fail('permission_denied', '只能撤回自己提交的退料申请。', 403);
            $return->update(['status' => 'cancelled', 'reviewed_by_legacy_id' => $this->actorId($actor), 'reviewed_by_name' => $this->actorName($actor), 'reviewed_at' => now(),
                'business_version' => (int) $return->business_version + 1]);
            $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($return->work_order_id);
            $this->workOrders->log($wo, 'material_return_cancelled', $wo->status, $wo->status, "撤回退料申请 {$return->return_no}", $actor, ['return_id' => $return->id]);
            $this->workOrders->refreshCompletion($wo, $actor);
            return $return->fresh(['lines']);
        }, 5);
    }

    /** 列表：审核人看全部，只有申请权限的只看自己提的；每页默认 10 条。 */
    public function paginate(array $filters, ?int $onlyRequester, bool $amounts): array
    {
        $query = MfgMaterialReturn::query()->with(['lines', 'workOrder:id,work_order_no,item_name,product_name,status', 'operation:id,operation_name'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $v === 'holding' ? $this->holding($q) : ($v === 'pending' && $onlyRequester === null ? self::pendingReceipts($q) : $q->where('status', $v)))
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
        return $page->toArray() + ['summary' => ['pending' => MfgMaterialReturn::query()->when($onlyRequester === null, fn ($q) => self::pendingReceipts($q), fn ($q) => $q->where('status', 'pending'))
            ->when($onlyRequester !== null, fn ($q) => $q->where('requested_by_legacy_id', $onlyRequester))->count(),
            'holding' => $this->holding(MfgMaterialReturn::query())->when($onlyRequester !== null, fn ($q) => $q->where('requested_by_legacy_id', $onlyRequester))->count()]];
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
        $disposed = MfgMaterialReturnDisposition::query()->where('return_id', $r->id)->orderBy('id')->get()->groupBy('return_line_id');
        $heldOf = fn (MfgMaterialReturnLine $l) => $r->return_type === 'defective' && $r->status === 'approved'
            ? max(0, round((float) $l->qty - (float) ($disposed->get($l->id)?->sum(fn ($d) => (float) $d->qty) ?? 0), 4)) : 0.0;
        $groups = $r->lines->groupBy('material_id')->map(fn ($rows) => ['component_item_name' => $rows->first()->component_item_name,
            'unit_name' => $rows->first()->unit_name, 'qty' => round($rows->sum(fn ($l) => (float) $l->qty), 4),
            'serial_nos' => $rows->pluck('serial_no')->filter()->values()->all()])->values();
        return [
            'id' => $r->id, 'return_no' => $r->return_no, 'work_order_id' => $r->work_order_id, 'work_order_operation_id' => $r->work_order_operation_id,
            'work_order_no' => $r->workOrder?->work_order_no, 'item_name' => $r->workOrder?->item_name, 'product_name' => $r->workOrder?->product_name,
            'operation_name' => $r->operation?->operation_name, 'return_type' => $r->return_type, 'return_type_label' => self::TYPES[$r->return_type] ?? $r->return_type,
            'status' => $r->status, 'status_label' => $r->status === 'pending' && ! self::receiptReady($r) ? '待负责人审核方案' : (self::STATUS_LABELS[$r->status] ?? $r->status),
            'business_version' => (int) $r->business_version, 'receipt_ready' => $r->status === 'pending' && self::receiptReady($r), 'reason' => $r->reason,
            'disposition_plan' => $r->disposition_plan,
            'media' => ProductionMediaService::forRead($r->disposition_plan['media'] ?? [], $r->disposition_plan['photos'] ?? []),
            'plan_label' => self::PLAN_ACTIONS[$r->disposition_plan['action'] ?? ''] ?? '',
            'summary' => $groups->map(fn ($g) => $g['component_item_name'].' '.$this->num($g['qty']).($g['unit_name'] ?: '').($g['serial_nos'] ? '（'.implode('、', $g['serial_nos']).'）' : ''))->implode('；'),
            'target_location' => $r->target_location_id ? $place($r->target_location_id) : null,
            'cost_amount' => $amounts && $r->status === 'approved' ? (string) $r->cost_amount : null,
            'requested_by_legacy_id' => $r->requested_by_legacy_id, 'requested_by_name' => $r->requested_by_name, 'requested_at' => $r->requested_at?->toDateTimeString(),
            'reviewed_by_name' => $r->reviewed_by_name, 'reviewed_at' => $r->reviewed_at?->toDateTimeString(), 'review_remark' => $r->review_remark,
            'held_qty' => round($r->lines->sum(fn ($l) => $heldOf($l)), 4),
            'lines' => $r->lines->map(fn (MfgMaterialReturnLine $l) => [
                'held_qty' => $heldOf($l),
                'dispositions' => ($disposed->get($l->id) ?? collect())->map(fn ($d) => ['id' => $d->id, 'action' => $d->action,
                    'action_label' => self::DISPOSITIONS[$d->action] ?? $d->action, 'qty' => (float) $d->qty, 'document_no' => $d->document_no,
                    'remark' => $d->remark, 'created_by_name' => $d->created_by_name, 'created_at' => $d->created_at?->toDateTimeString()])->values()->all(),
                'id' => $l->id, 'material_id' => $l->material_id, 'component_item_id' => $l->component_item_id, 'component_item_name' => $l->component_item_name,
                'unit_name' => $l->unit_name, 'qty' => (float) $l->qty, 'serial_no' => $l->serial_no, 'unit_serial_no' => $l->unit_serial_no, 'batch_no' => $l->batch_no,
                'source_location' => $place($l->source_location_id), 'location' => $l->location_id ? $place($l->location_id) : null,
                'cost_amount' => $amounts && $r->status === 'approved' ? (string) $l->cost_amount : null,
            ])->values()->all(),
        ];
    }

    /**
     * 不良退料处置（库管）：$lines = [{line_id, action: release|scrap|supplier_return, qty}]。
     * 判定可用 = 解冻回可用库存；报废 = 解冻后按退料单价报废出库；退供应商 = 解冻后转库存质量事件，生成退供应商单（按原批次追溯采购到货）。
     * 按编号退的行一次处置 1 个。工单完工后仍可处置（只动库存，不动工单成本）。
     */
    public function dispose(int $returnId, array $data, object $actor): MfgMaterialReturn
    {
        $this->assertPermission($actor, ['production.material_return.approve']);
        return DB::transaction(function () use ($returnId, $data, $actor) {
            $lines = $data['lines'] ?? [];
            $remark = $data['remark'] ?? null;
            $workOrderId = MfgMaterialReturn::query()->findOrFail($returnId)->work_order_id;
            MfgWorkOrder::query()->whereKey($workOrderId)->lockForUpdate()->firstOrFail();
            $return = MfgMaterialReturn::query()->lockForUpdate()->findOrFail($returnId);
            $this->assertVersion($return, $data);
            if ($return->return_type !== 'defective' || $return->status !== 'approved') $this->fail('status_invalid', '只有已入库的不良退料需要处置。');
            if (! $lines) $this->fail('disposition_empty', '请选择要处置的明细。');
            if (($return->disposition_plan['action'] ?? null) === 'rework' && ($reworkId = $return->disposition_plan['rework_work_order_id'] ?? null)
                && MfgWorkOrder::query()->whereKey($reworkId)->whereIn('status', self::OPEN_WO)->exists())
                $this->fail('rework_in_progress', '这张不良退料正在返工（返工工单 '.($return->disposition_plan['rework_work_order_no'] ?? '').'），返工合格自动解冻、报废自动出库，不用在这里处置。');
            $wo = MfgWorkOrder::query()->findOrFail($return->work_order_id);
            $rows = MfgMaterialReturnLine::query()->where('return_id', $return->id)->lockForUpdate()->get()->keyBy('id');
            $texts = [];
            foreach ($lines as $input) {
                $line = $rows->get((int) ($input['line_id'] ?? 0));
                $action = (string) ($input['action'] ?? '');
                if (! $line) $this->fail('disposition_line_invalid', '处置明细不属于这张退料单，请刷新。');
                if (! isset(self::DISPOSITIONS[$action])) $this->fail('disposition_action_invalid', '请选择处置方式：判定可用、报废或退供应商。');
                $held = round((float) $line->qty - (float) MfgMaterialReturnDisposition::query()->where('return_line_id', $line->id)->sum('qty'), 4);
                $qty = $line->inventory_serial_id ? 1.0 : round((float) ($input['qty'] ?? $held), 4);
                if ($qty <= self::EPS || $qty > $held + self::EPS)
                    $this->fail('disposition_qty_invalid', $line->component_item_name.($line->serial_no ? ' '.$line->serial_no : '').' 待处置 '.$this->num(max(0, $held)).($line->unit_name ?: '').'，处置数量不对。');
                // 退供应商先解冻回可用，再由库存质量事件冻结为待退供应商。
                $disposition = $this->disposeLine($return, $wo, $line, $action, $qty, $remark, $actor);
                if ($action === 'supplier_return') {
                    $balance = \App\Models\Erp\InventoryBalance::query()->where('item_id', $line->component_item_id)->where('warehouse_id', $line->warehouse_id)
                        ->where('location_id', $line->location_id)->where('batch_no', $line->batch_no)->firstOrFail();
                    $event = app(\App\Services\Erp\InventoryQualityApplicationService::class)->create([
                        'inventory_balance_id' => $balance->id, 'serial_no' => $line->serial_no, 'issue_qty' => $qty, 'issue_category' => 'other',
                        'issue_description' => mb_substr('生产不良退料 '.$return->return_no.'（工单 '.$wo->work_order_no.'）：'.$return->reason.($remark ? '；'.$remark : ''), 0, 1000),
                        'handling_method' => 'return_supplier', 'responsible_party' => 'supplier', 'remark' => '由生产不良退料处置生成',
                    ], $this->actorId($actor), $this->actorName($actor));
                    $disposition->fill(['document_type' => 'inventory_quality_event', 'document_id' => $event->id,
                        'document_no' => trim($event->event_no.' / '.($event->business_doc_no ?? ''), ' /')]);
                }
                $disposition->save();
                $texts[] = $line->component_item_name.($line->serial_no ? ' '.$line->serial_no : ' '.$this->num($qty).($line->unit_name ?: '')).' '.self::DISPOSITIONS[$action]
                    .($action === 'supplier_return' ? '（'.$disposition->document_no.'）' : '');
            }
            $this->workOrders->log($wo, 'material_defect_disposed', $wo->status, $wo->status, "不良退料 {$return->return_no} 处置：".implode('、', $texts).($remark ? '；'.$remark : ''), $actor, ['return_id' => $return->id]);
            $return->update(['business_version' => (int) $return->business_version + 1]);
            return $return->fresh(['lines']);
        }, 5);
    }

    /**
     * 负责人只决定方案，仓库接收之后才有可供返工/报废/解冻的冻结库存。
     */
    public function reviewPlan(int $id, array $data, object $actor, bool $hasApprovePermission = false): MfgMaterialReturn
    {
        $this->assertPermission($actor, ['production.task.execute', 'production.scrap.approve']);
        return DB::transaction(function () use ($id, $data, $actor, $hasApprovePermission) {
            $approve = (bool) $data['approve'];
            $remark = trim((string) ($data['remark'] ?? ''));
            $return = $this->lockedPending($id, $data);
            if (! $return->disposition_plan) $this->fail('status_invalid', '这张退料单没有不良处置，请库管在电脑端审核。');
            if (self::receiptReady($return)) $this->fail('status_invalid', '处置方案已经通过，待仓库接收，不能重复审核。');
            [$wo, $op] = $this->context((int) $return->work_order_id, (int) $return->work_order_operation_id, true);
            $ids = ProductionReviewers::for($wo);
            if (! ($hasApprovePermission && $this->hasPermission($actor, 'production.scrap.approve')) && ! in_array((int) $this->actorId($actor), $ids, true))
                $this->fail('permission_denied', '只有这张工单的生产负责人'.($ids ? '（'.ProductionReviewers::names($wo).'）' : '').'可以审核不良处置。', 403);
            $plan = array_replace($return->disposition_plan, ['review_status' => $approve ? 'approved' : 'rejected',
                'reviewed_by_legacy_id' => $this->actorId($actor), 'reviewed_by_name' => $this->actorName($actor),
                'reviewed_at' => now()->format('Y-m-d H:i:s'), 'review_remark' => mb_substr($remark, 0, 500) ?: null]);
            $return->update(['disposition_plan' => $plan, 'status' => $approve ? 'pending' : 'rejected', 'business_version' => (int) $return->business_version + 1]);
            if (! $approve) {
                $this->workOrders->log($wo, 'material_return_rejected', $wo->status, $wo->status, "不良处置 {$return->return_no} 被驳回".($remark ? '：'.$remark : '').'，料还在'.$op->operation_name, $actor, ['return_id' => $return->id]);
            } else {
                $this->workOrders->log($wo, 'material_return_plan_approved', $wo->status, $wo->status,
                    "不良处置 {$return->return_no} 方案通过，待仓库确认入库后执行".($remark ? '：'.$remark : ''), $actor, ['return_id' => $return->id]);
            }
            $this->workOrders->refreshCompletion($wo, $actor);
            return $return->fresh(['lines']);
        }, 5);
    }

    /** 待我审核的不良处置（小程序）：我是审核人的工单，或我有报废确认权限。 */
    public function pendingPlans(?int $actorId, bool $hasApprovePermission): array
    {
        return MfgMaterialReturn::query()->with(['lines', 'workOrder', 'operation:id,operation_name'])->where('status', 'pending')->whereNotNull('disposition_plan')
            ->where(fn ($q) => $q->where('disposition_plan->review_status', 'pending')
                ->orWhere(fn ($legacy) => $legacy->whereNull('disposition_plan->review_status')->where('disposition_plan->needs_review', true)))
            ->orderBy('id')->limit(50)->get()
            ->filter(fn ($r) => $r->workOrder && ($hasApprovePermission || in_array((int) $actorId, ProductionReviewers::for($r->workOrder), true)))
            ->map(fn ($r) => $this->row($r, false) + ['plan' => $r->disposition_plan, 'plan_label' => self::PLAN_ACTIONS[$r->disposition_plan['action'] ?? ''] ?? ''])->values()->all();
    }

    /**
     * 返工工单的一件处理完：最后一道合格（release）= 冻结的不良件解冻回可用库存；报废（scrap）= 冻结的不良件报废出库，总装出现缺口可以补单。
     * 调用方须在事务内并已锁定返工工单。
     */
    public function settleReworkUnit(MfgWorkOrder $reworkWo, MfgWorkOrderUnit $unit, string $mode, object $actor): void
    {
        $return = MfgMaterialReturn::query()->where('disposition_plan->rework_work_order_id', (int) $reworkWo->id)->lockForUpdate()->first();
        if (! $return) return;
        $lineId = (int) (($return->disposition_plan['units'] ?? [])[(string) $unit->id] ?? 0);
        $lines = MfgMaterialReturnLine::query()->where('return_id', $return->id)->orderBy('id')->lockForUpdate()->get();
        $held = fn ($l) => round((float) $l->qty - (float) MfgMaterialReturnDisposition::query()->where('return_line_id', $l->id)->sum('qty'), 4);
        $line = $lines->firstWhere('id', $lineId);
        if (! $line || $held($line) < 1 - self::EPS) $line = $lines->first(fn ($l) => $held($l) >= 1 - self::EPS);
        if (! $line) return;
        $parent = MfgWorkOrder::query()->findOrFail($return->work_order_id);
        $text = $mode === 'scrap' ? "返工工单 {$reworkWo->work_order_no} 的 {$unit->serial_no} 报废" : "返工工单 {$reworkWo->work_order_no} 的 {$unit->serial_no} 返工合格";
        $this->disposeLine($return, $parent, $line, $mode, 1.0, $text, $actor, ['type' => 'mfg_work_order', 'id' => $reworkWo->id, 'no' => $reworkWo->work_order_no]);
        $return->update(['business_version' => (int) $return->business_version + 1]);
        $this->workOrders->log($parent, 'material_defect_disposed', $parent->status, $parent->status, $text.'，不良退料 '.$return->return_no.' 的 '
            .$line->component_item_name.($line->serial_no ? ' '.$line->serial_no : ' 1'.($line->unit_name ?: '')).($mode === 'scrap' ? ' 报废出库，缺口出来了可以补开半成品工单' : ' 已解冻回库存，请重新收料'), $actor, ['return_id' => $return->id]);
    }

    /** 本工单已入库冻结、还没处置完的不良退料数量（含返工中的），按物料汇总：这部分不算缺口，防止重复补单。 */
    public static function heldDefectiveQty(int $workOrderId): array
    {
        return DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
            ->where('r.work_order_id', $workOrderId)->where('r.return_type', 'defective')->where('r.status', 'approved')
            ->selectRaw('l.component_item_id, SUM(l.qty - COALESCE((SELECT SUM(d.qty) FROM erp_mfg_material_return_dispositions d WHERE d.return_line_id = l.id), 0)) AS q')
            ->groupBy('l.component_item_id')->pluck('q', 'component_item_id')->map(fn ($v) => max(0, round((float) $v, 4)))->filter(fn ($v) => $v > self::EPS)->all();
    }

    /** 校验员工选的不良处置。返工只用于本厂工单做的自制半成品，起点从原半成品工单的车间工序里选（开料、下料除外）。 */
    private function buildPlan(MfgWorkOrder $wo, string $type, array $rows, array $input, object $actor): array
    {
        if ($type !== 'defective') $this->fail('plan_invalid', '只有不良退回可以选处置方式。');
        $action = (string) ($input['action'] ?? '');
        if (! isset(self::PLAN_ACTIONS[$action])) $this->fail('plan_invalid', '请选择处置方式：返工、报废或判定可用。');
        $needsReview = ProductionReviewers::needsReview($wo, $this->actorId($actor));
        $photos = array_values(array_slice(array_filter((array) ($input['photos'] ?? []), fn ($p) => is_string($p) && $p !== ''), 0, 9));
        $media = app(ProductionMediaService::class)->normalize($input['media'] ?? [], $actor, 'disposition');
        if ($action === 'scrap' && ! $needsReview && ! $photos && ! collect($media)->contains('type', 'image')) $this->fail('photo_required', '报废方案免审通过，请至少拍 1 张照片留存。');
        $plan = ['action' => $action, 'photos' => $photos, 'media' => $media, 'needs_review' => $needsReview, 'reviewers' => ProductionReviewers::names($wo),
            'review_status' => $needsReview ? 'pending' : 'approved', 'reviewed_by_legacy_id' => $needsReview ? null : $this->actorId($actor),
            'reviewed_by_name' => $needsReview ? null : $this->actorName($actor), 'reviewed_at' => $needsReview ? null : now()->format('Y-m-d H:i:s'),
            'review_remark' => $needsReview ? null : '按生产负责人规则免审通过'];
        $materials = collect($rows)->pluck('material_id')->unique();
        foreach ($materials as $materialId) {
            $item = Item::query()->find(collect($rows)->firstWhere('material_id', $materialId)['component_item_id']);
            if (! $item || ! $this->isMade($item)) $this->fail('plan_invalid', ($item?->item_name ?: '这种料').' 不是自制半成品，不能在这里选处置，请提交后由库管处置（判定可用、报废或退供应商）。');
        }
        if ($action !== 'rework') return $plan;
        if ($materials->count() !== 1) $this->fail('plan_invalid', '返工一次只能退一种半成品，请分开提交。');
        $qty = collect($rows)->sum(fn ($r) => (float) $r['qty']);
        if (abs($qty - round($qty)) > self::EPS || $qty < 1) $this->fail('plan_invalid', '返工按件数做，退回数量要是整数。');
        $m = MfgWorkOrderMaterial::query()->findOrFail($materials->first());
        $starts = $this->reworkStarts($wo, $m);
        if (! $starts['source']) $this->fail('plan_invalid', $m->component_item_name.' 不是本厂工单做的（期初或外购库存），不能返工，请选报废或判定可用。');
        $start = collect($starts['operations'])->firstWhere('sequence', (int) ($input['start_sequence'] ?? 0));
        if (! $start) $this->fail('plan_invalid', '请选从哪道工序开始返工（开料、下料不能返工）。');
        return $plan + ['source_work_order_id' => $starts['source']->id, 'source_work_order_no' => $starts['source']->work_order_no,
            'start_sequence' => (int) $start['sequence'], 'start_operation_name' => $start['operation_name']];
    }

    /** 执行不良处置（退料已入库冻结）：判定可用 / 报废 直接处置；返工开返工工单，冻结的件等返工完再解冻或报废。 */
    private function executePlan(MfgMaterialReturn $return, MfgWorkOrder $wo, object $actor): void
    {
        if ($return->status !== 'approved' || ! $return->inventory_transaction_id || ! self::receiptReady($return))
            $this->fail('plan_inventory_required', '处置方案必须在仓库确认入库并冻结后执行。');
        $plan = $return->disposition_plan;
        $lines = MfgMaterialReturnLine::query()->where('return_id', $return->id)->orderBy('id')->lockForUpdate()->get();
        if ($plan['action'] !== 'rework') {
            foreach ($lines as $line) $this->disposeLine($return, $wo, $line, $plan['action'], (float) $line->qty, '不良处置：'.self::PLAN_ACTIONS[$plan['action']], $actor);
            $this->workOrders->log($wo, 'material_defect_disposed', $wo->status, $wo->status, "不良退料 {$return->return_no} ".self::PLAN_ACTIONS[$plan['action']].'：'
                .$this->summary($lines->map(fn ($l) => $l->toArray())->all()).($plan['action'] === 'scrap' ? '，已报废出库，缺口出来了可以补开半成品工单' : '，已解冻回库存，请重新收料'), $actor, ['return_id' => $return->id]);
            return;
        }
        [$child, $map] = $this->workOrders->createRework($wo, $return, $plan, $lines, $actor);
        $return->update(['disposition_plan' => $plan + ['rework_work_order_id' => (int) $child->id, 'rework_work_order_no' => $child->work_order_no, 'units' => $map]]);
    }

    /** 处置一行冻结的不良退料：release 解冻回可用，scrap 报废出库，supplier_return 转退供应商。 */
    private function disposeLine(MfgMaterialReturn $return, MfgWorkOrder $wo, MfgMaterialReturnLine $line, string $action, float $qty, ?string $remark, object $actor, ?array $document = null): MfgMaterialReturnDisposition
    {
        $disposition = MfgMaterialReturnDisposition::create([
            'return_id' => $return->id, 'return_line_id' => $line->id, 'work_order_id' => $wo->id, 'item_id' => $line->component_item_id,
            'action' => $action, 'qty' => $qty, 'inventory_serial_id' => $line->inventory_serial_id, 'document_type' => $document['type'] ?? 'mfg_material_return',
            'document_id' => $document['id'] ?? $return->id, 'document_no' => $document['no'] ?? $return->return_no, 'remark' => $remark ? mb_substr(trim($remark), 0, 500) : null,
            'created_by_legacy_id' => $this->actorId($actor), 'created_by_name' => $this->actorName($actor),
        ]);
        $this->inventory->postProductionDefectDisposition($disposition, $line, $wo, $action === 'scrap' ? 'scrap' : 'release', $this->actorId($actor));
        return $disposition;
    }

    /** 不良半成品可以从哪道开始返工：按退料来源批次找到原半成品工单，列它的车间工序（开料、下料要重新耗原料，除外）。 */
    private function reworkStarts(MfgWorkOrder $wo, MfgWorkOrderMaterial $m): array
    {
        $batches = $this->sources($wo, $m)->map(fn ($s) => (string) $s['item']->batch_no)->filter()->unique()->values();
        if ($batches->isEmpty()) $batches = InventoryTransactionItem::query()->where('source_type', 'mfg_material_issue')
            ->whereIn('source_id', MfgMaterialIssue::query()->where('work_order_id', $wo->id)->select('id'))->where('source_item_id', $m->id)
            ->where('change_qty', '<', 0)->orderByDesc('id')->pluck('batch_no')->filter()->unique()->values();
        $source = $batches->isEmpty() ? null : MfgWorkOrder::query()->where('item_id', $m->component_item_id)->whereIn('work_order_no', $batches)
            ->whereNull('rework_of_work_order_id')->where('status', '!=', 'cancelled')->orderByDesc('id')->first();
        if (! $source) return ['source' => null, 'operations' => []];
        $skip = array_merge(MaterialIssueService::cuttingOperationIds((int) $source->id), MaterialIssueService::prepOperationIds((int) $source->id));
        $ops = MfgWorkOrderOperation::query()->where('work_order_id', $source->id)->where('execution_context', 'production')->orderBy('sequence')->orderBy('id')->get()
            ->reject(fn ($o) => in_array((int) $o->id, $skip, true))
            ->map(fn ($o) => ['sequence' => (int) $o->sequence, 'operation_name' => $o->operation_name])->unique('sequence')->values()->all();
        return ['source' => $source, 'operations' => $ops];
    }

    private function isMade(Item $item): bool
    {
        return in_array($item->item_type, ['semi_finished', 'finished_product'], true) && ! $item->isPurchaseFulfilledItem();
    }

    /** 还有冻结待处置数量的不良退料单。 */
    private function holding($query)
    {
        return $query->where('return_type', 'defective')->where('status', 'approved')
            ->whereRaw('EXISTS (SELECT 1 FROM erp_mfg_material_return_lines l WHERE l.return_id = erp_mfg_material_returns.id AND l.qty > (SELECT COALESCE(SUM(d.qty), 0) FROM erp_mfg_material_return_dispositions d WHERE d.return_line_id = l.id) + 0.00001)');
    }

    private function doApprove(MfgMaterialReturn $return, MfgWorkOrder $wo, MfgWorkOrderOperation $op, ?int $locationId, ?string $remark, object $actor, ?int $onlyIssueId = null): void
    {
        $lines = MfgMaterialReturnLine::query()->where('return_id', $return->id)->orderBy('id')->lockForUpdate()->get();
        // 审核时按当前状态重算：其他已审核/待审核的退料、新的收料和报工都可能改变可退数量。
        $fresh = collect($this->buildLines($wo, $op, $return->return_type, $this->payloadOf($lines), (int) $return->id, $onlyIssueId))
            ->whereNotNull('inventory_serial_id')->keyBy('inventory_serial_id');
        // 申请后编号可能被互换到别的设备，按审核时实际装的设备拆下。
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
            'reviewed_at' => now(), 'review_remark' => $remark ? mb_substr(trim($remark), 0, 500) : null,
            'business_version' => (int) $return->business_version + 1]);
        $where = $locationId ? '退入 '.Location::query()->whereKey($locationId)->value('location_code') : '退回原库位';
        $after = $return->return_type === 'scrapped' ? '' : '，已冲减已领数量'.($return->return_type === 'defective' ? '，不良料已冻结待库管处置，请重新收料' : '');
        $this->workOrders->log($wo, 'material_returned', $wo->status, $wo->status,
            "退料单 {$return->return_no} 已入库（{$where}）：".$this->summary($lines->map(fn ($l) => $l->toArray())->all()).$after, $actor, ['return_id' => $return->id]);
    }

    /**
     * 校验并生成退料明细。$exceptReturnId 为审核时排除本单自身占用。
     * 按数量的从最近的收料流水往前分配；按编号的每个编号一行。
     */
    private function buildLines(MfgWorkOrder $wo, MfgWorkOrderOperation $op, string $type, array $lines, ?int $exceptReturnId, ?int $onlyIssueId = null): array
    {
        $materials = $this->issues->receivable($op, true, true)->keyBy('id');
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
            $mode = $item->serialTrackingMode();
            $ids = array_values(array_unique($want['serial_ids']));
            $numbered = 0;
            if ($mode !== 'none' && $ids) {
                $candidates = $this->serialCandidates($wo, $op, $m, $exceptReturnId, true, (bool) $onlyIssueId);
                foreach ($ids as $serialId) {
                    $c = $candidates[$serialId] ?? null;
                    if ($c && $onlyIssueId && (int) $c['source']->source_id !== $onlyIssueId) $c = null;
                    if (! $c) $this->fail('return_serial_invalid', $m->component_item_name.' 的编号 #'.$serialId.' 不能退：不是本道领出的、已退过、已在其他退料申请里，或所在的设备已入库。');
                    if (($type === 'scrapped') !== ($c['kind'] === 'scrapped'))
                        $this->fail('return_serial_type', '编号 '.$c['serial_no'].($c['kind'] === 'scrapped' ? ' 装在报废设备上，请选“报废设备拆回”。' : ' 不在报废设备上，不能选“报废设备拆回”。'));
                    $rows[] = $this->lineRow($m, $c['source'], 1.0) + ['inventory_serial_id' => $serialId, 'serial_no' => $c['serial_no'],
                        'unit_id' => $c['unit_id'], 'unit_serial_no' => $c['unit_serial_no'], 'component_id' => $c['component_id']];
                    $numbered++;
                }
            }
            // 必须按编号的料只走编号；收回配送时历史上无编号领出的，仍按原领料单数量冲回。
            if ($mode === 'required' && ! $onlyIssueId) {
                if (! $ids) $this->fail('return_serial_required', $m->component_item_name.' 启用了单件追溯，请选择要退的编号。');
                continue;
            }
            if ($ids && $want['qty'] <= self::EPS) continue;
            $qty = $want['qty'];
            if ($qty <= self::EPS) $this->fail('return_qty_invalid', $m->component_item_name.' 退料数量要大于 0。');
            // 收回配送：整单按原领料单冲回，不受已开工设备数限制（签收前不能开工）。
            // 可退总量按本道整体口径算，本次已选的编号也占这个总量，编号 + 无编号合计不能超。
            // 业务上限（不封顶到无编号余量）含本次编号，扣掉编号后再受无编号实物余量约束。
            $total = $onlyIssueId ? $qty + $numbered : $this->quantityLimit($wo, $op, $m, $type, $exceptReturnId, false);
            $unnumbered = $onlyIssueId ? $qty : round($this->sources($wo, $m, $exceptReturnId)->sum('remaining'), 4);
            $limit = max(0, round(min($total - $numbered, $unnumbered), 4));
            $total = round($limit + $numbered, 4);
            if ($qty > $limit + self::EPS) $this->fail('return_qty_exceeded', $m->component_item_name.' 最多可退 '.$this->num($total).($m->unit_name ?: '').'（'.self::TYPES[$type].'）'
                .($numbered ? '，已选编号 '.$numbered.' 个，无编号部分最多 '.$this->num($limit) : '').'。');
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
            'qty' => round($rows->whereNull('inventory_serial_id')->sum(fn ($l) => (float) $l->qty), 4), 'serial_ids' => $rows->pluck('inventory_serial_id')->filter()->map(fn ($v) => (int) $v)->values()->all()])->values()->all();
    }

    /**
     * 按数量的可退上限。占用 = 其他待审核申请；已审核的退料已体现在已领数量里（报废设备拆回除外，单独累计）。
     */
    private function quantityLimit(MfgWorkOrder $wo, MfgWorkOrderOperation $op, MfgWorkOrderMaterial $m, string $type, ?int $exceptReturnId = null, bool $capUnnumbered = true): float
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
        return max(0, round($capUnnumbered ? min($limit, $unreturned) : $limit, 4));
    }

    /**
     * 本工单这种料的收料出库流水（最近的在前），按数量可退的部分：
     * remaining = 原出库数量 − 这条流水里有编号领出的个数 − 已审核和待审核的无编号退料。有编号的部分只能按编号退。
     * @return Collection<int, array{item: InventoryTransactionItem, remaining: float}>
     */
    private function sources(MfgWorkOrder $wo, MfgWorkOrderMaterial $m, ?int $exceptReturnId = null, bool $lock = false, ?int $onlyIssueId = null): Collection
    {
        $issues = MfgMaterialIssue::query()->where('work_order_id', $wo->id)->when($onlyIssueId, fn ($q, $v) => $q->whereKey($v))->get(['id', 'issue_lines']);
        if ($issues->isEmpty()) return collect();
        $query = InventoryTransactionItem::query()->where('source_type', 'mfg_material_issue')->whereIn('source_id', $issues->pluck('id'))
            ->where('source_item_id', $m->id)->where('item_id', $m->component_item_id)->where('change_qty', '<', 0)->orderByDesc('id');
        if ($lock) $query->lockForUpdate();
        $items = $query->get();
        $numbered = $this->numberedCounts($issues, $m);
        $used = MfgMaterialReturnLine::query()->whereIn('source_transaction_item_id', $items->pluck('id')->all() ?: [0])->whereNull('inventory_serial_id')
            ->whereHas('materialReturn', fn ($q) => $q->whereIn('status', ['pending', 'approved'])->when($exceptReturnId, fn ($w, $v) => $w->where('id', '!=', $v)))
            ->selectRaw('source_transaction_item_id, SUM(qty) AS q')->groupBy('source_transaction_item_id')->pluck('q', 'source_transaction_item_id');
        return $items->map(fn ($item) => ['item' => $item, 'remaining' => max(0, round(abs((float) $item->change_qty)
                - (float) ($numbered[$item->source_id.'|'.$item->warehouse_id.'|'.$item->location_id.'|'.$item->batch_no] ?? 0) - (float) ($used[$item->id] ?? 0), 4))])
            ->filter(fn ($s) => $s['remaining'] > self::EPS)->values();
    }

    /** 每张领料单每个库存行（仓库|库位|批次）有编号领出的个数，键为“领料单ID|仓库|库位|批次”。 */
    private function numberedCounts(Collection $issues, MfgWorkOrderMaterial $m): array
    {
        $picks = [];
        foreach ($issues as $issue) foreach ((array) $issue->issue_lines as $line) {
            if ((int) ($line['material_id'] ?? 0) !== (int) $m->id) continue;
            foreach ((array) ($line['picks'] ?? []) as $pick) if ($n = count((array) ($pick['serial_ids'] ?? []))) $picks[] = [(int) $issue->id, (int) $pick['balance_id'], $n];
        }
        if (! $picks) return [];
        $balances = \App\Models\Erp\InventoryBalance::query()->whereIn('id', array_unique(array_column($picks, 1)))->get(['id', 'warehouse_id', 'location_id', 'batch_no'])->keyBy('id');
        $out = [];
        foreach ($picks as [$issueId, $balanceId, $n]) {
            $b = $balances->get($balanceId);
            if (! $b) continue;
            $key = $issueId.'|'.$b->warehouse_id.'|'.$b->location_id.'|'.$b->batch_no;
            $out[$key] = ($out[$key] ?? 0) + $n;
        }
        return $out;
    }

    /**
     * 可退编号：本道收料领出、最近一次领用就是本工单、现在仍为生产已领、不在其他待审核申请里；
     * 装在未入库的设备上（active）、报废设备上（scrapped）或没登记到设备（unbound）；整单配送收回也接纳已取消台。键为库存序列号 ID。
     */
    private function serialCandidates(MfgWorkOrder $wo, MfgWorkOrderOperation $op, MfgWorkOrderMaterial $m, ?int $exceptReturnId = null, bool $lock = false, bool $allowCancelledUnits = false): array
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
            if ($unit && ! in_array($unit->status, array_merge(self::ACTIVE_UNIT, $allowCancelledUnits ? ['scrapped', 'cancelled'] : ['scrapped']), true)) continue;
            $source = $sourceItems->first(fn ($i) => (int) $i->source_id === (int) $picked[$s->id] && (int) $i->warehouse_id === (int) $event->warehouse_id
                && (int) $i->location_id === (int) $event->location_id && (string) $i->batch_no === (string) $event->batch_no);
            if (! $source) continue;
            $out[(int) $s->id] = ['id' => (int) $s->id, 'serial_no' => $s->serial_no, 'batch_no' => $s->batch_no, 'source' => $source,
                'unit_id' => $unit?->id, 'unit_serial_no' => $unit?->serial_no, 'unit_status' => $unit?->status, 'component_id' => $component?->id,
                'kind' => ! $unit ? 'unbound' : ($unit->status === 'scrapped' ? 'scrapped' : 'active')];
        }
        return $out;
    }

    /** 接收必须明确实际库位，不能把申请时的原位置自动当作实际收货位置。 */
    private function target(?int $locationId): array
    {
        if (! $locationId) $this->fail('return_location_required', '请扫描或选择实际接收库位。');
        $location = Location::query()->lockForUpdate()->find($locationId);
        $warehouse = $location ? Warehouse::query()->sharedLock()->find($location->warehouse_id) : null;
        if (! $location || ! in_array($location->status, ['enabled', 'active'], true) || $location->location_purpose !== 'general'
            || ! $warehouse || ! in_array($warehouse->status, ['enabled', 'active'], true) || ($warehouse->management_scope && $warehouse->management_scope !== 'factory'))
            $this->fail('return_location_invalid', '退料只能退入启用的工厂仓库里启用的普通库存库位。');
        return [(int) $warehouse->id, (int) $location->id];
    }

    private function context(int $workOrderId, int $operationId, bool $lock, bool $allowClosed = false): array
    {
        $query = MfgWorkOrder::query();
        if ($lock) $query->lockForUpdate();
        $wo = $query->findOrFail($workOrderId);
        if (! $allowClosed && ! in_array($wo->status, self::OPEN_WO, true)) $this->fail('status_invalid', '工单不在生产中，不能退料（已完工的工单材料成本已结转到入库成本）。');
        $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->when($lock, fn ($q) => $q->lockForUpdate())->find($operationId);
        if (! $op || $op->execution_context !== 'production') $this->fail('operation_invalid', '这道不是本工单的车间工序。');
        return [$wo, $op];
    }

    private function lockedPending(int $id, array $data): MfgMaterialReturn
    {
        $workOrderId = MfgMaterialReturn::query()->findOrFail($id)->work_order_id;
        MfgWorkOrder::query()->whereKey($workOrderId)->lockForUpdate()->firstOrFail();
        $return = MfgMaterialReturn::query()->lockForUpdate()->findOrFail($id);
        $this->assertVersion($return, $data);
        if ($return->status !== 'pending') $this->fail('status_invalid', '这张退料单已经处理过了（'.(self::STATUS_LABELS[$return->status] ?? $return->status).'）。');
        return $return;
    }

    public static function receiptReady(MfgMaterialReturn $return): bool
    {
        $plan = $return->disposition_plan;
        return ! $plan || ($plan['review_status'] ?? (($plan['needs_review'] ?? true) ? 'pending' : 'approved')) === 'approved';
    }

    /** 仓库待办和普通退料列表共用方案资格；旧免审 JSON 仅作兼容，不改历史事实。 */
    public static function pendingReceipts($query)
    {
        return $query->where('status', 'pending')->where(fn ($q) => $q->whereNull('disposition_plan')
            ->orWhere('disposition_plan->review_status', 'approved')
            ->orWhere(fn ($legacy) => $legacy->whereNull('disposition_plan->review_status')->where('disposition_plan->needs_review', false)));
    }

    private function assertVersion(MfgMaterialReturn $return, array $data): void
    {
        if ((int) ($data['expected_version'] ?? 0) !== (int) $return->business_version)
            $this->fail('version_conflict', '退料单已经变化，请刷新后重新核对。', 409);
    }

    private function confirmPhysical(MfgMaterialReturn $return, array $data): void
    {
        $this->target((int) ($data['location_id'] ?? 0));
        if (! array_key_exists('serial_nos', $data) || ! is_array($data['serial_nos']))
            $this->fail('serial_confirmation_required', '请提交本单核对的完整编号，无编号请明确提交空列表。');
        $expected = MfgMaterialReturnLine::query()->where('return_id', $return->id)->whereNotNull('inventory_serial_id')->orderBy('id')->lockForUpdate()->pluck('serial_no')->all();
        $scanned = array_map(fn ($sn) => trim((string) $sn), $data['serial_nos'] ?? []);
        sort($expected, SORT_STRING);
        sort($scanned, SORT_STRING);
        if ($scanned !== $expected || count(array_unique($scanned)) !== count($scanned))
            $this->fail('serial_confirmation_mismatch', '请逐个核对本单全部编号，不能重复、漏扫或带入其他编号。');
    }

    /** 申请及接收各核对一次原领料成本和加工事实，收回待确认不能绕过历史工单保护。 */
    private function assertRecallable(MfgWorkOrder $wo, MfgWorkOrderOperation $op, MfgMaterialIssue $issue): void
    {
        if ((int) $issue->work_order_id !== (int) $wo->id || (int) $issue->work_order_operation_id !== (int) $op->id)
            $this->fail('delivery_issue_invalid', '配料单的原领料记录不属于本工单和工序，不能收回。');
        if ($wo->cost_status !== 'open' || (float) $wo->stored_qty > self::EPS
            || MfgReceipt::query()->where('work_order_id', $wo->id)->lockForUpdate()->first(['id']))
            $this->fail('material_cost_transferred', '工单已有生产入库或入库申请，不能直接收回配送，请先核对材料去向和已入库成本。');
        if (! in_array($wo->status, self::OPEN_WO, true) && ((float) $op->completed_qty > self::EPS
            || MfgLaborSession::query()->where('work_order_operation_id', $op->id)->lockForUpdate()->first(['id'])
            || MfgUnitReport::query()->where('work_order_operation_id', $op->id)->lockForUpdate()->first(['id'])))
            $this->fail('material_usage_unconfirmed', '已结束工单的这道工序已有加工记录，不能直接整单收回，请先核对材料是否已消耗。');
    }

    private function hasPermission(object $actor, string $permission): bool
    {
        $auth = app(AuthContextService::class);
        return $auth->isSuperAdmin($actor) || in_array($permission, $auth->permissionCodes($actor), true);
    }

    private function assertPermission(object $actor, array $permissions): void
    {
        $auth = app(AuthContextService::class);
        if (! $auth->isSuperAdmin($actor) && ! array_intersect($permissions, $auth->permissionCodes($actor)))
            $this->fail('permission_denied', '当前账号没有办理这项退料操作的权限。', 403);
    }

    private function summary(array $rows): string
    {
        return collect($rows)->groupBy('material_id')->map(fn ($g) => $g->first()['component_item_name'].' '.$this->num($g->sum(fn ($l) => (float) $l['qty'])).($g->first()['unit_name'] ?: '')
            .(($nos = $g->pluck('serial_no')->filter()->values()->all()) ? '（'.implode('、', $nos).'）' : ''))->implode('、');
    }

    private function num(float $v): string { return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.'); }
}
