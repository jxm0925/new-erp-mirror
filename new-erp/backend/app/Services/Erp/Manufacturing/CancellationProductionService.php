<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\{SalesOrder, MfgWorkOrder, MfgWorkOrderLink, MfgLaborSession, MfgWorkOrderUnit, MfgWorkOrderMaterial, MfgMaterialReturn, CancellationProductionDisposition as Disposition};
use Illuminate\Support\Facades\DB;

final class CancellationProductionService
{
    public static function pending(int $workOrderId): ?Disposition
    {
        return Disposition::where('work_order_id', $workOrderId)->whereNull('resolved_at')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
    }

    public static function assertProductionAllowed(int $workOrderId): void
    {
        abort_if(self::pending($workOrderId), 422, '原销售订单已取消，工单待生产主管处置，不能新增加工、领料或入库；请先转备货或办理退料、拆回、报废。');
    }

    public function begin(SalesOrder $order, string $reason, object $actor): array
    {
        $service = app(WorkOrderService::class);
        $ids = MfgWorkOrder::where('sales_order_id', $order->id)->orderBy('id')->lockForUpdate()->pluck('id')->all();
        $pending = $ids;
        while ($pending) {
            $children = MfgWorkOrderLink::whereIn('parent_work_order_id', $pending)->lockForUpdate()->pluck('child_work_order_id')->all();
            $pending = array_values(array_diff($children, $ids)); $ids = array_values(array_unique([...$ids, ...$pending]));
        }
        $result = ['cancelled' => [], 'kept' => []];
        // Descendants first: unused parents may then be cancelled without orphaning live children.
        foreach (MfgWorkOrder::whereIn('id', $ids)->orderByDesc('id')->lockForUpdate()->get() as $wo) {
            // A prior supervisor decision must survive retries and historical reconciliation.
            if ($wo->source_type === 'stock' && !$wo->sales_order_id) continue;
            if (in_array($wo->status, ['closed', 'cancelled'], true) || ($wo->status === 'completed' && (float) $wo->stored_qty >= (float) $wo->planned_qty)) continue;
            if (self::pending((int) $wo->id)) { $result['kept'][] = $wo->work_order_no; continue; }
            if (MfgWorkOrderLink::where('child_work_order_id', $wo->id)->whereNotIn('parent_work_order_id', $ids)->exists()) continue;
            $any = fn ($q) => $q->lockForUpdate()->first(['id']) !== null;
            $hasInputs = $any(MfgWorkOrderMaterial::where('work_order_id', $wo->id)->where('issued_qty', '>', 0))
                || $any(MfgLaborSession::where('work_order_id', $wo->id))
                || $any(\App\Models\Erp\MfgUnitReport::where('work_order_id', $wo->id))
                || $any(DB::table('erp_mfg_material_deliveries')->where('work_order_id', $wo->id)->whereIn('status', ['dispatched', 'rejected']));
            foreach (['erp_mfg_cutting_batches', 'erp_mfg_cutting_allocations', 'erp_mfg_cutting_run_allocations'] as $table) $hasInputs = $hasInputs || $any(DB::table($table)->where('work_order_id', $wo->id));
            $hasChildPending = $any(MfgWorkOrder::whereIn('id', MfgWorkOrderLink::where('parent_work_order_id', $wo->id)->select('child_work_order_id'))->where('status', '!=', 'cancelled'));
            if (!$wo->started_at && !$hasInputs && !$hasChildPending && in_array($wo->status, ['planned', 'released', 'paused'], true)) {
                $service->cancel($wo->id, $wo->business_version, $reason, $actor); $result['cancelled'][] = $wo->work_order_no; continue;
            }
            foreach (MfgLaborSession::where('work_order_id', $wo->id)->where('status', 'active')->lockForUpdate()->get() as $session) TaskService::closeSession($session, 'paused');
            $before = $wo->status;
            $wo->update(['status' => 'paused', 'pause_reason' => $reason.'；待生产主管处置', 'paused_at' => now(), 'business_version' => $wo->business_version + 1]);
            DB::table('erp_mfg_material_deliveries')->where('work_order_id', $wo->id)->where('status', 'prepared')->update([
                'status' => 'cancelled', 'closed_by_name' => $actor->nickname ?: $actor->username, 'closed_at' => now(), 'close_remark' => '订单取消，停止未发出的配送']);
            $due = now(); $days = 0;
            while ($days < 3) { $due->addDay(); if (!$due->isWeekend() && !in_array($due->toDateString(), config('erp_cancellation.non_working_dates', []), true)) $days++; }
            Disposition::firstOrCreate(['sales_order_id' => $order->id, 'work_order_id' => $wo->id], [
                'due_at' => $due, 'deadline_at' => now()->addDays(14), 'reason' => $reason, 'history' => [['action' => 'paused', 'at' => now()->toISOString(), 'operator' => $actor->nickname ?: $actor->username]]]);
            $service->log($wo, 'cancellation_pending', $before, 'paused', $reason.'；自动暂停，3个工作日内由生产主管处置', $actor, ['sales_order_id' => $order->id]);
            $result['kept'][] = $wo->work_order_no;
        }
        return $result;
    }

