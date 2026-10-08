<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ProductionProcessCatalogService;
use Illuminate\Http\Request;

class ProductionProcessCatalogController extends Controller
{
    public function index(Request $request, string $type, ProductionProcessCatalogService $service)
    {
        $data = $request->validate(['keyword' => 'nullable|string|max:120', 'status' => 'nullable|in:enabled,disabled',
            'page' => 'nullable|integer|min:1', 'per_page' => 'nullable|integer|min:1|max:50']);
        [$actor, $permissions, $super] = $this->context($request);
        return response()->json($service->paginate($type, $data, $permissions, $super));
    }

    public function store(Request $request, string $type, ProductionProcessCatalogService $service)
    {
        return $this->save($request, $type, null, $service);
    }

    public function update(Request $request, string $type, int $id, ProductionProcessCatalogService $service)
    {
        return $this->save($request, $type, $id, $service);
    }

    private function save(Request $request, string $type, ?int $id, ProductionProcessCatalogService $service)
    {
        $data = $request->validate(['client_command_id' => 'required|string|max:120', 'expected_version' => $id ? 'required|integer|min:1' : 'prohibited',
            'code' => 'required|string|max:60|regex:/^[A-Za-z0-9_-]+$/', 'name' => 'required|string|max:120',
            'status' => 'nullable|in:enabled,disabled', 'sort' => 'nullable|integer|min:0|max:999999', 'description' => 'nullable|string|max:2000']);
        [$actor, $permissions, $super] = $this->context($request);
        return response()->json(['data' => $service->save($type, $id, $data, $actor, $permissions, $super)], $id ? 200 : 201);
    }

    private function context(Request $request): array
    {
        $auth = app(AuthContextService::class); $actor = $auth->currentUser($request);
        abort_unless($actor, 401, '请先登录ERP。');
        return [$actor, $auth->permissionCodes($actor), $auth->isSuperAdmin($actor)];
    }
}
