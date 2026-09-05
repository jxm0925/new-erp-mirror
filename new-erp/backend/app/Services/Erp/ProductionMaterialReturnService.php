<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\WorkOrderMaterialRequirement;
use Illuminate\Support\Facades\DB;

class ProductionMaterialReturnService
{
    public function __construct(private readonly DocumentNumberService $numbers, private readonly InventoryService $inventory) {}

    public function create(array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.material_return.create');
        return $this->command('create_production_material_return', 'production_task', (int) $payload['task_id'], $payload, $user, function () use ($payload, $user): array {
            $task = ProductionTask::query()->with('targets')->lockForUpdate()->find($payload['task_id']);
            if (! $task || (int) $task->assignee_user_legacy_id !== $this->userId($user)) $this->fail('task_owner_required', '只有已接单的任务负责人可以发起签收后退料。', 403);
            if ((int) $task->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '任务版本已变化，请刷新后重试。', 409);
            if (! $task->targets->contains(fn ($row) => $row->target_type === $payload['target_type'] && (int) $row->target_id === (int) $payload['target_id'])) $this->fail('task_target_not_found', '任务中不存在该生产目标。', 404);
            $lines = collect($payload['lines']); if ($lines->isEmpty()) $this->fail('return_lines_required', '退料明细不能为空。');
            $id = DB::table('erp_production_material_returns')->insertGetId(['return_no' => $this->numbers->next('production_material_return', 'PMR'),
                'work_order_id' => $task->work_order_id, 'task_id' => $task->id, 'target_type' => $payload['target_type'], 'target_id' => $payload['target_id'],
                'return_type' => $payload['return_type'], 'status' => 'SUBMITTED', 'reason' => $payload['reason'],
                'requested_by_legacy_id' => $this->userId($user), 'requested_at' => now(), 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            foreach ($lines as $line) {
                $requirement = WorkOrderMaterialRequirement::query()->lockForUpdate()->find((int) $line['material_requirement_id']);
                if (! $requirement || (int) $requirement->work_order_id !== (int) $task->work_order_id) $this->fail('material_requirement_invalid', '退料明细不属于当前工单。');
                $qty = (float) $line['return_base_qty'];
                $already = (float) DB::table('erp_production_material_return_lines as line')->join('erp_production_material_returns as header', 'header.id', '=', 'line.return_id')
                    ->where('line.material_requirement_id', $requirement->id)->whereIn('header.status', ['SUBMITTED', 'WAIT_QUALITY', 'COMPLETED'])->sum('line.return_base_qty');
                if ($qty <= 0 || $qty + $already > (float) $requirement->received_qty + 0.00000001) $this->fail('return_quantity_exceeds_received', '签收后退料数量不能超过该需求已签收且尚未退回的数量。');
                DB::table('erp_production_material_return_lines')->insert(['return_id' => $id, 'material_requirement_id' => $requirement->id,
                    'component_item_id' => $requirement->component_item_id, 'warehouse_id' => $line['warehouse_id'], 'location_id' => $line['location_id'],
                    'batch_no' => $line['batch_no'] ?? null, 'serial_snapshot' => isset($line['serial_ids']) ? json_encode(['inventory_serial_ids' => $line['serial_ids']]) : null,
                    'return_base_qty' => $qty, 'quality_disposition' => $payload['return_type'] === 'quality_return' ? 'pending' : 'not_required',
                    'created_at' => now(), 'updated_at' => now()]);
            }
            return ['id' => $id, 'status' => 'SUBMITTED', 'return_type' => $payload['return_type'], 'business_version' => 1];
        });
    }

    public function receive(int $id, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.material_return.receive');
        return $this->command('receive_production_material_return', 'production_material_return', $id, $payload, $user, function () use ($id, $payload, $user): array {
            $return = DB::table('erp_production_material_returns')->where('id', $id)->lockForUpdate()->first();
            if (! $return) $this->fail('material_return_not_found', '生产退料单不存在。', 404);
            if ((int) $return->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '退料单版本已变化，请刷新后重试。', 409);
            if ($return->status !== 'SUBMITTED') $this->fail('material_return_already_received', '该生产退料已由仓库处理。', 409);
            $lines = DB::table('erp_production_material_return_lines')->where('return_id', $id)->lockForUpdate()->get();
            $transaction = $this->inventory->postProductionMaterialReturnReceipt($return, $lines, $user, $return->return_type === 'quality_return');
            foreach ($lines as $line) { $requirement = WorkOrderMaterialRequirement::lockForUpdate()->findOrFail($line->material_requirement_id);
                $requirement->returned_qty = (float) $requirement->returned_qty + (float) $line->return_base_qty; $requirement->business_version++; $requirement->save(); }
            $status = $return->return_type === 'quality_return' ? 'WAIT_QUALITY' : 'COMPLETED';
            DB::table('erp_production_material_returns')->where('id', $id)->update(['status' => $status,
                'warehouse_received_by_legacy_id' => $this->userId($user), 'warehouse_received_at' => now(),
                'inventory_transaction_id' => $transaction->id, 'business_version' => (int) $return->business_version + 1, 'updated_at' => now()]);
            return ['id' => $id, 'status' => $status, 'inventory_transaction_id' => (int) $transaction->id, 'business_version' => (int) $return->business_version + 1];
        });
    }

    public function quality(int $id, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.material_return.quality');
        return $this->command('inspect_production_material_return', 'production_material_return', $id, $payload, $user, function () use ($id, $payload, $user): array {
            $return = DB::table('erp_production_material_returns')->where('id', $id)->lockForUpdate()->first();
            if (! $return) $this->fail('material_return_not_found', '生产退料单不存在。', 404);
            if ((int) $return->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '退料单版本已变化，请刷新后重试。', 409);
            if ($return->return_type !== 'quality_return' || $return->status !== 'WAIT_QUALITY') $this->fail('quality_return_not_pending', '只有仓库已接收的质量退料可以检验。', 409);
            $passed = (bool) $payload['passed']; $lines = DB::table('erp_production_material_return_lines')->where('return_id', $id)->lockForUpdate()->get();
            $transaction = $passed ? $this->inventory->releaseProductionMaterialReturnQuarantine($return, $lines, $user) : null;
            $inspectionId = DB::table('erp_production_material_return_inspections')->insertGetId(['inspection_no' => $this->numbers->next('production_return_quality', 'PRQ'),
                'return_id' => $id, 'status' => 'COMPLETED', 'result' => $passed ? 'passed' : 'failed', 'reason' => $payload['reason'] ?? null,
                'inspector_legacy_id' => $this->userId($user), 'inspected_at' => now(), 'inventory_transaction_id' => $transaction?->id,
                'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('erp_production_material_return_lines')->where('return_id', $id)->update(['quality_disposition' => $passed ? 'usable' : 'quarantined', 'updated_at' => now()]);
            DB::table('erp_production_material_returns')->where('id', $id)->update(['status' => $passed ? 'COMPLETED' : 'QUARANTINED',
                'business_version' => (int) $return->business_version + 1, 'updated_at' => now()]);
            return ['id' => $id, 'inspection_id' => $inspectionId, 'status' => $passed ? 'COMPLETED' : 'QUARANTINED',
                'inventory_transaction_id' => $transaction?->id, 'business_version' => (int) $return->business_version + 1];
        });
    }

    private function command(string $type, string $aggregateType, int $aggregateId, array $payload, object $user, callable $action): array
    { $commandId = trim((string) ($payload['client_command_id'] ?? '')); $hashPayload = $payload; ksort($hashPayload); $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE)); return DB::transaction(function () use ($type, $aggregateType, $aggregateId, $user, $action, $commandId, $hash): array { $existing = ProductionExecutionCommand::where('client_command_id', $commandId)->lockForUpdate()->first(); if ($existing) { if ($existing->command_type !== $type || $existing->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409); return $existing->response_snapshot; } $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => $type, 'aggregate_type' => $aggregateType, 'aggregate_id' => $aggregateId, 'request_hash' => $hash, 'status' => 'processing', 'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]); $result = $action(); $ledger->update(['result_type' => $aggregateType, 'result_id' => $result['id'] ?? $aggregateId, 'response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now()]); return $result; }, 5); }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
