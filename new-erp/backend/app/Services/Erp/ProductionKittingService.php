<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionKittingConfirmation;
use App\Models\Erp\ProductionLaborSession;
use App\Models\Erp\ProductionQuantityOperation;
use App\Models\Erp\ProductionTask;
use App\Models\Erp\ProductionUnitOperation;
use Illuminate\Support\Facades\DB;

class ProductionKittingService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    public function requirements(int $taskId, string $targetType, int $targetId, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.kitting.view');
        [$task, $target] = $this->taskTarget($taskId, $targetType, $targetId);
        $this->responsible($task, $user);
        return $this->materialRows($targetType, $targetId)->values()->all();
    }

    public function confirm(int $taskId, string $targetType, int $targetId, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.kitting.confirm');
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') $this->fail('client_command_id_required', '写操作必须提供 client_command_id。');
        $hashPayload = $payload + ['task_id' => $taskId, 'target_type' => $targetType, 'target_id' => $targetId];
        ksort($hashPayload);
        $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return DB::transaction(function () use ($taskId, $targetType, $targetId, $payload, $user, $commandId, $hash): array {
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->command_type !== 'confirm_kitting' || $existing->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
                if ($existing->status !== 'succeeded') $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409);
                return $existing->response_snapshot;
            }
            $ledger = ProductionExecutionCommand::create([
                'client_command_id' => $commandId, 'command_type' => 'confirm_kitting',
                'aggregate_type' => $targetType, 'aggregate_id' => $targetId, 'request_hash' => $hash,
                'status' => 'processing', 'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now(),
            ]);
            [$task, $target] = $this->taskTarget($taskId, $targetType, $targetId, true);
            $this->responsible($task, $user);
            if ((int) $target->business_version !== (int) $payload['expected_version']) $this->fail('version_conflict', '生产目标版本已变化，请刷新后重试。', 409);
            if (! in_array($target->status, ['CLAIMED', 'WAIT_MATERIAL', 'WAIT_HANDOVER'], true)) $this->fail('invalid_state', '当前生产目标状态不能确认齐套。');

            $pendingHandover = DB::table('erp_production_operation_handovers')
                ->where('target_target_type', $targetType)->where('target_target_id', $targetId)
                ->where('status', 'WAIT_RECEIVE')->exists();
            if ($pendingHandover) $this->fail('handover_not_received', '上一工序产出尚未完成交接接收，不能确认齐套。');

            $this->freezeWorkstationStockFacts($task, $targetType, $targetId, $payload, $user);

            $rows = $this->materialRows($targetType, $targetId);
            if ($rows->isEmpty() && ! $target->kitting_required) $this->fail('kitting_not_required', '当前工序没有需要齐套确认的物料。');
            $shortages = $rows->filter(fn (array $row): bool => $row['shortage_base_qty'] > 0.00000001)->values();
            if ($shortages->isNotEmpty()) $this->fail('materials_not_ready', '当前工序仍有必需物料未到位，不能确认齐套。', 422, ['shortages' => $shortages->all()]);

            $confirmation = ProductionKittingConfirmation::create([
                'confirmation_no' => $this->numbers->next('production_kitting', 'KIT'),
                'work_order_id' => $task->work_order_id, 'task_id' => $task->id,
                'target_type' => $targetType, 'target_id' => $targetId,
                'target_routing_operation_id_snapshot' => $target->routing_operation_id_snapshot,
                'status' => 'CONFIRMED', 'required_materials_snapshot' => $rows->pluck('required')->values()->all(),
                'received_materials_snapshot' => $rows->pluck('received')->values()->all(),
                'shortage_materials_snapshot' => [], 'confirmed_by_legacy_id' => $this->userId($user),
                'confirmed_at' => now(), 'business_version' => 1,
            ]);
            foreach ($rows as $row) {
                $confirmation->lines()->create([
                    'material_supply_rule_snapshot_id' => $row['material_supply_rule_snapshot_id'],
                    'component_item_id' => $row['component_item_id'],
                    'required_base_qty_snapshot' => $row['required_base_qty'],
                    'received_base_qty_snapshot' => $row['satisfied_base_qty'],
                    'shortage_base_qty_snapshot' => 0,
                    'source_facts_snapshot' => $row['source_facts'],
                ]);
            }
            DB::table('erp_production_workstation_stock_confirmations')
                ->where('task_id', $task->id)->where('target_type', $targetType)->where('target_id', $targetId)
                ->update(['kitting_confirmation_id' => $confirmation->id, 'updated_at' => now()]);

            $now = $confirmation->confirmed_at;
            $target->kitting_confirmed_at = $confirmation->confirmed_at;
            $target->kitting_confirmed_by_legacy_id = $this->userId($user);
            $target->started_at = $target->started_at ?: $now;
            $target->paused_at = null;
            $target->status = 'IN_PROGRESS';
            $target->business_version = (int) $target->business_version + 1;
            $target->save();
            $this->startOwnerLaborSession($task, $targetType, $targetId, $this->userId($user), $now);
            $task->targets()->where('target_type', $targetType)->where('target_id', $targetId)->update(['status_snapshot' => 'IN_PROGRESS']);
            if ($task->status !== 'IN_PROGRESS') {
                $task->update(['status' => 'IN_PROGRESS', 'business_version' => (int) $task->business_version + 1]);
            }

            $result = ['id' => (int) $confirmation->id, 'confirmation_no' => $confirmation->confirmation_no,
                'status' => $confirmation->status, 'target_status' => $target->status,
                'target_business_version' => (int) $target->business_version, 'confirmed_at' => $confirmation->confirmed_at->toISOString()];
            $ledger->update(['result_type' => 'kitting_confirmation', 'result_id' => $confirmation->id,
                'response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now()]);
            return $result;
        }, 5);
    }

    private function materialRows(string $targetType, int $targetId)
    {
        return DB::table('erp_production_target_material_requirements as requirement')
            ->join('erp_work_order_material_supply_rules as supply', 'supply.id', '=', 'requirement.material_supply_rule_snapshot_id')
            ->join('erp_items as item', 'item.id', '=', 'requirement.component_item_id')
            ->where('requirement.target_type', $targetType)->where('requirement.target_id', $targetId)
            ->where('supply.participates_in_kitting_snapshot', true)
            ->leftJoin('erp_production_workstation_stock_confirmations as workstation', 'workstation.target_material_requirement_id', '=', 'requirement.id')
            ->select([
                'requirement.id', 'requirement.material_supply_rule_snapshot_id', 'requirement.component_item_id',
                'requirement.required_base_qty', 'requirement.satisfied_base_qty',
                'supply.supply_mode_snapshot', 'item.item_code', 'item.item_name',
                'workstation.workstation_snapshot', 'workstation.onsite_available_base_qty_snapshot',
                'workstation.confirmed_base_qty', 'workstation.confirmed_by_legacy_id', 'workstation.confirmed_at',
            ])
            ->orderBy('requirement.id')->get()->map(function ($row): array {
                $required = (float) $row->required_base_qty;
                $received = (float) $row->satisfied_base_qty;
                $mode = $row->supply_mode_snapshot === 'line_side_stock' ? 'workstation_stock' : $row->supply_mode_snapshot;
                $sourceFacts = ['supply_mode' => $mode];
                if ($mode === 'workstation_stock' && $row->workstation_snapshot !== null) {
                    $sourceFacts += [
                        'workstation' => $row->workstation_snapshot,
                        'onsite_available_base_qty_snapshot' => (float) $row->onsite_available_base_qty_snapshot,
                        'confirmed_base_qty' => (float) $row->confirmed_base_qty,
                        'confirmed_by_legacy_id' => (int) $row->confirmed_by_legacy_id,
                        'confirmed_at' => $row->confirmed_at,
                    ];
                }
                return [
                    'id' => (int) $row->id, 'material_supply_rule_snapshot_id' => (int) $row->material_supply_rule_snapshot_id,
                    'component_item_id' => (int) $row->component_item_id, 'component_item_code' => $row->item_code,
                    'component_item_name' => $row->item_name, 'required_base_qty' => $required,
                    'satisfied_base_qty' => $received, 'shortage_base_qty' => max(0, $required - $received),
                    'required' => ['component_item_id' => (int) $row->component_item_id, 'base_qty' => $required],
                    'received' => ['component_item_id' => (int) $row->component_item_id, 'base_qty' => $received],
                    'source_facts' => $sourceFacts,
                ];
            });
    }

    private function freezeWorkstationStockFacts(ProductionTask $task, string $targetType, int $targetId, array $payload, object $user): void
    {
        $requirements = DB::table('erp_production_target_material_requirements as requirement')
            ->join('erp_work_order_material_supply_rules as supply', 'supply.id', '=', 'requirement.material_supply_rule_snapshot_id')
            ->where('requirement.target_type', $targetType)->where('requirement.target_id', $targetId)
            ->whereIn('supply.supply_mode_snapshot', ['workstation_stock', 'line_side_stock'])
            ->select('requirement.*')->lockForUpdate()->get();
        if ($requirements->isEmpty()) return;

        $provided = collect((array) ($payload['workstation_stock_confirmations'] ?? []));
        if ($provided->pluck('requirement_id')->map(fn ($id) => (int) $id)->duplicates()->isNotEmpty()) {
            $this->fail('workstation_stock_confirmation_duplicate', '同一项工位常备料不能重复确认。');
        }
        $provided = $provided->keyBy(fn (array $row): int => (int) ($row['requirement_id'] ?? 0));
        $validIds = $requirements->pluck('id')->map(fn ($id) => (int) $id);
        if ($provided->keys()->map(fn ($id) => (int) $id)->diff($validIds)->isNotEmpty()) {
            $this->fail('workstation_stock_requirement_invalid', '提交的工位常备料不属于当前生产目标。');
        }

        $defaultWorkstation = trim((string) DB::table('erp_work_orders')->where('id', $task->work_order_id)->value('production_location_name'));
        foreach ($requirements as $requirement) {
            $input = $provided->get((int) $requirement->id);
            if (! is_array($input)) $this->fail('workstation_stock_confirmation_required', '工位常备料必须逐项核对现场可用数量后才能确认齐套。', 422, ['requirement_id' => (int) $requirement->id]);
            $onsite = (float) ($input['onsite_available_base_qty'] ?? 0);
            $required = (float) $requirement->required_base_qty;
            if ($onsite + 0.00000001 < $required) $this->fail('workstation_stock_insufficient', '工位常备料现场可用数量不足，不能确认齐套。', 422, [
                'requirement_id' => (int) $requirement->id, 'required_base_qty' => $required, 'onsite_available_base_qty' => $onsite,
            ]);
            $workstation = trim((string) ($input['workstation'] ?? $defaultWorkstation));
            if ($workstation === '') $this->fail('workstation_required', '确认工位常备料时必须明确具体工位。');
            $now = now();
            DB::table('erp_production_workstation_stock_confirmations')->insert([
                'work_order_id' => $task->work_order_id, 'task_id' => $task->id,
                'target_type' => $targetType, 'target_id' => $targetId,
                'target_material_requirement_id' => $requirement->id, 'workstation_snapshot' => $workstation,
                'component_item_id' => $requirement->component_item_id,
                'required_base_qty_snapshot' => $required, 'onsite_available_base_qty_snapshot' => $onsite,
                'confirmed_base_qty' => $required, 'confirmed_by_legacy_id' => $this->userId($user), 'confirmed_at' => $now,
                'fact_snapshot' => json_encode(['source' => 'workstation_stock', 'basis' => 'onsite_count'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                'business_version' => 1, 'created_at' => $now, 'updated_at' => $now,
            ]);
            DB::table('erp_production_target_material_requirements')->where('id', $requirement->id)->update([
                'satisfied_base_qty' => $required, 'status' => 'SATISFIED',
                'business_version' => (int) $requirement->business_version + 1, 'updated_at' => $now,
            ]);
        }
    }

    private function startOwnerLaborSession(ProductionTask $task, string $targetType, int $targetId, int $userId, $now): void
    {
        if (ProductionLaborSession::query()->where('task_id', $task->id)->where('target_type', $targetType)
            ->where('target_id', $targetId)->where('employee_legacy_id', $userId)->where('status', 'ACTIVE')->exists()) {
            $this->fail('labor_session_active', '当前负责人已有进行中的加工计时。', 409);
        }
        ProductionLaborSession::create([
            'task_id' => $task->id, 'target_type' => $targetType, 'target_id' => $targetId,
            'employee_legacy_id' => $userId, 'role' => 'owner', 'status' => 'ACTIVE',
            'started_at' => $now, 'actual_labor_minutes' => 0,
            'responsibility_weight_snapshot' => 1, 'credited_labor_minutes' => 0,
        ]);
    }

    private function taskTarget(int $taskId, string $targetType, int $targetId, bool $lock = false): array
    {
        $taskQuery = ProductionTask::query();
        if ($lock) $taskQuery->lockForUpdate();
        $task = $taskQuery->find($taskId);
        if (! $task || ! $task->targets()->where('target_type', $targetType)->where('target_id', $targetId)->exists()) $this->fail('task_target_not_found', '任务中不存在该生产执行目标。', 404);
        $model = $targetType === 'unit_operation' ? ProductionUnitOperation::class : ($targetType === 'quantity_operation' ? ProductionQuantityOperation::class : null);
        if (! $model) $this->fail('task_target_invalid', '生产执行目标类型无效。');
        $targetQuery = $model::query();
        if ($lock) $targetQuery->lockForUpdate();
        $target = $targetQuery->find($targetId);
        if (! $target) $this->fail('task_target_not_found', '生产执行目标不存在。', 404);
        return [$task, $target];
    }

    private function responsible(ProductionTask $task, object $user): void { if ((int) $task->assignee_user_legacy_id !== $this->userId($user)) $this->fail('responsible_user_required', '只有当前任务负责人可以确认齐套。', 403); }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行该操作的权限。', 403, ['permission' => $code]); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never { throw new WorkOrderDomainException($code, $message, $status, $details); }
}
