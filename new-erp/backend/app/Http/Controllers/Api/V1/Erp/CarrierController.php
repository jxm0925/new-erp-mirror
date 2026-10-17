<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\CarrierApplicationService;
use App\Services\Erp\MasterDataAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class CarrierController extends Controller
{
    public function index(Request $request)
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        $permissions = $user ? $auth->permissionCodes($user) : [];
        $archiveView = $user && ($auth->isSuperAdmin($user) || in_array('master.base_archive', $permissions, true));
        $salesView = array_intersect($permissions, ['sales_order.view', 'sales_order.create', 'sales.order.create', 'sales.order.edit', 'sales_order.edit_draft', 'sales_order.change', 'sales_order.change.submit']) !== [];
        abort_unless($user && ($archiveView || $salesView), 403, '没有承运商查看权限。');
        $data = $request->validate([
            'keyword' => 'nullable|string|max:160', 'status' => 'nullable|in:enabled,disabled',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
            'include_stats' => 'sometimes|boolean',
        ]);
        $query = DB::table('erp_sales_order_deliveries');
        // 销售选择器只读有效选项，不能借用选择权限读取停用配置或取得维护权限。
        if (! $archiveView) $query->where('enabled', true);
        $stats = $request->boolean('include_stats') ? [
            'total' => (clone $query)->count(), 'enabled' => (clone $query)->where('enabled', true)->count(),
            'disabled' => (clone $query)->where('enabled', false)->count(),
        ] : null;
        if (! empty($data['status'])) $query->where('enabled', $data['status'] === 'enabled');
        if ($keyword = trim((string) ($data['keyword'] ?? ''))) {
            $query->where(fn ($query) => $query->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%"));
        }
        $rows = $query->orderBy('sort')->orderBy('id')->paginate($data['per_page'] ?? 10);
        $rows->setCollection($rows->getCollection()->map(fn ($row) => CarrierApplicationService::present($row)));
        return response()->json([...$rows->toArray(), ...($stats === null ? [] : ['stats' => $stats])]);
    }

    public function store(Request $request, CarrierApplicationService $service)
    {
        $operator = app(MasterDataAccessService::class)->authorize($request, 'carriers', 'edit');
        return response()->json(['data' => $service->save(null, $this->payload($request, false), $operator)], 201);
    }

    public function update(Request $request, int $id, CarrierApplicationService $service)
    {
        $operator = app(MasterDataAccessService::class)->authorize($request, 'carriers', 'edit');
        return response()->json(['data' => $service->save($id, $this->payload($request, true), $operator)]);
    }

    public function status(Request $request, int $id, CarrierApplicationService $service)
    {
        $operator = app(MasterDataAccessService::class)->authorize($request, 'carriers', 'edit');
        $data = $request->validate(['status' => 'required|in:enabled,disabled', 'expected_version' => 'required|string|size:64']);
        return response()->json(['data' => $service->setStatus($id, $data['status'], $data['expected_version'], $operator)]);
    }

    private function payload(Request $request, bool $editing): array
    {
        return $request->validate([
            'name' => 'required|string|max:160', 'code' => 'nullable|string|max:80',
            'sort' => 'required|integer|min:0|max:9999', 'status' => 'required|in:enabled,disabled',
            'expected_version' => $editing ? 'required|string|size:64' : 'prohibited',
            'client_request_id' => $editing ? 'prohibited' : 'required|uuid',
        ]);
    }
}
