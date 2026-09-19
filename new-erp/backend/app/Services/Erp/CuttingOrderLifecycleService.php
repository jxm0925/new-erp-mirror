<?php

namespace App\Services\Erp;

use Illuminate\Support\Facades\DB;

/** Finalizes the order aggregate without inventing stock, receipts or downstream consumption. */
final class CuttingOrderLifecycleService
{
    private const TERMINAL_BATCHES = ['CONFIRMED', 'RETURNED', 'REVERSED'];
    private const TERMINAL_ROUTES = ['RECEIVED', 'WAREHOUSED', 'CANCELLED'];

    public function __construct(private readonly CuttingCommandService $commands) {}

    public function close(int $orderId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $permission = 'production.cutting.close';
        $this->commands->order($orderId, $user, $permissions, $super, $permission);
        return $this->commands->run('close_cutting_order', $orderId, $payload, $user,
            function () use ($orderId, $payload, $user, $permissions, $super, $permission): array {
                $order = $this->commands->order($orderId, $user, $permissions, $super, $permission, true);
                $this->commands->version($order, $payload);
                $reason = $this->reason($payload);
                if (! in_array($order->status, ['PUBLISHED', 'IN_PROGRESS'], true)) {
                    $this->commands->fail('cutting_order_not_closable', '只有执行中的下料单可以关闭。', 409);
                }

                $task = DB::table('erp_cutting_tasks')->where('cutting_order_id', $orderId)->lockForUpdate()->first();
                $batchIds = DB::table('erp_cutting_settlement_batches')->where('cutting_order_id', $orderId)
                    ->orderBy('id')->lockForUpdate()->pluck('id');
                $routeIds = DB::table('erp_cutting_result_routes as route')
                    ->join('erp_cutting_results as result', 'result.id', '=', 'route.result_id')
                    ->join('erp_cutting_settlement_batches as batch', 'batch.id', '=', 'result.settlement_batch_id')
                    ->where('batch.cutting_order_id', $orderId)->orderBy('route.id')->lockForUpdate()->pluck('route.id');
                $planIds = DB::table('erp_cutting_plan_allocations')->where('cutting_order_id', $orderId)
                    ->orderBy('id')->lockForUpdate()->pluck('id');
                $blockers = $this->closeBlockers($orderId, $task, $batchIds, $routeIds);
                if ($blockers !== []) {
                    $this->commands->fail('cutting_order_close_blocked', '下料单尚未满足关闭条件。', 409, ['blockers' => $blockers]);
                }

                foreach ($planIds as $planId) {
                    $actual = (string) DB::table('erp_cutting_output_allocations')
                        ->where('plan_id', $planId)->where('status', 'EFFECTIVE')->sum('quantity');
                    DB::table('erp_cutting_plan_allocations')->where('id', $planId)
                        ->update(['closed_planned_qty' => $actual, 'updated_at' => now()]);
                }

                $actor = $this->commands->actor($user); $version = (int) $order->business_version + 1;
                DB::table('erp_cutting_orders')->where('id', $orderId)->update([
                    'status' => 'CLOSED', 'business_version' => $version, 'closed_at' => now(),
                    'closed_by_legacy_id' => $actor, 'close_reason' => $reason, 'updated_at' => now(),
                ]);
                $response = ['cutting_order_id' => $orderId, 'status' => 'CLOSED', 'business_version' => $version,
                    'closed_at' => (string) DB::table('erp_cutting_orders')->where('id', $orderId)->value('closed_at')];
                $this->commands->event('order', $orderId, 'close', $user, $order, $response + ['reason' => $reason]);
                return $response;
            });
    }

