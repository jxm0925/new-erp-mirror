<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\WorkOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** Resolve personal-share subjects from genuine execution identities, never from sales names. */
class ProductionPerformanceScopeService
{
    public const TYPES = ['unit_operation', 'quantity_operation', 'shipment_packing_operation'];
    public const RATE_TABLE = 'erp_production_performance_rate_versions';

    public function resolve(string $type, int $id, bool $lock = false): array
    {
        if (! in_array($type, self::TYPES, true)) $this->fail('performance_scope_invalid', '绩效来源工序类型不合法。');
        if ($type === 'shipment_packing_operation') return $this->packing($id, $lock);
        $table = $type === 'unit_operation' ? 'erp_production_unit_operations' : 'erp_production_quantity_operations';
        $query = DB::table($table)->where('id', $id);
        $target = $query->first();
        if (! $target) $this->fail('performance_scope_not_found', '绩效来源工序不存在。', 404);
        $taskQuery = DB::table('erp_production_tasks as task')
            ->join('erp_production_task_targets as link', 'link.task_id', '=', 'task.id')
            ->where('link.target_type', $type)->where('link.target_id', $id);
        if ($lock) $taskQuery->lockForUpdate();
        $task = $taskQuery->first(['task.*']);
        // Execution changes lock the task before its target. Rate/share commands use
        // that same order so a completion cannot deadlock against a ratio revision.
        if ($lock) {
            $target = DB::table($table)->where('id', $id)->lockForUpdate()->first();
            if (! $target) $this->fail('performance_scope_not_found', '绩效来源工序不存在。', 404);
        }
        $owner = (int) ($target->responsible_user_legacy_id ?: ($task->assignee_user_legacy_id ?? 0));
        if ($task && $owner > 0 && (int) $task->assignee_user_legacy_id !== $owner) {
            $this->fail('performance_owner_mismatch', '工序与接单人记录不一致，请先核对真实负责人。', 409);
        }
        $labor = DB::table('erp_production_labor_sessions')->where('target_type', $type)->where('target_id', $id)
            ->selectRaw('employee_legacy_id, COALESCE(SUM(actual_labor_minutes),0) AS actual_labor_minutes')->groupBy('employee_legacy_id')->get();
        $ids = $labor->pluck('employee_legacy_id')->map(fn ($value) => (int) $value)->all();
        if ($task) {
            $until = $target->completed_at ?: now();
            $ids = array_merge($ids, DB::table('erp_production_task_collaborators')->where('task_id', $task->id)
                ->where('joined_at', '<=', $until)->where(fn ($q) => $q->whereNull('left_at')->orWhere('left_at', '>=', $until))
                ->pluck('employee_legacy_id')->map(fn ($value) => (int) $value)->all());
        }
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
        $ids = DB::table('erp_shipment_packing_participants')->where('operation_id', $id)->where('is_active', true)->pluck('employee_legacy_id')
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
        // A rate correction is an independently versioned business fact. Frozen
        // execution/routing values remain the original source for audit and trace.
        $revision = Schema::hasTable(self::RATE_TABLE) ? DB::table(self::RATE_TABLE)->where('active_scope_key', $type.':'.$target->id)->first() : null;
        $originalRate = isset($target->performance_rate_snapshot) ? (string) $target->performance_rate_snapshot : null;
        return $extra + ['scope_type' => $type, 'scope_id' => (int) $target->id, 'owner_legacy_id' => $owner,
            'scope_version' => (int) $target->business_version, 'status' => (string) $target->status,
            'operation_code' => (string) ($target->operation_code_snapshot ?? ''), 'operation_name' => (string) ($target->operation_name_snapshot ?? ''),
            'stage_code' => (string) ($target->stage_code_snapshot ?? ''), 'stage_name' => (string) ($target->stage_name_snapshot ?? ''),
            'performance_rate_snapshot' => $revision ? (string) $revision->performance_rate : $originalRate,
            'original_performance_rate_snapshot' => $originalRate, 'rate_version' => $revision ? (int) $revision->version_no : 0,
            'rate_changed_at' => $revision?->changed_at,
            'routing_operation_id_snapshot' => (int) ($target->routing_operation_id_snapshot ?? $target->routing_operation_id ?? 0),
            'completed_at' => $target->completed_at ?? null, 'participants' => $participants];
    }

    public function canManageRate(array $scope, object $user, array $permissions, bool $superAdmin = false): bool
    {
        if (! $superAdmin && (! in_array('production.performance.manage', $permissions, true)
            || ! in_array('production.performance.view', $permissions, true))) return false;
        $resolver = app(ProductionDataScopeResolver::class);
        $visibility = $resolver->resolve($user, 'production.performance.manage', $permissions, $superAdmin);
        if (($visibility['mode'] ?? 'deny') === 'all') return true;
        if (($visibility['mode'] ?? 'deny') === 'deny') return false;
        if (in_array((int) $scope['owner_legacy_id'], $visibility['user_ids'], true)) return true;
        if (empty($scope['work_order_id'])) return false;
        $workOrder = WorkOrder::find((int) $scope['work_order_id']);
        return $workOrder && $resolver->workOrderVisible($workOrder, $visibility);
    }

    private function fail(string $code, string $message, int $status = 422): never
    {
        throw new WorkOrderDomainException($code, $message, $status);
    }
}
