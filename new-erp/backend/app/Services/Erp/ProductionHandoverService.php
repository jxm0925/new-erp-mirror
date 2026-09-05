<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use Illuminate\Support\Facades\DB;

class ProductionHandoverService
{
    public function pending(object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.handover.view');
        $userId = $this->userId($user);
        return DB::table('erp_production_operation_handovers as handover')
            ->join('erp_production_task_targets as link', function ($join): void {
                $join->on('link.target_type', '=', 'handover.target_target_type')->on('link.target_id', '=', 'handover.target_target_id');
            })->join('erp_production_tasks as task', 'task.id', '=', 'link.task_id')
            ->where('handover.status', 'WAIT_RECEIVE')->where('task.assignee_user_legacy_id', $userId)
            ->orderBy('handover.handed_over_at')->select('handover.*', 'task.id as task_id', 'task.task_no')->get()->map(fn ($row) => (array) $row)->all();
    }

    public function accept(int $id, array $payload, object $user, array $permissions): array
    { $this->permission($permissions, 'production.handover.receive'); return $this->decide($id, $payload, $user, true); }
    public function reject(int $id, array $payload, object $user, array $permissions): array
    { $this->permission($permissions, 'production.handover.reject'); return $this->decide($id, $payload, $user, false); }

    private function decide(int $id, array $payload, object $user, bool $accept): array
    {
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        $type = $accept ? 'accept_handover' : 'reject_handover';
        $hash = hash('sha256', json_encode([$id, (int) ($payload['expected_version'] ?? 0), $payload['reason'] ?? null], JSON_UNESCAPED_UNICODE));
        return DB::transaction(function () use ($id, $payload, $user, $accept, $commandId, $type, $hash): array {
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($existing) return $this->replay($existing, $type, $hash);
            $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => $type,
                'aggregate_type' => 'operation_handover', 'aggregate_id' => $id, 'request_hash' => $hash, 'status' => 'processing',
                'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]);
            $handover = DB::table('erp_production_operation_handovers')->where('id', $id)->lockForUpdate()->first();
            if (! $handover) $this->fail('handover_not_found', '工序交接单不存在。', 404);
            if ((int) $handover->business_version !== (int) ($payload['expected_version'] ?? 0)) $this->fail('version_conflict', '交接单版本已变化，请刷新后重试。', 409);
            if ($handover->status !== 'WAIT_RECEIVE') $this->fail('handover_already_decided', '该工序交接已经处理。', 409);
            [$task, $target] = $this->targetTask($handover->target_target_type, (int) $handover->target_target_id);
            if ((int) $task->assignee_user_legacy_id !== $this->userId($user)) $this->fail('expected_receiver_required', '只有下一工序当前接单负责人可以处理交接。', 403);
            $now = now();
            if ($accept) {
                $acceptedQty = $this->acceptTargetMaterial($handover);
                DB::table('erp_production_operation_handovers')->where('id', $id)->update(['status' => 'RECEIVED',
                    'expected_receiver_legacy_id' => $this->userId($user), 'received_by_legacy_id' => $this->userId($user),
                    'received_at' => $now, 'completeness_snapshot' => json_encode($payload['completeness'] ?? ['complete' => true], JSON_UNESCAPED_UNICODE),
                    'accepted_base_qty' => $acceptedQty,
                    'business_version' => (int) $handover->business_version + 1, 'updated_at' => $now]);
                $target->status = $target->kitting_required && ! $target->kitting_confirmed_at ? 'WAIT_MATERIAL' : 'READY';
            } else {
                $reason = trim((string) ($payload['reason'] ?? ''));
                if ($reason === '') $this->fail('reject_reason_required', '拒收交接时必须填写原因。');
                DB::table('erp_production_operation_handovers')->where('id', $id)->update(['status' => 'REJECTED', 'reject_reason' => $reason,
                    'expected_receiver_legacy_id' => $this->userId($user), 'received_by_legacy_id' => $this->userId($user), 'received_at' => $now,
                    'business_version' => (int) $handover->business_version + 1, 'updated_at' => $now]);
                $source = $this->target($handover->source_target_type, (int) $handover->source_target_id, true);
                $source->status = 'REWORK'; $source->business_version = (int) $source->business_version + 1; $source->save();
                $target->status = 'WAIT_HANDOVER';
            }
            $target->business_version = (int) $target->business_version + 1; $target->save();
            $task->targets()->where('target_type', $handover->target_target_type)->where('target_id', $handover->target_target_id)->update(['status_snapshot' => $target->status]);
            $result = ['id' => $id, 'status' => $accept ? 'RECEIVED' : 'REJECTED', 'target_status' => $target->status,
                'target_business_version' => (int) $target->business_version, 'handled_at' => $now->toISOString()];
            $ledger->update(['result_type' => 'operation_handover', 'result_id' => $id, 'response_snapshot' => $result,
                'status' => 'succeeded', 'processing_finished_at' => now()]);
            return $result;
        }, 5);
    }

    private function acceptTargetMaterial(object $handover): ?float
    {
        $requirementId = $handover->target_material_requirement_id;
        if (! $requirementId) return null;
        $requirement = DB::table('erp_production_target_material_requirements')->where('id', $requirementId)->lockForUpdate()->first();
        if (! $requirement || $requirement->target_type !== $handover->target_target_type || (int) $requirement->target_id !== (int) $handover->target_target_id) {
            $this->fail('handover_material_requirement_invalid', '工序交接绑定的目标物料需求无效，禁止接收。', 409);
        }
        $outputQty = (float) (DB::table('erp_production_output_records')->where('id', $handover->output_record_id)->value('output_base_qty') ?? 0);
        $shortage = max(0, (float) $requirement->required_base_qty - (float) $requirement->satisfied_base_qty);
        $accepted = min($outputQty, $shortage);
        if ($accepted <= 0) return 0;
        DB::table('erp_production_target_material_requirements')->where('id', $requirement->id)->update([
            'satisfied_base_qty' => (float) $requirement->satisfied_base_qty + $accepted,
            'status' => $accepted + (float) $requirement->satisfied_base_qty + 0.00000001 >= (float) $requirement->required_base_qty ? 'SATISFIED' : 'PARTIAL',
            'business_version' => (int) $requirement->business_version + 1, 'updated_at' => now(),
        ]);
        return $accepted;
    }

    private function targetTask(string $type, int $id): array
    {
        $link = DB::table('erp_production_task_targets')->where('target_type', $type)->where('target_id', $id)->first();
        $task = $link ? ProductionTask::query()->lockForUpdate()->find($link->task_id) : null;
        if (! $task) $this->fail('target_task_not_found', '下一工序尚未形成有效生产任务。', 409);
        return [$task, $this->target($type, $id, true)];
    }
    private function target(string $type, int $id, bool $lock): object
    {
        $model = $type === 'unit_operation' ? ProductionUnitOperation::class : ($type === 'quantity_operation' ? ProductionQuantityOperation::class : null);
        if (! $model) $this->fail('task_target_invalid', '生产执行目标类型无效。');
        $query = $model::query(); if ($lock) $query->lockForUpdate();
        return $query->findOrFail($id);
    }
    private function replay(ProductionExecutionCommand $command, string $type, string $hash): array
    {
        if ($command->command_type !== $type || $command->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
        if ($command->status !== 'succeeded' || ! is_array($command->response_snapshot)) $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409);
        return $command->response_snapshot;
    }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
