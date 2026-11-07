<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Location;
use App\Models\Erp\Item;
use App\Models\Erp\MfgCuttingBatch;
use App\Models\Erp\MfgDrawing;
use App\Models\Erp\MfgLaborSession;
use App\Models\Erp\MfgReceipt;
use App\Models\Erp\MfgTaskParticipant;
use App\Models\Erp\MfgUnitReport;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLink;
use App\Models\Erp\MfgWorkOrderMaterial;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderTech;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\ProductionOperationWorker;
use App\Models\Erp\ProductionStage;
use App\Models\Erp\Warehouse;
use App\Services\Erp\AuthContextService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** 车间只读：工人任务列表/详情（小程序）、生产作业看板（电脑端）、工序“能做的人”。 */
final class TaskQueryService
{
    use ProductionActor;

    private const OPEN = ['released', 'in_progress', 'paused'];

    public function __construct(
        private readonly TaskService $tasks,
        private readonly WorkOrderService $workOrders,
        private readonly TechService $tech,
    ) {}

    /**
     * 小程序“生产任务”：我的 / 可接 / 做完的；每个人只看到自己能做的工序。
     * 每道工序的正常生产和返工是两张卡（lane），返工单独接单、单独计时；还没轮到（前道没交过来）的工序不显示。
     */
    public function tasks(object $actor, string $tab, array $filters, array $permissions): array
    {
        $this->workOrders->placeWaitingUnits();
        $this->tasks->closeOverdueSessions();
        TaskService::syncWorkStatus();
        $me = (int) $this->actorId($actor);
        $super = app(AuthContextService::class)->isSuperAdmin($actor);
        $canExecute = $super || in_array('production.task.execute', $permissions, true);
        $perPage = min(50, max(1, (int) ($filters['per_page'] ?? 10)));
        if ($tab === 'done') {
            $page = $this->taskQuery($me, 'done', $filters, 'normal')->paginate($perPage);
            $page->getCollection()->transform(fn ($op) => $this->taskRow($op, 'normal', $me, $canExecute));
        } else {
            $page = DB::query()->fromSub($this->laneUnion($me, $tab, $filters), 't')
                ->orderBy('rp')->orderBy('rw')->orderBy('is_child')->orderBy('w')->orderBy('s')->orderBy('lane')
                ->paginate($perPage);
            $ops = MfgWorkOrderOperation::query()->whereIn('id', collect($page->items())->pluck('id')->all() ?: [0])->get()->keyBy('id');
            $page->setCollection($page->getCollection()->map(fn ($r) => $this->taskRow($ops[$r->id], $r->lane, $me, $canExecute))->values());
        }
        $hasApprove = $super || in_array('production.scrap.approve', $permissions, true);
        return $page->toArray() + ['permissions' => ['execute' => $canExecute], 'counts' => [
            'mine' => DB::query()->fromSub($this->laneUnion($me, 'mine', []), 'm')->count(),
            'available' => DB::query()->fromSub($this->laneUnion($me, 'available', []), 'v')->count(),
            // 工单数各自沿用任务范围并去重，避免把可接任务计入本人进行中的工单。
            'active_work_orders' => DB::query()->fromSub($this->laneUnion($me, 'mine', []), 'm')->distinct()->count('w'),
            'available_work_orders' => DB::query()->fromSub($this->laneUnion($me, 'available', []), 'v')->distinct()->count('w'),
            // 待我审核：报废申请、不良处置（我是工单生产负责人，或有报废确认权限）
            'review' => count($this->reviews($actor, $hasApprove, true)),
        ]];
    }

    /** 待我审核（小程序）：报废申请、员工提交的不良处置。$countOnly 时只返回 ID 列表用于计数。 */
    public function reviews(object $actor, bool $hasApprovePermission, bool $countOnly = false): array
    {
        $me = (int) $this->actorId($actor);
        $reports = MfgUnitReport::query()->where('review_status', 'pending')->orderBy('id')->limit(100)->get();
        $wos = MfgWorkOrder::query()->whereIn('id', $reports->pluck('work_order_id')->unique()->all() ?: [0])->get()->keyBy('id');
        $mine = fn (?MfgWorkOrder $wo) => $wo && ($hasApprovePermission || in_array($me, ProductionReviewers::for($wo), true));
        $reports = $reports->filter(fn ($r) => $mine($wos->get($r->work_order_id)))->values();
        $plans = app(MaterialReturnService::class)->pendingPlans($me, $hasApprovePermission);
        if ($countOnly) return array_merge($reports->pluck('id')->all(), array_column($plans, 'id'));
        $opNames = MfgWorkOrderOperation::query()->whereIn('id', $reports->pluck('work_order_operation_id')->all() ?: [0])->pluck('operation_name', 'id');
        return ['scraps' => $reports->map(fn ($r) => ['id' => $r->id, 'serial_no' => $r->serial_no, 'work_order_id' => $r->work_order_id,
                'work_order_no' => $wos->get($r->work_order_id)?->work_order_no, 'product_name' => ($w = $wos->get($r->work_order_id)) ? ($w->product_name ?: $w->item_name) : null,
                'operation_name' => $opNames[$r->work_order_operation_id] ?? null, 'reason_tags' => $r->reason_tags ?: [], 'reason' => $r->reason, 'photos' => $r->photos ?: [],
                'media' => \App\Services\Erp\ProductionMediaService::forRead($r->media ?: [], $r->photos ?: []),
                'reporter_name' => $r->reporter_name, 'created_at' => $r->created_at?->toDateTimeString()])->values()->all(),
            'plans' => $plans];
    }

    /** 正常、返工两条线的任务合在一起分页：id、lane、工单、主工单、优先级、工序顺序。 */
    private function laneUnion(int $me, string $tab, array $filters)
    {
        $part = fn (string $lane) => $this->taskQuery($me, $tab, $filters, $lane)->reorder()
            ->leftJoin('erp_mfg_work_order_links as wl', 'wl.child_work_order_id', '=', 'op.work_order_id')
            ->leftJoin('erp_mfg_work_orders as pwo', 'pwo.id', '=', 'wl.parent_work_order_id')
            ->select([
                'op.id',
                DB::raw("'{$lane}' AS lane"),
                'op.work_order_id AS w',
                DB::raw('COALESCE(wl.parent_work_order_id, op.work_order_id) AS rw'),
                DB::raw('COALESCE(pwo.priority, wo.priority) AS rp'),
                DB::raw('CASE WHEN wl.parent_work_order_id IS NULL THEN 0 ELSE 1 END AS is_child'),
                'wo.priority AS p',
                'op.sequence AS s',
            ])->toBase();
        return $part('normal')->unionAll($part('rework'));
    }

