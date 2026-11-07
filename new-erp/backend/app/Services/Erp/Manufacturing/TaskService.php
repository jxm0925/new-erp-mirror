<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Item;
use App\Models\Erp\MfgLaborSession;
use App\Models\Erp\MfgTaskParticipant;
use App\Models\Erp\MfgUnitReport;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLink;
use App\Models\Erp\MfgWorkOrderMaterial;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\ProductionOperationWorker;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\ShipmentPackingLaborSession;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionMediaService;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * 车间报工：接单、派工、协同人、开始/暂停计时、按设备报合格/不合格、报废确认。
 * 不合格由工人直接退回到本道或之前某道；报废按审核人规则（ProductionReviewers）确认或直接生效。
 * 每道工序分两条线（lane）：normal 正常生产、rework 返工；返工单独接单、单独计时，返工工时不算提成。
 * 工序状态 work_status：pending 未到 / ready 可接 / in_progress 进行中 / done 完成，前道交过来才轮到下道。
 */
final class TaskService
{
    use ProductionActor;

    public const REASON_TAGS = ['焊缝渗漏', '尺寸不对', '外观划伤', '零件装错', '其他'];
    public const SELECTABLE = ['waiting', 'in_progress', 'rework'];
    public const LANES = ['normal', 'rework'];
    public const WORK_STATUS_LABELS = ['pending' => '未到', 'ready' => '可接', 'in_progress' => '进行中', 'done' => '完成'];
    public const AUTO_STOP_HOURS = 4;

    public function __construct(
        private readonly WorkOrderService $workOrders,
        private readonly MaterialIssueService $materials,
    ) {}

    public static function laneStatuses(?string $lane): array
    {
        return match ($lane) { 'normal' => ['waiting', 'in_progress'], 'rework' => ['rework'], default => self::SELECTABLE };
    }

    public static function lane(?string $lane): string { return $lane === 'rework' ? 'rework' : 'normal'; }

    public static function laneTitle(string $name, string $lane): string { return $lane === 'rework' ? $name.'（返工）' : $name; }

    public function claim(int $opId, object $actor, string $lane = 'normal'): void
    {
        DB::transaction(function () use ($opId, $actor, $lane) {
            [$wo, $op] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            $me = (int) $this->actorId($actor);
            if ($this->owner($op, $lane)) $this->fail('claimed', '这道工序'.($lane === 'rework' ? '的返工' : '').'已经有人接了。');
            if ($this->selectableUnits($op, $lane)->isEmpty())
                $this->fail('not_ready', $lane === 'rework' ? '这道工序现在没有要返工的'.$this->unitNoun($wo).'。' : '这道工序还没轮到：前道还没交过来，交过来后再接。');
            if (! $this->canDo($op, $me)) $this->fail('not_worker', '你不在这道工序“能做的人”里，请找主管派工。');
            $this->setParticipant($op, $me, $this->actorName($actor), 'owner', $actor, null, $lane);
            $this->workOrders->log($wo, 'task_claimed', null, null, $this->actorName($actor).' 接了'.self::laneTitle($op->operation_name, $lane), $actor, ['lane' => $lane]);
        }, 5);
    }

    /** 主管派工或换人：原接单人正在计时则不能换。 */
    public function assign(int $opId, int $employeeId, object $actor, string $lane = 'normal'): void
    {
        DB::transaction(function () use ($opId, $employeeId, $actor, $lane) {
            [$wo, $op] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            $user = $this->activeUser($employeeId);
            if (! $user) $this->fail('user_invalid', '所选人员不存在或已停用。');
            $this->assertExecutor($user);
            $this->assertWorkerStaff($user);
            if (! $this->canDo($op, $employeeId)) $this->fail('not_worker', '这个人不在这道工序“能做的人”里，请先在工序管理里加上。');
            $old = $this->owner($op, $lane);
            if ($old && (int) $old->employee_legacy_id === $employeeId) $this->fail('no_change', '这道工序已经是他在做。');
            if (MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('is_active', true)->whereNotNull('share_percent')->lockForUpdate()->first(['id']))
                $this->fail('share_confirmation_required', '这道工序已约定固定份额，请先由接单人在协同设置中改为按实际工时分配，再改派并重新确认份额。');
            if ($old) {
                if ($this->activeSession($op, (int) $old->employee_legacy_id, $lane)) $this->fail('owner_timing', '原接单人 '.$old->employee_name.' 正在计时，请先让他暂停再换人。');
                $old->update(['is_active' => false, 'share_percent' => null]);
            }
            $name = $this->userName($user);
            $this->setParticipant($op, $employeeId, $name, 'owner', $actor, null, $lane);
            $title = self::laneTitle($op->operation_name, $lane);
            $text = $old ? "{$title} 由 {$old->employee_name} 改派给 {$name}" : "{$title} 派给 {$name}";
            $this->workOrders->log($wo, 'task_assigned', null, null, $text, $actor, ['lane' => $lane]);
        }, 5);
    }

    /**
     * 接单人设协同人。份额全不填 = 按实际工时分；填了就要连接单人一起合计 100%。
     * @param array<int, array{employee_legacy_id:int, share_percent:?float}> $rows
     */
    public function collaborators(int $opId, array $rows, ?float $ownerShare, object $actor, string $lane = 'normal', bool $updateShares = true): void
    {
        DB::transaction(function () use ($opId, $rows, $ownerShare, $actor, $lane, $updateShares) {
            [$wo, $op] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            $me = (int) $this->actorId($actor);
            $owner = $this->owner($op, $lane);
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
            foreach ($users as $user) {
                $this->assertExecutor($user);
                if (! $this->canDo($op, (int) $user->legacy_id)) $this->fail('not_worker', $this->userName($user).'不在本工序指定人员中，请先在工序管理中维护。');
            }
            // 已在本工序协同的人保留；新增协同人须是有效的生产工人档案。
            $current = MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('is_active', true)->pluck('employee_legacy_id')->map(fn ($v) => (int) $v)->all();
            foreach ($users as $user) if (! in_array((int) $user->legacy_id, $current, true)) $this->assertWorkerStaff($user);
            if (! $updateShares) {
                $existing = MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('is_active', true)->lockForUpdate()->get();
                if ($existing->contains(fn ($person) => $person->share_percent !== null)
                    && (array_diff($current, array_merge([$me], $ids)) || array_diff(array_merge([$me], $ids), $current))) {
                    $this->fail('share_confirmation_required', '本任务保留旧固定分摊比例，换人会改变提成归属；请先由管理员处理旧比例，不能在隐藏比例的界面自动重分。');
                }
                $ownerShare = $owner->share_percent === null ? null : (float) $owner->share_percent;
                foreach ($ids as $id) $shares[$id] = $existing->firstWhere('employee_legacy_id', $id)?->share_percent;
            }
            $fixed = $ownerShare !== null || in_array(true, array_map(fn ($v) => $v !== null, $shares), true);
            if ($fixed) {
                if ($ownerShare === null || in_array(null, $shares, true)) $this->fail('share_invalid', '要固定份额就每个人都填，不填就按实际工时分。');
                if (abs($ownerShare + array_sum($shares) - 100) > 0.01) $this->fail('share_invalid', '所有人的份额加起来要等于 100%。');
            }
            $removed = MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('role', 'collaborator')->where('is_active', true)
                ->whereNotIn('employee_legacy_id', $ids ?: [0])->lockForUpdate()->get();
            foreach ($removed as $row) {
                if ($this->activeSession($op, (int) $row->employee_legacy_id, $lane)) $this->fail('collaborator_timing', $row->employee_name.' 还在计时，请他先暂停再移出。');
                $row->update(['is_active' => false]);
            }
            foreach ($users as $user) {
                $this->setParticipant($op, (int) $user->legacy_id, $this->userName($user), 'collaborator', $actor, $fixed ? $shares[(int) $user->legacy_id] : null, $lane);
            }
            $owner->update(['share_percent' => $fixed ? $ownerShare : null]);
            $names = $users->map(fn ($u) => $this->userName($u))->implode('、');
            $this->workOrders->log($wo, 'task_collaborators', null, null, self::laneTitle($op->operation_name, $lane).' 协同人：'.($names ?: '无').($fixed ? '（固定份额）' : '（按工时分）'), $actor);
        }, 5);
    }

