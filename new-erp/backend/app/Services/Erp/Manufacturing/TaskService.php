<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\MfgLaborSession;
use App\Models\Erp\MfgTaskParticipant;
use App\Models\Erp\MfgUnitReport;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\ProductionOperationWorker;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\ShipmentPackingLaborSession;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\SalesOrderFundingGateService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 车间报工：接单、派工、协同人、开始/暂停计时、按台报合格/不合格、报废确认。
 * 不合格由工人直接退回到本道或之前某道；只有报废要主管确认。
 */
final class TaskService
{
    use ProductionActor;

    public const REASON_TAGS = ['焊缝渗漏', '尺寸不对', '外观划伤', '零件装错', '其他'];
    public const SELECTABLE = ['waiting', 'in_progress', 'rework'];

    public function __construct(
        private readonly WorkOrderService $workOrders,
        private readonly SalesOrderFundingGateService $funding,
        private readonly MaterialIssueService $materials,
        private readonly ReceiptService $receipts,
    ) {}

    public function claim(int $opId, object $actor): void
    {
        DB::transaction(function () use ($opId, $actor) {
            [$wo, $op] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            $me = (int) $this->actorId($actor);
            if ($this->owner($op)) $this->fail('claimed', '这道工序已经有人接了。');
            if (! $this->canDo($op, $me)) $this->fail('not_worker', '你不在这道工序“能做的人”里，请找主管派工。');
            $this->setParticipant($op, $me, $this->actorName($actor), 'owner', $actor);
            $this->workOrders->log($wo, 'task_claimed', null, null, $this->actorName($actor).' 接了'.$op->operation_name, $actor);
        }, 5);
    }

    /** 主管派工或换人：原接单人正在计时则不能换。 */
    public function assign(int $opId, int $employeeId, object $actor): void
    {
        DB::transaction(function () use ($opId, $employeeId, $actor) {
            [$wo, $op] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            $user = $this->activeUser($employeeId);
            if (! $user) $this->fail('user_invalid', '所选人员不存在或已停用。');
            $this->assertExecutor($user);
            if (! $this->canDo($op, $employeeId)) $this->fail('not_worker', '这个人不在这道工序“能做的人”里，请先在工序管理里加上。');
            $old = $this->owner($op);
            if ($old && (int) $old->employee_legacy_id === $employeeId) $this->fail('no_change', '这道工序已经是他在做。');
            if (MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('is_active', true)->whereNotNull('share_percent')->lockForUpdate()->first(['id']))
                $this->fail('share_confirmation_required', '这道工序已约定固定份额，请先由接单人在协同设置中改为按实际工时分配，再改派并重新确认份额。');
            if ($old) {
                if ($this->activeSession($op, (int) $old->employee_legacy_id)) $this->fail('owner_timing', '原接单人 '.$old->employee_name.' 正在计时，请先让他暂停再换人。');
                $old->update(['is_active' => false, 'share_percent' => null]);
            }
            $name = $this->userName($user);
            $this->setParticipant($op, $employeeId, $name, 'owner', $actor);
            $text = $old ? "{$op->operation_name} 由 {$old->employee_name} 改派给 {$name}" : "{$op->operation_name} 派给 {$name}";
            $this->workOrders->log($wo, 'task_assigned', null, null, $text, $actor);
        }, 5);
    }

