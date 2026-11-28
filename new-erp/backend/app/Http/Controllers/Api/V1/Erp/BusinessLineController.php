<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\BusinessLineApplicationService;
use App\Services\Erp\MasterDataAccessService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class BusinessLineController extends Controller
{
    public function index(Request $request)
    {
        app(MasterDataAccessService::class)->authorizeRead($request, 'business-lines');
        $data = $request->validate([
            'keyword' => 'nullable|string|max:160', 'status' => 'nullable|in:enabled,disabled',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:100',
            'include_stats' => 'sometimes|boolean',
        ]);
        $query = DB::table('erp_business_lines');
        $stats = $request->boolean('include_stats') ? [
            'total' => (clone $query)->count(), 'enabled' => (clone $query)->where('status', 'enabled')->count(),
            'disabled' => (clone $query)->where('status', 'disabled')->count(),
        ] : null;
        if (! empty($data['status'])) $query->where('status', $data['status']);
        $keyword = trim((string) ($data['keyword'] ?? ''));
        if ($keyword !== '') {
            $query->where(fn ($query) => $query->where('name', 'like', "%{$keyword}%")->orWhere('code', 'like', "%{$keyword}%"));
        }
        $rows = $query->orderBy('sort_order')->orderBy('id')->paginate($data['per_page'] ?? 10);
        $rows->setCollection($rows->getCollection()->map(fn ($row) => BusinessLineApplicationService::present($row)));
        return response()->json([...$rows->toArray(), ...($stats === null ? [] : ['stats' => $stats])]);
    }

    public function store(Request $request, BusinessLineApplicationService $service)
    {
        $operator = app(MasterDataAccessService::class)->authorize($request, 'business-lines', 'create');
        return response()->json(['data' => $service->save(null, $this->payload($request, false), $operator)], 201);
    }

    public function update(Request $request, int $id, BusinessLineApplicationService $service)
    {
        $operator = app(MasterDataAccessService::class)->authorize($request, 'business-lines', 'edit');
        return response()->json(['data' => $service->save($id, $this->payload($request, true), $operator)]);
    }

    public function status(Request $request, int $id, BusinessLineApplicationService $service)
    {
        $operator = app(MasterDataAccessService::class)->authorize($request, 'business-lines', 'edit');
        $data = $request->validate(['status' => 'required|in:enabled,disabled', 'expected_version' => 'required|string|size:64']);
        return response()->json(['data' => $service->setStatus($id, $data['status'], $data['expected_version'], $operator)]);
    }

    private function payload(Request $request, bool $editing): array
    {
        return $request->validate([
            'code' => 'nullable|string|max:64', 'name' => 'required|string|max:80',
            'sort_order' => 'sometimes|integer|min:0|max:2147483647', 'sort' => 'sometimes|integer|min:0|max:2147483647',
            'status' => 'sometimes|in:enabled,disabled', 'remark' => 'nullable|string|max:500',
            'expected_version' => $editing ? 'required|string|size:64' : 'prohibited',
            'client_request_id' => $editing ? 'prohibited' : 'nullable|uuid',
        ]);
    }
}