    public function cancel(int $orderId, array $payload, object $user, array $permissions, bool $super = false): array
    {
        $permission = 'production.cutting.cancel';
        $this->commands->order($orderId, $user, $permissions, $super, $permission);
        return $this->commands->run('cancel_cutting_order', $orderId, $payload, $user,
            function () use ($orderId, $payload, $user, $permissions, $super, $permission): array {
                $order = $this->commands->order($orderId, $user, $permissions, $super, $permission, true);
                $this->commands->version($order, $payload);
                $reason = $this->reason($payload);
                if (! in_array($order->status, ['PUBLISHED', 'IN_PROGRESS'], true)) {
                    $this->commands->fail('cutting_order_not_cancellable', '只有尚未关闭或取消的下料单可以取消。', 409);
                }

                $task = DB::table('erp_cutting_tasks')->where('cutting_order_id', $orderId)->lockForUpdate()->first();
                $batchIds = DB::table('erp_cutting_settlement_batches')->where('cutting_order_id', $orderId)
                    ->orderBy('id')->lockForUpdate()->pluck('id');
                $reservationIds = DB::table('erp_material_physical_reservations')->where('cutting_order_id', $orderId)
                    ->where('status', 'ACTIVE')->orderBy('id')->lockForUpdate()->pluck('id');
                $blockers = $this->cancelBlockers($orderId, $task, $batchIds);
                if ($blockers !== []) {
                    $this->commands->fail('cutting_order_cancel_blocked', '下料单已经产生不可直接撤销的执行事实。', 409, ['blockers' => $blockers]);
                }

                $physicalIds = DB::table('erp_material_physical_reservations')->whereIn('id', $reservationIds)
                    ->pluck('physical_material_id')->sort()->values();
                $physicals = DB::table('erp_material_physicals')->whereIn('id', $physicalIds)
                    ->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                foreach ($physicalIds as $physicalId) {
                    $physical = $physicals->get($physicalId);
                    if (! $physical || $physical->status !== 'RESERVED') {
                        $this->commands->fail('cutting_order_cancel_reservation_conflict', '占用的材料实物状态已变化，不能取消下料单。', 409);
                    }
                    DB::table('erp_material_physicals')->where('id', $physicalId)->update([
                        'status' => 'AVAILABLE', 'business_version' => (int) $physical->business_version + 1, 'updated_at' => now(),
                    ]);
                    $this->commands->event('physical', (int) $physicalId, 'release_for_order_cancel', $user, $physical,
                        ['cutting_order_id' => $orderId, 'status' => 'AVAILABLE', 'reason' => $reason]);
                }
                if ($reservationIds->isNotEmpty()) DB::table('erp_material_physical_reservations')->whereIn('id', $reservationIds)
                    ->update(['status' => 'RELEASED', 'updated_at' => now()]);

                $now = now();
                DB::table('erp_cutting_task_participants')->where('cutting_task_id', $task->id)->whereNull('left_at')
                    ->update(['left_at' => $now, 'active_participant_key' => null, 'business_version' => DB::raw('business_version + 1'), 'updated_at' => $now]);
                DB::table('erp_cutting_tasks')->where('id', $task->id)->update([
                    'status' => 'CANCELLED', 'paused_at' => null, 'business_version' => (int) $task->business_version + 1, 'updated_at' => $now,
                ]);
                $actor = $this->commands->actor($user); $version = (int) $order->business_version + 1;
                DB::table('erp_cutting_orders')->where('id', $orderId)->update([
                    'status' => 'CANCELLED', 'business_version' => $version, 'cancelled_at' => $now,
                    'cancelled_by_legacy_id' => $actor, 'cancel_reason' => $reason, 'updated_at' => $now,
                ]);
                $response = ['cutting_order_id' => $orderId, 'cutting_task_id' => (int) $task->id,
                    'status' => 'CANCELLED', 'business_version' => $version,
                    'released_physical_material_ids' => $physicalIds->map(fn ($id) => (int) $id)->all()];
                $this->commands->event('order', $orderId, 'cancel', $user, $order, $response + ['reason' => $reason]);
                return $response;
            });
    }

