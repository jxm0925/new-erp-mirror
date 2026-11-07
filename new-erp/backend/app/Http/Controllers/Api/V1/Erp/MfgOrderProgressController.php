<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Http\Controllers\Controller;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\Manufacturing\OrderProgressQueryService;
use Illuminate\Http\Request;

final class MfgOrderProgressController extends Controller
{
    public function __construct(private readonly AuthContextService $auth) {}

    public function index(Request $request, OrderProgressQueryService $progress)
    {
        $this->employee($request);
        $filters = $request->validate([
            'keyword' => 'nullable|string|max:120',
            'scope' => 'nullable|in:sales,stock',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:50',
        ]);

        return response()->json(['data' => $progress->paginate($filters)]);
    }

    public function show(Request $request, int $id, OrderProgressQueryService $progress)
    {
        $user = $this->employee($request);
        $scope = $request->validate(['scope' => 'nullable|in:sales,stock'])['scope'] ?? 'sales';
        $canExecute = $this->auth->isSuperAdmin($user)
            || in_array('production.task.execute', $this->auth->permissionCodes($user), true);

        return response()->json(['data' => $progress->show($id, $canExecute ? (int) $user->legacy_id : null, $scope)]);
    }

    private function employee(Request $request): object
    {
        $user = $this->auth->currentUser($request);
        if (! $user || ! empty($user->deleted_at)) {
            throw new WorkOrderDomainException('unauthenticated', '请先登录 ERP。', 401);
        }

        return $user;
    }
}