    public function start(int $opId, object $actor, string $lane = 'normal', ?array $unitIds = null): void
    {
        $this->closeOverdueSessions();
        DB::transaction(function () use ($opId, $actor, $lane, $unitIds) {
            [$wo, $op, $order] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            if ($op->status === 'done') $this->fail('operation_done', '这道工序已经做完。');
            $me = (int) $this->actorId($actor);
            // 人员维护同样先锁账号再锁资格行，不能反向持锁后互相等待。
            DB::table('erp_legacy_admin_users')->where('legacy_id', $me)->lockForUpdate()->first();
            $units = $this->selectableUnits($op, $lane);
            if ($unitIds !== null) $units = $this->pickUnits($op, $unitIds, $lane);
            CancellationProductionService::assertProductionAllowed((int) $wo->id, $units->pluck('id')->map(fn ($id) => (int) $id)->all());
            if ($units->isEmpty()) $this->fail('no_unit', $lane === 'rework' ? '这道工序现在没有要返工的'.$this->unitNoun($wo).'。'
                : '这道工序现在没有要做的'.$this->unitNoun($wo).'，等前道交过来再开始。');
            $owner = $this->owner($op, $lane);
            if (! $owner) {
                // 没人接时直接点开始 = 自己接单。
                if (! $this->canDo($op, $me)) $this->fail('not_worker', '你不在这道工序“能做的人”里，请找主管派工。');
                $this->setParticipant($op, $me, $this->actorName($actor), 'owner', $actor, null, $lane);
                $owner = $this->owner($op, $lane);
            }
            if (! $this->participant($op, $me, $lane)) $this->fail('not_participant', '你不是这道工序'.($lane === 'rework' ? '返工' : '').'的接单人或协同人。');
            if ((int) $owner->employee_legacy_id !== $me
                && ! MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('employee_legacy_id', $owner->employee_legacy_id)->exists())
                $this->fail('owner_first', '接单人还没开始，协同人不能先计时。');
            $this->assertSalesOrderNotCancelled($wo, $order);
            DispositionLaborService::assertNotTiming($me);
            if (MfgLaborSession::query()->where('employee_legacy_id', $me)->where('status', 'active')->lockForUpdate()->first())
                $this->fail('already_timing', '你还有别的工序在计时，请先暂停那一道。');
            if (ShipmentPackingLaborSession::query()->where('employee_legacy_id', $me)->where('status', 'ACTIVE')->lockForUpdate()->first(['id']))
                $this->fail('already_timing', '你有打包作业正在计时，请先暂停。');
            if ($no = CuttingRunService::activeFor($me)) $this->fail('already_timing', "你在集中开料 {$no} 还在计时，请先暂停那一批。");
            // 自制半成品和下料留到完成时校验。返工沿用原来的料。
            if ($lane === 'normal') $this->materials->assertReceivedForStart($wo, $op, $units);
            $this->markStarted($wo, $op, $actor);
            if ($op->status !== 'in_progress') $op->update(['status' => 'in_progress']);
            MfgWorkOrderUnit::query()->whereIn('id', $units->pluck('id'))->where('status', 'waiting')->update(['status' => 'in_progress', 'started_at' => now()]);
            MfgLaborSession::create([
                'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id, 'employee_legacy_id' => $me, 'employee_name' => $this->actorName($actor),
                'unit_ids' => $units->pluck('id')->map(fn ($id) => (int) $id)->all(),
                'is_rework' => $lane === 'rework', 'lane' => $lane, 'status' => 'active', 'started_at' => now(),
            ]);
            self::syncWorkStatus([(int) $wo->id]);
        }, 5);
    }

