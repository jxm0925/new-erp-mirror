<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Item, ProductionJobBundle, ProductionTask, WorkOrder};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ProductionJobBundleQueryService
{
    public function __construct(private readonly ProductionDataScopeResolver $scopes,
        private readonly ProductionJobBundleCompatibilityService $compatibility, private readonly ErpUserProjectionService $users) {}

    public function canCreate(array $permissions): bool { return in_array('production.work_order.edit', $permissions, true); }

    public function candidates(array $filters, object $user, array $permissions, bool $super = false): array
    {
        $this->permission($permissions, 'production.work_order.edit');
        $this->permission($permissions, 'production.task.view');
        $query = $this->candidateQuery($user, $permissions, $super)->with(['workOrder.outputItem', 'targets']);
        $query->whereNull('active_job_bundle_id')->whereIn('status', ['WAIT_CLAIM', 'CLAIMED', 'READY', 'WAIT_MATERIAL', 'WAIT_HANDOVER']);
        if (! empty($filters['work_order_id'])) $query->where('work_order_id', (int) $filters['work_order_id']);
        if (! empty($filters['operation_id'])) {
            $id = (int) $filters['operation_id'];
            $query->where(fn ($q) => $q->whereHas('productionQuantityOperation', fn ($t) => $t->where('operation_id_snapshot', $id))
                ->orWhereHas('productionUnitOperation', fn ($t) => $t->where('operation_id_snapshot', $id)));
        }
        $this->search($query, $filters['keyword'] ?? '');
        $page = $query->orderByDesc('id')->paginate(min(20, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $page->setCollection($page->getCollection()->map(function ($task) {
            try {
                $facts = $this->compatibility->inspect($task);
                return $this->taskProjection($task, $facts['target_type'], $facts['target']) + ['eligible' => true, 'reasons' => [],
                    'compatibility_key' => $facts['compatibility_key'], 'compatibility_snapshot' => $facts['compatibility_snapshot'],
                    'material_sources' => $facts['materials'], 'standard_weight_minutes' => $facts['standard_weight_minutes']];
            } catch (WorkOrderDomainException $e) {
                return ['task_id' => (int) $task->id, 'task_business_version' => (int) $task->business_version,
                    'task' => ['id' => (int) $task->id, 'no' => $task->task_no],
                    'work_order' => ['id' => (int) $task->work_order_id, 'no' => $task->workOrder?->work_order_no],
                    'eligible' => false, 'reasons' => [['code' => $e->errorCode, 'message' => $e->getMessage()]]];
            }
        }));
        return $page->toArray() + ['can_create' => $this->canCreate($permissions)];
    }

    public function paginate(array $filters, object $user, array $permissions, bool $super = false): array
    {
        $query = $this->visibleQuery($user, $permissions, $super);
        if (($filters['view'] ?? 'all') === 'mine') $query->where('assignee_user_legacy_id', $this->userId($user));
        if (! empty($filters['work_order_id'])) $query->whereHas('lines', fn ($l) => $l->where('work_order_id', (int) $filters['work_order_id']));
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if ($term = trim((string) ($filters['keyword'] ?? ''))) $query->where(fn ($q) => $q->where('bundle_no', 'like', '%'.addcslashes($term, '\\%_').'%')
            ->orWhere('title', 'like', '%'.addcslashes($term, '\\%_').'%')->orWhere('operation_name_snapshot', 'like', '%'.addcslashes($term, '\\%_').'%'));
        $page = $query->orderByDesc('id')->paginate(min(20, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $page->setCollection($page->getCollection()->map(fn ($bundle) => $this->project($bundle, $user, $permissions)));
        return $page->toArray() + ['can_create' => $this->canCreate($permissions)];
    }

    public function show(int $id, object $user, array $permissions, bool $super = false): array
    {
        return $this->project($this->visible($id, $user, $permissions, $super), $user, $permissions);
    }

    public function visible(int $id, object $user, array $permissions, bool $super = false): ProductionJobBundle
    {
        $bundle = $this->visibleQuery($user, $permissions, $super)->find($id);
        if (! $bundle) throw new WorkOrderDomainException('job_bundle_not_found', '共同加工作业不存在或不在当前数据范围。', 404);
        return $bundle;
    }

    public function assertCandidateScope(array $ids, object $user, array $permissions, bool $super): void
    {
        if ($this->candidateQuery($user, $permissions, $super)->whereIn('id', $ids)->count() !== count($ids)) {
            throw new WorkOrderDomainException('bundle_task_scope_denied', '所选任务或工单不在当前安排权限范围。', 403);
        }
    }

    public function project(ProductionJobBundle $bundle, object $user, array $permissions): array
    {
        $bundle->loadMissing(['lines.task.workOrder.outputItem', 'laborSessions']);
        $owner = (int) $bundle->assignee_user_legacy_id === $this->userId($user);
        $has = fn ($code) => in_array($code, $permissions, true);
        $active = $bundle->laborSessions->firstWhere('status', 'ACTIVE');
        $elapsed = $active ? max(0, $active->started_at->diffInSeconds(now()) / 60 - (float) $active->bundle_checkpoint_minutes) : 0;
        $lines = $bundle->lines->map(function ($line) use ($bundle, $owner, $has) {
            [$type, $target] = $this->compatibility->target($line->task);
            $data = $this->taskProjection($line->task, $type, $target);
            $data['item'] = $line->source_snapshot['item'] ?? $data['item'];
            $output = DB::table('erp_production_output_records')->where('source_target_type', $type)->where('source_target_id', $target->id)->first();
            return $data + ['id' => (int) $line->id, 'status' => $line->status, 'source_snapshot' => $line->source_snapshot,
                'material_sources' => $this->compatibility->materials($line->task, $type, (int) $target->id),
                'material_snapshot' => $line->material_snapshot, 'started_material_snapshot' => $line->started_material_snapshot,
                'standard_weight_minutes' => (string) $line->standard_weight_minutes_snapshot,
                'allocated_labor_minutes' => (string) $line->allocated_labor_minutes,
                'output_record' => $output ? ['id' => (int) $output->id, 'no' => $output->output_no, 'status' => $output->status,
                    'business_version' => (int) $output->business_version, 'output_base_qty' => (string) $output->output_base_qty] : null,
                'allowed_actions' => ['report' => $owner && ! $line->execution_completed_at && in_array($bundle->status, ['IN_PROGRESS', 'PAUSED'], true)
                        && $type === 'quantity_operation' && $has('production.report.create'),
                    'complete' => $owner && ! $line->execution_completed_at && in_array($bundle->status, ['IN_PROGRESS', 'PAUSED'], true) && $has('production.task.complete')]];
        })->values()->all();
        $hasOpen = $bundle->lines->contains(fn ($line) => ! $line->execution_completed_at);
        $allReady = collect($lines)->every(fn ($line) => $line['target']['status'] === 'READY'
            && $line['readiness']['ready'] && (! $line['target']['kitting_required'] || $line['target']['kitting_confirmed_at']));
        return ['id' => (int) $bundle->id, 'bundle_no' => $bundle->bundle_no, 'title' => $bundle->title, 'status' => $bundle->status,
            'business_version' => (int) $bundle->business_version, 'operation_id' => (int) $bundle->operation_id_snapshot,
            'operation_code' => $bundle->operation_code_snapshot, 'operation_name' => $bundle->operation_name_snapshot,
            'compatibility_key' => $bundle->compatibility_key, 'compatibility_snapshot' => $bundle->compatibility_snapshot,
            'assignee_user_legacy_id' => $bundle->assignee_user_legacy_id,
            'owner' => $bundle->assignee_user_legacy_id ? $this->users->one((int) $bundle->assignee_user_legacy_id) : null,
            'actual_labor_minutes' => (string) $bundle->actual_labor_minutes,
            'current_actual_labor_minutes' => number_format((float) $bundle->actual_labor_minutes + $elapsed, 2, '.', ''),
            'my_labor' => ['status' => $active && $owner ? 'ACTIVE' : 'INACTIVE', 'session_id' => $active ? (int) $active->id : null,
                'started_at' => optional($active?->started_at)->toISOString(), 'accumulated_seconds' => (int) round(((float) $bundle->actual_labor_minutes + $elapsed) * 60)],
            'started_at' => optional($bundle->started_at)->toISOString(), 'completed_at' => optional($bundle->completed_at)->toISOString(),
            'server_now' => now()->toISOString(), 'lines' => $lines, 'can_create' => $this->canCreate($permissions),
            'allowed_actions' => ['create' => $this->canCreate($permissions),
                'claim' => $bundle->status === 'WAIT_CLAIM' && (! $bundle->assignee_user_legacy_id || $owner) && $has('production.task.claim'),
                'start' => $owner && $bundle->status === 'CLAIMED' && $allReady && $has('production.task.start'),
                'pause' => $owner && $bundle->status === 'IN_PROGRESS' && $active && $has('production.task.pause'),
                'resume' => $owner && $bundle->status === 'PAUSED' && $hasOpen && $has('production.task.resume'),
                'finish' => $owner && in_array($bundle->status, ['IN_PROGRESS', 'PAUSED'], true) && ! $hasOpen && $has('production.task.complete'),
                'cancel' => ! $bundle->started_at && in_array($bundle->status, ['WAIT_CLAIM', 'CLAIMED'], true) && $this->canCreate($permissions)]];
    }

    public function taskProjection(ProductionTask $task, string $type, object $target): array
    {
        $task->loadMissing('workOrder.outputItem.unit');
        $item = Item::find($target->output_item_id_snapshot);
        $baseUnitName = $task->workOrder->base_unit_name_snapshot ?: $task->workOrder->outputItem?->unit?->unit_name;
        $unit = $type === 'unit_operation' ? $target->productionUnit : null;
        $quantity = $type === 'quantity_operation';
        return ['task_id' => (int) $task->id, 'task_business_version' => (int) $task->business_version,
            'target_type' => $type, 'target_id' => (int) $target->id, 'target_business_version' => (int) $target->business_version,
            'task' => ['id' => (int) $task->id, 'no' => $task->task_no, 'business_version' => (int) $task->business_version],
            'work_order' => ['id' => (int) $task->work_order_id, 'no' => $task->workOrder->work_order_no, 'source_type' => $task->workOrder->source_type],
            'item' => ['id' => $item?->id ? (int) $item->id : null, 'code' => $item?->item_code, 'name' => $item?->item_name, 'spec' => $item?->spec, 'unit_name' => $baseUnitName],
            'unit' => ['id' => $unit?->id ? (int) $unit->id : null, 'no' => $unit?->unit_no, 'base_qty' => $quantity ? (string) $target->planned_base_qty : '1', 'base_unit_name' => $baseUnitName],
            'operation_id' => (int) $target->operation_id_snapshot, 'operation_name' => $target->operation_name_snapshot,
            'target' => ['type' => $type, 'id' => (int) $target->id, 'status' => $target->status, 'business_version' => (int) $target->business_version,
                'planned_base_qty' => $quantity ? (string) $target->planned_base_qty : '1',
                'completed_base_qty' => $quantity ? (string) $target->completed_base_qty : ($target->completed_at ? '1' : '0'),
                'remaining_base_qty' => $quantity ? (string) $target->remaining_base_qty : ($target->completed_at ? '0' : '1'),
                'unqualified_base_qty' => $quantity ? (string) $target->unqualified_base_qty : '0', 'scrapped_base_qty' => $quantity ? (string) $target->scrapped_base_qty : '0',
                'quality_mode' => $target->quality_mode_snapshot, 'output_mode' => $target->output_mode_snapshot,
                'allow_continue_without_warehouse' => (bool) $target->allow_continue_without_warehouse_snapshot,
                'kitting_required' => (bool) $target->kitting_required, 'kitting_confirmed_at' => optional($target->kitting_confirmed_at)->toISOString(),
                'work_mode' => $target->work_mode_snapshot ?: 'manual'],
            'readiness' => app(ProductionTargetReadinessService::class)->project($type, $target)];
    }

    private function candidateQuery(object $user, array $permissions, bool $super): Builder
    {
        $workOrders = WorkOrder::query()->select('id')->whereIn('status', ['RELEASED', 'IN_PROGRESS']);
        $this->scopes->applyWorkOrderScope($workOrders, $this->scopes->resolve($user, 'production.work_order.edit', $permissions, $super));
        $tasks = ProductionTask::query()->whereIn('work_order_id', $workOrders);
        $this->scopes->applyProductionTaskScope($tasks, $this->scopes->resolve($user, 'production.task.view', $permissions, $super), $this->userId($user));
        return $tasks;
    }

    private function visibleQuery(object $user, array $permissions, bool $super): Builder
    {
        $this->permission($permissions, 'production.task.view');
        $visibleTasks = ProductionTask::query()->select('id');
        $this->scopes->applyProductionTaskScope($visibleTasks, $this->scopes->resolve($user, 'production.task.view', $permissions, $super), $this->userId($user));
        return ProductionJobBundle::query()->whereHas('lines')->whereDoesntHave('lines', fn ($l) => $l->whereNotIn('task_id', $visibleTasks));
    }

    private function search(Builder $query, string $keyword): void
    {
        if ($term = trim($keyword)) {
            $like = '%'.addcslashes($term, '\\%_').'%';
            $query->where(fn ($q) => $q->where('task_no', 'like', $like)->orWhere('operation_name_snapshot', 'like', $like)
                ->orWhereHas('workOrder', fn ($w) => $w->where('work_order_no', 'like', $like)
                    ->orWhereHas('outputItem', fn ($i) => $i->where('item_code', 'like', $like)->orWhere('item_name', 'like', $like)->orWhere('spec', 'like', $like))));
        }
    }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) throw new WorkOrderDomainException('permission_denied', '当前用户没有执行该操作的权限。', 403, ['permission' => $code]); }
}
