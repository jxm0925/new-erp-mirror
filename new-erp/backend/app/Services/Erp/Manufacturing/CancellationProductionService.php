<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\{SalesOrder, MfgWorkOrder, MfgWorkOrderLink, MfgLaborSession, MfgTaskParticipant, MfgWorkOrderOperation, MfgWorkOrderUnit, MfgWorkOrderMaterial, MfgMaterialReturn, CancellationProductionDisposition as Disposition};
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;

final class CancellationProductionService
{
    public static function pending(int $workOrderId): ?Disposition
    {
        return Disposition::where('work_order_id', $workOrderId)->whereNull('resolved_at')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
    }

    public static function dispositionForUnit(int $unitId): ?Disposition
    {
        return Disposition::where('work_order_unit_id', $unitId)->orderByDesc('id')->when(DB::transactionLevel() > 0, fn ($q) => $q->lockForUpdate())->first();
    }

    public static function blockedUnitIds(int $workOrderId): array
    {
        $rows = Disposition::where('work_order_id', $workOrderId)->whereNull('resolved_at')->get(['work_order_unit_id']);
        if ($rows->contains(fn ($row) => !$row->work_order_unit_id)) return MfgWorkOrderUnit::where('work_order_id', $workOrderId)->pluck('id')->map(fn ($id) => (int) $id)->all();
        return $rows->pluck('work_order_unit_id')->filter()->map(fn ($id) => (int) $id)->all();
    }

