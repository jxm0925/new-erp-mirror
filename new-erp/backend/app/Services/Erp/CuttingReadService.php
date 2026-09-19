<?php

namespace App\Services\Erp;

use App\Models\Erp\WorkOrder;
use App\Models\Erp\CuttingTask;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

final class CuttingReadService
{
    public function __construct(private readonly CuttingCommandService $commands, private readonly ProductionDataScopeResolver $scopes,
        private readonly CuttingOrderLifecycleService $lifecycle) {}

    public function orders(array $f, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.view');
        $visible = WorkOrder::query()->select('id');
        $this->scopes->applyWorkOrderScope($visible, $this->scopes->resolve($user, 'production.cutting.view', $permissions, $super));
        $tasks = CuttingTask::query()->select('cutting_order_id');
        $this->scopes->applyCuttingTaskScope($tasks, $this->scopes->resolve($user, 'production.cutting.view', $permissions, $super), $this->commands->actor($user));
        $q = DB::table('erp_cutting_orders as o')->where(function (Builder $scope) use ($visible, $tasks): void {
            $scope->where(fn (Builder $worker) => $worker->where('o.purpose','WORKER')->whereIn('o.id',$tasks->toBase()))
                ->orWhere(function (Builder $formal) use ($visible): void {
                    $formal->where('o.purpose','!=','WORKER')->whereExists(fn (Builder $p) => $p->selectRaw('1')->from('erp_cutting_plan_allocations as a')->whereColumn('a.cutting_order_id','o.id'))
            ->whereNotExists(fn (Builder $p) => $p->selectRaw('1')->from('erp_cutting_plan_allocations as a')->whereColumn('a.cutting_order_id','o.id')->whereNotIn('a.work_order_id',$visible->toBase()))
            ->whereNotExists(fn (Builder $p) => $p->selectRaw('1')->from('erp_cutting_plan_allocations as a')->join('erp_production_target_material_requirements as r','r.id','=','a.target_material_requirement_id')
                ->whereColumn('a.cutting_order_id','o.id')->whereNotIn('r.work_order_id',$visible->toBase()));
                });
        });
        if (! empty($f['status'])) $q->where('o.status',$f['status']);
        if (! empty($f['keyword'])) $q->where('o.cutting_order_no','like','%'.$f['keyword'].'%');
        return $this->page($q->orderByDesc('o.id'),$f);
    }

    public function tasks(array $f, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.view');
        $actor = $this->commands->actor($user);
        $visible = CuttingTask::query()->select('id');
        $this->scopes->applyCuttingTaskScope($visible, $this->scopes->resolve($user, 'production.cutting.view', $permissions, $super), $actor);
        $q = DB::table('erp_cutting_tasks as t')->join('erp_cutting_orders as o', 'o.id', '=', 't.cutting_order_id')
            ->whereIn('t.id', $visible->toBase());
        if (($f['scope'] ?? null) === 'pool') {
            $q->where('t.status', 'WAIT_CLAIM')->whereNull('t.assignee_user_legacy_id');
        } elseif (($f['scope'] ?? null) === 'mine') {
            $q->where(function (Builder $mine) use ($actor): void {
                $mine->where('t.assignee_user_legacy_id', $actor)->orWhereExists(function (Builder $participant) use ($actor): void {
                    $participant->selectRaw('1')->from('erp_cutting_task_participants as participant')
                        ->whereColumn('participant.cutting_task_id', 't.id')->where('participant.employee_legacy_id', $actor)
                        ->whereNull('participant.left_at');
                });
            });
        }
        if (! empty($f['status'])) $q->where('t.status', $f['status']);
        if (($f['status_group'] ?? null) === 'active') $q->whereNotIn('o.status',['CLOSED','CANCELLED']);
        if (($f['status_group'] ?? null) === 'finished') $q->whereIn('o.status',['CLOSED','CANCELLED']);
        if (! empty($f['keyword'])) $q->where(fn (Builder $w) => $w->where('t.task_no', 'like', '%'.$f['keyword'].'%')
            ->orWhere('o.cutting_order_no', 'like', '%'.$f['keyword'].'%'));
        $result = $this->page($q->select('t.*', 'o.cutting_order_no', 'o.status as cutting_order_status')->orderByDesc('t.id'), $f);
        $orderIds = collect($result['data'])->pluck('cutting_order_id')->filter()->unique()->all();
        $allowedOutputs = empty($orderIds) ? collect() : DB::table('erp_cutting_allowed_outputs as a')
            ->join('erp_items as i', 'i.id', '=', 'a.item_id')
            ->whereIn('a.cutting_order_id', $orderIds)
            ->select('a.cutting_order_id', 'i.item_name', 'i.spec', 'i.item_code')
            ->get()->groupBy('cutting_order_id');
        $recordedOutputs = empty($orderIds) ? collect() : DB::table('erp_cutting_results as r')
            ->join('erp_cutting_settlement_batches as b', 'b.id', '=', 'r.settlement_batch_id')
            ->join('erp_items as i', 'i.id', '=', 'r.item_id')
            ->whereIn('b.cutting_order_id', $orderIds)
            ->where('r.result_type', 'product')
            ->whereNotIn('r.status', ['VOIDED', 'SUPERSEDED', 'REVERSED'])
            ->select('b.cutting_order_id', 'i.item_name', 'r.actual_qty')
            ->get()->groupBy('cutting_order_id');

        foreach ($result['data'] as &$row) {
            $row['display_status'] = $this->taskStatusLabel($row['status']);
            $orderId = (int) ($row['cutting_order_id'] ?? 0);
            $orderResults = $recordedOutputs->get($orderId, collect());
            if ($orderResults->isNotEmpty()) {
                $row['output_summary'] = $orderResults->pluck('item_name')->filter()->unique()->implode('、');
            } else {
                $orderAllowed = $allowedOutputs->get($orderId, collect());
                $row['output_summary'] = $orderAllowed->isNotEmpty()
                    ? $orderAllowed->pluck('item_name')->filter()->unique()->implode('、')
                    : '待加工产出';
            }
        }
        unset($row); return $result;
    }

