<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionLaborSession;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use Illuminate\Support\Facades\DB;

final class ProductionReportService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    public function report(int $taskId, string $targetType, int $targetId, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.report.create');
        if ($targetType !== 'quantity_operation') {
            $this->fail('report_target_invalid', '逐件生产单元通过完工动作记录单件事实，只有数量型工序使用分批报工。');
        }
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') $this->fail('client_command_id_required', '写操作必须提供 client_command_id。');
        $hashPayload = $payload + ['task_id' => $taskId, 'target_type' => $targetType, 'target_id' => $targetId];
        ksort($hashPayload);
        $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($taskId, $targetId, $payload, $user, $commandId, $hash): array {
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($existing) return $this->replay($existing, $hash);
            $ledger = ProductionExecutionCommand::create([
                'client_command_id' => $commandId, 'command_type' => 'report_quantity_operation',
                'aggregate_type' => 'quantity_operation', 'aggregate_id' => $targetId,
                'request_hash' => $hash, 'status' => 'processing',
                'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now(),
            ]);
            $task = ProductionTask::query()->with('targets')->lockForUpdate()->find($taskId);
            if (! $task || ! $task->targets->contains(fn ($row) => $row->target_type === 'quantity_operation' && (int) $row->target_id === $targetId)) {
                $this->fail('task_target_not_found', '任务中不存在该数量型生产目标。', 404);
            }
            $this->participant($task, $user);
            $target = ProductionQuantityOperation::query()->lockForUpdate()->find($targetId);
            if (! $target) $this->fail('task_target_not_found', '数量型生产目标不存在。', 404);
            if ((int) $target->business_version !== (int) ($payload['expected_version'] ?? 0)) {
                $this->fail('version_conflict', '生产目标版本已变化，请刷新后重试。', 409, ['current_version' => (int) $target->business_version]);
            }
            if (! in_array($target->status, ['IN_PROGRESS', 'PAUSED'], true)) {
                $this->fail('target_not_reportable', '只有加工中或已暂停的数量型生产目标可以报工。', 409);
            }

            $qualified = $this->quantity($payload['qualified_base_qty'] ?? 0, 'qualified_base_qty');
            $unqualified = $this->quantity($payload['unqualified_base_qty'] ?? 0, 'unqualified_base_qty');
            $scrapped = $this->quantity($payload['scrapped_base_qty'] ?? 0, 'scrapped_base_qty');
            $reported = $qualified + $unqualified + $scrapped;
            if ($reported <= 0) $this->fail('report_quantity_required', '本次报工数量必须大于 0。');
            if ($reported > (float) $target->remaining_base_qty + 0.00000001) {
                $this->fail('report_quantity_exceeds_remaining', '本次报工数量不能超过剩余可报数量。', 409, [
                    'remaining_base_qty' => (float) $target->remaining_base_qty,
                ]);
            }
            $defectReason = trim((string) ($payload['defect_reason'] ?? ''));
            if (($unqualified > 0 || $scrapped > 0) && $defectReason === '') {
                $this->fail('defect_reason_required', '存在不良品或报废数量时必须填写原因。');
            }

            $now = now();
            $beforeStatus = (string) $target->status;
            $endedLabor = false;
            $laborSnapshot = $this->laborSnapshot($task, $target, $this->userId($user));
            if ((bool) ($payload['end_labor'] ?? false)) {
                $endedLabor = $this->endReporterLabor($task, $target, $this->userId($user), $now);
            }
            $reportId = DB::table('erp_production_reports')->insertGetId([
                'report_no' => $this->numbers->next('production_report', 'PRP'),
                'client_command_id' => $commandId, 'work_order_id' => $task->work_order_id,
                'task_id' => $task->id, 'target_type' => 'quantity_operation', 'target_id' => $target->id,
                'base_unit_id' => DB::table('erp_work_orders')->where('id', $task->work_order_id)->value('base_unit_id'),
                'qualified_base_qty' => $qualified, 'unqualified_base_qty' => $unqualified,
                'scrapped_base_qty' => $scrapped, 'defect_reason' => $defectReason ?: null,
                'remark' => $payload['remark'] ?? null,
                'attachment_snapshot' => empty($payload['attachments']) ? null : json_encode(array_values($payload['attachments']), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'labor_snapshot' => $laborSnapshot === [] ? null : json_encode($laborSnapshot, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'ended_reporter_labor' => $endedLabor, 'reported_by_legacy_id' => $this->userId($user),
                'organization_code' => $task->organization_code, 'reported_at' => $now,
                'business_version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);

            $target->completed_base_qty = (float) $target->completed_base_qty + $qualified;
            $target->unqualified_base_qty = (float) $target->unqualified_base_qty + $unqualified;
            $target->scrapped_base_qty = (float) $target->scrapped_base_qty + $scrapped;
            $target->remaining_base_qty = max(0, (float) $target->remaining_base_qty - $reported);
            if ($endedLabor) {
                $otherActive = ProductionLaborSession::query()->where('target_type', 'quantity_operation')
                    ->where('target_id', $target->id)->where('status', 'ACTIVE')->exists();
                $target->status = $otherActive ? 'IN_PROGRESS' : 'PAUSED';
                $target->paused_at = $otherActive ? null : $now;
            }
            $beforeVersion = (int) $target->business_version;
            $target->business_version = $beforeVersion + 1;
            $target->save();
            $task->targets()->where('target_type', 'quantity_operation')->where('target_id', $target->id)
                ->update(['status_snapshot' => $target->status, 'updated_at' => $now]);

            $result = [
                'report_id' => $reportId,
                'report_no' => DB::table('erp_production_reports')->where('id', $reportId)->value('report_no'),
                'task_id' => (int) $task->id, 'target_id' => (int) $target->id,
                'target_status' => (string) $target->status,
                'target_business_version' => (int) $target->business_version,
                'qualified_base_qty' => $qualified, 'unqualified_base_qty' => $unqualified,
                'scrapped_base_qty' => $scrapped, 'remaining_base_qty' => (float) $target->remaining_base_qty,
                'ready_for_completion' => (float) $target->remaining_base_qty <= 0.00000001,
                'ended_reporter_labor' => $endedLabor,
            ];
            DB::table('erp_production_execution_events')->insert([
                'aggregate_type' => 'quantity_operation', 'aggregate_id' => $target->id,
                'action' => 'report_quantity_operation', 'before_status' => $beforeStatus,
                'after_status' => $target->status, 'before_version' => $beforeVersion,
                'after_version' => (int) $target->business_version,
                'fact_snapshot' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'operator_legacy_id' => $this->userId($user), 'operator_name' => $user->nickname ?? $user->username ?? null,
                'occurred_at' => $now, 'created_at' => $now, 'updated_at' => $now,
            ]);
            $ledger->update([
                'result_type' => 'production_report', 'result_id' => $reportId,
                'response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now(),
            ]);
            return $result;
        }, 5);
    }

    private function laborSnapshot(ProductionTask $task, ProductionQuantityOperation $target, int $userId): array
    {
        return ProductionLaborSession::query()->where('task_id', $task->id)->where('target_type', 'quantity_operation')
            ->where('target_id', $target->id)->where('employee_legacy_id', $userId)->orderBy('id')->get()
            ->map(fn (ProductionLaborSession $session) => [
                'labor_session_id' => (int) $session->id, 'status' => (string) $session->status,
                'started_at' => optional($session->started_at)->toISOString(),
                'ended_at' => optional($session->ended_at)->toISOString(),
                'actual_labor_minutes' => (float) $session->actual_labor_minutes,
            ])->all();
    }

    private function endReporterLabor(ProductionTask $task, ProductionQuantityOperation $target, int $userId, $now): bool
    {
        $session = ProductionLaborSession::query()->where('task_id', $task->id)->where('target_type', 'quantity_operation')
            ->where('target_id', $target->id)->where('employee_legacy_id', $userId)->where('status', 'ACTIVE')->lockForUpdate()->first();
        if (! $session) $this->fail('labor_session_missing', '未找到当前报工人的进行中加工计时。', 409);
        $minutes = max(0, $session->started_at->diffInSeconds($now) / 60);
        $session->update(['status' => 'ENDED', 'ended_at' => $now, 'actual_labor_minutes' => $minutes, 'credited_labor_minutes' => 0]);
        $target->actual_labor_minutes = (float) $target->actual_labor_minutes + $minutes;
        return true;
    }

    private function participant(ProductionTask $task, object $user): void
    {
        $userId = $this->userId($user);
        if ((int) $task->assignee_user_legacy_id === $userId) return;
        if ($task->collaborators()->where('employee_legacy_id', $userId)->whereNull('left_at')->exists()) return;
        $this->fail('task_participant_required', '只有任务负责人或当前协作者可以提交报工。', 403);
    }

    private function replay(ProductionExecutionCommand $command, string $hash): array
    {
        if ($command->command_type !== 'report_quantity_operation' || $command->request_hash !== $hash) {
            $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
        }
        if ($command->status !== 'succeeded' || ! is_array($command->response_snapshot)) {
            $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409);
        }
        return $command->response_snapshot;
    }

    private function quantity(mixed $value, string $field): float
    {
        if (! is_numeric($value) || (float) $value < 0) $this->fail('report_quantity_invalid', $field.' 必须为非负数。');
        return round((float) $value, 8);
    }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有提交生产报工的权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never { throw new WorkOrderDomainException($code, $message, $status, $details); }
}