    public function task(int $opId, object $actor, array $permissions, ?string $lane = null): array
    {
        $this->tasks->closeOverdueSessions();
        $me = (int) $this->actorId($actor);
        $super = app(AuthContextService::class)->isSuperAdmin($actor);
        $canExecute = $super || in_array('production.task.execute', $permissions, true);
        $op = MfgWorkOrderOperation::query()->findOrFail($opId);
        TaskService::syncWorkStatus([(int) $op->work_order_id]);
        $op->refresh();
        $wo = MfgWorkOrder::query()->with(['operations', 'item:id,item_type,spec,model,material_grade'])->findOrFail($op->work_order_id);
        $ops = $wo->operations->where('execution_context', 'production')->values();
        $pos = $ops->pluck('id')->flip();
        $myPos = $pos[$op->id] ?? 0;
        $blocked = CancellationProductionService::blockedUnitIds((int) $wo->id);
        $laneCount = fn (string $l) => MfgWorkOrderUnit::query()->where('current_operation_id', $op->id)->whereNotIn('id', $blocked)->whereIn('status', TaskService::laneStatuses($l))->count();
        // 没指定看哪条线：返工工单只有返工；本道只剩返工的件（或我只在返工里）就看返工。
        if ($lane === null) {
            $inNormal = MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('lane', 'normal')->where('employee_legacy_id', $me)->where('is_active', true)->exists();
            // 正常线整张工单都没有在做的件了（比如试水返修退回来的成品），我接过正常单也直接看返工。
            $normalIdle = ! $inNormal || ! MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('status', TaskService::laneStatuses('normal'))->exists();
            $lane = $wo->rework_of_work_order_id || (! $laneCount('normal') && $normalIdle && $laneCount('rework')) ? 'rework' : 'normal';
        }
        $lane = TaskService::lane($lane);
        $selectable = $this->tasks->selectableUnits($op, $lane)->pluck('id')->all();
        $units = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereNotIn('id', $blocked)->where('current_operation_id', $op->id)->whereIn('status', TaskService::laneStatuses($lane))->orderBy('unit_no')->get();
        $unitDispositions = \App\Models\Erp\CancellationProductionDisposition::where('work_order_id', $wo->id)->whereNotNull('work_order_unit_id')->get()->keyBy('work_order_unit_id');
        $lastFail = MfgUnitReport::query()->whereIn('unit_id', $units->pluck('id') ?: [0])->whereIn('result', ['failed', 'scrap'])->orderByDesc('id')->get()->unique('unit_id')->keyBy('unit_id');
        $shipmentRepairReasons = DB::table('erp_shipment_reworks')->whereIn('unit_id', $units->pluck('id'))->where('status', 'in_progress')->pluck('reason', 'unit_id');
        $opNames = $wo->operations->pluck('operation_name', 'id');
        $participants = $this->participants($op, $lane);
        $opTiming = collect($participants)->contains(fn ($p) => $p['timing']);
        $timingSessions = MfgLaborSession::where('work_order_operation_id', $op->id)->where('lane', $lane)->where('status', 'active')->get();
        $canAssign = $super || in_array('production.task.assign', $permissions, true);
        $mine = collect($participants)->firstWhere('employee_legacy_id', $me);
        $owner = collect($participants)->firstWhere('role', 'owner');
        $canOrderSemi = $canExecute && $lane === 'normal' && $mine && $mine['role'] === 'owner' && $op->execution_context === 'production'
            && in_array($wo->status, ['planned', 'released', 'in_progress', 'paused'], true);
        $myActive = MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('employee_legacy_id', $me)->where('status', 'active')->first();
        $laneStarted = MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->exists();
        $runnable = $this->tasks->isRunnable($wo) && $op->status !== 'done';
        $canDo = $this->tasks->canDo($op, $me);
        $tech = MfgWorkOrderTech::query()->where('work_order_id', $wo->id)->first();
        $drawings = MfgDrawing::query()->where('work_order_id', $wo->id)->where('status', 'active')->orderBy('id')->get();
        $myLast = MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('employee_legacy_id', $me)->min('started_at');
        $upstream = $myPos > 0 ? MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('status', TaskService::SELECTABLE)
            ->whereNotIn('id', $blocked)->whereIn('current_operation_id', $ops->slice(0, $myPos)->pluck('id'))->count() : 0;
        $here = count($selectable);
        $cuttingOps = MaterialIssueService::cuttingOperationIds((int) $wo->id);
        // 开料、已备料只在正常生产里；返工用原来的料。
        $hasCutting = $lane === 'normal' && in_array((int) $op->id, $cuttingOps, true);
        $otherLane = $lane === 'normal' ? 'rework' : 'normal';
        $unitWord = $this->tasks->unitWord($wo);
        $cuttingBatches = $hasCutting ? MfgCuttingBatch::query()->where('work_order_operation_id', $op->id)->get(['id', 'batch_no', 'cutting_run_id', 'status', 'unit_qty', 'completed_unit_qty']) : collect();
        $cutUnits = $hasCutting ? (int) app(MaterialIssueService::class)->cutUnits($wo, $op) : 0;
        // 用按数量管理的板材／管材的下料工序：可以计时报工，也可以集中下料后点「已备料」不计时交下道。
        // 逐张板材的下料工序也可以：集中开料切好的件点「已备料」，按定额从开料池扣。
        $isPrep = $hasCutting || ($lane === 'normal' && in_array((int) $op->id, MaterialIssueService::prepOperationIds((int) $wo->id), true));
        $sheetRows = $hasCutting ? MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->where('cutting_mode', 'sheet')->where('required_qty', '>', 0)->get()
            ->filter(fn ($m) => MaterialIssueService::isCutting($m)) : collect();
        $pool = app(SharedCuttingService::class)->poolAvailable($sheetRows->pluck('component_item_id')->unique()->values()->all());
        $opDone = (int) round((float) $op->completed_qty);
        $completion = $units->isNotEmpty() && $units->every(fn ($u) => $shipmentRepairReasons->has($u->id))
            ? null : app(TaskCompletionService::class)->options($wo, $op);
        $specInfo = WorkOrderQueryService::itemSpecInfo($wo->item, $wo);
        $parentLink = MfgWorkOrderLink::query()->where('child_work_order_id', $wo->id)->first();
        $parentWo = $parentLink ? MfgWorkOrder::query()->with('item:id,item_type,spec,model,material_grade')->find($parentLink->parent_work_order_id) : null;
        $parentBrief = $parentWo ? $this->workOrderBrief($parentWo) : null;
        $childIds = MfgWorkOrderLink::query()->where('parent_work_order_id', $wo->id)->orderBy('id')->pluck('child_work_order_id');
        $childBriefs = $childIds->isEmpty() ? [] : MfgWorkOrder::query()->with('item:id,item_type,spec,model,material_grade')->whereIn('id', $childIds)->orderBy('id')->get()
            ->map(fn ($c) => $this->workOrderBrief($c))->values()->all();
        $opMaterials = MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->orderBy('id')->get();
        $compItems = Item::query()->whereIn('id', $opMaterials->pluck('component_item_id')->unique()->all() ?: [0])->get(['id', 'spec', 'model', 'material_grade'])->keyBy('id');
        $bomLines = collect(data_get($wo->bom_snapshot, 'items', []))->keyBy('id');
        return [
            'id' => $op->id, 'operation_name' => $op->operation_name, 'title' => TaskService::laneTitle($op->operation_name, $lane), 'lane' => $lane,
            'blocked_unit_ids' => $blocked, 'production_unit_ids' => CancellationProductionService::productionUnitIds((int) $wo->id),
            'sales_demand_unit_ids' => CancellationProductionService::salesDemandUnitIds((int) $wo->id),
            'stage_name' => $op->stage_name, 'status' => $op->status,
            'work_status' => $op->work_status, 'work_status_label' => TaskService::WORK_STATUS_LABELS[$op->work_status] ?? $op->work_status,
            'unit_word' => $unitWord, 'unit_short' => $unitWord === '台设备' ? '台' : $unitWord,
            // 本道另一条线（正常／返工）还有几件，详情页可以切过去
            'other_lane' => ['lane' => $otherLane, 'count' => $laneCount($otherLane), 'title' => TaskService::laneTitle($op->operation_name, $otherLane)],
            'rework_of_work_order_no' => $wo->rework_of_work_order_id ? MfgWorkOrder::query()->whereKey($wo->rework_of_work_order_id)->value('work_order_no') : null,
            'rework_note' => $wo->rework_of_work_order_id ? $wo->remark : null,
            // 订单信息：销售员、客户、电话、地址、各种备注（销售改过的标出来）；试水返修的说明
            'order_info' => app(OrderBrief::class)->forWorkOrder($wo),
            'workshop_reference' => app(WorkshopReferenceService::class)->forWorkOrder($wo, $op->routing_operation_id ? (int) $op->routing_operation_id : null),
            'shipment_repair' => collect(WorkOrderQueryService::shipmentRepairs($wo))->first(fn ($r) => $r['state'] === 'repairing' && (int) $r['current_operation_id'] === (int) $op->id),
            'work_order' => [
                'id' => $wo->id, 'work_order_no' => $wo->work_order_no, 'source_type' => $wo->source_type,
                'blocked_unit_ids' => $blocked, 'production_unit_ids' => CancellationProductionService::productionUnitIds((int) $wo->id),
                'sales_demand_unit_ids' => CancellationProductionService::salesDemandUnitIds((int) $wo->id),
                'source_label' => $wo->rework_of_work_order_id ? '返工工单' : match ($wo->source_type) { 'stock' => '备货', 'work_order' => '半成品子工单', default => '销售订单' },
                'is_sub_work_order' => $parentBrief !== null || $wo->source_type === 'work_order' || (bool) $wo->rework_of_work_order_id,
                'status' => $wo->status, 'status_label' => WorkOrderQueryService::STATUS_LABELS[$wo->status] ?? $wo->status,
                'pending_receipt_qty' => MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->where('status', 'pending_receipt')->count(),
                'completion_blockers' => $this->workOrders->completionBlockers($wo),
                'pause_reason' => $wo->pause_reason, 'product_name' => $wo->product_name ?: $wo->item_name, 'item_code' => $wo->item_code, 'item_name' => $wo->item_name,
                'sku_code' => $wo->sku_code, 'sku_name' => $wo->sku_name,
                'item_spec' => $specInfo['item_spec'], 'material_grade' => $specInfo['material_grade'], 'specification' => $specInfo['specification'],
                'customer_name' => $wo->customer_name ?: $parentWo?->customer_name, 'sales_order_no' => $wo->sales_order_no ?: $parentWo?->sales_order_no,
                'planned_qty' => (float) $wo->planned_qty, 'unit_name' => $wo->unit_name,
                'planned_finish_date' => $wo->planned_finish_date?->toDateString(), 'is_urgent' => (bool) $wo->is_urgent, 'priority' => (int) ($wo->priority ?: 3), 'priority_label' => \App\Services\Erp\SalesOrderPriorityService::label((int) ($wo->priority ?: 3)),
                'special_requirement' => $wo->special_requirement, 'custom_description' => $tech ? $this->tech->customDescription($wo) : null, 'is_custom' => (bool) $tech,
                'parent_work_order' => $parentBrief, 'child_work_orders' => $childBriefs,
            ],
            'units' => $units->map(fn ($u) => [
                'id' => $u->id, 'unit_no' => $u->unit_no, 'serial_no' => $u->serial_no, 'status' => $u->status,
                // 刚从前道交过来、本道还没人开始计时的显示“待做”，有人在计时才算“正在做”。
                'status_label' => ! in_array($u->id, $selectable, true) ? '等报废审核' : ($u->status === 'rework' ? '返工'
                    : ($timingSessions->contains(fn ($session) => $session->unit_ids === null || in_array((int) $u->id, $session->unit_ids, true)) ? '正在做' : '待做')),
                'selectable' => in_array($u->id, $selectable, true), 'rework_count' => (int) $u->rework_count,
                'disposition_id' => $unitDispositions->get($u->id)?->id,
                'disposition_status' => $unitDispositions->get($u->id)?->status,
                'production_destination' => $unitDispositions->get($u->id)?->status === 'stock' ? 'stock' : 'demand',
                'handoff_allowed' => $unitDispositions->get($u->id)?->status !== 'stock',
                'needs_completion' => $completion !== null && ! $shipmentRepairReasons->has($u->id),
                'fail_reason' => ($f = $lastFail->get($u->id)) && $u->status === 'rework'
                    ? trim(implode('：', array_filter([($opNames[$f->work_order_operation_id] ?? '').'不合格', implode('/', $f->reason_tags ?: []) ?: $f->reason]))) : ($shipmentRepairReasons[$u->id] ?? null),
            ])->values()->all(),
            'progress' => ['planned' => (float) $wo->planned_qty, 'done' => (float) $op->completed_qty, 'here' => $here, 'upstream' => $upstream,
                'next_operation_name' => $ops->get($myPos + 1)?->operation_name ?? ($completion['next_operation_name'] ?? null)],
            'completion' => $completion,
            'participants' => $participants,
            'my' => ['is_participant' => (bool) $mine, 'role' => $mine['role'] ?? null, 'timing' => (bool) $myActive,
                'unit_ids' => $myActive?->unit_ids ?? [], 'timing_deadline_at' => $myActive ? TaskService::sessionDeadline($myActive)->toIso8601String() : null,
                // 别的工序（或本道另一条线）在计时：开始前要先暂停那边
                'timing_elsewhere' => ! $myActive && MfgLaborSession::query()->where('employee_legacy_id', $me)->where('status', 'active')->exists(),
                'started_at' => $myActive?->started_at?->toIso8601String(), 'closed_minutes' => round((float) MfgLaborSession::query()->where('work_order_operation_id', $op->id)
                    ->where('lane', $lane)->where('employee_legacy_id', $me)->where('status', '!=', 'active')->sum('labor_minutes'), 2)],
            'share_mode' => collect($participants)->contains(fn ($p) => $p['share_percent'] !== null) ? 'fixed' : 'labor',
            'drawings' => $drawings->map(fn ($d) => ['id' => $d->id, 'drawing_name' => $d->drawing_name, 'version_no' => $d->version_no, 'url' => $d->url,
                'mime_type' => $d->mime_type, 'uploaded_at' => $d->uploaded_at?->toDateTimeString()])->values()->all(),
            'drawings_updated_at' => $tech?->drawings_updated_at && $myLast && $tech->drawings_updated_at->gt($myLast) ? $tech->drawings_updated_at->toDateTimeString() : null,
            'materials' => $opMaterials->map(function ($m) use ($compItems, $bomLines) {
                $ms = WorkOrderQueryService::materialSpecInfo($compItems->get($m->component_item_id), $bomLines->get($m->bom_item_id));
                return [
                    'id' => $m->id,
                    'component_item_id' => (int) $m->component_item_id,
                    'component_item_name' => $m->component_item_name,
                    'component_item_code' => $m->component_item_code,
                    'component_item_spec' => $ms['component_item_spec'],
                    'material_grade' => $ms['material_grade'],
                    'cut_spec' => $ms['cut_spec'],
                    'specification' => $ms['specification'],
                    'unit_name' => $m->unit_name,
                    'unit_qty' => (float) $m->unit_qty,
                    'required_qty' => (float) $m->required_qty,
                    'issued_qty' => (float) $m->issued_qty,
                    'remark' => $m->remark,
                    'cutting' => MaterialIssueService::isCutting($m),
                ];
            })->values()->all(),
            // 本道用板材／管材：在本道直接开料（领原料 → 登记切出的件和余料）。
            // 下料按套数报完成，不逐台勾选：开料登记了几套，最多就能报几套。
            'prep' => $isPrep,
            // 集中开料记录（同一产品、已结束、还有剩的批次）：点「已备料」时按件数领走开料工时。
            'cutting_runs' => $isPrep ? app(CuttingRunService::class)->availableFor($wo, $op) : null,
            'cutting' => $hasCutting ? [
                'planned_units' => (int) $wo->planned_qty,
                'cut_units' => $cutUnits,
                'completed_units' => $opDone,
                'reportable_units' => max(0, min($here, $cutUnits - $opDone)),
                'pending_batches' => $cuttingBatches->whereIn('status', ['issued', 'awaiting_confirm'])->count(),
                'awaiting_confirmation_batches' => $cuttingBatches->where('status', 'awaiting_confirm')->count(),
                'waiting_reasons' => $cuttingBatches->where('status', 'awaiting_confirm')->map(fn ($batch) => app(CuttingRemnantReceiptService::class)->waitingReason($batch))->values()->all(),
                // 集中开料池里这几种板还剩多少平方米可以备料
                'pool' => $sheetRows->unique('component_item_id')->map(fn ($m) => ['item_id' => (int) $m->component_item_id, 'item_name' => $m->component_item_name,
                    'left_measure' => round((float) ($pool[$m->component_item_id] ?? 0), 4)])->values()->all(),
            ] : null,
            // 仓库送来等本道签收的料（拒收待处理的也列出来，看库管怎么处理）
            'deliveries' => app(MaterialDeliveryService::class)->forOperation((int) $op->id),
            // 开料工序不能退回重做（要重新耗原料），切坏的只能报废。
            'return_options' => $ops->slice(0, $myPos + 1)->reject(fn ($o) => in_array((int) $o->id, $cuttingOps, true))->map(function ($o) use ($ops, $pos, $myPos) {
                $redo = $ops->slice($pos[$o->id], $myPos - $pos[$o->id] + 1)->pluck('operation_name')->all();
                return ['id' => $o->id, 'operation_name' => $o->operation_name, 'is_current' => $pos[$o->id] === $myPos,
                    'hint' => $pos[$o->id] === $myPos ? '本道重做' : implode('、', array_slice($redo, 1)).'要重做'];
            })->values()->all(),
            'reason_tags' => TaskService::REASON_TAGS,
            // 报废：有审核人时等审核；没有审核人或自己就是审核人，提交即生效（要拍照）
            'scrap_review' => ['needs_review' => ProductionReviewers::needsReview($wo, $me), 'reviewers' => ProductionReviewers::names($wo)],
            // BOM 子件（自制半成品）：只有本道正常生产的接单人能补开，最多补到缺口。
            'semi_components' => $lane === 'normal' ? array_map(fn ($r) => array_merge($r, ['can_order' => $canOrderSemi && $r['max_order_qty'] > 0]),
                $this->workOrders->semiRows($wo, (int) $op->id)) : [],
            'allowed' => [
                'claim' => $canExecute && $runnable && ! $owner && $canDo && $here > 0,
                'start' => $canExecute && $runnable && ! $myActive && $here > 0 && (($mine && ($mine['role'] === 'owner' || $laneStarted)) || (! $owner && $canDo)),
                'pause' => $canExecute && (bool) $myActive,
                'cutting' => $hasCutting && $canExecute && $mine && in_array($wo->status, ['released', 'in_progress'], true),
                'report' => $canExecute && $runnable && $mine && $here > 0,
                'prepare' => $isPrep && $canExecute && $runnable && $here > 0 && ($mine || (! $owner && $canDo)),
                'semi_order' => $canOrderSemi,
                'collaborators' => $canExecute && $runnable && $mine && $mine['role'] === 'owner',
                'assign' => $runnable && $canAssign,
                // 主管替忘了停的人停止计时
                'stop' => $canAssign && $opTiming,
                'delivery_sign' => $canExecute || in_array('production.work_order.edit', $permissions, true),
                // 退料：工单在生产中、本道收过料，有申请或审核退料权限
                'material_return' => in_array($wo->status, ['released', 'in_progress', 'paused'], true) && $op->execution_context === 'production'
                    && ($super || array_intersect(['production.material_return.request', 'production.material_return.approve'], $permissions) !== [])
                    && MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->where('issued_qty', '>', 0)->exists(),
            ],
        ];
    }

