<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{Item, ItemPurchaseConversion};
use App\Services\Erp\{AuthContextService, ItemPurchaseConversionApplicationService, UnitConversionDomainService};
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ItemPurchaseConversionController extends Controller
{
    public function index(Request $request, int $itemId)
    {
        Item::findOrFail($itemId);
        $query = ItemPurchaseConversion::with(['purchaseUnit.standardUnit', 'baseUnit.standardUnit'])->where('item_id', $itemId)->latest('effective_from');
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        return response()->json($query->paginate(min(100, max(5, $request->integer('per_page', 10)))));
    }

    public function options(Request $request, int $itemId, UnitConversionDomainService $domain)
    {
        Item::findOrFail($itemId);
        return response()->json($domain->activePurchaseConversions($itemId)
            ->with(['purchaseUnit.standardUnit', 'baseUnit.standardUnit'])
            ->orderByDesc('is_default')
            ->paginate(min(100, max(5, $request->integer('per_page', 20)))));
    }

    public function store(Request $request, int $itemId, ItemPurchaseConversionApplicationService $service)
    {
        $data = $this->validated($request);
        $record = $service->save($itemId, $data, null, $this->operatorId($request), $this->operatorName($request));
        return response()->json(['message' => '采购换算关系已保存并生效。', 'data' => $record], 201);
    }

    public function update(Request $request, int $itemId, int $id, ItemPurchaseConversionApplicationService $service)
    {
        $data = $this->validated($request);
        $record = $service->save($itemId, $data, $id, $this->operatorId($request), $this->operatorName($request));
        return response()->json(['message' => '采购换算新版本已保存，原关系已转入历史。', 'data' => $record]);
    }

    public function disable(Request $request, int $itemId, int $id, ItemPurchaseConversionApplicationService $service)
    {
        $data = $request->validate(['change_reason' => 'required|string|max:80']);
        $record = $service->disable($itemId, $id, $data['change_reason'], $this->operatorId($request), $this->operatorName($request));
        return response()->json(['message' => '采购换算关系已停用，历史单据快照不受影响。', 'data' => $record]);
    }

    private function validated(Request $request): array
    {
        return $request->validate([
            'purchase_unit_id' => ['required', 'integer', Rule::exists('erp_units', 'id')->where(fn ($query) => $query->where('status', 'enabled')->where('is_legacy', false))],
            'factor' => 'required|numeric|gt:0',
            'is_default' => 'required|boolean',
            'allow_actual_conversion' => 'required|boolean',
            'effective_from' => 'nullable|date',
            'effective_to' => 'nullable|date',
            'change_reason' => 'required|string|max:80',
            'remark' => 'nullable|string|max:1000',
        ]);
    }

    private function operatorId(Request $request): ?int
    {
        return app(AuthContextService::class)->currentUser($request)?->legacy_id;
    }

    private function operatorName(Request $request): string
    {
        $user = app(AuthContextService::class)->currentUser($request);
        return $user?->nickname ?: $user?->name ?: $user?->username ?: '系统';
    }
}