    /**
     * 开始计时后的提醒：自制子件不挡开工，但若仓库可用库存加已开子工单仍不够，
     * 说明还没人去做这批子件，到报合格时才会被收料校验拦住。这里只提示、不拦截，
     * 只读计算，放在开工事务之外，失败也不能影响已经成功的开工。
     * @return string[]
     */
    public function childOrderReminders(int $opId): array
    {
        $op = MfgWorkOrderOperation::query()->find($opId);
        $wo = $op ? MfgWorkOrder::query()->find($op->work_order_id) : null;
        if (! $wo) return [];
        $materials = MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->where('participates_in_kitting', true)
            ->where('required_qty', '>', 0)->get(['component_item_id', 'component_item_name', 'unit_name', 'required_qty', 'issued_qty']);
        if ($materials->isEmpty()) return [];
        $items = Item::query()->whereIn('id', $materials->pluck('component_item_id')->unique())->where('status', 'enabled')->get()
            ->filter(fn (Item $item) => WorkOrderService::isChildOrderItem($item))->keyBy('id');
        $orders = app(WorkOrderService::class);
        $reminders = [];
        foreach ($materials->groupBy('component_item_id') as $itemId => $rows) {
            if (! $items->has($itemId) || (float) $rows->sum('issued_qty') + 0.00001 >= (float) $rows->sum('required_qty')) continue;
            $gap = $orders->semiGap($wo, (int) $itemId);
            if ($gap <= 0.00001) continue;
            $first = $rows->first();
            $reminders[] = ($first->component_item_name ?: $items[$itemId]->item_name).' 还差 '.rtrim(rtrim(number_format($gap, 4, '.', ''), '0'), '.')
                .($first->unit_name ?: '').'（仓库可用库存不够，也还没开子工单）';
        }

        return $reminders;
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

    /** 主管替忘了停的人停止计时（人走了、手机没电等）。 */
    public function stopTiming(int $opId, int $employeeId, object $actor): void
    {
        DB::transaction(function () use ($opId, $employeeId, $actor) {
            [$wo, $op] = $this->lockTask($opId);
            $session = MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('employee_legacy_id', $employeeId)
                ->where('status', 'active')->lockForUpdate()->first();
            if (! $session) $this->fail('not_timing', '这个人在这道工序没有正在计时，请刷新。');
            self::closeSession($session, 'paused');
            $this->workOrders->log($wo, 'timing_stopped', null, null, $this->actorName($actor).' 停止了 '.$session->employee_name.' 在'.$op->operation_name.'的计时', $actor);
        }, 5);
    }

    /** 计时超过 4 小时没停的，按开始后 4 小时自动停止（忘了停、人走了），工时只算到 4 小时。 */
    public function closeOverdueSessions(): int
    {
        $limit = now()->subHours(self::AUTO_STOP_HOURS);
        $overdue = fn ($q) => $q->where('started_at', '<=', $limit)->orWhereIn('continuation_of_id', MfgLaborSession::where('started_at', '<=', $limit)->select('id'));
        if (! MfgLaborSession::query()->where('status', 'active')->where($overdue)->exists()) return 0;
        $closed = 0;
        $ids = MfgLaborSession::query()->where('status', 'active')->where($overdue)->orderBy('id')->limit(200)->pluck('id');
        foreach ($ids as $id) {
            DB::transaction(function () use ($id, $limit, &$closed) {
                $session = MfgLaborSession::query()->where('status', 'active')->lockForUpdate()->find($id);
                if (! $session) return;
                if (self::sessionDeadline($session)->gt(now())) return;
                self::closeSession($session, 'paused', self::sessionDeadline($session));
                $wo = MfgWorkOrder::query()->find($session->work_order_id);
                $opName = MfgWorkOrderOperation::query()->whereKey($session->work_order_operation_id)->value('operation_name') ?: '工序';
                if ($wo) $this->workOrders->log($wo, 'timing_auto_stopped', null, null, $session->employee_name.' 在'.$opName.'计时超过 '.self::AUTO_STOP_HOURS
                    .' 小时没停，系统已自动停止，工时按 '.self::AUTO_STOP_HOURS.' 小时算；还要做请重新点开始', null);
                $closed++;
            }, 5);
        }
        return $closed;
    }

    /** 本道已经没有可做的设备（都交下道、退回前道或在等报废确认），本道还在计时的人自动暂停。 */
    private function pauseIfIdle(MfgWorkOrder $wo, MfgWorkOrderOperation $op, object $actor): bool
    {
        $op->refresh();
        $paused = false;
        foreach (self::LANES as $lane) {
            if ($op->status !== 'done' && $this->selectableUnits($op, $lane)->isNotEmpty()) continue;
            $sessions = MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('status', 'active')->lockForUpdate()->get();
            if ($sessions->isEmpty()) continue;
            foreach ($sessions as $session) self::closeSession($session, 'paused');
            $this->workOrders->log($wo, 'timing_auto_paused', null, null, self::laneTitle($op->operation_name, $lane).'暂时没有可做的'.$this->unitNoun($wo).'，已自动暂停 '
                .$sessions->pluck('employee_name')->implode('、').' 的计时'.($lane === 'rework' ? '' : '；前道交过来后再点开始'), $actor);
            $paused = true;
        }
        return $paused;
    }

    /** 选中的设备本道合格：交下道；本工单末道按所选去向入库或直交。 */
    public function pass(int $opId, array $unitIds, object $actor, string $lane = 'normal', array $completion = []): array
    {
        $submissionKey = (string) Str::uuid();
        return DB::transaction(function () use ($opId, $unitIds, $actor, $lane, $completion, $submissionKey) {
            [$wo, $op] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            $this->assertReporter($op, $actor, $lane);
            $sessions = MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->lockForUpdate()->get();
            if ($sessions->isEmpty()) $this->fail('no_labor', '这道工序'.($lane === 'rework' ? '返工' : '').'还没有计时记录，请先点“开始”。');
            $units = $this->pickUnits($op, $unitIds, $lane);
            foreach ($units as $unit) {
                if (!$sessions->contains(fn ($session) => $session->unit_ids !== null ? in_array((int) $unit->id, $session->unit_ids, true)
                    : ($unit->started_at && $unit->started_at->lte($session->ended_at ?? now()))))
                    $this->fail('unit_not_started', $unit->serial_no.' 在本道还没有开始计时记录，请先选择该编号开始作业。');
            }
            if ($lane === 'normal') {
                $this->materials->assertReceivedForCompletion($wo, $op, $units);
                // 下料按套数报：本次首次完成的套数加上已完成的，不能超过开料已登记的套数。
                $firstTime = $units->filter(fn ($u) => ! MfgUnitReport::query()->where('unit_id', $u->id)->where('work_order_operation_id', $op->id)->where('result', 'passed')->exists())->count();
                $this->materials->assertCutForPass($wo, $op, $firstTime);
            }
            $reportedAt = now();
            $media = app(ProductionMediaService::class)->normalize($completion['media'] ?? [], $actor, 'complete');
            $result = $this->advance($wo, $op, $units, $this->participantsSnapshot($op, $sessions, $reportedAt, $lane), $reportedAt,
                ['media' => $media ?: null, 'submission_key' => $submissionKey], $actor, $completion);
            self::syncWorkStatus([(int) $wo->id]);
            return $result;
        }, 5);
    }

    /**
     * 已备料：集中下料提前切好的料，下料工序不计时，确认备好几台后直接交下道。
     * 只用于用按数量管理的板材／管材的工序；本道的料按 BOM 定额从库存扣（还没收的当场收），计入本工单材料成本。
     */
    public function prepare(int $opId, array $unitIds, ?string $remark, object $actor, ?int $cuttingRunId = null, array $completion = []): array
    {
        $this->closeOverdueSessions();
        $submissionKey = (string) Str::uuid();
        return DB::transaction(function () use ($opId, $unitIds, $remark, $actor, $cuttingRunId, $completion, $submissionKey) {
            [$wo, $op, $order] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            $sheet = in_array((int) $op->id, MaterialIssueService::cuttingOperationIds((int) $wo->id), true);
            if (! $sheet && ! in_array((int) $op->id, MaterialIssueService::prepOperationIds((int) $wo->id), true))
                $this->fail('prepare_invalid', $op->operation_name.'不用板材／管材，不能按“已备料”交下道，请开始计时后报合格。');
            $me = (int) $this->actorId($actor);
            DB::table('erp_legacy_admin_users')->where('legacy_id', $me)->lockForUpdate()->first();
            if (! $this->participant($op, $me)) {
                if ($this->owner($op)) $this->fail('not_participant', '这道已有人接单，只有接单人和协同人可以确认备料。');
                if (! $this->canDo($op, $me)) $this->fail('not_worker', '你不在这道工序“能做的人”里，请找主管派工。');
                $this->setParticipant($op, $me, $this->actorName($actor), 'owner', $actor);
            }
            $this->assertSalesOrderNotCancelled($wo, $order);
            $units = $this->pickUnits($op, $unitIds, 'normal');
            $remark = trim((string) $remark) ?: null;
            // 只为这次第一次过本道的设备收料；返工回来的设备料已经收过。
            $firstTime = $units->filter(fn ($u) => ! MfgUnitReport::query()->where('unit_id', $u->id)->where('work_order_operation_id', $op->id)->where('result', 'passed')->exists())->count();
            $activeIds = CancellationProductionService::productionUnitIds((int) $wo->id);
            $upTo = (float) MfgUnitReport::where('work_order_operation_id', $op->id)->where('result', 'passed')->whereIn('unit_id', $activeIds)->distinct()->count('unit_id') + $firstTime;
            $firstUnitIds = $units->filter(fn ($u) => !MfgUnitReport::where('unit_id', $u->id)->where('work_order_operation_id', $op->id)->where('result', 'passed')->exists())
                ->pluck('id')->map(fn ($id) => (int) $id)->all();
            // 集中开料记录：按件数领走这批的实际开料工时（这批总工时 × 领走件数 ÷ 这批总件数）；
            // 按数量管理的板材／管材同时带走这批切完时已扣的料和成本，记为本道已收，剩下不够的再按定额从仓库收。
            app(CuttingRunService::class)->allocate($wo, $op, $firstTime, $upTo, $actor, $cuttingRunId, $firstUnitIds);
            $this->materials->receiveUpTo($wo, $op, $upTo, $remark ? '已备料：'.$remark : '已备料', $actor, $firstUnitIds);
            $this->materials->assertReceivedForCompletion($wo, $op, $units);
            // 逐张板材：集中开料切好的件，按定额从开料池扣量计成本。
            if ($sheet) app(SharedCuttingService::class)->allocate($wo, $op, $upTo, $firstTime, $actor, $firstUnitIds);
            $this->materials->assertCutForPass($wo, $op, $firstTime);
            $this->markStarted($wo, $op, $actor);
            if ($op->status !== 'in_progress') $op->update(['status' => 'in_progress']);
            $reportedAt = now();
            $sessions = MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('lane', 'normal')->lockForUpdate()->get();
            $media = app(ProductionMediaService::class)->normalize($completion['media'] ?? [], $actor, 'complete');
            $result = $this->advance($wo, $op, $units, $this->participantsSnapshot($op, $sessions, $reportedAt, 'normal'), $reportedAt,
                ['pass_mode' => 'prepared', 'reason' => $remark ? mb_substr($remark, 0, 500) : null,
                    'media' => $media ?: null, 'submission_key' => $submissionKey], $actor, $completion);
            self::syncWorkStatus([(int) $wo->id]);
            return $result;
        }, 5);
    }

    /**
     * 不合格：退回本道或之前某道重做（直接生效，开料工序除外），退回的进那道工序的「返工」；
     * 或报废：有审核人（工单生产负责人／业务员档案里的生产负责人）时等审核，审核前设备停在本道、不能再报；没有审核人或审核人自己报的直接生效。
     * @param array{mode:string, return_operation_id:?int, reason_tags:array, reason:?string, photos:array} $data
     */
    public function reject(int $opId, array $unitIds, array $data, object $actor, string $lane = 'normal'): array
    {
        $submissionKey = (string) Str::uuid();
        return DB::transaction(function () use ($opId, $unitIds, $data, $actor, $lane, $submissionKey) {
            [$wo, $op] = $this->lockTask($opId);
            $this->assertRunnable($wo, $op);
            $this->assertReporter($op, $actor, $lane);
            // 下料切坏的在开料登记里按实际合格套数登记、废料计入损耗，不逐台报不合格。
            if (in_array((int) $op->id, MaterialIssueService::cuttingOperationIds((int) $wo->id), true))
                $this->fail('reject_invalid', $op->operation_name.'按套数报完成，切坏的请在「开料」登记里按实际合格套数登记，不用报不合格。');
            $tags = array_values(array_filter(array_map(fn ($t) => mb_substr(trim((string) $t), 0, 30), (array) ($data['reason_tags'] ?? []))));
            $reason = trim((string) ($data['reason'] ?? ''));
            if (! $tags && $reason === '') $this->fail('reason_required', '请选不合格原因或写一句说明。');
            $photos = array_values(array_slice(array_filter((array) ($data['photos'] ?? []), fn ($p) => is_string($p) && $p !== ''), 0, 9));
            $why = implode('、', array_filter([$tags ? implode('/', $tags) : null, $reason ?: null]));
            $units = $this->pickUnits($op, $unitIds, $lane);
            $media = app(ProductionMediaService::class)->normalize($data['media'] ?? [], $actor, 'reject');
            $reportedAt = now();
            $sessions = MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->lockForUpdate()->get();
            $extra = ['reason_tags' => $tags ?: null, 'reason' => $reason ?: null, 'photos' => $photos ?: null,
                'media' => $media ?: null, 'submission_key' => $submissionKey, 'created_at' => $reportedAt,
                'participants_snapshot' => $this->participantsSnapshot($op, $sessions, $reportedAt, $lane, false)];
            OrderChangeLaborService::freezeAt([(int) $wo->id], $reportedAt, $units->pluck('id')->map(fn ($id) => (int) $id)->all());
            if (($data['mode'] ?? 'return') === 'scrap') {
                if (\App\Models\Erp\ShipmentRework::query()->whereIn('unit_id', $units->pluck('id'))->where('status', 'in_progress')->exists())
                    $this->fail('stored_serial_scrap', '发货返修设备已经入库，须使用正式库存报废处理，不能只从制造产量删除。');
                $review = ProductionReviewers::needsReview($wo, $this->actorId($actor));
                // 不经审核直接生效的报废，必须写原因、拍照留痕。
                if (! $review && ! $photos && ! collect($media)->contains('type', 'image')) $this->fail('photo_required', '报废提交后直接生效，请至少拍 1 张照片。');
                $reports = [];
                foreach ($units as $unit) {
                    $reports[] = $this->report($wo, $op, $unit, 'scrap', $actor, $extra + ['is_rework' => $unit->status === 'rework', 'review_status' => 'pending']);
                    $unit->update(['status' => 'rework']);
                    $this->workOrders->log($wo, 'unit_scrap_requested', null, 'rework', "{$unit->serial_no} 在{$op->operation_name}".($review ? '申请报废' : '报废')."：{$why}"
                        .($review ? '，等 '.ProductionReviewers::names($wo).' 审核' : ''), $actor, [], $unit->id);
                }
                if (! $review) foreach ($reports as $report) $this->approveScrap($wo, $report, '无需审核，提交即生效', $actor);
                $paused = $this->pauseIfIdle($wo, $op, $actor);
                self::syncWorkStatus([(int) $wo->id]);
                return ['mode' => 'scrap', 'count' => $units->count(), 'immediate' => ! $review, 'reviewers' => $review ? ProductionReviewers::names($wo) : '', 'timing_paused' => $paused];
            }
            $ops = $this->productionOps($wo);
            $pos = $ops->pluck('id')->flip();
            $target = $ops->firstWhere('id', (int) ($data['return_operation_id'] ?? 0));
            if (! $target || $pos[$target->id] > $pos[$op->id]) $this->fail('return_invalid', '只能退回本道或之前的工序。');
            // 开料工序重做要重新耗板材／管材，不能直接退回；切坏的走报废。
            if (in_array((int) $target->id, MaterialIssueService::cuttingOperationIds((int) $wo->id), true))
                $this->fail('return_invalid', $target->operation_name.'要用板材或管材开料，不能退回重做；切坏的请申请报废。');
            foreach ($units as $unit) {
                $this->report($wo, $op, $unit, 'failed', $actor, $extra + ['is_rework' => $unit->status === 'rework',
                    'return_operation_id' => $target->id, 'return_operation_name' => $target->operation_name]);
                $unit->update(['status' => 'rework', 'rework_count' => (int) $unit->rework_count + 1, 'current_operation_id' => $target->id]);
                $where = $target->id === $op->id ? '本道返工' : '退回'.$target->operation_name.'返工';
                $this->workOrders->log($wo, 'unit_failed', null, 'rework', "{$unit->serial_no} {$op->operation_name}不合格，{$where}：{$why}", $actor,
                    ['operation_id' => $op->id, 'return_operation_id' => $target->id, 'lane' => $lane], $unit->id);
            }
            if ($target->id !== $op->id && $target->status === 'done') $target->update(['status' => 'in_progress']);
            $assigned = $this->ensureReworkOwner($wo, $target, $actor);
            $this->refreshOperation($wo, $op);
            $paused = $this->pauseIfIdle($wo, $op, $actor);
            self::syncWorkStatus([(int) $wo->id]);
            return ['mode' => 'return', 'count' => $units->count(), 'return_operation_name' => $target->operation_name, 'rework_owner_name' => $assigned,
                'timing_paused' => $paused];
        }, 5);
    }

    /**
     * 退回到某道返工：那道的「返工」还没人接时，默认交给这道正常生产的接单人（他不在了就留给大家接）。返回接手人姓名。
     */
    private function ensureReworkOwner(MfgWorkOrder $wo, MfgWorkOrderOperation $target, object $actor): ?string
    {
        if ($existing = $this->owner($target, 'rework')) return $existing->employee_name;
        $normal = $this->owner($target, 'normal');
        if (! $normal || ! $this->activeUser((int) $normal->employee_legacy_id)) return null;
        $this->setParticipant($target, (int) $normal->employee_legacy_id, $normal->employee_name, 'owner', $actor, null, 'rework');
        $this->workOrders->log($wo, 'task_assigned', null, null, self::laneTitle($target->operation_name, 'rework').' 默认交给本道接单人 '.$normal->employee_name, $actor, ['lane' => 'rework']);
        return $normal->employee_name;
    }

    /**
     * 审核报废：通过 = 这台设备报废；驳回 = 继续在本道返工。
     * 审核人：工单生产负责人，没填就是业务员档案里任一启用的生产负责人；$hasApprovePermission（报废确认权限）也可以审。
     */
    public function scrapForCancellation(MfgWorkOrder $wo, array $unitIds, string $reason, array $evidence, object $actor): int
    {
        $auth = app(AuthContextService::class);
        $needsReview = ProductionReviewers::needsReview($wo, $this->actorId($actor))
            && !$auth->isSuperAdmin($actor) && !in_array('production.scrap.approve', $auth->permissionCodes($actor), true);
        $media = app(ProductionMediaService::class)->normalize($evidence, $actor, 'reject');
        abort_unless(collect($media)->contains('type', 'image'), 422, '报废须上传现场照片。');
        $units = MfgWorkOrderUnit::where('work_order_id', $wo->id)->whereIn('id', $unitIds)->lockForUpdate()->get();
        abort_unless($units->count() === count(array_unique($unitIds)) && $units->isNotEmpty(), 422, '请选择本工单的在制设备。');
        foreach ($units as $unit) {
            abort_if(in_array($unit->status, ['scrapped', 'cancelled', 'stored', 'pending_receipt'], true), 422, '设备已报废、已入库或有入库申请，不能重复报废。');
            abort_if(MfgUnitReport::where('unit_id', $unit->id)->where('review_status', 'pending')->exists(), 422, '设备尚有待审核的报废申请。');
            $op = MfgWorkOrderOperation::where('work_order_id', $wo->id)->where('execution_context', 'production')
                ->when($unit->current_operation_id, fn ($q) => $q->whereKey($unit->current_operation_id))->orderBy('sequence')->firstOrFail();
            $report = $this->report($wo, $op, $unit, 'scrap', $actor, ['reason' => $reason, 'media' => $media,
                'submission_key' => (string) Str::uuid(), 'review_status' => 'pending', 'is_rework' => false]);
            if ($needsReview) {
                $before = $unit->status;
                $unit->update(['status' => 'rework']);
                $this->workOrders->log($wo, 'unit_scrap_requested', $before, 'rework', '订单需求变更，接单人申请报废：'.$reason, $actor, [], $unit->id);
            } else $this->approveScrap($wo, $report, '订单需求失效，报废确认：'.$reason, $actor);
        }
        return $units->count();
    }

    public function reviewScrap(int $reportId, bool $approve, ?string $remark, object $actor, bool $hasApprovePermission = false): void
    {
        DB::transaction(function () use ($reportId, $approve, $remark, $actor, $hasApprovePermission) {
            $woId = MfgUnitReport::query()->whereKey($reportId)->value('work_order_id');
            if (! $woId) $this->fail('not_found', '报废申请不存在。', 404);
            $orderId = MfgWorkOrder::query()->whereKey($woId)->value('sales_order_id');
            if ($orderId) SalesOrder::query()->whereKey($orderId)->lockForUpdate()->first();
            $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($woId);
            $ids = ProductionReviewers::for($wo);
            if (! $hasApprovePermission && ! in_array((int) $this->actorId($actor), $ids, true))
                $this->fail('permission_denied', '只有这张工单的生产负责人'.($ids ? '（'.ProductionReviewers::names($wo).'）' : '').'可以审核报废。', 403);
            $report = MfgUnitReport::query()->lockForUpdate()->findOrFail($reportId);
            if ($report->result !== 'scrap' || $report->review_status !== 'pending') $this->fail('status_invalid', '这条报废申请已经处理过了。');
            if (! $approve) {
                $unit = MfgWorkOrderUnit::query()->lockForUpdate()->findOrFail($report->unit_id);
                $op = MfgWorkOrderOperation::query()->lockForUpdate()->findOrFail($report->work_order_operation_id);
                $report->update(['review_status' => 'rejected', 'reviewed_by_legacy_id' => $this->actorId($actor),
                    'reviewed_by_name' => $this->actorName($actor), 'reviewed_at' => now(), 'review_remark' => $remark ?: null]);
                $this->workOrders->log($wo, 'unit_scrap_rejected', 'rework', 'rework', "{$unit->serial_no} 报废申请被驳回，继续在{$op->operation_name}返工".($remark ? '：'.$remark : ''), $actor, [], $unit->id);
                $this->ensureReworkOwner($wo, $op, $actor);
                self::syncWorkStatus([(int) $wo->id]);
                return;
            }
            $this->approveScrap($wo, $report, $remark, $actor);
            self::syncWorkStatus([(int) $wo->id]);
        }, 5);
    }

    /** 报废生效：设备报废、结束下游空转的计时、订单工单自动补做；返工工单报废的那件，冻结的不良库存一起报废出库。调用方须已锁定工单。 */
    private function approveScrap(MfgWorkOrder $wo, MfgUnitReport $report, ?string $remark, object $actor): void
    {
        $unit = MfgWorkOrderUnit::query()->lockForUpdate()->findOrFail($report->unit_id);
        $op = MfgWorkOrderOperation::query()->lockForUpdate()->findOrFail($report->work_order_operation_id);
        $report->update(['review_status' => 'approved', 'reviewed_by_legacy_id' => $this->actorId($actor),
            'reviewed_by_name' => $this->actorName($actor), 'reviewed_at' => now(), 'review_remark' => $remark ?: null]);
        $before = $unit->status;
        $unit->update(['status' => 'scrapped', 'current_operation_id' => null]);
        $this->workOrders->log($wo, 'unit_scrapped', $before, 'scrapped', "{$unit->serial_no} 报废生效（{$op->operation_name}）".($remark ? '：'.$remark : ''), $actor, [], $unit->id);
        if ($wo->rework_of_work_order_id) app(MaterialReturnService::class)->settleReworkUnit($wo, $unit, 'scrap', $actor);
        // 上游最后一台报废后，下游可能已无设备可做，必须一并结束这些工序的计时。
        $ops = $this->productionOps($wo);
        foreach ($ops->slice($ops->pluck('id')->search($op->id)) as $affected) {
            $this->refreshOperation($wo, $affected);
            $this->pauseIfIdle($wo, $affected, $actor);
        }
        $this->workOrders->refreshCompletion($wo, $actor);
        // 订单工单报废后订单就少了设备：自动补一张工单；补单失败不影响报废确认，留日志让主管手工处理。
        try {
            DB::transaction(fn () => $this->workOrders->createScrapReplacement($wo, $actor));
        } catch (\App\Exceptions\Erp\WorkOrderDomainException|\Illuminate\Validation\ValidationException|\RuntimeException $e) {
            $message = $e instanceof \Illuminate\Validation\ValidationException ? collect($e->errors())->flatten()->first() : $e->getMessage();
            $this->workOrders->log($wo, 'scrap_replace_failed', $wo->status, $wo->status, '设备报废后自动补做工单没开成：'.$message.'，请生产主管手工补单', $actor);
        }
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
            // 已在“能做的人”里的保留；新加入的须是有效的生产工人档案。
            $current = ProductionOperationWorker::query()->where('operation_id', $operationId)->pluck('employee_legacy_id')->map(fn ($v) => (int) $v)->all();
            foreach ($users as $user) if (! in_array((int) $user->legacy_id, $current, true)) $this->assertWorkerStaff($user);
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
        if ($ended->gt($deadline = self::sessionDeadline($session))) $ended = $deadline;
        // 返工单独计时：在「返工」任务里计的就是返工工时（不算提成）。
        $session->update(['status' => $status, 'ended_at' => $ended, 'is_rework' => ($session->lane ?: 'normal') === 'rework',
            'labor_minutes' => round(max(0, $session->started_at->diffInSeconds($ended, false)) / 60, 2)]);
    }

    public static function sessionDeadline(MfgLaborSession $session): CarbonInterface
    {
        $start = $session->continuation_of_id ? MfgLaborSession::findOrFail($session->continuation_of_id)->started_at : $session->started_at;
        return $start->copy()->addHours(self::AUTO_STOP_HOURS);
    }

    /** 某人能不能做这道工序：工序上没设“能做的人”时，有报工权限的人都能做。 */
    public function canDo(MfgWorkOrderOperation $op, int $employeeId): bool
    {
        if (! $op->operation_id) return true;
        $workers = ProductionOperationWorker::query()->where('operation_id', $op->operation_id)
            ->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->pluck('employee_legacy_id')->map(fn ($v) => (int) $v);
        return $workers->isEmpty() || $workers->contains($employeeId);
    }

    /** 本道现在能报的设备：停在本道、没在等报废确认；$lane = normal 正常生产的、rework 返工的、null 全部。 */
    public function selectableUnits(MfgWorkOrderOperation $op, ?string $lane = null): Collection
    {
        $query = MfgWorkOrderUnit::query()->where('work_order_id', $op->work_order_id)->where('current_operation_id', $op->id)
            ->whereIn('status', self::laneStatuses($lane))->whereNotIn('id', CancellationProductionService::blockedUnitIds((int) $op->work_order_id))->orderBy('unit_no');
        if (DB::transactionLevel() > 0) {
            // 待报废和台状态都要读当前值，不能混用等待工单锁前建立的旧视图。
            $pending = MfgUnitReport::query()->where('work_order_id', $op->work_order_id)->where('review_status', 'pending')->lockForUpdate()->pluck('unit_id');
            return $query->whereNotIn('id', $pending)->lockForUpdate()->get();
        }
        return $query->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_unit_reports as r')
            ->whereColumn('r.unit_id', 'erp_mfg_work_order_units.id')->where('r.review_status', 'pending'))->get();
    }

