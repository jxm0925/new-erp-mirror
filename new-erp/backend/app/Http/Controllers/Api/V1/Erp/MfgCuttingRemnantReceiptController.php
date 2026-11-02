<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\{AuthContextService, WarehouseCommandService};
use App\Services\Erp\Manufacturing\CuttingRemnantReceiptService;
use Illuminate\Http\Request;

class MfgCuttingRemnantReceiptController extends Controller
{
    public function index(Request $request, CuttingRemnantReceiptService $receipts)
    {
        [$user] = $this->context($request);
        $data = $request->validate(['status' => 'nullable|in:pending,confirmed,rejected,cancelled', 'keyword' => 'nullable|string|max:120',
            'cutting_batch_id' => 'nullable|integer|min:1', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($receipts->paginate($data, $user));
    }

    public function show(Request $request, int $id, CuttingRemnantReceiptService $receipts)
    {
        [$user] = $this->context($request);
        return response()->json(['data' => $receipts->show($id, $user)]);
    }

    public function confirm(Request $request, int $id) { return $this->action($request, $id, 'confirm'); }
    public function reject(Request $request, int $id) { return $this->action($request, $id, 'reject'); }
    public function cancel(Request $request, int $id) { return $this->action($request, $id, 'cancel'); }
    public function scrap(Request $request, int $id) { return $this->action($request, $id, 'scrap'); }

    private function action(Request $request, int $id, string $action)
    {
        [$user, $codes, $super] = $this->context($request);
        $rules = in_array($action, ['confirm', 'scrap'], true) ? CuttingRemnantReceiptService::confirmRules($action === 'scrap')
            : ['expected_version' => 'required|integer|min:1'] + ($action === 'reject' ? ['reason' => 'required|string|max:500'] : []);
        if ($action === 'confirm') $rules += ['remark' => 'nullable|string|max:500'];
        $data = $request->validate($rules + ['client_command_id' => 'required|string|max:120']);
        $commandId = $data['client_command_id']; unset($data['client_command_id']);
        $result = app(WarehouseCommandService::class)->run('cutting_remnant.'.$action, $id, $commandId, $data, $user, $codes, $super);
        return response()->json(['message' => match ($action) {
            'confirm' => '余料已确认入库，净耗已结算。', 'reject' => '已驳回，请登记人更正后重新提交。',
            'cancel' => '余料申请已撤回，实际产出保留，请重新登记真实余料。', default => '本批余料已明确整批报废，成本留在制造净耗。',
        }, 'data' => $result['result']]);
    }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }
}
