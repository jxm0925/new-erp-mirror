<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Item;
use App\Models\Erp\ItemCategory;
use App\Models\Erp\Unit;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\ItemIntegratedFormApplicationService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemIntegratedFormController extends Controller
{
    public function show(int $id)
    {
        $item = Item::query()->with(['category', 'unit.standardUnit', 'activeMaterialPolicy'])->findOrFail($id);

        return response()->json([
            'item' => $item,
            'policy' => [
                'active' => $item->activeMaterialPolicy,
                'draft' => $item->materialPolicies()->where('status', 'draft')->latest('id')->first(),
            ],
            'balance' => $this->balanceSummary($item->id),
            'history' => $item->materialPolicies()->whereIn('status', ['active', 'historical'])->latest('version_no')->paginate(5),
        ]);
    }

    public function store(Request $request, ItemIntegratedFormApplicationService $service, AuthContextService $auth)
    {
        [$item, $policy, $activate] = $this->validated($request);
        $saved = $service->save(null, $item, $policy, $activate, $auth->currentLegacyId($request));
        return response()->json(['message' => $activate ? '物料与归属策略已启用' : '物料与归属策略草稿已保存', 'data' => $saved], 201);
    }

    public function update(Request $request, int $id, ItemIntegratedFormApplicationService $service, AuthContextService $auth)
    {
        [$item, $policy, $activate] = $this->validated($request, $id);
        $saved = $service->save(Item::query()->findOrFail($id), $item, $policy, $activate, $auth->currentLegacyId($request));
        return response()->json(['message' => $activate ? '物料与归属策略已启用' : '物料与归属策略草稿已保存', 'data' => $saved]);
    }

    private function validated(Request $request, ?int $id = null): array
    {
        $item = $request->validate(['item' => 'required|array'])['item'];
        $policy = $request->validate(['policy' => 'required|array'])['policy'];
        $activate = $request->boolean('activate');
        $itemRules = [
            'item.item_code' => ['required', 'string', 'max:120', Rule::unique('erp_items', 'item_code')->ignore($id)],
            'item.item_name' => 'required|string|max:160',
            'item.item_type' => 'required|in:finished_product,semi_finished,raw_material,packaging,service,office_consumable',
            'item.category_id' => 'required|integer|exists:erp_item_categories,id',
            'item.spec' => 'nullable|string|max:255',
            'item.unit_id' => 'required|integer|exists:erp_units,id',
            'item.is_purchase_item' => 'boolean', 'item.is_stock_item' => 'boolean', 'item.is_production_item' => 'boolean',
            'item.serial_tracking_mode' => 'nullable|in:none,optional,required',
            'item.serial_number_prefix' => 'nullable|string|max:30|regex:/^[A-Za-z0-9_-]+$/',
            'item.cost_method' => 'required|in:weighted_average,standard,fifo',
            'item.status' => 'required|in:enabled,disabled', 'item.remark' => 'nullable|string|max:200',
            'item.reservation_token' => 'nullable|uuid', 'item.creation_session_id' => 'nullable|uuid',
        ];
        $policyRules = [
            'policy.template_code' => 'nullable|string|max:60', 'policy.is_stock_managed' => 'required|boolean',
            'policy.inventory_management_mode' => 'required|in:standard,none', 'policy.requires_custodian' => 'required|boolean',
            'policy.is_returnable' => 'required|boolean', 'policy.requires_capitalization' => 'required|boolean',
            'policy.serial_tracking_mode' => 'required|in:none,optional,required',
            'policy.post_purchase_action' => 'required|in:inventory_receipt,issue_confirmation,asset_acceptance,expense_confirmation,work_order_cost,sales_order_direct_cost',
            'policy.consumption_confirmation_mode' => 'required|in:none,issue,asset_acceptance,service_acceptance',
            'policy.future_route' => 'required|in:inventory,expense,asset,direct_expense,work_order_cost,sales_order_direct_cost',
            'policy.future_bearer_type' => 'required|in:company,department,employee,work_order,sales_order',
            'policy.change_reason' => 'nullable|string|max:200', 'policy.remark' => 'nullable|string|max:200',
        ];
        $request->validate($itemRules + $policyRules);

        $category = ItemCategory::query()->whereKey($item['category_id'])->where('category_type', 'item')->where('status', 'enabled')->first();
        abort_unless($category && !$category->children()->where('category_type', 'item')->exists(), 422, '请选择启用的末级 Item 类目。');
        abort_unless(Unit::query()->whereKey($item['unit_id'])->where('status', 'enabled')->where('is_legacy', false)->exists(), 422, '请选择启用的标准库存基本单位。');
        abort_if(($policy['is_returnable'] ?? false) && !($policy['requires_custodian'] ?? false), 422, '可归还物资必须同时启用责任人管理。');

        return [$item, $policy, $activate];
    }

    private function balanceSummary(int $itemId): array
    {
        $summary = InventoryBalance::query()->where('item_id', $itemId)->selectRaw('COALESCE(SUM(quantity_on_hand), 0) quantity_on_hand, COALESCE(SUM(quantity_locked), 0) quantity_locked, COALESCE(SUM(quantity_defective), 0) quantity_defective, COALESCE(SUM(quantity_pending), 0) quantity_pending, COALESCE(SUM(quantity_available), 0) quantity_available, COUNT(DISTINCT warehouse_id) warehouse_count, MAX(last_transaction_at) last_transaction_at')->first();
        return [
            'quantity_on_hand' => (float) ($summary->quantity_on_hand ?? 0), 'quantity_locked' => (float) ($summary->quantity_locked ?? 0),
            'quantity_defective' => (float) ($summary->quantity_defective ?? 0), 'quantity_pending' => (float) ($summary->quantity_pending ?? 0),
            'quantity_available' => (float) ($summary->quantity_available ?? 0), 'warehouse_count' => (int) ($summary->warehouse_count ?? 0),
            'last_transaction_at' => $summary->last_transaction_at,
        ];
    }
}
