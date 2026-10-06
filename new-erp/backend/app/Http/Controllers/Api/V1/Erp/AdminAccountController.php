<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ErpUserDirectoryService;
use App\Services\Erp\SystemAdministrationApplicationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AdminAccountController extends Controller
{
    public function show(Request $request, int $id, SystemAdministrationApplicationService $service)
    {
        $this->context($request, ['system.admin.view', 'system.admin.edit']);
        return response()->json($service->account($id));
    }

    public function store(Request $request, SystemAdministrationApplicationService $service)
    {
        return response()->json($service->saveAccount(null, $request->all(), ...$this->context($request, ['system.admin.create'])), 201);
    }

    public function update(Request $request, int $id, SystemAdministrationApplicationService $service)
    {
        return response()->json($service->saveAccount($id, $request->all(), ...$this->context($request, ['system.admin.edit'])));
    }

    public function status(Request $request, int $id, SystemAdministrationApplicationService $service)
    {
        return response()->json($service->setAccountStatus($id, $request->all(), ...$this->context($request, ['system.admin.toggle_status'])));
    }

    public function destroy(Request $request, int $id, SystemAdministrationApplicationService $service)
    {
        return response()->json($service->deleteAccount($id, $request->all(), ...$this->context($request, ['system.admin.delete'])));
    }

    public function options(Request $request)
    {
        $this->context($request, ['system.admin.view', 'system.admin.create', 'system.admin.edit', 'system.department.save']);
        return response()->json(['departments' => DB::table('erp_departments')->whereNull('deleted_at')->orderBy('sort')->orderBy('legacy_id')
            ->get(['legacy_id', 'parent_legacy_id', 'name', 'status'])]);
    }

    public function roleOptions(Request $request)
    {
        $this->context($request, ['system.admin.view', 'system.admin.create', 'system.admin.edit']);
        $rows = DB::table('erp_rbac_roles')
            ->when($request->input('status', 'enabled') === 'enabled', fn ($q) => $q->where('enabled', true))
            ->when($request->filled('keyword'), function ($q) use ($request): void {
                $keyword = trim((string) $request->input('keyword'));
                $q->where(fn ($match) => $match->where('name', 'like', '%'.$keyword.'%')->orWhere('code', 'like', '%'.$keyword.'%'));
            })->orderBy('id')->paginate(max(1, min(100, $request->integer('per_page', 10))), ['id', 'name', 'code', 'enabled', 'data_scope', 'is_system']);
        return response()->json(['data' => $rows->items(), 'meta' => ['total' => $rows->total(), 'current_page' => $rows->currentPage(), 'per_page' => $rows->perPage()]]);
    }

    public function userOptions(Request $request, ErpUserDirectoryService $directory)
    {
        $this->context($request, ['system.role.save_permissions', 'system.department.set_principal']);
        $rows = $directory->users(['scope' => 'system', 'status' => 'normal', 'keyword' => $request->input('keyword'),
            'department_id' => $request->integer('department_id'), 'page' => $request->integer('page', 1), 'per_page' => $request->integer('per_page', 10)]);
        return response()->json(['data' => $rows->items(), 'meta' => ['total' => $rows->total(), 'current_page' => $rows->currentPage(), 'per_page' => $rows->perPage()]]);
    }

    private function context(Request $request, array $required): array
    {
        $auth = app(AuthContextService::class);
        $user = $request->attributes->get('erp_user') ?: $auth->currentUser($request);
        abort_unless($user, 401, '请先登录 ERP。');
        $permissions = $auth->permissionCodes($user);
        $super = $auth->isSuperAdmin($user);
        abort_unless($super || array_intersect($required, $permissions), 403, '没有执行此系统管理操作的权限。');
        return [$user, $permissions, $super];
    }
}
