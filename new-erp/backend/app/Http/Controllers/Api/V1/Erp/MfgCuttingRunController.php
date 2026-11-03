<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\CuttingRunService;
use Illuminate\Http\Request;

/** 集中开料记录：选 BOM 开一批、按实际计时、切完登记件数；工单下料「已备料」按件数领走工时。 */
class MfgCuttingRunController extends Controller
{
    public function boms(Request $request, CuttingRunService $runs)
    {
        $this->user($request, false);
        return response()->json(['data' => $runs->boms($request->validate(['keyword' => 'nullable|string|max:120']))]);
    }

    public function index(Request $request, CuttingRunService $runs)
    {
        $this->user($request, false);
        $data = $request->validate(['status' => 'nullable|in:running,awaiting_confirm,finished,cancelled', 'output_item_id' => 'nullable|integer|min:1', 'only_left' => 'nullable|boolean',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        return response()->json($runs->index($data));
    }

    public function show(Request $request, int $id, CuttingRunService $runs)
    {
        $user = $this->user($request, false);
        return response()->json(['data' => $runs->show($id, $user)]);
    }

    public function store(Request $request, CuttingRunService $runs)
    {
        $user = $this->user($request, true);
        $data = $request->validate(['bom_id' => 'required|integer|min:1', 'set_qty' => 'required|integer|min:1|max:10000', 'remark' => 'nullable|string|max:500']);
        return response()->json(['message' => '已开始开料并计时，切完点「切完结束」登记件数。', 'data' => $runs->start($data, $user)]);
    }

    public function action(Request $request, int $id, CuttingRunService $runs)
    {
        $user = $this->user($request, true);
        $action = $request->validate(['action' => 'required|in:start,pause,finish,cancel'])['action'];
        if ($action === 'finish') {
            $data = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1', 'remark' => 'nullable|string|max:500',
                'lines' => 'required|array|min:1|max:200', 'lines.*.line_key' => 'required|string|max:160|distinct', 'lines.*.actual_pieces' => 'required|integer|min:0|max:100000']);
            $commandId = $data['client_command_id']; unset($data['client_command_id']);
            $auth = app(AuthContextService::class);
            $result = app(\App\Services\Erp\WarehouseCommandService::class)->run('cutting_run.finish', $id, $commandId, $data,
                $user, $auth->permissionCodes($user), $auth->isSuperAdmin($user))['result'];
            return response()->json(['message' => $result['status'] === 'awaiting_confirm' ? '实际件数已记录并停止计时，请下料人补齐各来源实际余料登记。'
                : '已结束，切好的件和实际工时可以按工单领用。', 'data' => $result]);
        }
        if ($action === 'cancel') return response()->json(['message' => '已作废。', 'data' => $runs->cancel($id, $user)]);
        return response()->json(['message' => $action === 'start' ? '开始计时。' : '已暂停计时。', 'data' => $runs->timing($id, $action, $user)]);
    }

    private function user(Request $request, bool $write): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $codes = $auth->permissionCodes($user);
        $permissions = $write ? ['production.task.execute', 'production.work_order.edit'] : ['production.work_order.view', 'production.work_order.edit', 'production.task.view', 'production.task.execute'];
        if (! $auth->isSuperAdmin($user) && ! array_intersect($permissions, $codes)) throw new WorkOrderDomainException('permission_denied', '当前账号没有集中开料权限。', 403);
        return $user;
    }
}