    private function assertSalesOrderNotCancelled(MfgWorkOrder $wo, ?SalesOrder $order): void
    {
        if ($wo->started_at || $wo->source_type !== 'sales_order' || ! $wo->sales_order_id) return;
        if ($order?->order_status === 'cancelled') $this->fail('order_cancelled', '销售订单已取消，不能按原订单开工。');
    }

    private function markStarted(MfgWorkOrder $wo, MfgWorkOrderOperation $op, object $actor): void
    {
        if ($wo->started_at) return;
        $before = $wo->status;
        $wo->update(['status' => 'in_progress', 'started_at' => now(), 'business_version' => (int) $wo->business_version + 1]);
        $this->workOrders->log($wo, 'started', $before, 'in_progress', $this->actorName($actor).' 开始'.$op->operation_name.'，工单开工', $actor);
    }

    /**
     * 合格／已备料的设备交下道；最后一道按用户所选入库或直交，和报工在同一事务内生效。
     */
    private function advance(MfgWorkOrder $wo, MfgWorkOrderOperation $op, Collection $units, array $snapshot, CarbonInterface $reportedAt, array $extra, object $actor, array $completion = []): array
    {
        OrderChangeLaborService::freezeAt([(int) $wo->id], $reportedAt, $units->pluck('id')->map(fn ($id) => (int) $id)->all());
        $prepared = ($extra['pass_mode'] ?? null) === 'prepared';
        // 返工工单（总装退回的不良半成品）：每道都是返工，最后一道合格就回到原来的库存（解冻），不再生产入库。
        $reworkOrder = (bool) $wo->rework_of_work_order_id;
        $reworkNext = false;
        $returned = 0;
        $ops = $this->productionOps($wo);
        $pos = $ops->pluck('id')->flip();
        $next = $ops->get($pos[$op->id] + 1);
        if ($next && ! empty($completion['completion_action'])) $this->fail('completion_not_ready', '本工单还有未完成的加工工序，请先交接给'.$next->operation_name.'。');
        $firstPass = 0;
        $finished = 0;
        $receiptUnitIds = [];
        $repaired = 0;
        foreach ($units as $unit) {
            $shipmentRepair = \App\Models\Erp\ShipmentRework::query()->where('unit_id', $unit->id)->where('status', 'in_progress')->lockForUpdate()->first();
            $wasRework = $unit->status === 'rework';
            if (! MfgUnitReport::query()->where('unit_id', $unit->id)->where('work_order_operation_id', $op->id)->where('result', 'passed')->lockForUpdate()->first(['id'])) $firstPass++;
            $this->report($wo, $op, $unit, 'passed', $actor, $extra + ['is_rework' => $wasRework, 'participants_snapshot' => $snapshot, 'created_at' => $reportedAt]);
            $stillRework = false;
            if ($wasRework) {
                $failedAt = MfgUnitReport::query()->where('unit_id', $unit->id)->where('result', 'failed')->orderByDesc('id')->lockForUpdate()->value('work_order_operation_id');
                $stillRework = $failedAt && isset($pos[$failedAt]) && $pos[$failedAt] > $pos[$op->id];
            }
            if ($next) {
                $toRework = $stillRework || $shipmentRepair || $reworkOrder;
                if ($toRework) $reworkNext = true;
                $unit->update(['status' => $toRework ? 'rework' : 'in_progress', 'current_operation_id' => $next->id]);
                if ($next->status === 'done') $next->update(['status' => 'in_progress']);
                $text = ($prepared ? "{$op->operation_name}已备料" : "{$op->operation_name}合格")."，交{$next->operation_name}".($toRework ? '返工' : '');
            } else {
                if ($reworkOrder) {
                    $unit->update(['status' => 'stored', 'current_operation_id' => null, 'completed_at' => $reportedAt]);
                    app(MaterialReturnService::class)->settleReworkUnit($wo, $unit, 'release', $actor);
                    $returned++;
                    $text = "{$op->operation_name}返工合格，退回的不良件已解冻回库存，可以重新收料";
                } elseif ($shipmentRepair) {
                    // 已在库原机返工只完成本轮维修，不增加首次制造成果，也不再入库一台。
                    $unit->update(['status' => 'stored', 'current_operation_id' => null]);
                    $shipmentRepair->update(['status' => 'completed', 'completed_at' => $reportedAt]);
                    $repaired++;
                    $text = "{$op->operation_name}返修合格，等待返回成品位重新试水";
                } else {
                    $unit->update(['status' => 'completed', 'current_operation_id' => null, 'completed_at' => $reportedAt]);
                    $finished++;
                    $receiptUnitIds[] = (int) $unit->id;
                    $text = ($prepared ? "{$op->operation_name}已备料" : "{$op->operation_name}合格").'，生产完成，等入库';
                }
            }
            $this->workOrders->log($wo, 'unit_passed', $wasRework ? 'rework' : 'in_progress', $next ? null : ($shipmentRepair || $reworkOrder ? 'stored' : 'completed'),
                $unit->serial_no.' '.$text.($wasRework ? '（返工）' : ''), $actor, ['operation_id' => $op->id], $unit->id);
        }
        if ($firstPass) $op->update(['completed_qty' => (float) $op->completed_qty + $firstPass]);
        if ($finished || $returned) $wo->update(['completed_qty' => (float) $wo->completed_qty + $finished + $returned]);
        if ($next && $reworkNext && ! $reworkOrder) $this->ensureReworkOwner($wo, $next, $actor);
        $this->refreshOperation($wo, $op, $reportedAt);
        $timingPaused = $this->pauseIfIdle($wo, $op, $actor);
        if ($returned) $this->workOrders->refreshCompletion($wo, $actor);
        $completed = $finished ? app(TaskCompletionService::class)->complete($wo, $op, $receiptUnitIds, $completion, $actor) : [];
        return ['passed' => $units->count(), 'finished' => $finished, 'repaired' => $repaired, 'returned' => $returned, 'next_operation_name' => $next?->operation_name,
            'timing_paused' => $timingPaused] + $completed;
    }