    public static function productionUnitIds(int $workOrderId): array
    {
        return MfgWorkOrderUnit::where('work_order_id', $workOrderId)->whereNotIn('status', ['scrapped', 'cancelled'])
            ->whereNotIn('id', self::blockedUnitIds($workOrderId))->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function salesDemandUnitIds(int $workOrderId): array
    {
        return MfgWorkOrderUnit::where('work_order_id', $workOrderId)->whereNotIn('status', ['scrapped', 'cancelled'])
            ->whereNotIn('id', Disposition::where('work_order_id', $workOrderId)->whereNotNull('work_order_unit_id')->select('work_order_unit_id'))
            ->pluck('id')->map(fn ($id) => (int) $id)->all();
    }

    public static function reservedSerialIds(int $workOrderId): array
    {
        return Disposition::where('work_order_id', $workOrderId)->whereNull('resolved_at')->get(['material_allocation_snapshot'])
            ->flatMap(fn ($row) => collect(data_get($row->material_allocation_snapshot, 'sources', []))->pluck('serial_ids')->flatten())
            ->filter()->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public static function assertProductionAllowed(int $workOrderId, ?array $unitIds = null): void
    {
        $blocked = self::blockedUnitIds($workOrderId);
        if ($unitIds !== null) {
            $ids = array_values(array_unique(array_map('intval', $unitIds)));
            abort_if(array_intersect($ids, $blocked), 422, '所选编号正在办理原订单变更处置，请先由该台的接单人选择去向。');
            return;
        }
        abort_if($blocked && !self::productionUnitIds($workOrderId), 422, '本工单尚可生产的编号都在办理订单变更处置，请先选择去向。');
    }

    /** 只遍历失效行的独占供给链；外部仍有需求的共享子单及其后代继续保留。 */
    public static function affectedScope(SalesOrder $order, ?array $lineIds = null, bool $lock = false, ?array $rootIds = null): array
    {
        $roots = MfgWorkOrder::where('sales_order_id', $order->id)->where('source_type', 'sales_order')
            ->whereNotIn('status', ['closed', 'cancelled'])
            ->when($lineIds !== null, fn ($q) => $q->whereIn('sales_order_line_id', $lineIds))
            ->when($rootIds !== null, fn ($q) => $q->whereIn('id', $rootIds))
            ->when($lock, fn ($q) => $q->lockForUpdate())->orderBy('id')->get(['id', 'sales_order_line_id']);
        $lineMap = $roots->mapWithKeys(fn ($wo) => [(int) $wo->id => (int) $wo->sales_order_line_id])->all();
        $ids = array_keys($lineMap);
        $pending = $ids;
        while ($pending) {
            $links = MfgWorkOrderLink::whereIn('parent_work_order_id', $pending)->where('qty', '>', 0)
                ->whereIn('child_work_order_id', MfgWorkOrder::where('source_type', '!=', 'stock')->where('status', '!=', 'cancelled')->select('id'))
                ->when($lock, fn ($q) => $q->lockForUpdate())->get();
            $children = $links->pluck('child_work_order_id')->map(fn ($id) => (int) $id)->all();
            foreach ($links as $link) $lineMap[(int) $link->child_work_order_id] ??= $lineMap[(int) $link->parent_work_order_id] ?? null;
            $pending = array_values(array_diff($children, $ids)); $ids = array_values(array_unique([...$ids, ...$pending]));
        }
        $reviewIds = $ids;
        do {
            $shared = MfgWorkOrderLink::whereIn('child_work_order_id', $ids)->where('qty', '>', 0)->whereNotIn('parent_work_order_id', $ids)
                ->whereIn('parent_work_order_id', MfgWorkOrder::where('status', '!=', 'cancelled')->select('id'))
                ->when($lock, fn ($q) => $q->lockForUpdate())->pluck('child_work_order_id')->map(fn ($id) => (int) $id)->all();
            $before = $ids;
            $ids = array_values(array_diff($ids, $shared));
        } while ($ids !== $before);
        if ($lock) MfgWorkOrder::whereIn('id', $reviewIds)->orderBy('id')->lockForUpdate()->get(['id']);
        return ['ids' => $ids, 'review_ids' => $reviewIds, 'line_ids' => array_intersect_key($lineMap, array_flip($ids))];
    }

    /** 共享投入尚无本次份额与区间事实时，保留原需求，禁止遗漏工时或冒用整单投入。 */
    public static function assertSharedInputsAllocated(array $scope): void
    {
        $shared = array_values(array_diff($scope['review_ids'], $scope['ids']));
        if (!$shared) return;
        $hasInputs = OrderChangeLaborService::hasActualWork($shared);
        foreach ($shared as $id) $hasInputs = $hasInputs || self::hasMaterialFacts((int) $id);
        abort_if($hasInputs, 422, '关联半成品由多个主工单共用且已有工时或材料投入，尚未明确本次变更的具体数量、材料与工时份额，不能直接变更或取消；原订单与生产保持不变，请先明确共享投入的差额处置。');
    }

    public static function hasMaterialFacts(int $workOrderId): bool
    {
        return MfgWorkOrderMaterial::where('work_order_id', $workOrderId)->where('issued_qty', '>', 0)->exists()
            || MfgMaterialReturn::where('work_order_id', $workOrderId)->where('status', 'pending')->exists()
            || DB::table('erp_mfg_material_deliveries')->where('work_order_id', $workOrderId)->whereIn('status', ['dispatched', 'rejected'])->exists()
            || DB::table('erp_mfg_receipts')->where('work_order_id', $workOrderId)->where('status', 'pending')->exists()
            || \App\Models\Erp\MfgUnitReport::where('work_order_id', $workOrderId)->where('review_status', 'pending')->exists()
            || \App\Models\Erp\MfgUnitComponent::installed()->where('work_order_id', $workOrderId)->exists()
            || self::hasCuttingInput($workOrderId);
    }

    /** 历史链接保留，但只把产出交给仍有效的父单需求。 */
    public static function activeParentLinks(int $childId, bool $lock = false)
    {
        return MfgWorkOrderLink::where('child_work_order_id', $childId)->where('qty', '>', 0)
            ->whereIn('parent_work_order_id', MfgWorkOrder::whereIn('status', ['planned', 'released', 'in_progress', 'paused'])
                ->where(fn ($q) => $q->where('source_type', '!=', 'sales_order')->orWhere(fn ($sales) => $sales
                    ->whereIn('sales_fulfillment_id', \App\Models\Erp\SalesOrderFulfillment::where('demand_status', 'confirmed')->select('id'))
                    ->whereIn('sales_order_id', SalesOrder::where('order_status', 'confirmed')->whereNull('short_closed_at')->select('id'))))->select('id'))
            ->orderBy('id')->when($lock, fn ($q) => $q->lockForUpdate())->get()->filter(fn ($link) =>
                !MfgWorkOrderUnit::where('work_order_id', $link->parent_work_order_id)->exists()
                || count(self::salesDemandUnitIds((int) $link->parent_work_order_id)) > 0)->values();
    }

    private static function hasCuttingInput(int $workOrderId): bool
    {
        foreach (['erp_mfg_cutting_batches', 'erp_mfg_cutting_allocations', 'erp_mfg_cutting_run_allocations'] as $table) {
            if (DB::table($table)->where('work_order_id', $workOrderId)->exists()) return true;
        }
        return false;
    }

    public static function deciders(int $workOrderId): array
    {
        $owners = MfgTaskParticipant::where('work_order_id', $workOrderId)->where('role', 'owner')->where('is_active', true)
            ->get(['employee_legacy_id', 'employee_name'])->map(fn ($p) => ['id' => (int) $p->employee_legacy_id, 'name' => $p->employee_name])->all();
        $cutIds = app(OrderChangeLaborService::class)->cuttingWorkers([$workOrderId]);
        foreach (DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $cutIds)->get(['legacy_id', 'nickname', 'username']) as $worker) {
            $owners[] = ['id' => (int) $worker->legacy_id, 'name' => $worker->nickname ?: $worker->username];
        }
        return collect($owners)->unique('id')->values()->all();
    }

    public static function canDecide(Disposition $row, object $actor): bool
    {
        $ids = data_get($row->history, '0.permitted_decider_ids');
        if ($ids === null) $ids = array_column(self::deciders((int) $row->work_order_id), 'id');
        return in_array((int) ($actor->legacy_id ?? $actor->id ?? 0), array_map('intval', $ids), true);
    }

    public function begin(SalesOrder $order, string $reason, ?object $actor, ?array $lineIds = null, ?int $changeId = null,
        ?CarbonInterface $effectiveAt = null, ?array $productionSelections = null, ?array $unnumberedWorkOrderIds = null): array
    {
        $scope = self::affectedScope($order, $lineIds, true);
        $at = $effectiveAt ?: now()->startOfSecond();
        $operator = ($actor->nickname ?? null) ?: ($actor->username ?? null) ?: '系统';
        $labor = app(OrderChangeLaborService::class); $service = app(WorkOrderService::class);
        $selections = $productionSelections;
        if ($selections === null) {
            $selections = [];
            foreach (MfgWorkOrder::whereIn('id', $scope['ids'])->orderBy('id')->get() as $wo) {
                $selections[] = ['line_id' => $scope['line_ids'][(int) $wo->id] ?? null, 'work_order_id' => (int) $wo->id,
                    'parent_unit_id' => null, 'unit_ids' => self::salesDemandUnitIds((int) $wo->id)];
            }
        }
        $unitIds = collect($selections)->pluck('unit_ids')->flatten()->map(fn ($id) => (int) $id)->unique()->values()->all();
        $workIds = array_values(array_unique(array_map('intval', array_column($selections, 'work_order_id'))));
        $hasWork = [];
        foreach (MfgWorkOrderUnit::whereIn('id', $unitIds)->orderBy('id')->lockForUpdate()->get() as $unit)
            $hasWork[(int) $unit->id] = $labor->hasActualWork([(int) $unit->work_order_id], [(int) $unit->id]);
        $labor->freezeAt($workIds, $at, $unitIds);
        foreach ($workIds as $workId) MaterialDeliveryService::excludeUnitsFromPrepared($workId,
            MfgWorkOrderUnit::where('work_order_id', $workId)->whereIn('id', $unitIds)->pluck('id')->map(fn ($id) => (int) $id)->all(), $actor);
        $result = ['cancelled' => [], 'kept' => []]; $touched = [];
        foreach ($selections as $selection) {
            $wo = MfgWorkOrder::whereKey($selection['work_order_id'])->lockForUpdate()->firstOrFail();
            abort_unless(in_array((int) $wo->id, $scope['review_ids'], true), 422, '所选工单不属于本次订单变更范围。');
            $units = MfgWorkOrderUnit::where('work_order_id', $wo->id)->whereIn('id', $selection['unit_ids'])->orderBy('id')->lockForUpdate()->get();
            abort_unless($units->count() === count(array_unique($selection['unit_ids'])), 422, '所选编号已不属于该工单，请重新预览。');
            foreach ($units as $unit) {
                if (self::dispositionForUnit((int) $unit->id)) continue;
                $deciders = self::deciders((int) $wo->id);
                if (!$deciders && empty($hasWork[(int) $unit->id])) {
                    $people = DB::table('erp_mfg_material_issues')->where('work_order_id', $wo->id)->pluck('operator_legacy_id')->filter()->all();
                    foreach (DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $people)->get(['legacy_id', 'nickname', 'username']) as $person)
                        $deciders[] = ['id' => (int) $person->legacy_id, 'name' => $person->nickname ?: $person->username];
                }
                $due = $at->copy(); $days = 0;
                while ($days < 3) { $due->addDay(); if (!$due->isWeekend() && !in_array($due->toDateString(), config('erp_cancellation.non_working_dates', []), true)) $days++; }
                $stored = $unit->status === 'stored';
                $row = Disposition::create(['sales_order_id' => $order->id, 'sales_order_line_id' => $selection['line_id'] ?: null,
                    'sales_order_change_id' => $changeId, 'work_order_id' => $wo->id, 'work_order_unit_id' => $unit->id,
                    'parent_unit_id' => $selection['parent_unit_id'] ?? null, 'effective_at' => $at,
                    'status' => $stored ? 'stored' : (!empty($hasWork[(int) $unit->id]) ? 'pending' : 'terminating'),
                    'due_at' => $due, 'deadline_at' => $at->copy()->addDays(14), 'resolved_at' => $stored ? $at : null,
                    'reason' => $reason, 'history' => [['action' => $stored ? 'stored' : 'selected', 'at' => $at->toISOString(),
                        'operator' => $operator, 'unit_id' => (int) $unit->id, 'serial_no' => $unit->serial_no,
                        'has_actual_work' => !empty($hasWork[(int) $unit->id]), 'permitted_decider_ids' => array_column($deciders, 'id'), 'deciders' => $deciders]]]);
                // 已入库子件只保留生产来源；装在父台上的编号由父台正式退料拆回，不能再记一次库存。
                if (!$stored) $labor->freezeMaterialsForDisposition($row, $wo, $actor);
                if (!$stored && empty($hasWork[(int) $unit->id]) && !$labor->hasOutstandingMaterials($row)
                    && !$this->unitHasOpenDocuments($row, $unit)
                    && !\App\Models\Erp\MfgUnitComponent::installed()->where('unit_id', $unit->id)->exists()) {
                    $this->completeCancellation($row, $wo, $unit, $reason, $actor, $row->history);
                    $result['cancelled'][] = $unit->serial_no;
                } elseif (!$stored) $result['kept'][] = $unit->serial_no;
                $service->log($wo, 'cancellation_pending', $wo->status, $wo->status,
                    $unit->serial_no.'：'.$reason.($stored ? '；已入库事实保留' : '；仅此编号办理处置，其余编号继续'), $actor,
                    ['sales_order_id' => $order->id, 'sales_order_change_id' => $changeId, 'disposition_id' => $row->id], (int) $unit->id);
                $touched[(int) $wo->id] = $wo;
            }
        }
        // 尚未下达、没有任何编号的计划仍能取消，不为了处置虚构一台设备。
        $unnumberedIds = $productionSelections === null ? $scope['ids'] : ($unnumberedWorkOrderIds ?? []);
        foreach (MfgWorkOrder::whereIn('id', array_intersect($scope['ids'], $unnumberedIds))->where('status', 'planned')->lockForUpdate()->get() as $wo) {
            if (!MfgWorkOrderUnit::where('work_order_id', $wo->id)->exists() && !self::hasMaterialFacts((int) $wo->id)
                && !$labor->hasActualWork([(int) $wo->id])) {
                $service->cancelUnusedForOrderChange($wo, $reason, $actor); $result['cancelled'][] = $wo->work_order_no;
            }
        }
        foreach ($touched as $wo) {
            $wo->refresh(); $wo->update(['business_version' => $wo->business_version + 1]);
            $this->refreshWorkOrder($wo, $actor);
        }
        if ($touched) TaskService::syncWorkStatus(array_keys($touched));
        return $result;
    }