    public function taskExecution(int $id, array $f, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.view');
        $taskModel = $this->commands->cuttingTask($id, $user, $permissions, $super, 'production.cutting.view');
        $order = DB::table('erp_cutting_orders')->where('id', $taskModel->cutting_order_id)->first();
        $task = $taskModel->toArray(); $task['display_status'] = $this->taskStatusLabel($task['status']);
        $orderId = (int) $taskModel->cutting_order_id;
        $orderResults = DB::table('erp_cutting_results as r')
            ->join('erp_cutting_settlement_batches as b', 'b.id', '=', 'r.settlement_batch_id')
            ->join('erp_items as i', 'i.id', '=', 'r.item_id')
            ->where('b.cutting_order_id', $orderId)
            ->where('r.result_type', 'product')
            ->whereNotIn('r.status', ['VOIDED', 'SUPERSEDED', 'REVERSED'])
            ->select('i.item_name')
            ->get();
        if ($orderResults->isNotEmpty()) {
            $task['output_summary'] = $orderResults->pluck('item_name')->filter()->unique()->implode('、');
        } else {
            $orderAllowed = DB::table('erp_cutting_allowed_outputs as a')
                ->join('erp_items as i', 'i.id', '=', 'a.item_id')
                ->where('a.cutting_order_id', $orderId)
                ->select('i.item_name')
                ->get();
            $task['output_summary'] = $orderAllowed->isNotEmpty()
                ? $orderAllowed->pluck('item_name')->filter()->unique()->implode('、')
                : '待加工产出';
        }
        $participants = DB::table('erp_cutting_task_participants as p')->leftJoin('erp_legacy_admin_users as u', 'u.legacy_id', '=', 'p.employee_legacy_id')
            ->where('p.cutting_task_id', $id)->orderBy('p.id')->select('p.id', 'p.employee_legacy_id', 'p.role', 'p.responsibility_weight',
                'p.joined_at', 'p.left_at', 'p.business_version', 'u.username', 'u.nickname')->get()->map(fn ($row) => (array) $row)->all();
        $labor = DB::table('erp_production_labor_sessions')->where('execution_task_type', 'CUTTING_TASK')->where('cutting_task_id', $id)
            ->orderBy('id')->get(['id', 'employee_legacy_id', 'role', 'status', 'started_at', 'ended_at', 'end_reason',
                'actual_labor_minutes', 'credited_labor_minutes', 'previous_labor_session_id'])->map(fn ($row) => (array) $row)->all();
        $actor = $this->commands->actor($user);
        $mine = collect($labor)->where('employee_legacy_id',$actor);
        $active = $mine->firstWhere('status','ACTIVE');
        $myLabor = ['active_session_id'=>$active['id'] ?? null,
            'started_at'=>isset($active['started_at']) ? \Illuminate\Support\Carbon::parse($active['started_at'])->toISOString() : null,
            'closed_minutes'=>(string) $mine->where('status','!=','ACTIVE')->sum('actual_labor_minutes')];
        $inputs = $this->page(DB::table('erp_cutting_settlement_batches as b')->leftJoin('erp_material_physicals as p', 'p.id', '=', 'b.physical_material_id')
            ->join('erp_items as i', 'i.id', '=', 'b.input_item_id')->where('b.cutting_task_id', $id)
            ->select($this->sourceColumns())->orderBy('b.id'), $f);
        foreach ($inputs['data'] as &$input) $input['display_status'] = $this->batchStatusLabel($input['status']);
        unset($input);
        return ['task' => $task, 'order' => (array) $order, 'participants' => $participants, 'labor_sessions' => $labor,
            'inputs' => $inputs, 'my_labor'=>$myLabor,'server_now'=>now()->toISOString(),
            'page_title' => '下料任务', 'input_source_locked_per_record' => true];
    }