    public function projection(object $order): array
    {
        $orderId = (int) $order->id;
        $task = DB::table('erp_cutting_tasks')->where('cutting_order_id', $orderId)->first();
        $batchIds = DB::table('erp_cutting_settlement_batches')->where('cutting_order_id', $orderId)->pluck('id');
        $routeIds = DB::table('erp_cutting_result_routes as route')
            ->join('erp_cutting_results as result', 'result.id', '=', 'route.result_id')
            ->join('erp_cutting_settlement_batches as batch', 'batch.id', '=', 'result.settlement_batch_id')
            ->where('batch.cutting_order_id', $orderId)->pluck('route.id');
        $close = $this->closeBlockers($orderId, $task, $batchIds, $routeIds);
        $cancel = $this->cancelBlockers($orderId, $task, $batchIds);
        if (! in_array($order->status, ['PUBLISHED', 'IN_PROGRESS'], true)) {
            $close = [['code' => 'order_terminal', 'message' => '下料单已经关闭或取消。']];
            $cancel = $close;
        }
        return ['status' => $order->status, 'can_close' => $close === [], 'can_cancel' => $cancel === [],
            'close_blockers' => $close, 'cancel_blockers' => $cancel];
    }

    private function closeBlockers(int $orderId, ?object $task, object $batchIds, object $routeIds): array
    {
        $blockers = [];
        if (! $task || $task->status !== 'FINISHED') $blockers[] = ['code' => 'task_not_finished', 'message' => '加工任务尚未完成。'];
        $activeLabor = $task ? DB::table('erp_production_labor_sessions')->where('execution_task_type', 'CUTTING_TASK')
            ->where('cutting_task_id', $task->id)->where('status', 'ACTIVE')->count() : 0;
        if ($activeLabor > 0) $blockers[] = ['code' => 'active_labor', 'message' => '仍有进行中的下料计时。', 'count' => $activeLabor];
        if ($batchIds->isEmpty()) $blockers[] = ['code' => 'input_missing', 'message' => '尚无实际用料批次。'];
        $pendingBatches = DB::table('erp_cutting_settlement_batches')->whereIn('id', $batchIds)
            ->whereNotIn('status', self::TERMINAL_BATCHES)->count();
        if ($pendingBatches > 0) $blockers[] = ['code' => 'batch_not_final', 'message' => '仍有用料批次未完成核算或退回。', 'count' => $pendingBatches];
        $openCorrections = DB::table('erp_cutting_corrections as correction')
            ->join('erp_cutting_settlement_batches as batch', 'batch.id', '=', 'correction.correction_settlement_batch_id')
            ->where('batch.cutting_order_id', $orderId)->where('correction.status', 'OPEN')->count();
        if ($openCorrections > 0) $blockers[] = ['code' => 'correction_open', 'message' => '仍有用料更正尚未完成。', 'count' => $openCorrections];
        $pendingRoutes = DB::table('erp_cutting_result_routes')->whereIn('id', $routeIds)
            ->whereNotIn('status', self::TERMINAL_ROUTES)->count();
        if ($pendingRoutes > 0) $blockers[] = ['code' => 'route_not_received', 'message' => '仍有产出尚未完成工序接收或正式入库。', 'count' => $pendingRoutes];
        return $blockers;
    }

    private function cancelBlockers(int $orderId, ?object $task, object $batchIds): array
    {
        $blockers = [];
        if (! $task) return [['code' => 'task_missing', 'message' => '下料任务不存在。']];
        if ($batchIds->isNotEmpty()) $blockers[] = ['code' => 'input_already_issued', 'message' => '已经发生正式领料，不能直接取消。', 'count' => $batchIds->count()];
        $activeLabor = DB::table('erp_production_labor_sessions')->where('execution_task_type', 'CUTTING_TASK')
            ->where('cutting_task_id', $task->id)->where('status', 'ACTIVE')->count();
        if ($activeLabor > 0) $blockers[] = ['code' => 'active_labor', 'message' => '仍有进行中的下料计时。', 'count' => $activeLabor];
        if ($task->started_at || in_array($task->status, ['IN_PROGRESS', 'PAUSED', 'FINISHED'], true)) {
            $blockers[] = ['code' => 'task_already_started', 'message' => '加工任务已经开工，不能直接取消。'];
        }
        return $blockers;
    }

    private function reason(array $payload): string
    {
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($reason === '' || mb_strlen($reason) > 1000) $this->commands->fail('reason_required', '请填写关闭或取消原因。');
        return $reason;
    }
}
