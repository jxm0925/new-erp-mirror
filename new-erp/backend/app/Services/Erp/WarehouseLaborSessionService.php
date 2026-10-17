<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{MaterialDelivery, MaterialPickingTask, PublicMaterialPreparationTask, WarehouseLaborSession, WorkOrder};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Actual personal warehouse intervals. Shared public work is never allocated by material quantities. */
final class WarehouseLaborSessionService
{
    public function __construct(private readonly ProductionDataScopeResolver $scope,
        private readonly ProductionMaterialCommandService $commands) {}

    public function ready(): bool { return Schema::hasTable('erp_warehouse_labor_sessions'); }

    /** Every producer of a personal timer uses this same employee row before opening a segment. */
    public function lockEmployee(int $employee): void
    {
        if (DB::transactionLevel() < 1) $this->fail('transaction_required', '工时操作必须在正式业务事务内执行。');
        if ($employee <= 0 || ! DB::table('erp_legacy_admin_users')->where('legacy_id', $employee)->lockForUpdate()->first(['legacy_id']))
            $this->fail('labor_employee_invalid', '计时人员不存在。');
    }

    public function assertNoActiveWarehouse(int $employee): void
    {
        $this->lockEmployee($employee);
        if ($this->ready() && WarehouseLaborSession::where('employee_legacy_id', $employee)->where('status', 'ACTIVE')->lockForUpdate()->exists())
            $this->fail('labor_session_active', '本人还有进行中的配料或配送计时，请先暂停原作业。');
    }

    public function command(string $type, int $id, string $action, array $payload, object $user, array $permissions, bool $admin): array
    {
        $this->type($type); $actor = $this->actor($user);
        if (! in_array($action, ['start', 'pause', 'resume'], true)) $this->fail('labor_action_invalid', '工时操作不合法。');
        $this->requireReady();
        $identity = $payload + ['labor_target_type' => $type, 'labor_target_id' => $id];
        return $this->commands->run('warehouse_labor_'.$action, $identity, $user, function () use ($type, $id, $action, $payload, $user, $permissions, $admin, $actor) {
            $this->lockEmployee($actor);
            $target = $this->target($type, $id, $user, $permissions, $admin, true, true);
            if ((int) ($payload['expected_version'] ?? 0) !== (int) $target->business_version)
                $this->fail('version_conflict', '作业记录已变化，请刷新后继续计时。');
            $this->assertOwner($type, $target, $actor);
            if ($target->status !== ($type === 'delivery' ? 'IN_TRANSIT' : 'PICKING'))
                $this->fail('labor_state_invalid', '只有正在配料或配送的本人作业可以计时。');
            if ($action === 'pause') $this->close($type, $id, $actor, 'paused', true);
            else $this->open($type, $target, $actor, $action === 'resume');
            $target->business_version++; $target->save();
            return $this->patch($type, $target, $user, $permissions, $admin);
        }, function ($resultId) use ($type, $id, $user, $permissions, $admin, $actor) {
            if ($resultId !== $id) $this->fail('idempotency_hash_conflict', '提交标识不属于当前作业。');
            $target = $this->target($type, $id, $user, $permissions, $admin, false, true);
            $this->assertOwner($type, $target, $actor);
            return $this->patch($type, $target, $user, $permissions, $admin);
        });
    }

    /** Original business actions own their existing version and transaction. */
    public function businessAction(string $type, object $target, string $action, object $user): void
    {
        $this->type($type); $actor = $this->actor($user);
        if (in_array($action, ['assign', 'cancel'], true)) {
            if ($this->ready() && WarehouseLaborSession::where('target_type', $type)->where('target_id', $target->id)
                ->where('status', 'ACTIVE')->lockForUpdate()->exists())
                $this->fail('labor_pause_required', '该作业仍有本人计时，请由计时人员先暂停后再取消或改派。');
            return;
        }
        if (! in_array($action, ['start', 'confirm', 'deliver'], true)) return;
        $this->requireReady(); $this->lockEmployee($actor); $this->assertOwner($type, $target, $actor);
        if ($action === 'start') $this->open($type, $target, $actor, false);
        else {
            if (WarehouseLaborSession::where('target_type', $type)->where('target_id', $target->id)->where('status', 'ACTIVE')
                ->where('employee_legacy_id', '<>', $actor)->lockForUpdate()->exists())
                $this->fail('labor_pause_required', '其他人员仍在计时，请由本人先暂停后再确认完成。');
            if (! WarehouseLaborSession::where('target_type', $type)->where('target_id', $target->id)->where('employee_legacy_id', $actor)->exists())
                $this->fail('labor_session_required', '请先记录本人的实际作业工时，再确认完成。');
            $this->close($type, (int) $target->id, $actor, 'completed', false);
        }
    }