    public function execution(int $id, array $f, object $user, array $permissions, bool $super = false): array
    {
        $order = $this->commands->order($id,$user,$permissions,$super,'production.cutting.view');
        $inputQuery = DB::table('erp_cutting_settlement_batches as b')->leftJoin('erp_material_physicals as p','p.id','=','b.physical_material_id')
            ->join('erp_items as i','i.id','=','b.input_item_id')->where('b.cutting_order_id',$id)
            ->select($this->sourceColumns())->orderBy('b.id');
        if (($f['flow'] ?? null) === 'confirm') $inputQuery->whereIn('b.status',['WAIT_CONFIRM','WAIT_QUALITY','QUALITY_FAILED']);
        $inputs = $this->page($inputQuery,$f);
        foreach ($inputs['data'] as &$input) $input['display_status'] = $this->batchStatusLabel($input['status']);
        unset($input);
        return ['order'=>(array) $order,'task'=>(array) DB::table('erp_cutting_tasks')->where('cutting_order_id',$id)->first(),
            'inputs'=>$inputs,'results'=>$this->results($id,$f),'flow'=>$this->flowSummary($id),'page_title'=>'下料记录','submit_label'=>'提交加工结果',
            'page_scope'=>'ORDER_OVERVIEW','lifecycle'=>$this->lifecycle->projection($order)+[
                'close_permitted'=>in_array('production.cutting.close',$permissions,true),
                'cancel_permitted'=>in_array('production.cutting.cancel',$permissions,true),
            ]];
    }

    public function settlementExecution(int $id, array $f, object $user, array $permissions, bool $super = false): array
    {
        $batch = $this->commands->assertBatchVisible($id,$user,$permissions,$super,'production.cutting.view');
        $source = DB::table('erp_cutting_settlement_batches as b')->leftJoin('erp_material_physicals as p','p.id','=','b.physical_material_id')
            ->join('erp_items as i','i.id','=','b.input_item_id')->where('b.id',$id)
            ->select($this->sourceColumns())->first();
        // The URL, not a query/body source ID, owns the source and every result below.
        $source = (array) $source; $source['display_status'] = $this->batchStatusLabel($source['status']);
        $confirmation = null;
        if (in_array('production.cutting.confirm',$permissions,true)) {
            $this->commands->assertBatchVisible($id,$user,$permissions,$super,'production.cutting.confirm');
            $confirmation = ['original_total_cost'=>(string) $batch->original_total_cost,
                'result_costs'=>DB::table('erp_cutting_results')->where('settlement_batch_id',$id)->whereNotIn('status',['VOIDED','SUPERSEDED','REVERSED'])
                    ->orderBy('id')->get(['id','total_cost'])->map(fn ($row) => (array) $row)->all()];
        }
        return ['order'=>(array) DB::table('erp_cutting_orders')->where('id',$batch->cutting_order_id)->first(),
            'source'=>$source,'results'=>$this->results((int) $batch->cutting_order_id,$f,$id),
            'confirmation'=>$confirmation,
            'page_title'=>'下料记录','submit_label'=>'提交加工结果','page_scope'=>'SETTLEMENT_BATCH',
            'source_locked'=>true,'can_add_input'=>false];
    }

