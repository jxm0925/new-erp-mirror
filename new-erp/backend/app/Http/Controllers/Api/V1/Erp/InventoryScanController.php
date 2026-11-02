<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Services\Erp\{AuthContextService, InventoryScanResolverService};
use Illuminate\Http\Request;

final class InventoryScanController extends Controller
{
    public function resolve(Request $request, InventoryScanResolverService $resolver)
    {
        $auth = app(AuthContextService::class);
        $user = $request->attributes->get('erp_user') ?? $auth->currentUser($request);
        abort_unless($user, 401, '请先登录ERP。');
        $data = $request->validate(['code' => 'required|string|max:255']);
        return response()->json(['data' => $resolver->resolve($data['code'], $user, $auth->permissionCodes($user))]);
    }
}
