<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\WorkOrderCompletionService;
use Illuminate\Http\Request;

final class WorkOrderCompletionController extends Controller
{
    public function preflight(Request $request, int $id, WorkOrderCompletionService $service)
    { return response()->json(['data' => $service->preflight($id, ...$this->context($request))]); }

    public function index(Request $request, int $id, WorkOrderCompletionService $service)
    {
        $filters = $request->validate(['page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($service->paginate($id, (int) ($filters['page'] ?? 1),
            (int) ($filters['per_page'] ?? 20), ...$this->context($request)));
    }

    public function store(Request $request, int $id, WorkOrderCompletionService $service)
    {
        $payload = $request->validate([
            'client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'output_record_ids' => 'required|array|min:1|max:100', 'output_record_ids.*' => 'required|integer|distinct|exists:erp_production_output_records,id',
            'defect_reason' => 'nullable|string|max:1000', 'remark' => 'nullable|string|max:2000',
            'attachments' => 'nullable|array|max:20', 'attachments.*' => 'array',
        ]);
        return response()->json(['message' => '完工事实已提交，等待 PC 审核。', 'data' => $service->submit($id, $payload, ...$this->context($request))], 201);
    }

    public function review(Request $request, int $completionId, WorkOrderCompletionService $service)
    {
        $payload = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => 'required|integer|min:1',
            'decision' => 'required|in:approve,reject', 'reason' => 'nullable|string|max:1000']);
        return response()->json(['message' => '完工审核已记录。', 'data' => $service->review($completionId, $payload, ...$this->context($request))]);
    }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $permissions = $auth->permissionCodes($user); $super = $auth->isSuperAdmin($user);
        $request->attributes->set('erp_action_context', ['permissions' => $permissions, 'super_admin' => $super]);
        return [$user, $permissions, $super];
    }
}