    public function projection(string $type, int $id, object $user, array $permissions, bool $admin, array $filters = []): array
    {
        $target = $this->target($type, $id, $user, $permissions, $admin);
        $related = $this->related($type, $target); $actor = $this->actor($user);
        $empty = ['schema_ready' => $this->ready(), 'closed_minutes' => '0.00', 'active_count' => 0,
            'my_active_session_id' => null, 'can_start' => false, 'can_pause' => false, 'can_resume' => false,
            'shared' => $type === 'public_preparation', 'related_work_orders' => $related,
            'sessions' => ['data' => [], 'total' => 0, 'current_page' => 1, 'per_page' => 10]];
        if (! $empty['schema_ready']) return $empty;
        $query = WarehouseLaborSession::where('target_type', $type)->where('target_id', $id);
        $mine = (clone $query)->where('employee_legacy_id', $actor);
        $active = (clone $mine)->where('status', 'ACTIVE')->first();
        $history = (clone $mine)->exists();
        $writePermission = $type === 'delivery' ? 'production.material_delivery.confirm' : 'production.material_picking.pick';
        $owner = (int) ($type === 'delivery' ? $target->delivery_user_legacy_id : $target->assigned_picker_legacy_id) === $actor;
        $writable = $owner && ($admin || in_array($writePermission, $permissions, true))
            && $target->status === ($type === 'delivery' ? 'IN_TRANSIT' : 'PICKING')
            && ($type !== 'picking' || ! $target->public_preparation_task_id);
        $page = max(1, (int) ($filters['labor_page'] ?? 1));
        $rows = (clone $query)->orderByDesc('id')->paginate(10, ['*'], 'labor_page', $page);
        $names = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $rows->getCollection()->pluck('employee_legacy_id'))
            ->get(['legacy_id', 'nickname', 'username'])->keyBy('legacy_id');
        $rows->setCollection($rows->getCollection()->map(fn ($row) => [
            'id' => (int) $row->id, 'employee_legacy_id' => (int) $row->employee_legacy_id,
            'employee_name' => $names[$row->employee_legacy_id]->nickname ?? $names[$row->employee_legacy_id]->username ?? null,
            'status' => $row->status, 'started_at' => $row->started_at?->toISOString(), 'ended_at' => $row->ended_at?->toISOString(),
            'end_reason' => $row->end_reason, 'actual_labor_minutes' => $row->actual_labor_minutes,
        ]));
        return [...$empty, 'closed_minutes' => bcadd((string) (clone $query)->where('status', 'ENDED')->sum('actual_labor_minutes'), '0', 2),
            'active_count' => (clone $query)->where('status', 'ACTIVE')->count(), 'my_active_session_id' => $active?->id,
            'can_start' => $writable && ! $active && ! $history, 'can_pause' => $writable && (bool) $active,
            'can_resume' => $writable && ! $active && $history, 'sessions' => $rows->toArray()];
    }

    private function patch(string $type, object $target, object $user, array $permissions, bool $admin): array
    { return ['id' => (int) $target->id, 'business_version' => (int) $target->business_version,
        'labor' => $this->projection($type, (int) $target->id, $user, $permissions, $admin)]; }

    private function open(string $type, object $target, int $actor, bool $resume): void
    {
        $this->lockEmployee($actor);
        if (DB::table('erp_production_labor_sessions')->where('employee_legacy_id', $actor)->where('status', 'ACTIVE')->lockForUpdate()->exists()
            || DB::table('erp_shipment_packing_labor_sessions')->where('employee_legacy_id', $actor)->where('status', 'ACTIVE')->lockForUpdate()->exists()
            || WarehouseLaborSession::where('employee_legacy_id', $actor)->where('status', 'ACTIVE')->lockForUpdate()->exists())
            $this->fail('labor_session_active', '本人已有正在计时的作业，请先暂停原作业。');
        $previous = WarehouseLaborSession::where('target_type', $type)->where('target_id', $target->id)
            ->where('employee_legacy_id', $actor)->orderByDesc('id')->lockForUpdate()->first();
        if ($resume !== (bool) $previous) $this->fail('labor_state_invalid', $resume ? '尚无可继续的本人作业时段。' : '该作业已有本人时段，请使用继续计时。');
        if ($previous && ($previous->status !== 'ENDED' || ! $previous->ended_at || $previous->ended_at->lt($previous->started_at)
            || bccomp((string) $previous->actual_labor_minutes, '0', 2) < 0)) $this->fail('labor_history_invalid', '原工时时段状态或时间不一致，请先核对。');
        WarehouseLaborSession::create(['target_type' => $type, 'target_id' => $target->id, 'employee_legacy_id' => $actor,
            'status' => 'ACTIVE', 'started_at' => now(), 'actual_labor_minutes' => '0.00', 'previous_session_id' => $previous?->id,
            'source_snapshot' => ['source_no' => $type === 'delivery' ? $target->delivery_no : $target->task_no,
                'source_business_version' => (int) $target->business_version, 'related_work_orders' => $this->related($type, $target)]]);
    }

    private function close(string $type, int $id, int $actor, string $reason, bool $required): void
    {
        $row = WarehouseLaborSession::where('target_type', $type)->where('target_id', $id)
            ->where('employee_legacy_id', $actor)->where('status', 'ACTIVE')->lockForUpdate()->first();
        if (! $row) { if ($required) $this->fail('labor_session_missing', '本人没有正在计时的作业。'); return; }
        $ended = now();
        if (! $row->started_at || $row->ended_at || $ended->lt($row->started_at)) $this->fail('labor_time_invalid', '实际工时时间不一致，请先核对。');
        $minutes = number_format($row->started_at->diffInSeconds($ended, false) / 60, 2, '.', '');
        if (bccomp($minutes, '0', 2) < 0 || bccomp($minutes, '999999999999.99', 2) > 0) $this->fail('labor_time_invalid', '实际工时超出可记录范围。');
        $row->update(['status' => 'ENDED', 'ended_at' => $ended, 'end_reason' => $reason, 'actual_labor_minutes' => $minutes]);
    }

    private function target(string $type, int $id, object $user, array $permissions, bool $admin, bool $lock = false, bool $write = false): object
    {
        $this->type($type); if ($id <= 0) $this->fail('not_found', '作业不存在。', 404);
        $permission = $type === 'delivery' ? 'production.material_delivery.view' : 'production.material_picking.view';
        if (! $admin && ! in_array($permission, $permissions, true)) $this->fail('permission_denied', '当前账号没有查看该作业的权限。', 403);
        if ($write && ! $admin && ! in_array($type === 'delivery' ? 'production.material_delivery.confirm' : 'production.material_picking.pick', $permissions, true))
            $this->fail('permission_denied', '当前账号没有本人作业计时权限。', 403);
        $scope = $this->scope->resolve($user, $permission, $permissions, $admin);
        $visible = WorkOrder::query()->select('id'); $this->scope->applyWorkOrderScope($visible, $scope);
        if ($type === 'public_preparation') $query = PublicMaterialPreparationTask::whereHas('tasks')
            ->whereDoesntHave('tasks', fn ($q) => $q->whereNotIn('work_order_id', $visible));
        else $query = ($type === 'delivery' ? MaterialDelivery::query() : MaterialPickingTask::query())->whereIn('work_order_id', $visible);
        $target = ($lock ? $query->lockForUpdate() : $query)->find($id);
        if (! $target) $this->fail('not_found', '作业不存在或不在当前数据范围内。', 404);
        if ($write && $type === 'picking' && $target->public_preparation_task_id) $this->fail('public_labor_parent_required', '公共配料请在公共任务记录本人计时，不能重复计入子单。');
        return $target;
    }

    private function related(string $type, object $target): array
    {
        $query = WorkOrder::query()->select('id', 'work_order_no');
        if ($type === 'public_preparation') $query->whereIn('id', MaterialPickingTask::where('public_preparation_task_id', $target->id)->select('work_order_id'));
        else $query->whereKey($target->work_order_id);
        return $query->orderBy('id')->get()->map(fn ($wo) => ['id' => (int) $wo->id, 'work_order_no' => $wo->work_order_no])->all();
    }
    private function assertOwner(string $type, object $target, int $actor): void
    { if ((int) ($type === 'delivery' ? $target->delivery_user_legacy_id : $target->assigned_picker_legacy_id) !== $actor)
        $this->fail('labor_owner_required', '只有本任务实际负责人员可以记录本人工时。', 403); }
    private function actor(object $user): int { $id = (int) ($user->legacy_id ?? $user->id ?? 0); if ($id <= 0) $this->fail('unauthenticated', '请先登录ERP。', 401); return $id; }
    private function type(string $type): void { if (! in_array($type, ['public_preparation', 'picking', 'delivery'], true)) $this->fail('labor_target_invalid', '工时对象类型不合法。'); }
    private function requireReady(): void { if (! $this->ready()) $this->fail('warehouse_labor_schema_missing', '仓配工时结构尚未就绪，请先完成正式迁移。'); }
    private function fail(string $code, string $message, int $status = 409): never { throw new WorkOrderDomainException($code, $message, $status); }
}
