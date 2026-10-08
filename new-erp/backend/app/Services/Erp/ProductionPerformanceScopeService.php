<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use Illuminate\Support\Facades\DB;

/** Resolve personal-share subjects from genuine execution identities, never from sales names. */
class ProductionPerformanceScopeService
{
    public const TYPES = ['unit_operation', 'quantity_operation', 'shipment_packing_operation'];

    public function resolve(string $type, int $id, bool $lock = false): array
    {
        if (! in_array($type, self::TYPES, true)) $this->fail('performance_scope_invalid', '绩效来源工序类型不合法。');
        if ($type === 'shipment_packing_operation') return $this->packing($id, $lock);
        $table = $type === 'unit_operation' ? 'erp_production_unit_operations' : 'erp_production_quantity_operations';
        $query = DB::table($table)->where('id', $id);
        if ($lock) $query->lockForUpdate();
        $target = $query->first();
        if (! $target) $this->fail('performance_scope_not_found', '绩效来源工序不存在。', 404);
        $taskQuery = DB::table('erp_production_tasks as task')
            ->join('erp_production_task_targets as link', 'link.task_id', '=', 'task.id')
            ->where('link.target_type', $type)->where('link.target_id', $id);
        if ($lock) $taskQuery->lockForUpdate();
        $task = $taskQuery->first(['task.*']);
        $owner = (int) ($target->responsible_user_legacy_id ?: ($task->assignee_user_legacy_id ?? 0));
        if ($task && $owner > 0 && (int) $task->assignee_user_legacy_id !== $owner) {
            $this->fail('performance_owner_mismatch', '工序与接单人记录不一致，请先核对真实负责人。', 409);
        }
        $labor = DB::table('erp_production_labor_sessions')->where('target_type', $type)->where('target_id', $id)
            ->selectRaw('employee_legacy_id, COALESCE(SUM(actual_labor_minutes),0) AS actual_labor_minutes')->groupBy('employee_legacy_id')->get();
        $ids = $labor->pluck('employee_legacy_id')->map(fn ($value) => (int) $value)->all();
        if ($task) $ids = array_merge($ids, DB::table('erp_production_task_collaborators')->where('task_id', $task->id)
            ->pluck('employee_legacy_id')->map(fn ($value) => (int) $value)->all());
        if ($owner > 0) $ids[] = $owner;
        $workOrder = DB::table('erp_work_orders')->where('id', $target->work_order_id)->first(['id', 'work_order_no']);
        return $this->projection($type, $target, $owner, $this->people($ids, $labor->keyBy('employee_legacy_id')->all()), [
            'work_order_id' => (int) $target->work_order_id, 'work_order_no' => $workOrder->work_order_no ?? null,
            'task_id' => $task ? (int) $task->id : null, 'task_no' => $task->task_no ?? null,
        ]);
    }

    private function packing(int $id, bool $lock): array
    {
        $query = DB::table('erp_shipment_packing_operations')->where('id', $id);
        if ($lock) $query->lockForUpdate();
        $target = $query->first();
        if (! $target) $this->fail('performance_scope_not_found', '包装工序不存在。', 404);
        $labor = DB::table('erp_shipment_packing_labor_sessions')->where('operation_id', $id)
            ->selectRaw('employee_legacy_id, COALESCE(SUM(actual_labor_minutes),0) AS actual_labor_minutes')->groupBy('employee_legacy_id')->get();
        $ids = DB::table('erp_shipment_packing_participants')->where('operation_id', $id)->pluck('employee_legacy_id')
            ->merge($labor->pluck('employee_legacy_id'))->map(fn ($value) => (int) $value)->all();
        $owner = (int) ($target->owner_legacy_id ?? 0);
        if ($owner > 0) $ids[] = $owner;
        return $this->projection('shipment_packing_operation', $target, $owner, $this->people($ids, $labor->keyBy('employee_legacy_id')->all()), [
            'shipment_id' => (int) $target->shipment_id, 'package_id' => (int) $target->package_id,
            'work_order_id' => null, 'task_id' => null, 'work_order_no' => null, 'task_no' => null,
        ]);
    }

    private function people(array $ids, array $labor): array
    {
        $ids = array_values(array_unique(array_filter($ids, fn ($id) => $id > 0)));
        sort($ids);
        $users = DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)->get(['legacy_id', 'username', 'nickname'])->keyBy('legacy_id');
        return array_map(function ($id) use ($users, $labor): array {
            $person = $users->get($id);
            return ['employee_legacy_id' => $id, 'employee_name' => $person ? ($person->nickname ?: $person->username) : '账号 #'.$id,
                'identity_exists' => $person !== null, 'actual_labor_minutes' => (string) ($labor[$id]->actual_labor_minutes ?? '0')];
        }, $ids);
    }

    private function projection(string $type, object $target, int $owner, array $participants, array $extra): array
    {
        return $extra + ['scope_type' => $type, 'scope_id' => (int) $target->id, 'owner_legacy_id' => $owner,
            'scope_version' => (int) $target->business_version, 'status' => (string) $target->status,
            'operation_code' => (string) ($target->operation_code_snapshot ?? ''), 'operation_name' => (string) ($target->operation_name_snapshot ?? ''),
            'stage_code' => (string) ($target->stage_code_snapshot ?? ''), 'stage_name' => (string) ($target->stage_name_snapshot ?? ''),
            'performance_rate_snapshot' => isset($target->performance_rate_snapshot) ? (string) $target->performance_rate_snapshot : null,
            'routing_operation_id_snapshot' => (int) ($target->routing_operation_id_snapshot ?? $target->routing_operation_id ?? 0),
            'completed_at' => $target->completed_at ?? null, 'participants' => $participants];
    }

    private function fail(string $code, string $message, int $status = 422): never
    {
        throw new WorkOrderDomainException($code, $message, $status);
    }
}