    /** 接单人选协同人：有报工权限、在职、业务员档案中有效的生产工人。 */
    public function people(int $opId, ?string $keyword): array
    {
        MfgWorkOrderOperation::query()->findOrFail($opId);
        $ids = DB::table('erp_rbac_user_roles as ur')->join('erp_rbac_role_permissions as rp', 'rp.role_id', '=', 'ur.role_id')
            ->join('erp_rbac_permissions as p', 'p.id', '=', 'rp.permission_id')->where('p.code', 'production.task.execute')->distinct()->pluck('ur.user_legacy_id');
        $query = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->whereIn(DB::raw('LOWER(status)'), ['normal', 'active']);
        $staff = app(\App\Services\Erp\BusinessStaffService::class);
        $staff->ensureBackfilled();
        $staff->applyActive($query, 'production_worker');
        if ($keyword = trim((string) $keyword)) $query->where(fn ($q) => $q->where('nickname', 'like', "%{$keyword}%")->orWhere('username', 'like', "%{$keyword}%"));
        return $query->orderBy('legacy_id')->limit(50)->get(['legacy_id', 'nickname', 'username'])
            ->map(fn ($u) => ['id' => (int) $u->legacy_id, 'name' => $u->nickname ?: $u->username])->values()->all();
    }

    /** 生产作业看板：按生产阶段分列，看每张工单每道工序的设备在哪、谁在做。 */
    public function board(array $filters): array
    {
        $this->workOrders->placeWaitingUnits();
        $this->tasks->closeOverdueSessions();
        TaskService::syncWorkStatus();
        $today = now()->toDateString();
        $woQuery = MfgWorkOrder::query()->where(fn ($q) => $q->whereIn('status', self::OPEN)
            ->orWhere(fn ($repair) => $repair->where('status', 'completed')->whereIn('id', DB::table('erp_shipment_reworks')->where('status', 'in_progress')->whereNotNull('work_order_id')->select('work_order_id'))));
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $like = '%'.$keyword.'%';
            $woQuery->where(fn ($q) => $q->where('work_order_no', 'like', $like)->orWhere('product_name', 'like', $like)->orWhere('item_name', 'like', $like)
                ->orWhere('sku_code', 'like', $like)->orWhere('customer_name', 'like', $like)
                ->orWhereExists(fn ($u) => $u->selectRaw('1')->from('erp_mfg_work_order_units as u')->whereColumn('u.work_order_id', 'erp_mfg_work_orders.id')->where('u.serial_no', 'like', $like)));
        }
        if (! empty($filters['urgent_only'])) $woQuery->where('priority', '<=', \App\Services\Erp\SalesOrderPriorityService::URGENT);
        if (! empty($filters['custom_only'])) $woQuery->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_work_order_techs as t')->whereColumn('t.work_order_id', 'erp_mfg_work_orders.id'));
        if ($owner = (int) ($filters['owner_legacy_id'] ?? 0)) $woQuery->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_task_participants as p')
            ->whereColumn('p.work_order_id', 'erp_mfg_work_orders.id')->where('p.employee_legacy_id', $owner)->where('p.is_active', true));
        $wos = $woQuery->with(['operations', 'item:id,item_type,spec,model,material_grade'])->orderBy('priority')->orderBy('id')->limit(300)->get()->keyBy('id');
        $itemScopes = Item::query()->whereIn('id', $wos->pluck('item_id'))->pluck('management_scope', 'id');
        $woIds = $wos->keys()->all() ?: [0];
        $units = MfgWorkOrderUnit::query()->whereIn('work_order_id', $woIds)->whereNotIn('id', DB::table('erp_cancellation_production_dispositions')->whereNull('resolved_at')->whereNotNull('work_order_unit_id')->select('work_order_unit_id'))
            ->whereIn('status', array_merge(TaskService::SELECTABLE, ['completed']))->orderBy('unit_no')->get();
        $pendingScrap = MfgUnitReport::query()->where('review_status', 'pending')->whereIn('work_order_id', $woIds)->pluck('unit_id')->flip();
        $opIds = $units->pluck('current_operation_id')->filter()->unique()->values();
        $owners = MfgTaskParticipant::query()->whereIn('work_order_operation_id', $opIds ?: [0])->where('is_active', true)->where('role', 'owner')->get()->keyBy('work_order_operation_id');
        $active = MfgLaborSession::query()->whereIn('work_order_operation_id', $opIds ?: [0])->where('status', 'active')->get()->groupBy('work_order_operation_id');
        $minutes = MfgLaborSession::query()->whereIn('work_order_operation_id', $opIds ?: [0])->selectRaw('work_order_operation_id, SUM(labor_minutes) AS m')
            ->groupBy('work_order_operation_id')->pluck('m', 'work_order_operation_id');
        $techs = MfgWorkOrderTech::query()->whereIn('work_order_id', $woIds)->pluck('work_order_id')->flip();
        $drawingWos = MfgDrawing::query()->whereIn('work_order_id', $woIds)->where('status', 'active')->distinct()->pluck('work_order_id')->flip();
        $stageSort = ProductionStage::query()->pluck('sort', 'id');
        $columns = [];
        foreach ($units->where('status', '!=', 'completed')->groupBy('current_operation_id') as $opId => $group) {
            $wo = $wos->get($group->first()->work_order_id);
            $op = $wo?->operations->firstWhere('id', (int) $opId);
            if (! $op) continue;
            $key = $op->production_stage_id ? 's'.$op->production_stage_id : 'n'.$op->operation_name;
            $columns[$key] ??= ['key' => $key, 'title' => $op->stage_name ?: $op->operation_name,
                'sort' => $op->production_stage_id ? (int) ($stageSort[$op->production_stage_id] ?? 0) : 1000 + (int) $op->sequence, 'cards' => []];
            $timing = ($active->get($op->id) ?? collect())->map(fn ($s) => ['name' => $s->employee_name, 'employee_legacy_id' => (int) $s->employee_legacy_id, 'started_at' => $s->started_at?->toIso8601String()])->values();
            $owner = $owners->get($op->id);
            $closed = (float) ($minutes[$op->id] ?? 0);
            $running = ($active->get($op->id) ?? collect())->sum(fn ($s) => max(0, $s->started_at->diffInSeconds(now(), false)) / 60);
            $reworkCount = $group->where('status', 'rework')->count();
            $specInfo = WorkOrderQueryService::itemSpecInfo($wo->item, $wo);
            $columns[$key]['cards'][] = [
                'operation_id' => $op->id, 'operation_name' => $op->operation_name, 'work_order_id' => $wo->id, 'work_order_no' => $wo->work_order_no,
                'product_name' => $wo->product_name ?: $wo->item_name, 'item_name' => $wo->item_name, 'sku_name' => $wo->sku_name, 'sku_code' => $wo->sku_code,
                'item_spec' => $specInfo['item_spec'], 'material_grade' => $specInfo['material_grade'], 'specification' => $specInfo['specification'],
                'customer_name' => $wo->customer_name,
                'planned_qty' => (float) $wo->planned_qty, 'done_qty' => (float) $op->completed_qty, 'is_urgent' => (bool) $wo->is_urgent, 'priority' => (int) ($wo->priority ?: 3), 'priority_label' => \App\Services\Erp\SalesOrderPriorityService::label((int) ($wo->priority ?: 3)),
                'is_custom' => isset($techs[$wo->id]), 'has_drawing' => isset($drawingWos[$wo->id]), 'paused' => $wo->status === 'paused', 'pause_reason' => $wo->pause_reason,
                'planned_finish_date' => $wo->planned_finish_date?->toDateString(),
                'overdue' => $wo->planned_finish_date && $wo->planned_finish_date->lt(now()->startOfDay()),
                'units' => $group->map(fn ($u) => ['id' => $u->id, 'serial_no' => $u->serial_no, 'short' => substr($u->serial_no, -4), 'status' => $u->status,
                    'pending_scrap' => isset($pendingScrap[$u->id])])->values()->all(),
                'rework_count' => $reworkCount, 'owner_name' => $owner?->employee_name, 'owner_legacy_id' => $owner ? (int) $owner->employee_legacy_id : null,
                'timing' => $timing->all(), 'labor_minutes' => round($closed + $running, 1),
                'state' => $wo->status === 'paused' ? 'paused' : (! $owner ? 'unclaimed' : ($timing->isNotEmpty() ? 'working' : ($reworkCount ? 'rework' : 'claimed'))),
            ];
        }
        $columns = collect($columns)->sortBy('sort')->map(function ($col) {
            $col['unit_count'] = collect($col['cards'])->sum(fn ($c) => count($c['units']));
            $col['people_count'] = collect($col['cards'])->flatMap(fn ($c) => collect($c['timing'])->pluck('name'))->unique()->count();
            return $col;
        })->values()->all();
        $completed = $units->where('status', 'completed')->groupBy('work_order_id');
        $lastPass = MfgUnitReport::query()->whereIn('unit_id', $units->where('status', 'completed')->pluck('id') ?: [0])->where('result', 'passed')
            ->selectRaw('unit_id, MAX(created_at) AS t')->groupBy('unit_id')->pluck('t', 'unit_id');
        $waiting = $completed->map(function ($group, $woId) use ($wos, $lastPass, $itemScopes) {
            $wo = $wos->get($woId);
            $lastOp = $wo->operations->where('execution_context', 'production')->last();
            return ['work_order_id' => $wo->id, 'work_order_no' => $wo->work_order_no, 'product_name' => $wo->product_name ?: $wo->item_name, 'sku_name' => $wo->sku_name,
                'warehouse_id' => $wo->warehouse_id, 'management_scope' => $itemScopes[$wo->item_id] ?? null, 'last_operation_name' => $lastOp?->operation_name,
                'allowed_location_purposes' => ReceiptService::allowedLocationPurposes($wo),
                'units' => $group->map(fn ($u) => ['id' => $u->id, 'serial_no' => $u->serial_no, 'short' => substr($u->serial_no, -4),
                    'passed_at' => isset($lastPass[$u->id]) ? substr((string) $lastPass[$u->id], 0, 16) : null])->values()->all()];
        })->values()->all();
        // 历史确认时间取原正式流水，不把申请时间计成今日入库。
        $receiptTime = 'COALESCE(erp_mfg_receipts.confirmed_at, receipt_tx.posted_at, erp_mfg_receipts.created_at)';
        $receiptQuery = MfgReceipt::query()->join('erp_inventory_transactions as receipt_tx', 'receipt_tx.id', '=', 'erp_mfg_receipts.inventory_transaction_id')
            ->where('erp_mfg_receipts.status', 'confirmed')->where('receipt_tx.source_type', 'mfg_receipt')
            ->whereColumn('receipt_tx.source_id', 'erp_mfg_receipts.id')->where('receipt_tx.transaction_type', 'production_receipt_inbound')
            ->where('receipt_tx.posting_status', 'posted')->whereDate(DB::raw($receiptTime), $today);
        $receipts = (clone $receiptQuery)->select('erp_mfg_receipts.*')->selectRaw($receiptTime.' as receipt_confirmed_at')->orderByDesc('erp_mfg_receipts.id')->limit(20)->get();
        $receiptWos = MfgWorkOrder::query()->whereIn('id', $receipts->pluck('work_order_id') ?: [0])->get(['id', 'work_order_no', 'product_name', 'item_name', 'sku_name', 'item_id'])->keyBy('id');
        $serialItems = Item::query()->whereIn('id', $receiptWos->pluck('item_id'))->get()->keyBy('id');
        $warehouses = Warehouse::query()->whereIn('id', $receipts->pluck('warehouse_id') ?: [0])->pluck('warehouse_name', 'id');
        $locations = Location::query()->whereIn('id', $receipts->pluck('location_id') ?: [0])->pluck('location_code', 'id');
        $cards = collect($columns)->flatMap(fn ($c) => $c['cards']);
        $todayMinutes = (float) MfgLaborSession::query()->where('status', '!=', 'active')->whereDate('ended_at', $today)->sum('labor_minutes')
            + MfgLaborSession::query()->where('status', 'active')->get()->sum(fn ($s) => max(0, $s->started_at->diffInSeconds(now(), false)) / 60);
        $reports = MfgUnitReport::query()->orderByDesc('id')->limit(10)->get();
        $reportOps = MfgWorkOrderOperation::query()->whereIn('id', $reports->pluck('work_order_operation_id')->merge(
            MfgUnitReport::query()->where('review_status', 'pending')->pluck('work_order_operation_id')) ?: [0])->pluck('operation_name', 'id');
        $shipping = app(ShipmentBoardQuery::class)->forBoard($filters);
        return [
            'stats' => [
                'in_progress_units' => MfgWorkOrderUnit::query()->whereIn('status', ['in_progress', 'rework'])->count(),
                'today_passed' => MfgUnitReport::query()->where('result', 'passed')->whereDate('created_at', $today)->count(),
                'unclaimed' => $cards->where('state', 'unclaimed')->count(),
                'rework_units' => MfgWorkOrderUnit::query()->where('status', 'rework')->count(),
                'waiting_receipt' => MfgWorkOrderUnit::query()->where('status', 'completed')->count(),
                'pending_receipt_qty' => MfgWorkOrderUnit::query()->where('status', 'pending_receipt')->count(),
                'today_received_qty' => (float) (clone $receiptQuery)->sum('erp_mfg_receipts.qty'),
                'today_hours' => round($todayMinutes / 60, 1),
            ],
            'columns' => $columns,
            'shipment_columns' => $shipping['columns'],
            'waiting_receipt' => $waiting,
            'today_receipts' => $receipts->map(fn ($r) => ['id' => $r->id, 'receipt_no' => $r->receipt_no, 'work_order_no' => $receiptWos->get($r->work_order_id)?->work_order_no,
                'product_name' => ($w = $receiptWos->get($r->work_order_id)) ? ($w->product_name ?: $w->item_name) : null, 'sku_name' => $w?->sku_name,
                'qty' => (float) $r->qty, 'requires_serial_check' => ($serial = $serialItems->get($w?->item_id)) && $serial->serialTrackingMode() !== 'none',
                'serial_nos' => $serial && $serial->serialTrackingMode() !== 'none' ? ($r->serial_nos ?: []) : [], 'operator_name' => $r->confirmed_by_name ?: $r->operator_name,
                'status' => $r->status, 'confirmation_source' => $r->confirmation_source, 'confirmed_at' => (string) $r->receipt_confirmed_at,
                'warehouse_name' => $warehouses[$r->warehouse_id] ?? null, 'location_code' => $locations[$r->location_id] ?? null,
                'created_at' => (string) $r->receipt_confirmed_at, 'requested_at' => ($r->requested_at ?? $r->created_at)?->toDateTimeString()])->values()->all(),
            'pending_receipts' => MfgReceipt::query()->where('status', 'pending')->orderBy('requested_at')->orderBy('id')->limit(10)->get()
                ->map(fn ($r) => app(ReceiptService::class)->present($r, false))->values()->all(),
            'unclaimed' => $cards->where('state', 'unclaimed')->map(fn ($c) => ['operation_id' => $c['operation_id'], 'operation_name' => $c['operation_name'],
                'work_order_no' => $c['work_order_no'], 'product_name' => $c['product_name'], 'sku_name' => $c['sku_name'], 'qty' => count($c['units']),
                'is_custom' => $c['is_custom'], 'planned_finish_date' => $c['planned_finish_date']])->values()->all(),
            'recent_reports' => $reports->map(fn ($r) => ['id' => $r->id, 'time' => $r->created_at?->format('H:i'), 'created_at' => $r->created_at?->toDateTimeString(),
                'reporter_name' => $r->reporter_name, 'operation_name' => $reportOps[$r->work_order_operation_id] ?? null, 'serial_no' => $r->serial_no,
                'short' => substr($r->serial_no, -4), 'result' => $r->result, 'return_operation_name' => $r->return_operation_name,
                'reason' => implode('/', $r->reason_tags ?: []).($r->reason ? '：'.$r->reason : ''), 'is_rework' => (bool) $r->is_rework])->values()->all(),
            'pending_scraps' => $this->pendingScraps($reportOps),
            'owner_options' => MfgTaskParticipant::query()->whereIn('work_order_id', MfgWorkOrder::query()->whereIn('status', self::OPEN)->pluck('id') ?: [0])
                ->where('is_active', true)->select('employee_legacy_id', 'employee_name')->distinct()->get()
                ->map(fn ($p) => ['id' => (int) $p->employee_legacy_id, 'name' => $p->employee_name])->concat($shipping['owners'])->unique('id')->values()->all(),
            'refreshed_at' => now()->toDateTimeString(),
        ];
    }

    public function operationWorkers(int $operationId): array
    {
        return ProductionOperationWorker::query()->where('operation_id', $operationId)->orderBy('id')->get()
            ->map(fn ($w) => ['id' => (int) $w->employee_legacy_id, 'name' => $w->employee_name])->values()->all();
    }

    private function pendingScraps($opNames): array
    {
        $rows = MfgUnitReport::query()->where('review_status', 'pending')->orderBy('id')->limit(50)->get();
        $wos = MfgWorkOrder::query()->whereIn('id', $rows->pluck('work_order_id') ?: [0])->get(['id', 'work_order_no', 'product_name', 'item_name'])->keyBy('id');
        return $rows->map(fn ($r) => ['id' => $r->id, 'serial_no' => $r->serial_no, 'work_order_id' => $r->work_order_id,
            'work_order_no' => $wos->get($r->work_order_id)?->work_order_no, 'product_name' => ($w = $wos->get($r->work_order_id)) ? ($w->product_name ?: $w->item_name) : null,
            'operation_name' => $opNames[$r->work_order_operation_id] ?? MfgWorkOrderOperation::query()->whereKey($r->work_order_operation_id)->value('operation_name'),
            'reason_tags' => $r->reason_tags ?: [], 'reason' => $r->reason, 'photos' => $r->photos ?: [],
            'media' => \App\Services\Erp\ProductionMediaService::forRead($r->media ?: [], $r->photos ?: []), 'reporter_name' => $r->reporter_name,
            'created_at' => $r->created_at?->toDateTimeString()])->values()->all();
    }

    private function taskQuery(int $me, string $tab, array $filters, string $lane = 'normal')
    {
        $query = MfgWorkOrderOperation::query()->from('erp_mfg_work_order_operations as op')
            ->join('erp_mfg_work_orders as wo', 'wo.id', '=', 'op.work_order_id')
            ->where('op.execution_context', 'production')->select('op.*');
        $participant = fn ($q, bool $activeOnly) => $q->selectRaw('1')->from('erp_mfg_task_participants as tp')->whereColumn('tp.work_order_operation_id', 'op.id')
            ->where('tp.employee_legacy_id', $me)->when($activeOnly, fn ($w) => $w->where('tp.is_active', true)->where('tp.lane', $lane));
        $statuses = TaskService::laneStatuses($lane);
        // 本道有这条线能做的件（不含等报废审核的）
        $hereUnits = fn ($q) => $q->selectRaw('1')->from('erp_mfg_work_order_units as u')->whereColumn('u.current_operation_id', 'op.id')->whereIn('u.status', $statuses)
            ->whereNotIn('u.id', DB::table('erp_cancellation_production_dispositions')->whereNull('resolved_at')->whereNotNull('work_order_unit_id')->select('work_order_unit_id'))
            ->whereNotExists(fn ($r) => $r->selectRaw('1')->from('erp_mfg_unit_reports as pr')->whereColumn('pr.unit_id', 'u.id')->where('pr.review_status', 'pending'));
        if ($tab === 'done') {
            $query->whereExists(fn ($q) => $participant($q, false))
                ->where(fn ($q) => $q->where('op.status', 'done')->orWhere('wo.status', 'closed')
                    ->orWhere(fn ($finished) => $finished->where('wo.status', 'completed')->whereNotIn('wo.id', DB::table('erp_shipment_reworks')->where('status', 'in_progress')->whereNotNull('work_order_id')->select('work_order_id'))))
                ->orderByDesc('op.updated_at');
        } elseif ($tab === 'available') {
            $query->where(fn ($q) => $q->whereIn('wo.status', ['released', 'in_progress'])
                ->orWhere(fn ($repair) => $repair->where('wo.status', 'completed')->whereIn('wo.id', DB::table('erp_shipment_reworks')->where('status', 'in_progress')->whereNotNull('work_order_id')->select('work_order_id'))))->where('op.status', '!=', 'done')
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_task_participants as ow')->whereColumn('ow.work_order_operation_id', 'op.id')
                    ->where('ow.role', 'owner')->where('ow.lane', $lane)->where('ow.is_active', true))
                // 已经轮到（前道交过来了）、本道有这条线要做的件才能接；还没交过来的不显示，不能提前占着。
                ->whereIn('op.work_status', ['ready', 'in_progress'])->whereExists($hereUnits)
                ->where(fn ($q) => $q->whereNull('op.operation_id')
                    ->orWhereExists(fn ($w) => $w->selectRaw('1')->from('erp_production_operation_workers as pw')->whereColumn('pw.operation_id', 'op.operation_id')->where('pw.employee_legacy_id', $me))
                    ->orWhereNotExists(fn ($w) => $w->selectRaw('1')->from('erp_production_operation_workers as pw2')->whereColumn('pw2.operation_id', 'op.operation_id')))
                ->orderBy('wo.priority')->orderBy('wo.id')->orderBy('op.sequence');
        } else {
            $query->where(fn ($q) => $q->whereIn('wo.status', self::OPEN)
                ->orWhere(fn ($repair) => $repair->where('wo.status', 'completed')->whereIn('wo.id', DB::table('erp_shipment_reworks')->where('status', 'in_progress')->whereNotNull('work_order_id')->select('work_order_id'))))->where('op.status', '!=', 'done')->whereExists(fn ($q) => $participant($q, true));
            if ($lane === 'rework') {
                // 返工卡：本道还有返工的件（含等报废审核的）才显示
                $query->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_work_order_units as ru')->whereColumn('ru.current_operation_id', 'op.id')->where('ru.status', 'rework'));
            } else {
                // 正常卡：已经轮到；本道还有要做的，或前道还有在做的会交过来，或我正在计时
                $query->where('op.work_status', '!=', 'pending')->where(fn ($w) => $w
                    ->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_work_order_units as nu')->whereColumn('nu.current_operation_id', 'op.id')->whereIn('nu.status', $statuses))
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_work_order_units as uu')->whereColumn('uu.work_order_id', 'op.work_order_id')
                        ->whereIn('uu.status', TaskService::SELECTABLE)
                        ->whereIn('uu.current_operation_id', fn ($o) => $o->select('o2.id')->from('erp_mfg_work_order_operations as o2')
                            ->whereColumn('o2.work_order_id', 'op.work_order_id')->where('o2.execution_context', 'production')
                            ->where(fn ($x) => $x->whereColumn('o2.sequence', '<', 'op.sequence')
                                ->orWhere(fn ($same) => $same->whereColumn('o2.sequence', 'op.sequence')->whereColumn('o2.id', '<', 'op.id')))))
                    ->orWhereExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_labor_sessions as ls')->whereColumn('ls.work_order_operation_id', 'op.id')
                        ->where('ls.employee_legacy_id', $me)->where('ls.status', 'active')->where('ls.lane', 'normal')));
            }
            $query->orderBy('wo.priority')->orderBy('wo.id')->orderBy('op.sequence');
        }
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $like = '%'.$keyword.'%';
            $query->where(fn ($q) => $q->where('wo.work_order_no', 'like', $like)->orWhere('wo.sales_order_no', 'like', $like)->orWhere('wo.product_name', 'like', $like)->orWhere('wo.item_name', 'like', $like)
                ->orWhere('wo.item_code', 'like', $like)->orWhere('wo.sku_name', 'like', $like)->orWhere('wo.customer_name', 'like', $like)
                ->orWhereExists(fn ($it) => $it->selectRaw('1')->from('erp_items as it')->whereColumn('it.id', 'wo.item_id')->where(fn ($w) => $w->where('it.spec', 'like', $like)->orWhere('it.model', 'like', $like)))
                ->orWhereExists(fn ($pl) => $pl->selectRaw('1')->from('erp_mfg_work_order_links as wl2')->join('erp_mfg_work_orders as pwo2', 'pwo2.id', '=', 'wl2.parent_work_order_id')
                    ->whereColumn('wl2.child_work_order_id', 'wo.id')
                    ->where(fn ($w) => $w->where('pwo2.work_order_no', 'like', $like)->orWhere('pwo2.sales_order_no', 'like', $like)->orWhere('pwo2.product_name', 'like', $like)->orWhere('pwo2.item_name', 'like', $like)->orWhere('pwo2.customer_name', 'like', $like)))
                ->orWhereExists(fn ($u) => $u->selectRaw('1')->from('erp_mfg_work_order_units as su')->whereColumn('su.work_order_id', 'wo.id')->where('su.serial_no', 'like', $like)));
        }
        return $query;
    }

    private function taskRow(MfgWorkOrderOperation $op, string $lane, int $me, bool $canExecute): array
    {
        $wo = MfgWorkOrder::query()->with('item:id,item_type,spec,model,material_grade')->findOrFail($op->work_order_id);
        $specInfo = WorkOrderQueryService::itemSpecInfo($wo->item, $wo);
        $parentLink = MfgWorkOrderLink::query()->where('child_work_order_id', $wo->id)->first();
        $parentWo = $parentLink ? MfgWorkOrder::query()->with('item:id,item_type,spec,model,material_grade')->find($parentLink->parent_work_order_id) : null;
        if (! $parentWo && $wo->rework_of_work_order_id) {
            $parentWo = MfgWorkOrder::query()->with('item:id,item_type,spec,model,material_grade')->find($wo->rework_of_work_order_id);
        }
        $parentBrief = $parentWo ? $this->workOrderBrief($parentWo) : null;
        $isSubWorkOrder = $parentBrief !== null || $wo->source_type === 'work_order' || (bool) $wo->rework_of_work_order_id;
        $childIds = MfgWorkOrderLink::query()->where('parent_work_order_id', $wo->id)->orderBy('id')->pluck('child_work_order_id');
        $childProgress = $childIds->isEmpty() ? [] : $this->childProgress($childIds);
        $semiSummary = $childIds->isEmpty() ? [] : MfgWorkOrder::query()->with('item:id,item_type,spec,model,material_grade')
            ->whereIn('id', $childIds)->where('status', '!=', 'cancelled')->orderBy('id')->get()
            ->map(function ($c) use ($childProgress) {
                $cs = WorkOrderQueryService::itemSpecInfo($c->item, $c);
                return [
                    'id' => $c->id, 'work_order_no' => $c->work_order_no, 'item_id' => $c->item_id, 'item_name' => $c->item_name,
                    'item_spec' => $cs['item_spec'], 'material_grade' => $cs['material_grade'], 'specification' => $cs['specification'],
                    'planned_qty' => (float) $c->planned_qty, 'stored_qty' => (float) $c->stored_qty, 'completed_qty' => (float) $c->completed_qty,
                    'unit_name' => $c->unit_name, 'status' => $c->status, 'status_label' => WorkOrderQueryService::STATUS_LABELS[$c->status] ?? $c->status,
                    'progress' => $childProgress[$c->id] ?? [],
                ];
            })->values()->all();
        $units = $this->tasks->selectableUnits($op, $lane);
        $owner = MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('role', 'owner')->where('is_active', true)->first();
        $mine = MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('employee_legacy_id', $me)->where('is_active', true)->first();
        $unitWord = $this->tasks->unitWord($wo);
        // 返工卡：从哪道、谁那里打回来的，什么原因
        $why = null;
        if ($lane === 'rework') {
            $f = MfgUnitReport::query()->whereIn('unit_id', MfgWorkOrderUnit::query()->where('current_operation_id', $op->id)->where('status', 'rework')->select('id'))
                ->where('result', 'failed')->orderByDesc('id')->first();
            $why = $f ? trim(MfgWorkOrderOperation::query()->whereKey($f->work_order_operation_id)->value('operation_name').' '.$f->reporter_name.' 打回：'
                .(implode('/', $f->reason_tags ?: []) ?: '').($f->reason ? ' '.$f->reason : '')) : ($wo->rework_of_work_order_id ? '总装退回的不良件返工' : null);
        }
        $tech = MfgWorkOrderTech::query()->where('work_order_id', $wo->id)->first();
        $myFirst = MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('employee_legacy_id', $me)->min('started_at');
        // 前面工序还在做、还没交到本道的设备
        $before = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->where('execution_context', 'production')
            ->where(fn ($q) => $q->where('sequence', '<', $op->sequence)->orWhere(fn ($w) => $w->where('sequence', $op->sequence)->where('id', '<', $op->id)))->pluck('id');
        $upstream = $before->isEmpty() ? 0 : MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('status', TaskService::SELECTABLE)
            ->whereNotIn('id', CancellationProductionService::blockedUnitIds((int) $wo->id))->whereIn('current_operation_id', $before)->count();
        return [
            'id' => $op->id, 'key' => $op->id.'-'.$lane, 'lane' => $lane, 'operation_name' => $op->operation_name, 'title' => TaskService::laneTitle($op->operation_name, $lane),
            'stage_name' => $op->stage_name, 'status' => $op->status,
            'work_status' => $op->work_status, 'work_status_label' => TaskService::WORK_STATUS_LABELS[$op->work_status] ?? $op->work_status,
            'unit_word' => $unitWord, 'unit_short' => $unitWord === '台设备' ? '台' : $unitWord, 'rework_reason' => $why, 'is_rework_order' => (bool) $wo->rework_of_work_order_id,
            'work_order_id' => $wo->id, 'work_order_no' => $wo->work_order_no, 'source_type' => $wo->source_type,
            'source_label' => $wo->rework_of_work_order_id ? '返工工单' : match ($wo->source_type) { 'stock' => '备货', 'work_order' => '半成品子工单', default => '销售订单' },
            'is_sub_work_order' => $isSubWorkOrder,
            'parent_work_order_id' => $parentBrief['id'] ?? null,
            'parent_work_order_no' => $parentBrief['work_order_no'] ?? null,
            'parent_work_order' => $parentBrief,
            'semi_summary' => $semiSummary,
            'product_name' => $wo->product_name ?: $wo->item_name, 'item_code' => $wo->item_code, 'item_name' => $wo->item_name,
            'sku_code' => $wo->sku_code, 'sku_name' => $wo->sku_name,
            'item_spec' => $specInfo['item_spec'], 'material_grade' => $specInfo['material_grade'], 'specification' => $specInfo['specification'],
            'unit_name' => $wo->unit_name, 'work_order_status' => $wo->status, 'work_order_status_label' => WorkOrderQueryService::STATUS_LABELS[$wo->status] ?? $wo->status,
            'customer_name' => $wo->customer_name ?: ($parentBrief['customer_name'] ?? null), 'sales_order_no' => $wo->sales_order_no ?: ($parentBrief['sales_order_no'] ?? null),
            'is_urgent' => (bool) $wo->is_urgent, 'priority' => (int) ($wo->priority ?: 3), 'priority_label' => \App\Services\Erp\SalesOrderPriorityService::label((int) ($wo->priority ?: 3)), 'is_custom' => (bool) $tech,
            'has_drawing' => MfgDrawing::query()->where('work_order_id', $wo->id)->where('status', 'active')->exists(),
            'drawings_updated' => (bool) ($tech?->drawings_updated_at && $myFirst && $tech->drawings_updated_at->gt($myFirst)),
            'planned_finish_date' => $wo->planned_finish_date?->toDateString(), 'planned_qty' => (float) $wo->planned_qty, 'done_qty' => (float) $op->completed_qty,
            'here_qty' => $units->count(), 'upstream_qty' => $upstream, 'rework_qty' => $units->where('status', 'rework')->count(),
            'owner_name' => $owner?->employee_name, 'is_owner' => $owner && (int) $owner->employee_legacy_id === $me, 'my_role' => $mine?->role,
            'assigned_by_name' => $owner && (int) $owner->employee_legacy_id === $me && $owner->assigned_by_legacy_id && (int) $owner->assigned_by_legacy_id !== $me ? $owner->assigned_by_name : null,
            'timing' => MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('employee_legacy_id', $me)->where('status', 'active')->exists(),
            'paused' => $wo->status === 'paused', 'pause_reason' => $wo->pause_reason,
            'allowed' => ['claim' => $canExecute && $this->tasks->isRunnable($wo) && $op->status !== 'done'
                && ! $owner && $units->isNotEmpty() && $this->tasks->canDo($op, $me)],
        ];
    }

    /**
     * 子工单当前做到哪道、谁接单、谁在计时。上级工序可以先开工等子件，
     * 上级接单人在自己的卡片上直接看到子件进度；口径与车间订单进度共用。
     * @return array<int, string[]> 子工单 ID => 进行中工序描述
     */
    private function childProgress(Collection $childIds): array
    {
        return app(OrderProgressQueryService::class)->currentOperations($childIds);
    }

    private function workOrderBrief(MfgWorkOrder $w): array
    {
        $specInfo = WorkOrderQueryService::itemSpecInfo($w->relationLoaded('item') ? $w->item : null, $w);
        return [
            'id' => $w->id,
            'work_order_no' => $w->work_order_no,
            'source_type' => $w->source_type,
            'source_label' => $w->rework_of_work_order_id ? '返工工单' : match ($w->source_type) { 'stock' => '备货', 'work_order' => '半成品子工单', default => '销售订单' },
            'product_name' => $w->product_name ?: $w->item_name,
            'item_code' => $w->item_code,
            'item_name' => $w->item_name,
            'sku_code' => $w->sku_code,
            'sku_name' => $w->sku_name,
            'item_spec' => $specInfo['item_spec'],
            'material_grade' => $specInfo['material_grade'],
            'specification' => $specInfo['specification'],
            'customer_name' => $w->customer_name,
            'sales_order_no' => $w->sales_order_no,
            'status' => $w->status,
            'status_label' => WorkOrderQueryService::STATUS_LABELS[$w->status] ?? $w->status,
            'planned_qty' => (float) $w->planned_qty,
            'completed_qty' => (float) $w->completed_qty,
            'stored_qty' => (float) $w->stored_qty,
            'unit_name' => $w->unit_name,
            'planned_finish_date' => $w->planned_finish_date?->toDateString(),
            'is_urgent' => (bool) $w->is_urgent,
            'priority' => (int) ($w->priority ?: 3),
            'priority_label' => \App\Services\Erp\SalesOrderPriorityService::label((int) ($w->priority ?: 3)),
            'is_rework' => (bool) $w->rework_of_work_order_id,
            'paused' => $w->status === 'paused',
            'pause_reason' => $w->pause_reason,
        ];
    }

    private function participants(MfgWorkOrderOperation $op, string $lane = 'normal'): array
    {
        $sessions = MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->get()->groupBy('employee_legacy_id');
        return MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('is_active', true)->orderByRaw("FIELD(role, 'owner', 'collaborator')")->orderBy('id')->get()
            ->map(function ($p) use ($sessions) {
                $rows = $sessions->get($p->employee_legacy_id) ?? collect();
                $active = $rows->firstWhere('status', 'active');
                $minutes = $rows->where('status', '!=', 'active')->sum(fn ($s) => (float) $s->labor_minutes)
                    + ($active ? max(0, $active->started_at->diffInSeconds(now(), false)) / 60 : 0);
                return ['employee_legacy_id' => (int) $p->employee_legacy_id, 'employee_name' => $p->employee_name, 'role' => $p->role,
                    'role_label' => $p->role === 'owner' ? '接单人' : '协同', 'share_percent' => $p->share_percent === null ? null : (float) $p->share_percent,
                    'labor_minutes' => round($minutes, 1), 'timing' => (bool) $active];
            })->values()->all();
    }
}