    /**
     * 接单人设协同人。份额全不填 = 按实际工时分；填了就要连接单人一起合计 100%。
     * @param array<int, array{employee_legacy_id:int, share_percent:?float}> $rows
     */
    public function collaborators(int $opId, array $rows, ?float $ownerShare, object $actor): void
    {
        DB::transaction(function () use ($opId, $rows, $ownerShare, $actor) {
            [$wo, $op] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            $me = (int) $this->actorId($actor);
            $owner = $this->owner($op);
            if (! $owner || (int) $owner->employee_legacy_id !== $me) $this->fail('not_owner', '只有接单人可以设置协同人。');
            $shares = [];
            foreach ($rows as $row) {
                $id = (int) ($row['employee_legacy_id'] ?? 0);
                if ($id && $id !== $me) $shares[$id] = isset($row['share_percent']) && $row['share_percent'] !== '' ? (float) $row['share_percent'] : null;
            }
            $ids = array_keys($shares);
            $users = $ids ? DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->whereNull('deleted_at')
                ->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active'])->orderBy('legacy_id')->lockForUpdate()->get() : collect();
            if ($users->count() !== count($ids)) $this->fail('user_invalid', '协同人必须是在职的账号。');
            foreach ($users as $user) $this->assertExecutor($user);
            $fixed = $ownerShare !== null || in_array(true, array_map(fn ($v) => $v !== null, $shares), true);
            if ($fixed) {
                if ($ownerShare === null || in_array(null, $shares, true)) $this->fail('share_invalid', '要固定份额就每个人都填，不填就按实际工时分。');
                if (abs($ownerShare + array_sum($shares) - 100) > 0.01) $this->fail('share_invalid', '所有人的份额加起来要等于 100%。');
            }
            $removed = MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('role', 'collaborator')->where('is_active', true)
                ->whereNotIn('employee_legacy_id', $ids ?: [0])->lockForUpdate()->get();
            foreach ($removed as $row) {
                if ($this->activeSession($op, (int) $row->employee_legacy_id)) $this->fail('collaborator_timing', $row->employee_name.' 还在计时，请他先暂停再移出。');
                $row->update(['is_active' => false]);
            }
            foreach ($users as $user) {
                $this->setParticipant($op, (int) $user->legacy_id, $this->userName($user), 'collaborator', $actor, $fixed ? $shares[(int) $user->legacy_id] : null);
            }
            $owner->update(['share_percent' => $fixed ? $ownerShare : null]);
            $names = $users->map(fn ($u) => $this->userName($u))->implode('、');
            $this->workOrders->log($wo, 'task_collaborators', null, null, $op->operation_name.' 协同人：'.($names ?: '无').($fixed ? '（固定份额）' : '（按工时分）'), $actor);
        }, 5);
    }

    public function start(int $opId, object $actor): void
    {
        DB::transaction(function () use ($opId, $actor) {
            [$wo, $op] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            if ($op->status === 'done') $this->fail('operation_done', '这道工序已经做完。');
            $me = (int) $this->actorId($actor);
            // 人员维护同样先锁账号再锁资格行，不能反向持锁后互相等待。
            DB::table('erp_legacy_admin_users')->where('legacy_id', $me)->lockForUpdate()->first();
            $owner = $this->owner($op);
            if (! $owner) {
                // 没人接时直接点开始 = 自己接单。
                if (! $this->canDo($op, $me)) $this->fail('not_worker', '你不在这道工序“能做的人”里，请找主管派工。');
                $this->setParticipant($op, $me, $this->actorName($actor), 'owner', $actor);
                $owner = $this->owner($op);
            }
            if (! $this->participant($op, $me)) $this->fail('not_participant', '你不是这道工序的接单人或协同人。');
            if ((int) $owner->employee_legacy_id !== $me && $op->status !== 'in_progress') $this->fail('owner_first', '接单人还没开始，协同人不能先计时。');
            if (! $wo->started_at && $wo->source_type === 'sales_order' && $wo->sales_order_id) {
                $order = SalesOrder::query()->find($wo->sales_order_id);
                $status = $order ? $this->funding->status($order) : ['production_funds_satisfied' => true];
                if (! ($status['production_funds_satisfied'] ?? false)) $this->fail('funds_unsatisfied', $status['production_block_message'] ?? '定金未到，不能开工。');
            }
            if (MfgLaborSession::query()->where('employee_legacy_id', $me)->where('status', 'active')->lockForUpdate()->first())
                $this->fail('already_timing', '你还有别的工序在计时，请先暂停那一道。');
            if (ShipmentPackingLaborSession::query()->where('employee_legacy_id', $me)->where('status', 'ACTIVE')->lockForUpdate()->first(['id']))
                $this->fail('already_timing', '你有打包作业正在计时，请先暂停。');
            $units = $this->selectableUnits($op);
            if ($units->isEmpty()) $this->fail('no_unit', '这道工序现在没有要做的台，等前道交过来再开始。');
            // 本道的料（总装的半成品、外购件）收到才能开工；缺货就停在这里。
            $this->materials->assertReceivedForStart($wo, $op, $units);
            if (! $wo->started_at) {
                $before = $wo->status;
                $wo->update(['status' => 'in_progress', 'started_at' => now(), 'business_version' => (int) $wo->business_version + 1]);
                $this->workOrders->log($wo, 'started', $before, 'in_progress', $this->actorName($actor).' 开始'.$op->operation_name.'，工单开工', $actor);
            }
            if ($op->status !== 'in_progress') $op->update(['status' => 'in_progress']);
            MfgWorkOrderUnit::query()->whereIn('id', $units->pluck('id'))->where('status', 'waiting')->update(['status' => 'in_progress', 'started_at' => now()]);
            MfgLaborSession::create([
                'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id, 'employee_legacy_id' => $me, 'employee_name' => $this->actorName($actor),
                'is_rework' => $units->every(fn ($u) => $u->status === 'rework'), 'status' => 'active', 'started_at' => now(),
            ]);
        }, 5);
    }

