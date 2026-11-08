<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{SalesOrder, CancellationProductionDisposition as Disposition};
use App\Services\Erp\{AuthContextService, SalesCancellationService};
use App\Services\Erp\Manufacturing\CancellationProductionService;
use Illuminate\Http\Request;

final class SalesCancellationController extends Controller
{
    private function user(Request $request, string|array $permission): object
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        abort_unless($user, 401, '请先登录 ERP');
        abort_unless($auth->isSuperAdmin($user) || array_intersect((array) $permission, $auth->permissionCodes($user)), 403, '当前账号没有处置权限。');
        return $user;
    }

    public function finance(Request $request, SalesCancellationService $service)
    {
        $this->user($request, 'finance.payment.view');
        $data = $request->validate(['keyword' => 'nullable|string|max:120', 'include_settled' => 'nullable|boolean', 'per_page' => 'nullable|integer|min:1|max:100']);
        $q = SalesOrder::where('order_status', 'cancelled')->where('order_source', '!=', 'legacy');
        if ($keyword = trim($data['keyword'] ?? '')) $q->where(fn ($q) => $q->where('sales_order_no', 'like', '%'.$keyword.'%')->orWhere('customer_name', 'like', '%'.$keyword.'%'));
        if (!$request->boolean('include_settled')) {
            $sum = fn ($type, $direction) => "COALESCE((SELECT SUM(a.allocated_amount) FROM erp_finance_allocations a JOIN erp_finance_cash_documents c ON c.id=a.cash_document_id WHERE a.source_document_id=erp_sales_orders.id AND a.source_business_type='{$type}' AND a.status='active' AND c.status='confirmed' AND c.direction='{$direction}'),0)";
            $q->whereRaw($sum('sales_order', 'receipt').' - '.$sum('sales_order_refund', 'payment')." - COALESCE((SELECT retained_amount FROM erp_sales_cancellations sc WHERE sc.sales_order_id=erp_sales_orders.id),0) > 0");
        }
        return response()->json($q->latest('id')->paginate($data['per_page'] ?? 10)->through(fn ($order) => [
            'id' => $order->id, 'sales_order_no' => $order->sales_order_no, 'customer_name' => $order->customer_name_snapshot ?: $order->customer_name,
            'currency' => $order->currency ?: 'CNY', 'cancelled_at' => $order->cancelled_at, ...$service->amounts($order),
            'refund_reserved' => $service->reservedRefunds($order->id)]));
    }

    public function approve(Request $request, int $id, SalesCancellationService $service)
    {
        $actor = $this->user($request, 'finance.payment.confirm');
        $data = $request->validate(['expected_version' => 'required|integer|min:0', 'retained_amount' => 'required|numeric|min:0|decimal:0,4', 'accounting_note' => 'required|string|max:1000']);
        return response()->json(['data' => $service->approve($id, $data, $actor)]);
    }

    public function production(Request $request)
    {
        $actor = $this->user($request, ['production.work_order.view', 'production.task.execute', 'production.material_return.request']);
        $data = $request->validate(['work_order_id' => 'nullable|integer|min:1', 'sales_order_id' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $q = Disposition::query()->whereNull('resolved_at');
        $auth = app(AuthContextService::class);
        $super = $auth->isSuperAdmin($actor); $permissions = $auth->permissionCodes($actor);
        if (!$super && !in_array('production.work_order.view', $permissions, true)) {
            $me = (int) ($actor->legacy_id ?? $actor->id ?? 0);
            $q->where(fn ($visible) => $visible->whereJsonContains('history->[0]->permitted_decider_ids', $me)
                ->orWhereExists(fn ($p) => $p->selectRaw('1')->from('erp_mfg_task_participants as p')->whereColumn('p.work_order_id', 'erp_cancellation_production_dispositions.work_order_id')->where('p.employee_legacy_id', $me))
                ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('erp_mfg_labor_sessions as s')->whereColumn('s.work_order_id', 'erp_cancellation_production_dispositions.work_order_id')->where('s.employee_legacy_id', $me))
                ->orWhereExists(fn ($c) => $c->selectRaw('1')->from('erp_mfg_cutting_run_allocations as a')->join('erp_mfg_cutting_run_sessions as s', 's.cutting_run_id', '=', 'a.cutting_run_id')
                    ->whereColumn('a.work_order_id', 'erp_cancellation_production_dispositions.work_order_id')->where('a.pieces', '>', 0)->where('s.employee_legacy_id', $me)));
        }
        if (!empty($data['work_order_id'])) $q->where('work_order_id', $data['work_order_id']);
        if (!empty($data['sales_order_id'])) $q->where('sales_order_id', $data['sales_order_id']);
        return response()->json($q->orderBy('due_at')->paginate($data['per_page'] ?? 10)->through(function ($row) use ($actor, $super, $permissions) {
            $wo = \App\Models\Erp\MfgWorkOrder::find($row->work_order_id); $order = SalesOrder::find($row->sales_order_id);
            $unit = $row->work_order_unit_id ? \App\Models\Erp\MfgWorkOrderUnit::find($row->work_order_unit_id) : null;
            $canDecide = CancellationProductionService::canDecide($row, $actor)
                && $unit && ($super || array_intersect(['production.work_order.edit', 'production.task.execute'], $permissions));
            return [...\Illuminate\Support\Arr::except($row->toArray(), ['history']), 'work_order_no' => $wo?->work_order_no, 'item_name' => $wo?->item_name,
                'sales_order_no' => $order?->sales_order_no, 'owner_name' => $wo?->owner_name,
                'unit_serial_no' => $unit?->serial_no, 'unit_status' => $unit?->status,
                'parent_unit_serial_no' => $row->parent_unit_id ? \App\Models\Erp\MfgWorkOrderUnit::whereKey($row->parent_unit_id)->value('serial_no') : null,
                'origin_type' => $row->sales_order_change_id ? 'change' : 'cancellation', 'can_decide' => $canDecide,
                'can_record_labor' => \App\Services\Erp\Manufacturing\DispositionLaborService::canRecord($row, (int) ($actor->legacy_id ?? $actor->id ?? 0))
                    && ($super || array_intersect(\App\Services\Erp\Manufacturing\DispositionLaborService::EXECUTE_PERMISSIONS, $permissions)),
                'can_request_material_return' => $canDecide && $row->status === 'terminating' && ($super || in_array('production.material_return.request', $permissions, true)),
                'has_actual_work' => data_get($row->history, '0.has_actual_work', true),
                'needs_material_return' => \App\Services\Erp\Manufacturing\OrderChangeLaborService::hasOutstandingMaterials($row),
                'decider_names' => array_column(data_get($row->history, '0.deciders', []), 'name'),
                'units' => $unit ? [$unit->only(['id', 'serial_no', 'status'])] : [],
                'operations' => \App\Models\Erp\MfgWorkOrderOperation::where('work_order_id', $row->work_order_id)->where('execution_context', 'production')
                    ->orderBy('sequence')->get(['id', 'operation_name'])->map(fn ($op) => ['id' => (int) $op->id, 'name' => $op->operation_name])->all(),
                'allowed_actions' => !$canDecide ? [] : ($row->status === 'terminating' ? ['scrap', 'finish_termination'] : ['cancel', 'stock', 'dismantle', 'scrap', 'postpone']),
                'overdue' => $row->due_at->isPast(), 'deadline_exceeded' => $row->deadline_at->isPast()];
        }));
    }

    public function decide(Request $request, int $id, CancellationProductionService $service)
    {
        $actor = $this->user($request, ['production.work_order.edit', 'production.task.execute']);
        $data = $request->validate(['action' => 'required|in:stock,postpone,terminate,cancel,dismantle,scrap,finish_termination', 'reason' => 'required|string|max:500',
            'expected_version' => 'required|integer|min:1', 'due_at' => 'required_if:action,postpone|nullable|date',
            'unit_ids' => 'required_if:action,scrap|array|min:1|max:1000', 'unit_ids.*' => 'integer|min:1|distinct',
            ...\App\Services\Erp\ProductionMediaService::rules()]);
        $row = $service->decide($id, $data, $actor);
        return response()->json(['data' => $row->only(['id', 'status', 'business_version', 'resolved_at'])]);
    }
}
