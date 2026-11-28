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
        $this->user($request, false);
        $filters = $request->validate(['keyword' => 'nullable|string|max:120', 'status' => 'nullable|in:enabled,disabled',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        return response()->json($service->paginate($filters));
    }

    public function operations(Request $request, ProductionPerformanceTemplateService $service)
    {
        $this->user($request, false);
        return response()->json(['data' => $service->operations($request->validate(['keyword' => 'nullable|string|max:80'])['keyword'] ?? null)]);
    }

    public function show(Request $request, int $id, ProductionPerformanceTemplateService $service)
    {
        $this->user($request, false);
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
        return response()->json(['message' => '提成模板已删除。']);
    }

    public function bindCategories(Request $request, int $id, ProductionPerformanceTemplateService $service)
    {
        $user = $this->user($request);
        $data = $this->categoryPayload($request, false);
        return response()->json(['message' => '已指定商品分类使用本模板；已生成的工单仍按原比例。', 'data' => $service->bindCategories($id, $data['category_ids'], $user, isset($data['expected_version']) ? (int) $data['expected_version'] : null)]);
    }

    public function unbindCategories(Request $request, int $id, ProductionPerformanceTemplateService $service)
    {
        $user = $this->user($request);
        $data = $this->categoryPayload($request, false);
        return response()->json(['message' => '已取消商品分类的指定，改按上级分类或默认模板计提成。', 'data' => $service->unbindCategories($id, $data['category_ids'], $user, isset($data['expected_version']) ? (int) $data['expected_version'] : null)]);
    }

    public function syncCategories(Request $request, int $id, ProductionPerformanceTemplateService $service)
    {
        $user = $this->user($request);
        $data = $this->categoryPayload($request, true);
        return response()->json(['message' => '商品分类适用范围已更新；已生成的工单仍按原比例。',
            'data' => $service->syncCategories($id, $data['category_ids'], isset($data['expected_version']) ? (int) $data['expected_version'] : null, $user)]);
    }

    private function categoryPayload(Request $request, bool $replace): array
    {
        return $request->validate(['category_ids' => $replace ? 'present|array' : 'required|array|min:1|max:100',
            'category_ids.*' => 'required|integer|min:1|distinct', 'expected_version' => 'nullable|integer|min:1']);
    }

    public function setDefault(Request $request, int $id, ProductionPerformanceTemplateService $service)
    {
        $user = $this->user($request);
        return response()->json(['message' => '已设为默认提成模板：BOM 未指定、商品分类也未指定模板的工序按它计提成；已生成的工单仍按原比例。', 'data' => $service->setDefault($id, $user)]);
    }

    private function payload(Request $request, bool $update = false): array
    {
        return $request->validate(['template_name' => 'required|string|max:120', 'remark' => 'nullable|string|max:500',
            'status' => 'nullable|in:enabled,disabled', 'expected_version' => ($update ? 'required' : 'nullable').'|integer|min:1',
            'lines' => 'required|array|min:1|max:100', 'lines.*.operation_id' => 'required|integer|min:1|distinct',
            'lines.*.performance_rate' => 'required|numeric|min:0|max:1']);
    }

    private function user(Request $request, bool $write = true): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        if (! $user) throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        $required = $write ? ProductionPerformanceTemplateService::WRITE_PERMISSIONS : ProductionPerformanceTemplateService::READ_PERMISSIONS;
        if (! $auth->isSuperAdmin($user) && ! array_intersect($required, $auth->permissionCodes($user)))
            throw new WorkOrderDomainException('permission_denied', $write ? '当前用户没有维护提成模板的权限。' : '当前用户没有查看提成模板的权限。', 403);
        return $user;
    }
}
