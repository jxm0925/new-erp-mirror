<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\ItemSupplierPrice;
use App\Models\Erp\PurchasePriceHistory;
use App\Models\Erp\Supplier;
use App\Models\Erp\SupplierCategoryCapability;
use App\Models\Erp\SupplierItemRelation;
use App\Models\Erp\SupplierItemRelationLog;
use App\Models\Erp\SupplierQuotationHistory;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\SupplierCapabilityService;
use App\Services\Erp\SupplierQuotationService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SupplierCapabilityController extends Controller
{
    public function summary(Request $request, int $supplierId)
    {
        $this->authorizePermission($request, 'supplier_capability.view');
        $supplier = Supplier::with([
            'categoryCapabilities' => fn ($q) => $q->where('status', 'active')->with('category'),
        ])->withCount([
            'itemRelations as active_item_relation_count' => fn ($q) => $q->where('relation_status', 'active'),
            'quotations as enabled_quotation_count' => fn ($q) => $q->where('status', 'enabled'),
        ])->findOrFail($supplierId);

        return response()->json(['data' => $supplier]);
    }

    public function syncCategories(
        Request $request,
        int $supplierId,
        SupplierCapabilityService $service
    ) {
        $this->authorizePermission($request, 'supplier_capability.edit');
        $data = $request->validate([
            'category_ids' => 'array',
            'category_ids.*' => 'integer|exists:erp_item_categories,id',
            'remark' => 'nullable|string|max:1000',
        ]);
        $service->syncCategories($supplierId, $data['category_ids'] ?? [], $this->operatorId($request), $data['remark'] ?? null);

        return response()->json([
            'message' => '可供Item类目已保存。',
            'data' => SupplierCategoryCapability::with('category')
                ->where('supplier_id', $supplierId)->where('status', 'active')->get(),
        ]);
    }

    public function itemRelations(Request $request, int $supplierId)
    {
        $this->authorizePermission($request, 'supplier_capability.view');
        Supplier::findOrFail($supplierId);
        $query = SupplierItemRelation::with(['item.category', 'item.unit'])
            ->where('supplier_id', $supplierId)
            ->latest('updated_at');
        if ($request->filled('status')) $query->where('relation_status', $request->input('status'));
        if ($request->filled('source')) $query->where('capability_source', $request->input('source'));
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->whereHas('item', fn ($items) => $items
                ->where('item_code', 'like', "%{$keyword}%")
                ->orWhere('item_name', 'like', "%{$keyword}%"));
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function storeItemRelation(
        Request $request,
        int $supplierId,
        SupplierCapabilityService $service
    ) {
        $this->authorizePermission($request, 'supplier_capability.edit');
        $data = $request->validate([
            'item_id' => 'required|exists:erp_items,id',
            'capability_source' => ['required', Rule::in(SupplierCapabilityService::SOURCES)],
            'relation_status' => ['nullable', Rule::in(['active', 'inactive'])],
            'is_default' => 'nullable|boolean',
            'effective_at' => 'nullable|date',
            'expired_at' => 'nullable|date|after:effective_at',
            'change_reason' => 'required|string|max:100',
            'remark' => 'nullable|string|max:1000',
        ]);
        abort_if($data['capability_source'] === 'category_candidate', 422, '品类候选不能直接保存为正式 Item 供货关系。');

        return response()->json([
            'message' => '供应商 Item 关系已保存。',
            'data' => $service->saveItemRelation($supplierId, $data, $this->operatorId($request)),
        ], 201);
    }

    public function disableItemRelation(
        Request $request,
        int $supplierId,
        int $relationId,
        SupplierCapabilityService $service
    ) {
        $this->authorizePermission($request, 'supplier_capability.edit');
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $relation = SupplierItemRelation::where('supplier_id', $supplierId)->findOrFail($relationId);

        return response()->json([
            'message' => '供应商 Item 关系已停用，历史记录已保留。',
            'data' => $service->disableRelation($relation, $data['reason'], $this->operatorId($request)),
        ]);
    }

    public function quotations(Request $request, int $supplierId)
    {
        $this->authorizePermission($request, 'supplier_quotation.view');
        Supplier::findOrFail($supplierId);
        $query = ItemSupplierPrice::with(['item.unit', 'unit'])
            ->where('supplier_id', $supplierId)
            ->latest('updated_at');
        if ($request->filled('status')) $query->where('status', $request->input('status'));
        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->whereHas('item', fn ($items) => $items
                ->where('item_code', 'like', "%{$keyword}%")
                ->orWhere('item_name', 'like', "%{$keyword}%"));
        }

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function storeQuotation(
        Request $request,
        int $supplierId,
        SupplierQuotationService $quotationService,
        SupplierCapabilityService $capabilityService
    ) {
        $this->authorizePermission($request, 'supplier_quotation.edit');
        if ($request->filled('max_order_qty') && (float) $request->input('max_order_qty') <= 0) {
            $request->merge(['max_order_qty' => null]);
        }
        $data = $request->validate([
            'item_id' => 'required|exists:erp_items,id',
            'unit_id' => 'required|exists:erp_units,id',
            'price' => 'required|numeric|gt:0',
            'currency' => 'required|string|max:10',
            'tax_mode' => ['required', Rule::in(['tax_included', 'tax_excluded'])],
            'tax_rate' => 'required|numeric|min:0|max:100',
            'lead_time_days' => 'nullable|integer|min:0',
            'min_order_qty' => 'required|numeric|min:0',
            'max_order_qty' => 'nullable|numeric|gte:min_order_qty',
            'valid_from' => 'required|date',
            'valid_until' => 'nullable|date|after_or_equal:valid_from',
            'tier_prices' => 'nullable|array',
            'remark' => 'nullable|string|max:1000',
            'change_reason' => 'nullable|string|max:100',
        ]);
        $data['supplier_id'] = $supplierId;

        $quote = DB::transaction(function () use ($data, $quotationService, $capabilityService, $request, $supplierId) {
            $quote = $quotationService->saveQuote($data, $this->operatorId($request));
            $capabilityService->saveItemRelation($supplierId, [
                'item_id' => $data['item_id'],
                'capability_source' => 'quotation',
                'relation_status' => 'active',
                'effective_at' => $data['valid_from'],
                'expired_at' => $data['valid_until'] ?? null,
                'change_reason' => '有效报价形成具体 Item 供货关系',
                'remark' => $data['remark'] ?? null,
            ], $this->operatorId($request));

            return $quote;
        });

        return response()->json(['message' => '供应商报价已保存并形成具体 Item 能力关系。', 'data' => $quote], 201);
    }

    public function purchaseHistory(Request $request, int $supplierId)
    {
        $this->authorizePermission($request, 'supplier_capability.view');
        Supplier::findOrFail($supplierId);
        $query = PurchasePriceHistory::with(['item.unit', 'unit'])
            ->where('supplier_id', $supplierId)
            ->latest('effective_date');
        if ($request->filled('item_id')) $query->where('item_id', $request->integer('item_id'));

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function disableQuotation(
        Request $request,
        int $supplierId,
        int $quotationId,
        SupplierQuotationService $service
    ) {
        $this->authorizePermission($request, 'supplier_quotation.edit');
        $data = $request->validate(['reason' => 'required|string|max:1000']);

        return response()->json([
            'message' => '供应商报价已停用，报价历史已保留。',
            'data' => $service->disableQuote($supplierId, $quotationId, $data['reason'], $this->operatorId($request)),
        ]);
    }

    public function relationHistory(Request $request, int $supplierId)
    {
        $this->authorizePermission($request, 'supplier_capability.history');
        Supplier::findOrFail($supplierId);
        $query = SupplierItemRelationLog::with('item')
            ->where('supplier_id', $supplierId)
            ->latest('created_at');
        if ($request->filled('item_id')) $query->where('item_id', $request->integer('item_id'));

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function quotationHistory(Request $request, int $supplierId)
    {
        $this->authorizePermission($request, 'supplier_quotation.view');
        Supplier::findOrFail($supplierId);
        $query = SupplierQuotationHistory::with('item')
            ->where('supplier_id', $supplierId)
            ->latest('created_at');
        if ($request->filled('item_id')) $query->where('item_id', $request->integer('item_id'));

        return response()->json($query->paginate($this->perPage($request)));
    }

    private function perPage(Request $request): int
    {
        return min(100, max(5, $request->integer('per_page', 20)));
    }

    private function operatorId(Request $request): ?int
    {
        return app(AuthContextService::class)->currentUser($request)?->legacy_id;
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
