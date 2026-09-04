<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\Item;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\PurchaseSupplierRecommendationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class PurchaseSupplierRecommendationController extends Controller
{
    public function index(
        Request $request,
        int $itemId,
        PurchaseSupplierRecommendationService $service
    ) {
        $this->authorizePermission($request, 'purchase.supplier_recommendation.view');
        $data = $request->validate([
            'quantity' => 'required|numeric|min:0.0001',
            'unit_id' => 'required|exists:erp_units,id',
            'required_date' => 'nullable|date',
            'currency' => 'nullable|string|max:10',
            'tax_mode' => ['nullable', Rule::in(['tax_included', 'tax_excluded'])],
        ]);
        $item = Item::with(['unit', 'category'])->findOrFail($itemId);

        return response()->json(['data' => $service->recommend(
            $item,
            (float) $data['quantity'],
            (int) $data['unit_id'],
            $data['required_date'] ?? null,
            strtoupper($data['currency'] ?? 'CNY'),
            $data['tax_mode'] ?? 'tax_included'
        )]);
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless(
            $auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true),
            403,
            '无按钮权限：'.$permission
        );
    }
}
