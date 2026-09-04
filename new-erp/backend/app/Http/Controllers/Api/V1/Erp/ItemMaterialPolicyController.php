<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\Item;
use App\Models\Erp\ItemMaterialPolicy;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\MaterialPolicyApplicationService;
use Illuminate\Http\Request;

class ItemMaterialPolicyController extends Controller
{
    public function show(int $itemId, MaterialPolicyApplicationService $service)
    {
        return response()->json($service->current(Item::findOrFail($itemId)));
    }

    public function history(Request $request, int $itemId)
    {
        $data = ItemMaterialPolicy::query()->where('item_id', $itemId)
            ->orderByDesc('version_no')->paginate(min(100, max(5, (int) $request->input('per_page', 20))));
        return response()->json($data);
    }

    public function saveDraft(Request $request, int $itemId, MaterialPolicyApplicationService $service, AuthContextService $auth)
    {
        $policy = $service->saveDraft(Item::findOrFail($itemId), $this->validated($request, false), $auth->currentLegacyId($request));
        return response()->json(['message' => '策略草稿已保存', 'data' => $policy]);
    }

    public function activate(Request $request, int $itemId, MaterialPolicyApplicationService $service, AuthContextService $auth)
    {
        $policy = $service->activate(Item::findOrFail($itemId), $this->validated($request, true), $auth->currentLegacyId($request));
        return response()->json(['message' => '物资归属策略已启用', 'data' => $policy]);
    }

    private function validated(Request $request, bool $activation): array
    {
        return $request->validate([
            'template_code' => 'nullable|string|max:60',
            'is_stock_managed' => 'required|boolean',
            'inventory_management_mode' => 'required|in:standard,none',
            'requires_custodian' => 'required|boolean',
            'is_returnable' => 'required|boolean',
            'requires_capitalization' => 'required|boolean',
            'serial_tracking_mode' => 'required|in:none,optional,required',
            'post_purchase_action' => 'required|in:inventory_receipt,issue_confirmation,asset_acceptance,expense_confirmation,work_order_cost,sales_order_direct_cost',
            'consumption_confirmation_mode' => 'required|in:none,issue,asset_acceptance,service_acceptance',
            'future_route' => 'required|in:inventory,expense,asset,direct_expense,work_order_cost,sales_order_direct_cost',
            'future_bearer_type' => 'required|in:company,department,employee,work_order,sales_order',
            'change_reason' => ($activation ? 'nullable' : 'nullable').'|string|max:200',
            'remark' => 'nullable|string|max:200',
        ]);
    }
}
