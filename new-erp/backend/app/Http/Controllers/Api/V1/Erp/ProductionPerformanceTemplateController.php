<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionPerformanceTemplateService;
use Illuminate\Http\Request;

class ProductionPerformanceTemplateController extends Controller
{
    public function index(Request $request, ProductionPerformanceTemplateService $service)
    {
        $this->user($request);
        $filters = $request->validate(['keyword' => 'nullable|string|max:120', 'status' => 'nullable|in:enabled,disabled',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($service->paginate($filters));
    }

    public function operations(Request $request, ProductionPerformanceTemplateService $service)
    {
        $this->user($request);
        return response()->json(['data' => $service->operations($request->validate(['keyword' => 'nullable|string|max:80'])['keyword'] ?? null)]);
    }

    public function show(Request $request, int $id, ProductionPerformanceTemplateService $service)
    {
        $this->user($request);
        return response()->json(['data' => $service->show($id)]);
    }

    public function store(Request $request, ProductionPerformanceTemplateService $service)
    {
        $user = $this->user($request);
        return response()->json(['message' => '提成模板已保存。', 'data' => $service->save(null, $this->payload($request), (int) $user->legacy_id)]);
    }

    public function update(Request $request, int $id, ProductionPerformanceTemplateService $service)
    {
        $user = $this->user($request);
        return response()->json(['message' => '提成模板已保存。', 'data' => $service->save($id, $this->payload($request, true), (int) $user->legacy_id)]);
    }

    public function destroy(Request $request, int $id, ProductionPerformanceTemplateService $service)
    {
        $this->user($request);
        $service->destroy($id);
        return response()->json(['message' => '提成模板已删除，已套用到路线上的比例不受影响。']);
    }

    public function preview(Request $request, int $id, ProductionPerformanceTemplateService $service)
    {
        $this->user($request);
        $data = $request->validate(['routing_ids' => 'required|array|min:1|max:50', 'routing_ids.*' => 'integer|min:1|distinct']);
        return response()->json(['data' => $service->preview($id, array_map('intval', $data['routing_ids']))]);
    }

    public function apply(Request $request, int $id, ProductionPerformanceTemplateService $service)
    {
        $user = $this->user($request);
        $data = $request->validate(['routing_ids' => 'required|array|min:1|max:50', 'routing_ids.*' => 'integer|min:1|distinct']);
        $result = $service->apply($id, array_map('intval', $data['routing_ids']), $user);
        return response()->json(['message' => "已套用到 {$result['routings']} 条路线、{$result['operations']} 道工序；已生成的工单仍按原比例。", 'data' => $result]);
    }

    private function payload(Request $request, bool $update = false): array
    {
        return $request->validate(['template_name' => 'required|string|max:120', 'remark' => 'nullable|string|max:500',
            'status' => 'nullable|in:enabled,disabled', 'expected_version' => ($update ? 'required' : 'nullable').'|integer|min:1',
            'lines' => 'required|array|min:1|max:100', 'lines.*.operation_id' => 'required|integer|min:1|distinct',
            'lines.*.performance_rate' => 'required|numeric|min:0|max:1']);
    }

    private function user(Request $request): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        if (! $auth->isSuperAdmin($user) && ! in_array(ProductionPerformanceTemplateService::PERMISSION, $auth->permissionCodes($user), true))
            throw new WorkOrderDomainException('permission_denied', '当前用户没有维护提成比例的权限。', 403);
        return $user;
    }
}
