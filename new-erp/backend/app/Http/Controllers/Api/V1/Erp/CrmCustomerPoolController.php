<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\CrmCustomerPool;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Crm\CrmCustomerPoolService;
use App\Services\Erp\Crm\CrmNineStepCatalog;
use App\Services\Erp\MasterDataAccessService;
use Illuminate\Http\Request;

final class CrmCustomerPoolController extends Controller
{
    public function catalog(Request $request)
    {
        abort_unless(app(AuthContextService::class)->currentUser($request), 401, '未登录');

        return response()->json(['data' => CrmNineStepCatalog::metadata('all')]);
    }

    public function index(Request $request, CrmCustomerPoolService $service)
    {
        abort_unless(app(AuthContextService::class)->currentUser($request), 401, '未登录');
        $data = $request->validate(['scope' => 'nullable|in:lead,opportunity,customer', 'keyword' => 'nullable|string|max:160', 'status' => 'nullable|in:enabled,disabled', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100', 'include_stats' => 'nullable|boolean']);
        $q = CrmCustomerPool::query();
        $stats = ['total' => (clone $q)->count(), 'enabled' => (clone $q)->where('status', 'enabled')->count(), 'disabled' => (clone $q)->where('status', 'disabled')->count()];
        if (! empty($data['scope'])) {
            $q->whereJsonContains('applicable_scopes', $data['scope']);
        }
        if (! empty($data['status'])) {
            $q->where('status', $data['status']);
        }
        if (! empty($data['keyword'])) {
            $q->where(fn ($q) => $q->where('pool_name', 'like', '%'.$data['keyword'].'%')->orWhere('pool_code', 'like', '%'.$data['keyword'].'%'));
        }
        $p = $q->orderBy('sort_order')->orderBy('id')->paginate($data['per_page'] ?? 10)->through(fn ($p) => $service->present($p));

        return response()->json($p->toArray() + ['stats' => $stats]);
    }

    public function store(Request $request, CrmCustomerPoolService $service)
    {
        return response()->json(['data' => $service->save(null, $this->payload($request, false), $this->actor($request))], 201);
    }

    public function update(Request $request, int $id, CrmCustomerPoolService $service)
    {
        return response()->json(['data' => $service->save($id, $this->payload($request, true), $this->actor($request))]);
    }

    public function status(Request $request, int $id, CrmCustomerPoolService $service)
    {
        $a = $this->actor($request);
        $d = $request->validate(['status' => 'required|in:enabled,disabled', 'expected_version' => 'required|string|size:64']);

        return response()->json(['data' => $service->setStatus($id, $d['status'], $d['expected_version'], $a)]);
    }

    public function destroy(Request $request, int $id, CrmCustomerPoolService $service)
    {
        $a = $this->actor($request);
        $d = $request->validate(['expected_version' => 'required|string|size:64']);
        $service->delete($id, $d['expected_version'], $a);

        return response()->json(['message' => '客户池已删除']);
    }

    private function actor(Request $r): object
    {
        return app(MasterDataAccessService::class)->authorize($r, 'business-lines', 'create');
    }

    private function payload(Request $r, bool $edit): array
    {
        return $r->validate(['pool_code' => 'required|string|max:64', 'pool_name' => 'required|string|max:80', 'group_code' => 'required|in:lead,opportunity,customer', 'applicable_scopes' => 'required|array|min:1|max:3', 'applicable_scopes.*' => 'required|in:lead,opportunity,customer|distinct', 'legacy_aliases' => 'nullable|array|max:50', 'legacy_aliases.*' => 'required|string|max:80|distinct', 'sort_order' => 'nullable|integer|min:0', 'status' => 'nullable|in:enabled,disabled', 'expected_version' => ($edit ? 'required' : 'nullable').'|string|size:64']);
    }
}