    public function decide(int $id, array $data, object $actor): Disposition
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $identity = Disposition::findOrFail($id);
            SalesOrder::whereKey($identity->sales_order_id)->lockForUpdate()->firstOrFail();
            $wo = MfgWorkOrder::whereKey($identity->work_order_id)->lockForUpdate()->firstOrFail();
            $row = Disposition::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($row->resolved_at || $row->business_version !== (int) $data['expected_version'], 409, '该工单处置已变化，请刷新。');
            $action = $data['action']; $reason = trim($data['reason']); $service = app(WorkOrderService::class);
            $changes = ['reason' => $reason, 'business_version' => $row->business_version + 1,
                'history' => [...$row->history, ['action' => $action, 'reason' => $reason, 'operator' => $actor->nickname ?: $actor->username, 'at' => now()->toISOString()]]];
            if ($action === 'stock') {
                abort_if($row->status === 'terminating', 422, '已进入终止生产处置，不能直接转备货。');
                $service->convertToStock($wo, '主管确认转备货：'.$reason, $actor);
                // Existing parent links remain as history; stock work no longer supplies that parent's demand.
                $changes += ['status' => 'stock', 'resolved_at' => now()];
            } elseif ($action === 'postpone') {
                $due = \Carbon\Carbon::parse($data['due_at'] ?? '')->endOfDay();
                abort_if($due->lte(now()) || $due->gt($row->deadline_at), 422, '延期必须在订单取消后两周以内；超过两周须选择转备货或终止生产。');
                $changes += ['status' => 'postponed', 'due_at' => $due];
            } elseif ($action === 'terminate') {
                $changes += ['status' => 'terminating'];
            } elseif ($action === 'scrap') {
                abort_unless($row->status === 'terminating', 422, '请先选择终止生产。');
                $auth = app(\App\Services\Erp\AuthContextService::class);
                abort_unless($auth->isSuperAdmin($actor) || in_array('production.scrap.approve', $auth->permissionCodes($actor), true), 403, '确认报废需要生产报废审核权限。');
                app(TaskService::class)->scrapForCancellation($wo, $data['unit_ids'], $reason, $data['media'] ?? [], $actor);
            } elseif ($action === 'finish_termination') {
                abort_unless($row->status === 'terminating', 422, '请先选择终止生产，再办理材料和在制品处置。');
                abort_if(Disposition::whereIn('work_order_id', MfgWorkOrderLink::where('parent_work_order_id', $wo->id)->select('child_work_order_id'))->whereNull('resolved_at')->exists(), 422, '请先处理关联半成品子工单。');
                abort_if(MfgMaterialReturn::where('work_order_id', $wo->id)->where('status', 'pending')->exists()
                    || DB::table('erp_mfg_material_deliveries')->where('work_order_id', $wo->id)->whereIn('status', ['dispatched', 'rejected'])->exists()
                    || DB::table('erp_mfg_receipts')->where('work_order_id', $wo->id)->where('status', 'pending')->exists(), 422, '仍有待审核退料、在途配送或入库申请，请先办结。');
                abort_if(\App\Models\Erp\MfgUnitReport::where('work_order_id', $wo->id)->where('review_status', 'pending')->exists(), 422, '仍有待审核的设备报废申请。');
                $states = MfgWorkOrderUnit::where('work_order_id', $wo->id)->lockForUpdate()->pluck('status')->countBy()->all();
                $issued = MfgWorkOrderMaterial::where('work_order_id', $wo->id)->where('issued_qty', '>', 0)->exists();
                $cutInput = DB::table('erp_mfg_cutting_batches')->where('work_order_id', $wo->id)->exists()
                    || DB::table('erp_mfg_cutting_allocations')->where('work_order_id', $wo->id)->exists()
                    || DB::table('erp_mfg_cutting_run_allocations')->where('work_order_id', $wo->id)->exists();
                abort_if(!$issued && \App\Models\Erp\MfgUnitComponent::installed()->where('work_order_id', $wo->id)->exists(), 422, '仍有装配部件未拆回，请核对实物去向。');
                if ($issued || $cutInput) {
                    abort_if(array_diff(array_keys($states), ['scrapped', 'cancelled', 'stored']), 422, '已投入材料须先拆回，或将无法恢复的在制设备报废。');
                    abort_if($service->scrapSettlementPending($wo, $states), 422, '请先确认报废设备已处理，保留拆回及损失成本事实。');
                }
                abort_if(DB::table('erp_mfg_cutting_batches')->where('work_order_id', $wo->id)->whereNotIn('status', ['settled', 'cancelled'])->exists(), 422, '开料投入与余料尚未办结。');
                MfgWorkOrderUnit::where('work_order_id', $wo->id)->whereNotIn('status', ['scrapped', 'stored', 'cancelled'])->update(['status' => 'cancelled']);
                $wo->update(['status' => 'cancelled', 'cancel_reason' => $reason, 'cancelled_at' => now(), 'business_version' => $wo->business_version + 1]);
                $netCost = (string) DB::table('erp_mfg_material_issues')->where('work_order_id', $wo->id)->sum('cost_amount');
                foreach (['erp_mfg_cutting_allocations' => 'cost_amount', 'erp_mfg_cutting_run_allocations' => 'cost_amount'] as $table => $column) $netCost = bcadd($netCost, (string) DB::table($table)->where('work_order_id', $wo->id)->sum($column), 4);
                $netCost = bcadd($netCost, (string) DB::table('erp_mfg_cutting_batches')->where('work_order_id', $wo->id)->where('status', 'settled')->sum('net_cost_amount'), 4);
                $netCost = bcsub($netCost, (string) MfgMaterialReturn::where('work_order_id', $wo->id)->where('status', 'approved')->sum('cost_amount'), 4);
                $changes['history'][count($changes['history']) - 1] += ['unit_states' => $states, 'net_material_cost_snapshot' => $netCost,
                    'stored_output_cost_snapshot' => (string) DB::table('erp_mfg_receipts')->where('work_order_id', $wo->id)->where('status', 'confirmed')->sum('cost_amount')];
                $changes += ['status' => 'cancelled', 'resolved_at' => now()];
            }
            $row->update($changes);
            if ($action === 'stock' && $wo->status === 'paused') {
                if (!$wo->released_at) $wo->update(['status' => 'planned', 'pause_reason' => null, 'paused_at' => null, 'business_version' => $wo->business_version + 1]);
                else $service->resume($wo->id, $wo->business_version, $actor);
            }
            $service->log($wo, 'cancellation_disposition', $wo->status, $wo->status, '订单取消处置 '.$action.'：'.$reason, $actor, ['disposition_id' => $row->id]);
            return $row->fresh();
        }, 5);
    }
}