    public function finishUnusedAfterReturn(MfgWorkOrder $wo, object $actor): void
    {
        foreach (Disposition::where('work_order_id', $wo->id)->whereNull('resolved_at')->where('status', 'terminating')->lockForUpdate()->get() as $row) {
            $unit = $row->work_order_unit_id ? MfgWorkOrderUnit::whereKey($row->work_order_unit_id)->lockForUpdate()->first() : null;
            if (!$unit || data_get($row->history, '0.has_actual_work', true) || app(OrderChangeLaborService::class)->hasOutstandingMaterials($row)
                || $this->unitHasOpenDocuments($row, $unit) || \App\Models\Erp\MfgUnitComponent::installed()->where('unit_id', $unit->id)->exists()) continue;
            $this->completeCancellation($row, $wo, $unit, $row->reason, $actor, [...$row->history,
                ['action' => 'returned_and_cancelled', 'at' => now()->toISOString(), 'operator_legacy_id' => (int) ($actor->legacy_id ?? $actor->id ?? 0)]]);
        }
        $this->refreshWorkOrder($wo->refresh(), $actor);
    }

    public function decide(int $id, array $data, object $actor): Disposition
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $identity = Disposition::findOrFail($id);
            SalesOrder::whereKey($identity->sales_order_id)->lockForUpdate()->firstOrFail();
            $wo = MfgWorkOrder::whereKey($identity->work_order_id)->lockForUpdate()->firstOrFail();
            $unit = $identity->work_order_unit_id ? MfgWorkOrderUnit::whereKey($identity->work_order_unit_id)->lockForUpdate()->firstOrFail() : null;
            $row = Disposition::whereKey($id)->lockForUpdate()->firstOrFail();
            abort_if($row->resolved_at || $row->business_version !== (int) $data['expected_version'], 409, '该编号处置已变化，请刷新。');
            abort_unless(self::canDecide($row, $actor), 403, '只有这张工单的实际接单人可以决定该编号的去向。');
            abort_unless($unit, 422, '这条旧记录没有设备编号，请先核对原实物记录，不能把整张工单代替某一台处置。');
            $action = $data['action']; $reason = trim($data['reason']);
            $history = [...$row->history, ['action' => $action, 'reason' => $reason, 'operator' => $actor->nickname ?: $actor->username,
                'operator_legacy_id' => (int) ($actor->legacy_id ?? $actor->id ?? 0), 'unit_id' => (int) $unit->id, 'at' => now()->toISOString()]];
            $changes = ['reason' => $reason, 'history' => $history, 'business_version' => $row->business_version + 1];
            if ($action === 'stock') {
                abort_if($row->status === 'terminating', 422, '该编号已进入取消、拆解或报废处置，请先完成实物事项。');
                app(OrderChangeLaborService::class)->settleMaterialDisposition($row, 'stock', $actor);
                app(DispositionLaborService::class)->closeForDisposition((int) $row->id);
                $changes += ['status' => 'stock', 'resolved_at' => now()];
                if ($unit->current_operation_id) MfgWorkOrderOperation::whereKey($unit->current_operation_id)->where('status', 'done')->update(['status' => 'in_progress']);
            } elseif ($action === 'postpone') {
                abort_if($row->status === 'terminating', 422, '该编号正在办理实物处置，不能改为继续暂停。');
                $due = \Carbon\Carbon::parse($data['due_at'] ?? '')->endOfDay();
                abort_if($due->lte(now()) || $due->gt($row->deadline_at), 422, '下次处理日期须在本次变更生效后两周以内。');
                $changes += ['status' => 'postponed', 'due_at' => $due];
            } elseif (in_array($action, ['terminate', 'cancel', 'dismantle'], true)) {
                $changes += ['status' => 'terminating'];
                if ($action === 'cancel' && !app(OrderChangeLaborService::class)->hasOutstandingMaterials($row)
                    && !$this->unitHasOpenDocuments($row, $unit)
                    && !\App\Models\Erp\MfgUnitComponent::installed()->where('unit_id', $unit->id)->exists()) {
                    $this->completeCancellation($row, $wo, $unit, $reason, $actor, $history);
                    $this->refreshWorkOrder($wo->refresh(), $actor);
                    return $row->fresh();
                }
            } elseif ($action === 'scrap') {
                $ids = array_values(array_unique(array_map('intval', $data['unit_ids'] ?? [$unit->id])));
                abort_unless($ids === [(int) $unit->id], 422, '本次只处理这条处置记录所指的一个编号。');
                $changes += ['status' => 'terminating'];
                app(TaskService::class)->scrapForCancellation($wo, $ids, $reason, $data['media'] ?? [], $actor);
            } elseif ($action === 'finish_termination') {
                abort_unless($row->status === 'terminating', 422, '请先选择该编号的取消、拆解退料或报废去向。');
                abort_if($this->unitHasOpenDocuments($row, $unit), 422, '这个编号还有待审核退料、报废或入库申请，请先办结。');
                abort_if(\App\Models\Erp\MfgUnitComponent::installed()->where('unit_id', $unit->id)->exists(), 422, '这个编号仍有部件未拆回，请核对实物去向。');
                abort_if(app(OrderChangeLaborService::class)->hasOutstandingMaterials($row) && !in_array($unit->status, ['scrapped', 'stored'], true),
                    422, '这个编号分得的材料尚未退清，请先实际拆回，或将无法回收的在制品按正式申请报废。');
                $this->completeCancellation($row, $wo, $unit, $reason, $actor, $history);
                $this->refreshWorkOrder($wo->refresh(), $actor);
                return $row->fresh();
            }
            $row->update($changes);
            $this->refreshWorkOrder($wo->refresh(), $actor);
            TaskService::syncWorkStatus([(int) $wo->id]);
            app(WorkOrderService::class)->log($wo, 'cancellation_disposition', $wo->status, $wo->status,
                $unit->serial_no.' 处置 '.$action.'：'.$reason, $actor, ['disposition_id' => $row->id], (int) $unit->id);
            return $row->fresh();
        }, 5);
    }

    private function unitHasOpenDocuments(Disposition $row, MfgWorkOrderUnit $unit): bool
    {
        return MfgMaterialReturn::where('disposition_id', $row->id)->where('status', 'pending')->exists()
            || MaterialDeliveryService::hasOpenForUnit((int) $unit->work_order_id, (int) $unit->id)
            || \App\Models\Erp\MfgUnitReport::where('unit_id', $unit->id)->where('review_status', 'pending')->exists()
            || DB::table('erp_mfg_receipts')->where('work_order_id', $unit->work_order_id)->where('status', 'pending')->whereJsonContains('serial_nos', $unit->serial_no)->exists();
    }

    private function completeCancellation(Disposition $row, MfgWorkOrder $wo, MfgWorkOrderUnit $unit, string $reason, ?object $actor, array $history): void
    {
        app(OrderChangeLaborService::class)->settleMaterialDisposition($row, 'cancelled', $actor);
        app(DispositionLaborService::class)->closeForDisposition((int) $row->id);
        if (!in_array($unit->status, ['scrapped', 'stored'], true)) $unit->update(['status' => 'cancelled', 'current_operation_id' => null]);
        $row->update(['status' => 'cancelled', 'resolved_at' => now(), 'reason' => $reason, 'history' => $history, 'business_version' => $row->business_version + 1]);
        app(WorkOrderService::class)->log($wo, 'cancellation_unit_closed', $unit->status, $unit->status,
            $unit->serial_no.' 已办结：'.$reason, $actor, ['disposition_id' => $row->id], (int) $unit->id);
    }

    private function refreshWorkOrder(MfgWorkOrder $wo, ?object $actor): void
    {
        $states = MfgWorkOrderUnit::where('work_order_id', $wo->id)->pluck('status');
        if ($states->isEmpty() || self::pending((int) $wo->id)) return;
        if ($states->every(fn ($status) => in_array($status, ['cancelled', 'scrapped'], true))) {
            $wo->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => '本工单全部编号已分别办结', 'business_version' => $wo->business_version + 1]);
        } else app(WorkOrderService::class)->refreshCompletion($wo, $actor);
    }
}