    public function pause(int $opId, object $actor): void
    {
        DB::transaction(function () use ($opId, $actor) {
            $session = MfgLaborSession::query()->where('work_order_operation_id', $opId)->where('employee_legacy_id', (int) $this->actorId($actor))
                ->where('status', 'active')->lockForUpdate()->first();
            if (! $session) $this->fail('not_timing', '你在这道工序没有正在计时。');
            self::closeSession($session, 'paused');
        }, 5);
    }

    /** 选中的台本道合格：交下道；最后一道合格就“等入库”。 */
    public function pass(int $opId, array $unitIds, object $actor): array
    {
        return DB::transaction(function () use ($opId, $unitIds, $actor) {
            [$wo, $op] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            $this->assertReporter($op, $actor);
            $sessions = MfgLaborSession::query()->where('work_order_operation_id', $op->id)->lockForUpdate()->get();
            if ($sessions->isEmpty()) $this->fail('no_labor', '这道工序还没有计时记录，请先点“开始”。');
            $units = $this->pickUnits($op, $unitIds);
            $this->materials->assertReceivedForStart($wo, $op, $units);
            $ops = $this->productionOps($wo);
            $pos = $ops->pluck('id')->flip();
            $next = $ops->get($pos[$op->id] + 1);
            $reportedAt = now();
            $snapshot = $this->participantsSnapshot($op, $sessions, $reportedAt);
            $firstPass = 0;
            $finished = 0;
            $receiptUnitIds = [];
            foreach ($units as $unit) {
                $shipmentRepair = \App\Models\Erp\ShipmentRework::query()->where('unit_id', $unit->id)->where('status', 'in_progress')->lockForUpdate()->first();
                $wasRework = $unit->status === 'rework';
                if (! MfgUnitReport::query()->where('unit_id', $unit->id)->where('work_order_operation_id', $op->id)->where('result', 'passed')->lockForUpdate()->first(['id'])) $firstPass++;
                $this->report($wo, $op, $unit, 'passed', $actor, ['is_rework' => $wasRework, 'participants_snapshot' => $snapshot, 'created_at' => $reportedAt]);
                $stillRework = false;
                if ($wasRework) {
                    $failedAt = MfgUnitReport::query()->where('unit_id', $unit->id)->where('result', 'failed')->orderByDesc('id')->lockForUpdate()->value('work_order_operation_id');
                    $stillRework = $failedAt && isset($pos[$failedAt]) && $pos[$failedAt] > $pos[$op->id];
                }
                if ($next) {
                    $unit->update(['status' => $stillRework || $shipmentRepair ? 'rework' : 'in_progress', 'current_operation_id' => $next->id]);
                    if ($next->status === 'done') $next->update(['status' => 'in_progress']);
                    $text = "{$op->operation_name}合格，交{$next->operation_name}";
                } else {
                    if ($shipmentRepair) {
                        // 已在库原机返工只完成本轮维修，不增加首次制造成果，也不再入库一台。
                        $unit->update(['status' => 'stored', 'current_operation_id' => null]);
                        $shipmentRepair->update(['status' => 'completed', 'completed_at' => $reportedAt]);
                        $text = "{$op->operation_name}返修合格，等待返回成品位重新试水";
                    } else {
                    $unit->update(['status' => 'completed', 'current_operation_id' => null, 'completed_at' => $reportedAt]);
                    $finished++;
                    $receiptUnitIds[] = (int) $unit->id;
                    $text = "{$op->operation_name}合格，生产完成，等入库";
                    }
                }
                $this->workOrders->log($wo, 'unit_passed', $wasRework ? 'rework' : 'in_progress', $next ? null : ($shipmentRepair ? 'stored' : 'completed'),
                    $unit->serial_no.' '.$text.($wasRework ? '（返工）' : ''), $actor, ['operation_id' => $op->id], $unit->id);
            }
            // 物料策略设为“指定工序完成时”生成序列号的，这道合格就把台号换成正式序列号。
            foreach (app(UnitSerialService::class)->assignIfDue($wo, $units, UnitSerialService::EVENT_OPERATION_PASSED, $op) as $unitId => [$from, $to]) {
                $this->workOrders->log($wo, 'serial_assigned', null, null, "{$op->operation_name}完成，台号 {$from} 生成序列号 {$to}", $actor, ['from' => $from, 'to' => $to], $unitId);
            }
            if ($firstPass) $op->update(['completed_qty' => (float) $op->completed_qty + $firstPass]);
            if ($finished) $wo->update(['completed_qty' => (float) $wo->completed_qty + $finished]);
            $this->refreshOperation($wo, $op, $reportedAt);
            // 总装做完、订单马上要发：自动记入成品待发区，直接去发货试水。
            $staged = null;
            if ($finished) {
                try {
                    $staged = $this->receipts->autoStage($wo->id, $receiptUnitIds, $actor);
                } catch (\App\Exceptions\Erp\WorkOrderDomainException|\Illuminate\Validation\ValidationException $e) {
                    // 自动入待发区失败不影响报工，留给仓库手工入库。
                    $message = $e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
                    $this->workOrders->log($wo, 'staging_failed', $wo->status, $wo->status, '自动记入成品待发区没成功：'.$message.'，请仓库手工入库', $actor);
                }
            }
            return ['passed' => $units->count(), 'finished' => $finished, 'next_operation_name' => $next?->operation_name,
                'staged_qty' => $staged ? (int) $staged->qty : 0, 'staged_receipt_no' => $staged?->receipt_no];
        }, 5);
    }