    private function lockTask(int $opId): array
    {
        $woId = MfgWorkOrderOperation::query()->whereKey($opId)->value('work_order_id');
        if (! $woId) $this->fail('not_found', '生产任务不存在。', 404);
        $orderId = MfgWorkOrder::query()->whereKey($woId)->value('sales_order_id');
        // 随单半成品直交会同时改主工单，先锁主单所属订单，沿用销售变更的订单优先锁序。
        if (! $orderId) {
            $parentId = MfgWorkOrderLink::query()->where('child_work_order_id', $woId)->value('parent_work_order_id');
            if ($parentId) $orderId = MfgWorkOrder::query()->whereKey($parentId)->value('sales_order_id');
        }
        $order = $orderId ? SalesOrder::query()->whereKey($orderId)->lockForUpdate()->firstOrFail() : null;
        $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($woId);
        $op = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->lockForUpdate()->findOrFail($opId);
        if ($op->execution_context !== 'production') $this->fail('not_production', '这道不是车间工序，不能在这里报工。');
        return [$wo, $op, $order];
    }

    private function assertRunnable(MfgWorkOrder $wo, MfgWorkOrderOperation $op): void
    {
        CancellationProductionService::assertProductionAllowed((int) $wo->id);
        if ($wo->status === 'paused') $this->fail('paused', '工单已暂停（'.($wo->pause_reason ?: '未写原因').'），暂时不能报工。');
        if (! $this->isRunnable($wo)) $this->fail('status_invalid', '工单不在生产中，也没有本机发货返工任务，不能报工。');
    }

