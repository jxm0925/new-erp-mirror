<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use App\Models\Erp\WorkOrderMaterialRequirement;
use Illuminate\Support\Facades\DB;

class ProductionMaterialSupplementService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    public function request(array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.material_supplement.request');
        $taskId = (int) $payload['task_id'];
        return $this->command('request_material_supplement', 'production_task', $taskId, $payload, $user,
            function () use ($payload, $user, $taskId): array {
                $task = ProductionTask::query()->with('targets')->lockForUpdate()->find($taskId);
                if (! $task || (int) $task->assignee_user_legacy_id !== $this->userId($user)) $this->fail('task_owner_required', '只有任务负责人可以申请生产追加补料。', 403);
                $targetType = (string) $payload['target_type']; $targetId = (int) $payload['target_id'];
                if (! $task->targets->contains(fn ($row) => $row->target_type === $targetType && (int) $row->target_id === $targetId)) $this->fail('task_target_not_found', '任务中不存在该生产目标。', 404);
                $target = $this->target($targetType, $targetId);
                if ((int) $target->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '生产目标版本已变化，请刷新后重试。', 409);
                $reason = trim((string) $payload['reason']); if ($reason === '') $this->fail('reason_required', '补料原因不能为空。');
                $lines = collect($payload['lines'] ?? []); if ($lines->isEmpty()) $this->fail('supplement_lines_required', '补料明细不能为空。');
                $requestId = DB::table('erp_production_material_supplement_requests')->insertGetId([
                    'request_no' => $this->numbers->next('production_supplement', 'PSU'), 'work_order_id' => $task->work_order_id,
                    'task_id' => $task->id, 'target_type' => $targetType, 'target_id' => $targetId, 'status' => 'SUBMITTED',
                    'blocking' => (bool) ($payload['blocking'] ?? true), 'reason' => $reason, 'requested_by_legacy_id' => $this->userId($user),
                    'requested_at' => now(), 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
                foreach ($lines as $line) {
                    $qty = (float) ($line['additional_base_qty'] ?? 0); if ($qty <= 0) $this->fail('supplement_quantity_invalid', '追加补料数量必须大于 0。');
                    $standard = DB::table('erp_production_target_material_requirements')->where('target_type', $targetType)->where('target_id', $targetId)
                        ->where('component_item_id', (int) $line['component_item_id'])->where('requirement_kind', 'standard')->first();
                    if (! $standard) $this->fail('standard_requirement_not_found', '追加补料物料必须来自该工序的冻结标准需求。');
                    DB::table('erp_production_material_supplement_lines')->insert(['supplement_request_id' => $requestId,
                        'component_item_id' => $standard->component_item_id, 'base_unit_id' => WorkOrderMaterialRequirement::find($standard->material_requirement_id)?->base_unit_id,
                        'standard_base_qty_snapshot' => $standard->required_base_qty, 'additional_base_qty' => $qty,
                        'created_at' => now(), 'updated_at' => now()]);
                }
                if ((bool) ($payload['blocking'] ?? true)) $target->update(['status' => 'WAIT_MATERIAL', 'business_version' => (int) $target->business_version + 1]);
                return ['id' => $requestId, 'status' => 'SUBMITTED', 'blocking' => (bool) ($payload['blocking'] ?? true)];
            });
    }

    public function approve(int $id, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.material_supplement.approve');
        return $this->command('approve_material_supplement', 'material_supplement', $id, $payload, $user, function () use ($id, $payload, $user): array {
            $request = DB::table('erp_production_material_supplement_requests')->where('id', $id)->lockForUpdate()->first();
            if (! $request) $this->fail('supplement_not_found', '生产补料申请不存在。', 404);
            if ((int) $request->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '补料申请版本已变化，请刷新后重试。', 409);
            if ($request->status !== 'SUBMITTED') $this->fail('supplement_already_decided', '补料申请已经处理。', 409);
            $approved = (bool) $payload['approved']; $now = now();
            if ($approved) foreach (DB::table('erp_production_material_supplement_lines')->where('supplement_request_id', $id)->lockForUpdate()->get() as $line) {
                $standardTarget = DB::table('erp_production_target_material_requirements')->where('target_type', $request->target_type)->where('target_id', $request->target_id)
                    ->where('component_item_id', $line->component_item_id)->where('requirement_kind', 'standard')->first();
                $standard = WorkOrderMaterialRequirement::query()->lockForUpdate()->findOrFail($standardTarget->material_requirement_id);
                $new = $standard->replicate(); $new->line_no = (int) WorkOrderMaterialRequirement::where('work_order_id', $request->work_order_id)->max('line_no') + 10;
                $new->per_output_qty = 0; $new->fixed_qty = $line->additional_base_qty; $new->required_qty = $line->additional_base_qty;
                $new->base_required_qty = $line->additional_base_qty; $new->issued_qty = 0; $new->returned_qty = 0; $new->remaining_qty = $line->additional_base_qty;
                $new->status = 'OPEN'; $new->business_version = 1; $new->save();
                $newTargetId = DB::table('erp_production_target_material_requirements')->insertGetId(['work_order_id' => $request->work_order_id,
                    'target_type' => $request->target_type, 'target_id' => $request->target_id, 'material_requirement_id' => $new->id,
                    'material_supply_rule_snapshot_id' => $standardTarget->material_supply_rule_snapshot_id, 'component_item_id' => $line->component_item_id,
                    'requirement_kind' => 'supplement_'.$id, 'required_base_qty' => $line->additional_base_qty, 'satisfied_base_qty' => 0,
                    'consumed_base_qty' => 0, 'returned_base_qty' => 0, 'status' => 'OPEN', 'business_version' => 1, 'created_at' => $now, 'updated_at' => $now]);
                DB::table('erp_production_material_supplement_lines')->where('id', $line->id)->update(['generated_material_requirement_id' => $new->id, 'updated_at' => $now]);
            }
            DB::table('erp_production_material_supplement_requests')->where('id', $id)->update(['status' => $approved ? 'APPROVED' : 'REJECTED',
                'decided_by_legacy_id' => $this->userId($user), 'decided_at' => $now, 'decision_reason' => $payload['reason'] ?? null,
                'business_version' => (int) $request->business_version + 1, 'updated_at' => $now]);
            return ['id' => $id, 'status' => $approved ? 'APPROVED' : 'REJECTED', 'business_version' => (int) $request->business_version + 1];
        });
    }

    private function target(string $type, int $id): object { $model = $type === 'unit_operation' ? ProductionUnitOperation::class : ProductionQuantityOperation::class; return $model::query()->lockForUpdate()->findOrFail($id); }
    private function command(string $type, string $aggregateType, int $aggregateId, array $payload, object $user, callable $action): array
    { $commandId = trim((string) ($payload['client_command_id'] ?? '')); $hashPayload = $payload; ksort($hashPayload); $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE)); return DB::transaction(function () use ($type, $aggregateType, $aggregateId, $payload, $user, $action, $commandId, $hash): array { $existing = ProductionExecutionCommand::where('client_command_id', $commandId)->lockForUpdate()->first(); if ($existing) { if ($existing->command_type !== $type || $existing->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409); return $existing->response_snapshot; } $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => $type, 'aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId, 'request_hash' => $hash, 'status' => 'processing', 'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]); $result = $action(); $ledger->update(['result_type' => $aggregateType, 'result_id' => $result['id'] ?? $aggregateId, 'response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now()]); return $result; }, 5); }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
