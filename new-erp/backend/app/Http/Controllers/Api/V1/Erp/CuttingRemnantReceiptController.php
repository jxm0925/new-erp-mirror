<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\{AuthContextService, CuttingRemnantReceiptService};
use Illuminate\Http\Request;

final class CuttingRemnantReceiptController extends Controller
{
    public function index(Request $r, int $id, CuttingRemnantReceiptService $s)
    {
        return response()->json($s->paginate($id, $r->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
            'keyword' => 'nullable|string|max:100', 'source_page' => 'nullable|integer|min:1', 'status' => 'nullable|in:PENDING,POSTED,UNAVAILABLE']), ...$this->context($r)));
    }

    public function show(Request $r, int $id, int $receipt, CuttingRemnantReceiptService $s)
    { return response()->json(['data' => $s->show($id, $receipt, ...$this->context($r))]); }

    public function command(Request $r, int $id, CuttingRemnantReceiptService $s)
    {
        $data = $r->validate(['client_command_id' => 'required|string|max:120']);
        return response()->json(['data' => $s->commandResult($id, $data['client_command_id'], ...$this->context($r))]);
    }

    public function store(Request $r, int $id, CuttingRemnantReceiptService $s)
    {
        $data = $r->validate(['client_command_id' => 'required|string|max:120', 'warehouse_id' => 'required|integer|min:1',
            'location_id' => 'required|integer|min:1', 'remark' => 'nullable|string|max:1000', 'lines' => 'required|array|min:1|max:100',
            'lines.*' => 'required|array:result_id,expected_version,holding_version,physical_version',
            'lines.*.result_id' => 'required|integer|min:1|distinct', 'lines.*.expected_version' => 'required|integer|min:1',
            'lines.*.holding_version' => 'required|integer|min:1', 'lines.*.physical_version' => 'nullable|integer|min:1']);
        return response()->json(['data' => $s->post($id, $data, ...$this->context($r))], 201);
    }

    private function context(Request $r): array
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($r);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录ERP。', 401);
        return [$user, $auth->permissionCodes($user), $auth->isSuperAdmin($user)];
    }
}