    /**
     * 不合格：退回本道或之前某道重做（直接生效）；或申请报废（主管确认前台停在本道、不能再报）。
     * @param array{mode:string, return_operation_id:?int, reason_tags:array, reason:?string, photos:array} $data
     */
    public function reject(int $opId, array $unitIds, array $data, object $actor): array
    {
        return DB::transaction(function () use ($opId, $unitIds, $data, $actor) {
            [$wo, $op] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            $this->assertReporter($op, $actor);
            $tags = array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 30), (array) ($data['reason_tags'] ?? []))));
            $reason = trim((string) ($data['reason'] ?? ''));
            if (! $tags && $reason === '') $this->fail('reason_required', '请选不合格原因或写一句说明。');
            $photos = array_values(array_slice(array_filter((array) ($data['photos'] ?? []), fn ($p) => is_string($p) && $p !== ''), 0, 9));
            $why = implode('、', array_filter([$tags ? implode('/', $tags) : null, $reason ?: null]));
            $units = $this->pickUnits($op, $unitIds);
            $extra = ['reason_tags' => $tags ?: null, 'reason' => $reason ?: null, 'photos' => $photos ?: null];
            if (($data['mode'] ?? 'return') === 'scrap') {
                if (\App\Models\Erp\ShipmentRework::query()->whereIn('unit_id', $units->pluck('id'))->where('status', 'in_progress')->exists())
                    $this->fail('stored_serial_scrap', '发货返修设备已经入库，须使用正式库存报废处理，不能只从制造产量删除。');
                foreach ($units as $unit) {
                    $this->report($wo, $op, $unit, 'scrap', $actor, $extra + ['is_rework' => $unit->status === 'rework', 'review_status' => 'pending']);
                    $unit->update(['status' => 'rework']);
                    $this->workOrders->log($wo, 'unit_scrap_requested', null, 'rework', "{$unit->serial_no} 在{$op->operation_name}申请报废：{$why}，等主管确认", $actor, [], $unit->id);
                }
                return ['mode' => 'scrap', 'count' => $units->count()];
            }
            $ops = $this->productionOps($wo);
            $pos = $ops->pluck('id')->flip();
            $target = $ops->firstWhere('id', (int) ($data['return_operation_id'] ?? 0));
            if (! $target || $pos[$target->id] > $pos[$op->id]) $this->fail('return_invalid', '只能退回本道或之前的工序。');
            foreach ($units as $unit) {
                $this->report($wo, $op, $unit, 'failed', $actor, $extra + ['is_rework' => $unit->status === 'rework',
                    'return_operation_id' => $target->id, 'return_operation_name' => $target->operation_name]);
                $unit->update(['status' => 'rework', 'rework_count' => (int) $unit->rework_count + 1, 'current_operation_id' => $target->id]);
                $where = $target->id === $op->id ? '本道重做' : '退回'.$target->operation_name;
                $this->workOrders->log($wo, 'unit_failed', null, 'rework', "{$unit->serial_no} {$op->operation_name}不合格，{$where}：{$why}", $actor, ['operation_id' => $op->id, 'return_operation_id' => $target->id], $unit->id);
            }
            if ($target->id !== $op->id && $target->status === 'done') $target->update(['status' => 'in_progress']);
            $this->refreshOperation($wo, $op);
            return ['mode' => 'return', 'count' => $units->count(), 'return_operation_name' => $target->operation_name];
        }, 5);
    }

    /** 主管确认报废：通过 = 这台报废；驳回 = 继续在本道返工。 */
    public function reviewScrap(int $reportId, bool $approve, ?string $remark, object $actor): void
    {
        DB::transaction(function () use ($reportId, $approve, $remark, $actor) {
            $woId = MfgUnitReport::query()->whereKey($reportId)->value('work_order_id');
            if (! $woId) $this->fail('not_found', '报废申请不存在。', 404);
            $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($woId);
            $report = MfgUnitReport::query()->lockForUpdate()->findOrFail($reportId);
            if ($report->result !== 'scrap' || $report->review_status !== 'pending') $this->fail('status_invalid', '这条报废申请已经处理过了。');
            $unit = MfgWorkOrderUnit::query()->lockForUpdate()->findOrFail($report->unit_id);
            $op = MfgWorkOrderOperation::query()->lockForUpdate()->findOrFail($report->work_order_operation_id);
            $report->update(['review_status' => $approve ? 'approved' : 'rejected', 'reviewed_by_legacy_id' => $this->actorId($actor),
                'reviewed_by_name' => $this->actorName($actor), 'reviewed_at' => now(), 'review_remark' => $remark ?: null]);
            if (! $approve) {
                $this->workOrders->log($wo, 'unit_scrap_rejected', 'rework', 'rework', "{$unit->serial_no} 报废申请被驳回，继续在{$op->operation_name}返工".($remark ? '：'.$remark : ''), $actor, [], $unit->id);
                return;
            }
            $before = $unit->status;
            $unit->update(['status' => 'scrapped', 'current_operation_id' => null]);
            $this->workOrders->log($wo, 'unit_scrapped', $before, 'scrapped', "{$unit->serial_no} 确认报废（{$op->operation_name}）".($remark ? '：'.$remark : ''), $actor, [], $unit->id);
            // 上游最后一台报废后，下游可能已无台可做，必须一并结束这些工序的计时。
            $ops = $this->productionOps($wo);
            foreach ($ops->slice($ops->pluck('id')->search($op->id)) as $affected) {
                $this->refreshOperation($wo, $affected);
            }
            $this->workOrders->refreshCompletion($wo, $actor);
        }, 5);
    }

    /** 工序管理里设置“能做的人”；不设 = 有报工权限的人都能做。 */
    public function saveWorkers(int $operationId, array $employeeIds, object $actor): array
    {
        return DB::transaction(function () use ($operationId, $employeeIds, $actor) {
            \App\Models\Erp\ProductionOperation::query()->lockForUpdate()->findOrFail($operationId);
            $ids = array_values(array_unique(array_filter(array_map('intval', $employeeIds))));
            $users = $ids ? DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->whereNull('deleted_at')
                ->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active'])->orderBy('legacy_id')->lockForUpdate()->get()->keyBy('legacy_id') : collect();
            if ($users->count() !== count($ids)) $this->fail('user_invalid', '所选人员必须是未删除的在职账号，请刷新后重选。');
            foreach ($users as $user) $this->assertExecutor($user);
            ProductionOperationWorker::query()->where('operation_id', $operationId)->whereNotIn('employee_legacy_id', $ids ?: [0])->delete();
            foreach ($ids as $id) {
                ProductionOperationWorker::query()->lockForUpdate()->updateOrCreate(['operation_id' => $operationId, 'employee_legacy_id' => $id],
                    ['employee_name' => $this->userName($users[$id]), 'created_by_legacy_id' => $this->actorId($actor)]);
            }
            return ProductionOperationWorker::query()->where('operation_id', $operationId)->orderBy('id')->get()
                ->map(fn ($w) => ['id' => (int) $w->employee_legacy_id, 'name' => $w->employee_name])->values()->all();
        }, 5);
    }

    public static function closeSession(MfgLaborSession $session, string $status, ?CarbonInterface $endedAt = null): void
    {
        $ended = $endedAt ?? now();
        $session->update(['status' => $status, 'ended_at' => $ended,
            'labor_minutes' => round(max(0, $session->started_at->diffInSeconds($ended, false)) / 60, 2)]);
    }

    /** 某人能不能做这道工序：工序上没设“能做的人”时，有报工权限的人都能做。 */
    public function canDo(MfgWorkOrderOperation $op, int $employeeId): bool
    {
        if (! $op->operation_id) return true;
        $workers = ProductionOperationWorker::query()->where('operation_id', $op->operation_id)
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->pluck('employee_legacy_id')->map(fn ($v) => (int) $v);
        return $workers->isEmpty() || $workers->contains($employeeId);
    }

    /** 本道现在能报的台：停在本道、没在等报废确认。 */
    public function selectableUnits(MfgWorkOrderOperation $op): Collection
    {
        $query = MfgWorkOrderUnit::query()->where('work_order_id', $op->work_order_id)->where('current_operation_id', $op->id)
            ->whereIn('status', self::SELECTABLE)->orderBy('unit_no');
        if (DB::transactionLevel() > 0) {
            // 待报废和台状态都要读当前值，不能混用等待工单锁前建立的旧视图。
            $pending = MfgUnitReport::query()->where('work_order_id', $op->work_order_id)->where('review_status', 'pending')->lockForUpdate()->pluck('unit_id');
            return $query->whereNotIn('id', $pending)->lockForUpdate()->get();
        }
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_unit_reports as r')
            ->whereColumn('r.unit_id', 'erp_mfg_work_order_units.id')->where('r.review_status', 'pending'))->get();
    }

    private function lockTask(int $opId): array
    {
        $woId = MfgWorkOrderOperation::query()->whereKey($opId)->value('work_order_id');
        if (! $woId) $this->fail('not_found', '生产任务不存在。', 404);
        $orderId = MfgWorkOrder::query()->whereKey($woId)->value('sales_order_id');
        if ($orderId) SalesOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail();
        $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($woId);
        $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->lockForUpdate()->findOrFail($opId);
        if ($op->execution_context !== 'production') $this->fail('not_production', '这道不是车间工序，不能在这里报工。');
        return [$wo, $op];
    }

    private function assertRunnable(MfgWorkOrder $wo, MfgWorkOrderOperation $op): void
    {
        if ($wo->status === 'paused') $this->fail('paused', '工单已暂停（'.($wo->pause_reason ?: '未写原因').'），暂时不能报工。');
        if (! $this->isRunnable($wo)) $this->fail('status_invalid', '工单不在生产中，也没有本机发货返工任务，不能报工。');
    }

    public function isRunnable(MfgWorkOrder $wo): bool
    {
        return in_array($wo->status, ['released', 'in_progress'], true) || ($wo->status === 'completed'
            && \App\Models\Erp\ShipmentRework::query()->where('work_order_id', $wo->id)->where('status', 'in_progress')->exists());
    }

    private function assertReporter(MfgWorkOrderOperation $op, object $actor): void
    {
        if (! $this->participant($op, (int) $this->actorId($actor))) $this->fail('not_participant', '只有这道工序的接单人和协同人可以报工。');
    }

    private function pickUnits(MfgWorkOrderOperation $op, array $unitIds): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $unitIds))));
        if (! $ids) $this->fail('unit_required', '请选择这次做完的台。');
        $units = MfgWorkOrderUnit::query()->where('work_order_id', $op->work_order_id)->whereIn('id', $ids)->orderBy('unit_no')->lockForUpdate()->get();
        $selectable = $this->selectableUnits($op)->pluck('id')->all();
        if ($units->count() !== count($ids) || $units->contains(fn ($u) => ! in_array($u->id, $selectable, true)))
            $this->fail('unit_invalid', '选的台不在这道工序上或正在等报废确认，请刷新后重选。');
        return $units;
    }

    private function productionOps(MfgWorkOrder $wo): Collection
    {
        return MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->where('execution_context', 'production')
            ->orderBy('sequence')->orderBy('id')->lockForUpdate()->get()->values();
    }

    /** 本道和之前各道都没有在做的台了，本道就算做完，同时结束本道所有计时。 */
    private function refreshOperation(MfgWorkOrder $wo, MfgWorkOrderOperation $op, ?CarbonInterface $endedAt = null): void
    {
        $ops = $this->productionOps($wo);
        $upTo = $ops->slice(0, $ops->pluck('id')->search($op->id) + 1)->pluck('id');
        $alive = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereIn('status', self::SELECTABLE)->whereIn('current_operation_id', $upTo)->lockForUpdate()->first(['id']);
        if ($alive) {
            if ($op->status === 'done') $op->update(['status' => 'in_progress']);
            return;
        }
        $op->update(['status' => 'done']);
        foreach (MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('status', 'active')->lockForUpdate()->get() as $session) {
            self::closeSession($session, 'completed', $endedAt);
        }
    }

    private function report(MfgWorkOrder $wo, MfgWorkOrderOperation $op, MfgWorkOrderUnit $unit, string $result, object $actor, array $extra): void
    {
        MfgUnitReport::create($extra + [
            'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id, 'unit_id' => $unit->id, 'serial_no' => $unit->serial_no,
            'result' => $result, 'reporter_legacy_id' => $this->actorId($actor), 'reporter_name' => $this->actorName($actor),
        ]);
    }

    private function participantsSnapshot(MfgWorkOrderOperation $op, Collection $sessions, CarbonInterface $reportedAt): array
    {
        $participants = MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('is_active', true)->orderByRaw("FIELD(role, 'owner', 'collaborator')")->lockForUpdate()->get();
        if ($participants->contains(fn ($p) => $p->share_percent !== null)
            && ($participants->contains(fn ($p) => $p->share_percent === null) || abs($participants->sum('share_percent') - 100) > 0.01))
            $this->fail('share_confirmation_required', '固定份额必须每个人都填写且合计为 100%，请由接单人在协同设置中重新确认。');
        // 同批报工和完成计时共用这一时刻，快照包含尚未结束的真实工时段。
        $minutes = $sessions->groupBy('employee_legacy_id')->map(fn ($rows) => $rows->sum(fn ($s) => $s->status === 'active'
            ? round(max(0, $s->started_at->diffInSeconds($reportedAt, false)) / 60, 2) : (float) $s->labor_minutes));
        return $participants
            ->map(fn ($p) => ['employee_legacy_id' => (int) $p->employee_legacy_id, 'employee_name' => $p->employee_name, 'role' => $p->role,
                'share_percent' => $p->share_percent === null ? null : (float) $p->share_percent, 'labor_minutes' => round((float) ($minutes[$p->employee_legacy_id] ?? 0), 2)])
            ->values()->all();
    }

    private function owner(MfgWorkOrderOperation $op): ?MfgTaskParticipant
    {
        return MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('role', 'owner')->where('is_active', true)->lockForUpdate()->first();
    }

    private function participant(MfgWorkOrderOperation $op, int $employeeId): ?MfgTaskParticipant
    {
        return MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('employee_legacy_id', $employeeId)->where('is_active', true)->lockForUpdate()->first();
    }

    private function activeSession(MfgWorkOrderOperation $op, int $employeeId): ?MfgLaborSession
    {
        return MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('employee_legacy_id', $employeeId)->where('status', 'active')->lockForUpdate()->first();
    }

    private function setParticipant(MfgWorkOrderOperation $op, int $employeeId, string $name, string $role, object $actor, ?float $share = null): void
    {
        MfgTaskParticipant::query()->lockForUpdate()->updateOrCreate(['work_order_operation_id' => $op->id, 'employee_legacy_id' => $employeeId], [
            'work_order_id' => $op->work_order_id, 'employee_name' => $name, 'role' => $role, 'is_active' => true, 'share_percent' => $share,
            'assigned_by_legacy_id' => $this->actorId($actor), 'assigned_by_name' => $this->actorName($actor), 'joined_at' => now(),
        ]);
    }

    private function activeUser(int $id): ?object
    {
        return DB::table('erp_legacy_admin_users')->where('legacy_id', $id)->whereNull('deleted_at')
            ->whereIn(DB::raw('LOWER(TRIM(status))'), ['normal', 'active'])->lockForUpdate()->first();
    }

    private function assertExecutor(object $user): void
    {
        $auth = app(AuthContextService::class);
        if (! $auth->isSuperAdmin($user) && ! in_array('production.task.execute', $auth->permissionCodes($user), true))
            $this->fail('user_invalid', $this->userName($user).' 没有生产报工权限，不能参与这道工序。');
    }

    private function userName(object $user): string { return (string) (($user->nickname ?? null) ?: ($user->username ?? null) ?: ('#'.$user->legacy_id)); }
}