    public function handoverTargets(int $resultId, array $f, object $user, array $permissions, bool $super = false): array
    {
        $result = $this->commands->assertResultVisible($resultId, $user, $permissions, $super, 'production.cutting.view');
        $batch = DB::table('erp_cutting_settlement_batches')->where('id', $result->settlement_batch_id)->first();
        $pending = DB::table('erp_cutting_result_routes')->whereNotNull('target_material_requirement_id')->where('status', '!=', 'CANCELLED')
            ->selectRaw('target_material_requirement_id, SUM(quantity - received_qty) AS pending_qty')
            ->groupBy('target_material_requirement_id');
        $workerOrigin = DB::table('erp_cutting_orders')->where('id',$batch->cutting_order_id)->where('purpose','WORKER')->exists();
        $q = DB::table('erp_production_target_material_requirements as requirement');
        if (! $workerOrigin) $q->join('erp_cutting_plan_allocations as plan','requirement.id','=','plan.target_material_requirement_id')
            ->where('plan.cutting_order_id',$batch->cutting_order_id)->where('plan.output_item_id',$result->item_id)
            ->where('plan.stage_id',$result->stage_id)->where('plan.configuration_id',$result->configuration_id);
        else {
            $visible = WorkOrder::query()->select('id');
            $this->scopes->applyWorkOrderScope($visible, $this->scopes->resolve($user,'production.cutting.record',$permissions,$super));
            $q->where('requirement.component_item_id',$result->item_id)->whereIn('requirement.work_order_id',$visible->toBase());
            if ($result->configuration_id && DB::table('erp_custom_configurations')->where('id',$result->configuration_id)->value('scope_mode') !== 'PUBLIC')
                $q->whereIn('requirement.work_order_id', DB::table('erp_custom_configuration_scopes')->select('source_id')
                    ->where('configuration_id',$result->configuration_id)->where('source_type','work_order'));
        }
        $q->join('erp_work_orders as wo', 'wo.id', '=', 'requirement.work_order_id')->whereIn('wo.status',['RELEASED','IN_PROGRESS'])
            ->join('erp_production_task_targets as link', function ($join): void {
                $join->on('link.target_type', '=', 'requirement.target_type')->on('link.target_id', '=', 'requirement.target_id');
            })->join('erp_production_tasks as task', 'task.id', '=', 'link.task_id')
            ->leftJoin('erp_production_unit_operations as unit_operation', function ($join): void {
                $join->on('unit_operation.id', '=', 'requirement.target_id')->where('requirement.target_type', '=', 'unit_operation');
            })->leftJoin('erp_production_units as unit', 'unit.id', '=', 'unit_operation.production_unit_id')
            ->leftJoinSub($pending, 'pending_supply', fn ($join) => $join->on('pending_supply.target_material_requirement_id', '=', 'requirement.id'));
        if (! empty($f['keyword'])) {
            $keyword = '%'.$f['keyword'].'%';
            $q->where(fn (Builder $where) => $where->where('wo.work_order_no', 'like', $keyword)->orWhere('task.task_no', 'like', $keyword)
                ->orWhere('unit.unit_no', 'like', $keyword));
        }
        $q->select('requirement.id as target_material_requirement_id', 'requirement.target_type', 'requirement.target_id',
            'requirement.required_base_qty', 'requirement.satisfied_base_qty', 'requirement.returned_base_qty', 'requirement.status as requirement_status',
            'wo.id as work_order_id', 'wo.work_order_no', 'task.id as task_id', 'task.task_no', 'task.status as task_status',
            'task.assignee_user_legacy_id', 'unit.id as production_unit_id', 'unit.unit_no', DB::raw('COALESCE(pending_supply.pending_qty,0) AS pending_qty'))
            ->distinct()->orderBy('wo.id')->orderBy('task.id');
        $page = $this->page($q, $f);
        foreach ($page['data'] as &$row) {
            $received = max(0, (float) $row['satisfied_base_qty'] - (float) $row['returned_base_qty']);
            $row['received_qty'] = number_format($received, 8, '.', '');
            $row['selectable_qty'] = number_format(max(0, (float) $row['required_base_qty'] - $received - (float) $row['pending_qty']), 8, '.', '');
            $row['eligible_for_dispatch'] = ! empty($row['assignee_user_legacy_id'])
                && ! in_array($row['task_status'], ['WAIT_CLAIM', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'], true);
        }
        unset($row); return $page;
    }

    private function results(int $orderId, array $f, ?int $batchId = null): array
    {
        // Never GROUP BY Item/configuration/stage: one result ID retains one source.
        $q = DB::table('erp_cutting_results as r')->join('erp_cutting_settlement_batches as b','b.id','=','r.settlement_batch_id')
            ->leftJoin('erp_items as i','i.id','=','r.item_id')->leftJoin('erp_material_physicals as p','p.id','=','b.physical_material_id')
            ->leftJoin('erp_custom_configurations as cfg','cfg.id','=','r.configuration_id')
            ->where('b.cutting_order_id',$orderId)->whereNotIn('r.status',['VOIDED','SUPERSEDED','REVERSED'])
            ->select('r.id','r.settlement_batch_id','r.client_row_id','r.result_type','r.allowed_output_id','r.item_id','r.configuration_id','r.stage_id',
                'r.actual_qty','r.piece_qty','r.cut_length_mm','r.measurements','r.measurement_status','r.quality_status','r.reported_quality',
                'r.status','r.material_lot_id','r.physical_material_id','r.business_version','r.created_at','r.updated_at',
                'b.batch_no','b.status as batch_status','b.physical_material_id as input_physical_material_id','p.physical_no as input_physical_no','i.item_code','i.item_name',
                'i.spec','cfg.configuration_no','cfg.version_no as configuration_version','cfg.scope_mode as configuration_scope',
                'cfg.drawing_reference','cfg.dimensions as configuration_dimensions')->orderBy('r.id');
        if ($batchId !== null) $q->where('b.id',$batchId);
        if (in_array($f['flow'] ?? null,['handover','warehouse'],true)) {
            $type = $f['flow'] === 'warehouse' ? 'WAREHOUSE' : 'NEXT_OPERATION';
            $q->whereExists(fn (Builder $routes) => $routes->selectRaw('1')->from('erp_cutting_result_routes as candidate')
                ->whereColumn('candidate.result_id','r.id')->where('candidate.route_type',$type)
                ->whereNotIn('candidate.status',['CANCELLED','RECEIVED','WAREHOUSED']));
        }
        $results = $this->page($q,$f);
        $ids = array_column($results['data'],'id');
        $routes = DB::table('erp_cutting_result_routes')->whereIn('result_id',$ids)->where('status','!=','CANCELLED')->orderBy('id')->get()->groupBy('result_id');
        $routeIds = $routes->flatten(1)->pluck('id');
        $handovers = DB::table('erp_cutting_handovers')->whereIn('route_id', $routeIds)->orderBy('id')->get()
            ->groupBy('route_id');
        $receipts = DB::table('erp_cutting_warehouse_receipts')->whereIn('route_id', $routeIds)->where('status', 'POSTED')
            ->orderBy('id')->get()->groupBy('route_id');
        foreach ($results['data'] as &$row) {
            $row['routes'] = $routes->get($row['id'],collect())->map(fn ($route) => [
                'id'=>$route->id,'result_id'=>$route->result_id,'route_type'=>$route->route_type,
                'target_material_requirement_id'=>$route->target_material_requirement_id,'quantity'=>$route->quantity,
                'handed_over_qty'=>$route->handed_over_qty,'received_qty'=>$route->received_qty,
                'warehoused_qty'=>$route->warehoused_qty,
                'status'=>$route->status,'business_version'=>$route->business_version,
                'handovers'=>$handovers->get($route->id,collect())->map(fn ($handover) => [
                    'id'=>(int) $handover->id,'handover_no'=>$handover->handover_no,'status'=>$handover->status,
                    'dispatched_qty'=>$handover->dispatched_qty,'accepted_qty'=>$handover->accepted_qty,
                    'rejected_qty'=>$handover->rejected_qty,'expected_receiver_legacy_id'=>(int) $handover->expected_receiver_legacy_id,
                    'business_version'=>(int) $handover->business_version,'dispatched_at'=>$handover->dispatched_at,
                ])->all(),
                'warehouse_receipts'=>$receipts->get($route->id,collect())->map(fn ($receipt) => [
                    'id'=>(int) $receipt->id,'receipt_no'=>$receipt->receipt_no,'status'=>$receipt->status,
                    'warehouse_id'=>(int) $receipt->warehouse_id,'location_id'=>(int) $receipt->location_id,
                    'batch_no'=>$receipt->batch_no,'posted_qty'=>$receipt->posted_qty,'posted_cost'=>$receipt->posted_cost,
                    'inventory_transaction_id'=>(int) $receipt->inventory_transaction_id,'posted_at'=>$receipt->posted_at,
                ])->all(),
            ])->all();
            foreach ($row['routes'] as &$route) $route['display_status'] = match ($route['status']) {
                'PLANNED' => $route['route_type'] === 'WAREHOUSE' ? '待入库确认' : '待交接',
                'WAIT_DISPATCH' => '待交出', 'PART_DISPATCHED' => '部分已交出', 'IN_TRANSIT' => '已交出待接收',
                'PART_RECEIVED' => '部分已接收', 'RECEIVED' => '已接收', 'WAIT_WAREHOUSE' => '待入库确认',
                'PART_WAREHOUSED' => '部分已入库', 'WAREHOUSED' => '已入库',
                default => $route['status'],
            };
            unset($route);
            if ($row['result_type'] === 'product') {
                $designated = '0.00000000'; $handedOver = '0.00000000'; $received = '0.00000000'; $warehoused = '0.00000000';
                foreach ($row['routes'] as $route) {
                    $designated = bcadd($designated, (string) $route['quantity'], 8);
                    $handedOver = bcadd($handedOver, (string) $route['handed_over_qty'], 8);
                    $received = bcadd($received, (string) $route['received_qty'], 8);
                    $warehoused = bcadd($warehoused, (string) $route['warehoused_qty'], 8);
                }
                $row['designated_qty'] = $designated;
                $row['handed_over_qty'] = $handedOver;
                $row['received_qty'] = $received;
                $row['warehoused_qty'] = $warehoused;
                $row['assigned_qty'] = $designated;
                $row['unassigned_qty'] = bcsub((string) $row['actual_qty'],$designated,8);
                $row['route_complete'] = bccomp($row['unassigned_qty'],'0',8) === 0;
                $row['confirm_allowed'] = $row['route_complete'] && $row['batch_status'] === 'WAIT_CONFIRM';
            }
            unset($row['batch_status']);
        }
        unset($row);
        return $results;
    }

    private function flowSummary(int $orderId): array
    {
        $routes = DB::table('erp_cutting_result_routes as route')->join('erp_cutting_results as result','result.id','=','route.result_id')
            ->join('erp_cutting_settlement_batches as batch','batch.id','=','result.settlement_batch_id')->where('batch.cutting_order_id',$orderId)
            ->whereNotIn('result.status',['VOIDED','SUPERSEDED','REVERSED'])->where('route.status','!=','CANCELLED');
        return ['confirm'=>DB::table('erp_cutting_settlement_batches')->where('cutting_order_id',$orderId)->whereIn('status',['WAIT_CONFIRM','WAIT_QUALITY','QUALITY_FAILED'])->count(),
            'handover'=>(string) (clone $routes)->where('route.route_type','NEXT_OPERATION')->sum(DB::raw('route.quantity-route.received_qty')),
            'warehouse'=>(string) (clone $routes)->where('route.route_type','WAREHOUSE')->sum(DB::raw('route.quantity-route.warehoused_qty'))];
    }

    private function sourceColumns(): array
    {
        // Mobile execution projection deliberately excludes costs, holdings and transaction internals.
        return ['b.id','b.batch_no','b.cutting_order_id','b.cutting_task_id','b.input_item_id','b.physical_material_id',
            'b.input_qty','b.standard_stock_length_mm','b.status','b.business_version','b.correction_of_batch_id','b.first_cut_at','b.submitted_at','b.confirmed_at',
            'p.physical_no','p.material_form','p.shape','p.dimensions','i.item_code','i.item_name','i.spec'];
    }

    private function batchStatusLabel(string $status): string
    {
        return match ($status) {
            'PROCESSING'=>'草稿','WAIT_ROUTE'=>'待完善去向','WAIT_QUALITY'=>'待质检','WAIT_CONFIRM'=>'待用料确认',
            'QUALITY_FAILED'=>'质量不合格','CONFIRMED'=>'已核算','REVERSED'=>'已转更正',default=>$status,
        };
    }

    private function taskStatusLabel(string $status): string
    {
        return match ($status) {
            'WAIT_CLAIM' => '待领取', 'READY' => '待开工', 'IN_PROGRESS' => '进行中',
            'PAUSED' => '已暂停', 'FINISHED' => '已完成', 'CANCELLED' => '已取消', default => $status,
        };
    }

    public function allowedOutputs(int $id, array $f, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->order($id,$user,$permissions,$super,'production.cutting.view');
        $q = DB::table('erp_cutting_allowed_outputs as a')->join('erp_items as i','i.id','=','a.item_id')
            ->leftJoin('erp_custom_configurations as c','c.id','=','a.configuration_id')->where('a.cutting_order_id',$id)->where('i.status','enabled')
            ->where(fn (Builder $q) => $q->whereNull('a.configuration_id')->orWhere('c.status','PUBLISHED'));
        $this->itemFilter($q,$f);
        return $this->page($q->select('a.*','i.item_code','i.item_name','i.spec','i.category_id','c.configuration_no','c.version_no as configuration_version','c.drawing_reference')->orderBy('a.id'),$f);
    }

    public function selectorCategories(int $id, array $f, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->order($id, $user, $permissions, $super, 'production.cutting.view');
        $mode = $f['mode'] ?? '';
        if ($mode === 'outputs') {
            $query = DB::table('erp_cutting_allowed_outputs as allowed')
                ->join('erp_items as item', 'item.id', '=', 'allowed.item_id')
                ->join('erp_item_categories as category', 'category.id', '=', 'item.category_id')
                ->where('allowed.cutting_order_id', $id);
        } elseif ($mode === 'inputs') {
            $itemIds = app(CuttingMaterialEligibilityService::class)->itemIds($id);
            $query = DB::table('erp_items as item')
                ->join('erp_item_categories as category', 'category.id', '=', 'item.category_id')
                ->whereIn('item.id', $itemIds);
        } else {
            $this->commands->fail('selector_mode_invalid', '请选择用料或产出分类。');
        }

        return $this->page($query->where('category.status', 'enabled')
            ->select('category.id', 'category.category_code', 'category.category_name', 'category.sort_order')
            ->distinct()->orderBy('category.sort_order')->orderBy('category.id'), $f);
    }

    public function inputCandidates(int $id, array $f, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->order($id,$user,$permissions,$super,'production.cutting.view');
        $itemIds = app(CuttingMaterialEligibilityService::class)->itemIds($id);
        return $this->inputCandidatesForItems($itemIds, $f);
    }

    public function workerInputs(array $f, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.record');
        $this->commands->permission($permissions, 'production.cutting.issue');
        return $this->inputCandidatesForItems(app(CuttingMaterialEligibilityService::class)->workerMaterials()->select('id'), $f);
    }

    public function workerInputCategories(array $f, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.record');
        $this->commands->permission($permissions, 'production.cutting.issue');
        return $this->page(DB::table('erp_item_categories')->where('status','enabled')->whereIn('id',
            app(CuttingMaterialEligibilityService::class)->workerMaterials()->select('category_id'))
            ->select('id','category_code','category_name')->orderBy('sort_order')->orderBy('id'), $f);
    }

    private function inputCandidatesForItems(Builder $itemIds, array $f): array
    {
        if (($f['input_type'] ?? 'physical') === 'physical') {
            $q = DB::table('erp_material_physicals as p')->join('erp_items as i','i.id','=','p.item_id')
                ->join('erp_material_holdings as h','h.id','=','p.current_holding_id')->whereIn('p.item_id',$itemIds)->where('p.status','AVAILABLE')->where('h.status','ACTIVE')
                ->where(fn (Builder $q) => $q->where('h.position_type','WAREHOUSE')
                    ->orWhere(fn (Builder $q) => $q->where('h.position_type','REMNANT_WIP')->where('p.material_form','REMNANT')->whereNull('h.inventory_balance_id')));
            if (! empty($f['material_form'])) $q->where('p.material_form',$f['material_form']);
            if (! empty($f['shape'])) $q->where('p.shape',$f['shape']);
            $this->itemFilter($q,$f,'p.physical_no');
            return $this->page($q->select('p.id','p.physical_no','p.item_id','p.material_form','p.shape','p.dimensions','p.status','p.business_version',
                'i.item_code','i.item_name','i.spec','i.category_id','h.position_type','h.position_id')->orderBy('p.id'),$f);
        }
        $warehouse = DB::table('erp_inventory_balances as b')->join('erp_items as i','i.id','=','b.item_id')
            ->leftJoin('erp_material_lots as l','l.id','=','b.material_lot_id')->whereNull('l.configuration_id')->whereNull('l.stage_id')->whereIn('b.item_id',$itemIds)
            ->where('b.quantity_available','>',0)->where('i.material_management_mode','quantity')
            ->where(fn (Builder $q) => $q->where('i.cutting_mode','length')->orWhere(fn (Builder $q) => $q->whereNull('i.cutting_mode')->where('i.is_length_cut_material',true)));
        $warehouse->selectRaw("'WAREHOUSE' AS source_type, b.id AS inventory_balance_id, NULL AS remnant_holding_id, b.item_id, b.batch_no,
            b.quantity_available AS available_root_qty, i.item_code, i.item_name, i.spec, i.category_id,
            i.standard_stock_length_mm, l.material_form, 'WAREHOUSE' AS position_type");
        $remnants = DB::table('erp_material_holdings as h')->join('erp_material_lots as l', 'l.id', '=', 'h.material_lot_id')
            ->join('erp_items as i', 'i.id', '=', 'l.item_id')->whereIn('l.item_id', $itemIds)
            ->where('h.position_type', 'REMNANT_WIP')->where('h.status', 'ACTIVE')->whereNull('h.inventory_balance_id')
            ->where('l.material_form', 'REMNANT')->whereNotNull('l.cut_length_mm')->where('i.material_management_mode', 'quantity')
            ->where(fn (Builder $q) => $q->where('i.cutting_mode', 'length')->orWhere(fn (Builder $q) => $q->whereNull('i.cutting_mode')->where('i.is_length_cut_material', true)))
            ->selectRaw("'REMNANT_WIP' AS source_type, NULL AS inventory_balance_id, h.id AS remnant_holding_id, l.item_id,
                l.lot_no AS batch_no, h.quantity AS available_root_qty, i.item_code, i.item_name, i.spec, i.category_id,
                l.cut_length_mm AS standard_stock_length_mm, l.material_form, h.position_type");
        $q = DB::query()->fromSub($warehouse->unionAll($remnants), 'candidate');
        if (! empty($f['category_id'])) $q->where('candidate.category_id', (int) $f['category_id']);
        if (! empty($f['material_form'])) $q->where('candidate.material_form', $f['material_form']);
        if (! empty($f['keyword'])) {
            $keyword = '%'.$f['keyword'].'%';
            $q->where(fn (Builder $q) => $q->where('candidate.item_code', 'like', $keyword)->orWhere('candidate.item_name', 'like', $keyword)
                ->orWhere('candidate.spec', 'like', $keyword)->orWhere('candidate.batch_no', 'like', $keyword));
        }

        return $this->page($q->orderBy('candidate.source_type')->orderBy('candidate.item_id')->orderBy('candidate.batch_no'), $f);
    }

    public function materialPhysicals(array $f, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.material_manage');
        $query = $this->materialPhysicalQuery($user, $permissions, $super);
        if (! empty($f['status'])) $query->where('p.status', $f['status']);
        if (! empty($f['material_form'])) $query->where('p.material_form', $f['material_form']);
        if (! empty($f['shape'])) $query->where('p.shape', $f['shape']);
        $this->itemFilter($query, $f, 'p.physical_no');
        $summary = [
            'full_stock' => (clone $query)->where('p.material_form', 'FULL_STOCK')->count('p.id'),
            'rectangle_remnant' => (clone $query)->where('p.material_form', 'REMNANT')->where('p.shape', 'RECTANGLE')->count('p.id'),
            'irregular_remnant' => (clone $query)->where('p.material_form', 'REMNANT')->where('p.shape', 'IRREGULAR')->count('p.id'),
        ];
        $page = $this->page($query->select($this->physicalColumns())->orderByDesc('p.id'), $f);

        return $page + ['summary' => $summary];
    }

    public function materialPhysical(int $id, object $user, array $permissions, bool $super = false): array
    {
        $this->commands->permission($permissions, 'production.cutting.material_manage');
        $base = $this->materialPhysicalQuery($user, $permissions, $super);
        $physical = (clone $base)->where('p.id', $id)->select($this->physicalColumns())->first();
        if (! $physical) $this->commands->fail('physical_missing', '材料实物不存在或不在当前数据范围。', 404);
        $rootId = (int) ($physical->root_physical_id ?: $physical->id);
        $lineage = (clone $base)->where(fn (Builder $q) => $q->where('p.id', $rootId)->orWhere('p.root_physical_id', $rootId))
            ->select($this->physicalColumns())->orderBy('p.id')->get()->map(fn ($row) => (array) $row)->all();
        $sourceResult = DB::table('erp_cutting_results as r')->join('erp_cutting_settlement_batches as b', 'b.id', '=', 'r.settlement_batch_id')
            ->where('r.physical_material_id', $id)->orderByDesc('r.id')->first(['r.id as result_id', 'r.settlement_batch_id', 'b.batch_no']);
        $uses = DB::table('erp_cutting_settlement_batches as b')->join('erp_cutting_orders as o', 'o.id', '=', 'b.cutting_order_id')
            ->where('b.physical_material_id', $id)->orderBy('b.id')->get(['b.id as settlement_batch_id', 'b.batch_no', 'b.status',
                'b.first_cut_at', 'b.correction_of_batch_id', 'o.id as cutting_order_id', 'o.cutting_order_no'])
            ->map(fn ($row) => (array) $row)->all();

        return ['physical' => (array) $physical, 'source' => $physical->source_transaction_item_id
            ? ['type' => 'PURCHASE_RECEIPT_ITEM', 'source_transaction_item_id' => (int) $physical->source_transaction_item_id]
            : ['type' => 'CUTTING_RESULT', 'result_id' => $sourceResult ? (int) $sourceResult->result_id : null,
                'settlement_batch_id' => $sourceResult ? (int) $sourceResult->settlement_batch_id : null,
                'batch_no' => $sourceResult?->batch_no],
            'lineage' => $lineage, 'uses' => $uses];
    }

    private function materialPhysicalQuery(object $user, array $permissions, bool $super): Builder
    {
        $scope = $this->scopes->resolve($user, 'production.cutting.view', $permissions, $super);
        $visible = CuttingTask::query()->select('id');
        $this->scopes->applyCuttingTaskScope($visible, $scope, $this->commands->actor($user));
        $query = DB::table('erp_material_physicals as p')->join('erp_items as i', 'i.id', '=', 'p.item_id')
            ->join('erp_material_holdings as h', 'h.id', '=', 'p.current_holding_id');
        if (($scope['mode'] ?? 'deny') !== 'all') $query->where(fn (Builder $q) => $q->whereExists(fn (Builder $b) => $b->selectRaw('1')->from('erp_cutting_settlement_batches as direct_batch')
                ->whereColumn('direct_batch.physical_material_id', 'p.id')->whereIn('direct_batch.cutting_task_id', $visible->toBase()))
                ->orWhereExists(fn (Builder $r) => $r->selectRaw('1')->from('erp_cutting_results as source_result')
                    ->join('erp_cutting_settlement_batches as source_batch', 'source_batch.id', '=', 'source_result.settlement_batch_id')
                    ->whereColumn('source_result.physical_material_id', 'p.id')->whereIn('source_batch.cutting_task_id', $visible->toBase())));

        return $query;
    }

    private function physicalColumns(): array
    {
        return ['p.id', 'p.physical_no', 'p.item_id', 'p.source_transaction_item_id', 'p.root_physical_id', 'p.parent_physical_id',
            'p.material_form', 'p.shape', 'p.dimensions', 'p.status', 'p.business_version', 'p.first_cut_at', 'p.created_at', 'p.updated_at',
            'i.item_code', 'i.item_name', 'i.spec', 'i.category_id', 'h.position_type', 'h.position_id', 'h.status as holding_status'];
    }

    private function itemFilter(Builder $q, array $f, ?string $extra = null): void
    {
        if (! empty($f['category_id'])) $q->where('i.category_id',(int) $f['category_id']);
        if (! empty($f['keyword'])) $q->where(function (Builder $q) use ($f,$extra): void {
            $keyword = '%'.$f['keyword'].'%'; $q->where('i.item_code','like',$keyword)->orWhere('i.item_name','like',$keyword)->orWhere('i.spec','like',$keyword);
            if ($extra) $q->orWhere($extra,'like',$keyword);
        });
    }

    private function page(Builder $q, array $f): array
    {
        $page = filter_var($f['page'] ?? 1,FILTER_VALIDATE_INT); $size = filter_var($f['per_page'] ?? 20,FILTER_VALIDATE_INT);
        if (! $page || $page < 1 || ! $size || $size < 1 || $size > 100) $this->commands->fail('pagination_invalid','分页参数不合法，每页最多100条。');
        $result = $q->paginate($size,['*'],'page',$page);
        return ['data'=>array_map(fn ($r) => (array) $r,$result->items()),'meta'=>['current_page'=>$page,'per_page'=>$size,'total'=>$result->total(),'last_page'=>$result->lastPage()]];
    }
}
