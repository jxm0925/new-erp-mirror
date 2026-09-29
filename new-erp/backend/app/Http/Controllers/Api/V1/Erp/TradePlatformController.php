<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\TradePlatformApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class TradePlatformController extends Controller
{
    public function index(Request $request)
    {
        $this->authorizeArchive($request);
        $data = $request->validate(['keyword' => 'nullable|string|max:160', 'status' => 'nullable|in:enabled,disabled', 'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100']);
        $query = DB::table('erp_sales_order_trade_platforms as p')
            ->leftJoin('erp_sales_order_trade_platforms as parent', 'parent.legacy_id', '=', 'p.parent_legacy_id')
            ->select('p.*', 'parent.name as parent_name');
        if (!empty($data['status'])) $query->where('p.enabled', $data['status'] === 'enabled');
        if ($keyword = trim((string) ($data['keyword'] ?? ''))) $query->where(fn ($q) => $q->where('p.name', 'like', "%{$keyword}%")->orWhere('p.short_name', 'like', "%{$keyword}%"));
        $stats = $request->boolean('include_stats') ? [
            'enabled' => (clone $query)->where('p.enabled', true)->count(),
            'root' => (clone $query)->where('p.parent_legacy_id', 0)->count(),
            'sub' => (clone $query)->where('p.parent_legacy_id', '>', 0)->count(),
        ] : null;
        $rows = $query->orderBy('p.parent_legacy_id')->orderBy('p.sort')->orderBy('p.id')->paginate($data['per_page'] ?? 20);
        $rows->setCollection($rows->getCollection()->map(fn ($row) => TradePlatformApplicationService::present($row)));
        return response()->json([...$rows->toArray(), ...($stats === null ? [] : ['stats' => $stats])]);
    }

    public function store(Request $request, TradePlatformApplicationService $service)
    {
        $operator = app(\App\Services\Erp\MasterDataAccessService::class)->authorize($request, 'trade-platforms', 'edit');
        return response()->json(['data' => $service->save(null, $this->payload($request, false), $operator)], 201);
    }

    public function update(Request $request, int $id, TradePlatformApplicationService $service)
    {
        $operator = app(\App\Services\Erp\MasterDataAccessService::class)->authorize($request, 'trade-platforms', 'edit');
        return response()->json(['data' => $service->save($id, $this->payload($request, true), $operator)]);
    }

    public function status(Request $request, int $id, TradePlatformApplicationService $service)
    {
        $operator = app(\App\Services\Erp\MasterDataAccessService::class)->authorize($request, 'trade-platforms', 'edit');
        $data = $request->validate(['status' => 'required|in:enabled,disabled', 'expected_version' => 'required|string|size:64']);
        return response()->json(['data' => $service->setStatus($id, $data['status'], $data['expected_version'], $operator)]);
    }

    private function payload(Request $request, bool $editing): array
    {
        return $request->validate([
            'name' => 'required|string|max:160', 'short_name' => 'nullable|string|max:160', 'trade_type' => 'nullable|string|max:40',
            'parent_legacy_id' => 'required|integer|min:0', 'sort' => 'required|integer|min:0|max:9999', 'status' => 'required|in:enabled,disabled',
            'expected_version' => $editing ? 'required|string|size:64' : 'prohibited',
            'client_request_id' => $editing ? 'prohibited' : 'required|uuid',
        ]);
    }

    private function authorizeArchive(Request $request): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user && ($auth->isSuperAdmin($user) || in_array('master.base_archive', $auth->permissionCodes($user), true)), 403, '没有基础档案维护权限。');
        return $user;
    }
}