    public function isRunnable(MfgWorkOrder $wo): bool
    {
        return in_array($wo->status, ['released', 'in_progress'], true) || ($wo->status === 'completed'
            && \App\Models\Erp\ShipmentRework::query()->where('work_order_id', $wo->id)->where('status', 'in_progress')->exists());
    }

    private function assertReporter(MfgWorkOrderOperation $op, object $actor, string $lane = 'normal'): void
    {
        if (! $this->participant($op, (int) $this->actorId($actor), $lane))
            $this->fail('not_participant', '只有这道工序'.($lane === 'rework' ? '返工' : '').'的接单人和协同人可以报工。');
    }

    private function pickUnits(MfgWorkOrderOperation $op, array $unitIds, string $lane = 'normal'): Collection
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $unitIds))));
        if (! $ids) $this->fail('unit_required', '请选择这次做完的设备。');
        $units = MfgWorkOrderUnit::query()->where('work_order_id', $op->work_order_id)->whereIn('id', $ids)->orderBy('unit_no')->lockForUpdate()->get();
        $selectable = $this->selectableUnits($op, $lane)->pluck('id')->all();
        if ($units->count() !== count($ids) || $units->contains(fn ($u) => ! in_array($u->id, $selectable, true)))
            $this->fail('unit_invalid', '选的不在这道工序'.($lane === 'rework' ? '的返工' : '').'里或正在等报废审核，请刷新后重选。');
        return $units;
    }

    private function productionOps(MfgWorkOrder $wo): Collection
    {
        return MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->where('execution_context', 'production')
            ->orderBy('sequence')->orderBy('id')->lockForUpdate()->get()->values();
    }

    /** 本道和之前各道都没有在做的设备了，本道就算做完，同时结束本道所有计时。 */
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

    private function report(MfgWorkOrder $wo, MfgWorkOrderOperation $op, MfgWorkOrderUnit $unit, string $result, object $actor, array $extra): MfgUnitReport
    {
        return MfgUnitReport::create($extra + [
            'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id, 'unit_id' => $unit->id, 'serial_no' => $unit->serial_no,
            'result' => $result, 'reporter_legacy_id' => $this->actorId($actor), 'reporter_name' => $this->actorName($actor),
        ]);
    }

    private function participantsSnapshot(MfgWorkOrderOperation $op, Collection $sessions, CarbonInterface $reportedAt, string $lane = 'normal', bool $validateShares = true): array
    {
        $participants = MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('is_active', true)->orderByRaw("FIELD(role, 'owner', 'collaborator')")->lockForUpdate()->get();
        if ($validateShares && $participants->contains(fn ($p) => $p->share_percent !== null)
            && ($participants->contains(fn ($p) => $p->share_percent === null) || abs($participants->sum('share_percent') - 100) > 0.01))
            $this->fail('share_confirmation_required', '固定份额必须每个人都填写且合计为 100%，请由接单人在协同设置中重新确认。');
        // 同批报工和完成计时共用这一时刻，快照包含尚未结束的真实工时段。
        $minutes = $sessions->groupBy('employee_legacy_id')->map(fn ($rows) => $rows->sum(fn ($s) => $s->status === 'active'
            ? round(max(0, $s->started_at->diffInSeconds($reportedAt->lessThan(self::sessionDeadline($s)) ? $reportedAt : self::sessionDeadline($s), false)) / 60, 2) : (float) $s->labor_minutes));
        $participants = $participants->concat(MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)
            ->where('is_active', false)->whereIn('employee_legacy_id', $minutes->keys())->orderBy('id')->lockForUpdate()->get());
        return $participants->map(function ($p) use ($sessions, $reportedAt, $minutes) {
            $started = $p->joined_at ? $sessions->filter(fn ($s) => (int) $s->employee_legacy_id === (int) $p->employee_legacy_id
                && $s->started_at->gte($p->joined_at) && $s->started_at->lte($reportedAt))->sortBy('started_at')->first()?->started_at : null;
            return ['employee_legacy_id' => (int) $p->employee_legacy_id, 'employee_name' => $p->employee_name, 'role' => $p->role,
                'is_active' => (bool) $p->is_active,
                'joined_at' => $p->joined_at?->toDateTimeString(), 'started_at' => $started?->toDateTimeString(),
                'share_percent' => $p->share_percent === null ? null : (float) $p->share_percent,
                'labor_scope' => 'operation_cumulative',
                'labor_minutes' => round((float) ($minutes[$p->employee_legacy_id] ?? 0), 2)];
        })->values()->all();
    }

    private function owner(MfgWorkOrderOperation $op, string $lane = 'normal'): ?MfgTaskParticipant
    {
        return MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('role', 'owner')->where('is_active', true)->lockForUpdate()->first();
    }

    private function participant(MfgWorkOrderOperation $op, int $employeeId, string $lane = 'normal'): ?MfgTaskParticipant
    {
        return MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('lane', $lane)->where('employee_legacy_id', $employeeId)->where('is_active', true)->lockForUpdate()->first();
    }

    private function activeSession(MfgWorkOrderOperation $op, int $employeeId, ?string $lane = null): ?MfgLaborSession
    {
        return MfgLaborSession::query()->where('work_order_operation_id', $op->id)->where('employee_legacy_id', $employeeId)->when($lane, fn ($q, $v) => $q->where('lane', $v))
            ->where('status', 'active')->lockForUpdate()->first();
    }

    private function setParticipant(MfgWorkOrderOperation $op, int $employeeId, string $name, string $role, object $actor, ?float $share = null, string $lane = 'normal'): void
    {
        $existing = MfgTaskParticipant::query()->where('work_order_operation_id', $op->id)->where('employee_legacy_id', $employeeId)->where('lane', $lane)->lockForUpdate()->first();
        MfgTaskParticipant::query()->lockForUpdate()->updateOrCreate(['work_order_operation_id' => $op->id, 'employee_legacy_id' => $employeeId, 'lane' => $lane], [
            'work_order_id' => $op->work_order_id, 'employee_name' => $name, 'role' => $role, 'is_active' => true, 'share_percent' => $share,
            'assigned_by_legacy_id' => $this->actorId($actor), 'assigned_by_name' => $this->actorName($actor), 'joined_at' => $existing?->joined_at ?: now(),
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

    private function assertWorkerStaff(object $user): void
    {
        if (! app(\App\Services\Erp\BusinessStaffService::class)->isActive((int) $user->legacy_id, 'production_worker'))
            $this->fail('user_invalid', $this->userName($user).' 不是有效的生产工人，请先在“系统管理 / 业务员档案”登记并启用。');
    }

    /** 提示语量词：整机说“台设备”，半成品按工单单位（如“个”）。 */
    public function unitWord(MfgWorkOrder $wo): string
    {
        $type = DB::table('erp_items')->where('id', $wo->item_id)->value('item_type');
        if ($type === 'finished_product' || ! $type) return '台设备';
        return trim((string) $wo->unit_name) ?: '件';
    }

    /** 返工工单：每道的返工默认交给原半成品工单同一道的接单人（他不在了就留给大家接）。调用方须在事务内。 */
    public function defaultReworkOwners(MfgWorkOrder $reworkWo, MfgWorkOrder $source, object $actor): void
    {
        $sourceOps = MfgWorkOrderOperation::query()->where('work_order_id', $source->id)->get();
        foreach (MfgWorkOrderOperation::query()->where('work_order_id', $reworkWo->id)->where('execution_context', 'production')->get() as $op) {
            $from = $sourceOps->first(fn ($o) => (int) $o->sequence === (int) $op->sequence && $o->operation_name === $op->operation_name)
                ?? $sourceOps->firstWhere('operation_name', $op->operation_name);
            $normal = $from ? $this->owner($from, 'normal') : null;
            if (! $normal || ! $this->activeUser((int) $normal->employee_legacy_id)) continue;
            $this->setParticipant($op, (int) $normal->employee_legacy_id, $normal->employee_name, 'owner', $actor, null, 'rework');
        }
    }

    /** 提示语名词：整机说“设备”，半成品说物料名（如“设备架子”）。 */
    public function unitNoun(MfgWorkOrder $wo): string
    {
        $type = DB::table('erp_items')->where('id', $wo->item_id)->value('item_type');
        if ($type === 'finished_product' || ! $type) return '设备';
        return trim((string) $wo->item_name) ?: '件';
    }

    /**
     * 同步工序状态 work_status：done 完成；本道有设备（正常或返工）= 有人开始过就是进行中，否则可接；
     * 本道没设备 = 做过（开始过或报过合格）就是进行中（等前道再交），一台都没交过来就是未到。$woIds 为空同步全部未结束的工单。
     */
    public static function syncWorkStatus(?array $woIds = null): void
    {
        if ($woIds !== null && ! $woIds) return;
        $here = "SELECT current_operation_id AS oid FROM erp_mfg_work_order_units WHERE status IN ('waiting', 'in_progress', 'rework') AND current_operation_id IS NOT NULL"
            .($woIds !== null ? ' AND work_order_id IN ('.implode(',', array_map('intval', $woIds)).')' : '').' GROUP BY current_operation_id';
        $sql = "UPDATE erp_mfg_work_order_operations op JOIN erp_mfg_work_orders wo ON wo.id = op.work_order_id LEFT JOIN ({$here}) u ON u.oid = op.id
            SET op.work_status = CASE WHEN op.status = 'done' THEN 'done'
                WHEN u.oid IS NOT NULL THEN IF(op.status = 'in_progress', 'in_progress', 'ready')
                WHEN op.status = 'in_progress' OR op.completed_qty > 0 THEN 'in_progress' ELSE 'pending' END
            WHERE op.execution_context = 'production'"
            .($woIds !== null ? ' AND op.work_order_id IN ('.implode(',', array_map('intval', $woIds)).')' : " AND wo.status IN ('planned', 'released', 'in_progress', 'paused', 'completed')");
        DB::update($sql);
    }

    private function userName(object $user): string { return (string) (($user->nickname ?? null) ?: ($user->username ?? null) ?: ('#'.$user->legacy_id)); }
}
