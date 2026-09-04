<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryAdjustment;
use App\Models\Erp\Item;
use App\Services\Erp\InventoryAdjustmentApplicationService;
use App\Services\Erp\InventorySerialApplicationService;
use App\Services\Erp\InventoryService;
use Illuminate\Http\Request;

class InventoryAdjustmentController extends Controller
{
    public function reasons()
    {
        return response()->json([
            ['value' => 'stocktaking_difference', 'label' => '盘点差异'],
            ['value' => 'quality_recheck', 'label' => '质检复核修正'],
            ['value' => 'warehouse_correction', 'label' => '仓储录入修正'],
            ['value' => 'system_correction', 'label' => '系统数据修正'],
            ['value' => 'other', 'label' => '其他'],
        ]);
    }

    public function index(Request $request)
    {
        $query = InventoryAdjustment::with(['items.item.unit', 'items.warehouse', 'items.location', 'items.serials'])->latest('updated_at');
        if ($request->filled('adjustment_no')) $query->where('adjustment_no', 'like', '%' . $request->input('adjustment_no') . '%');
        if ($request->filled('reason')) $query->where('reason', $request->input('reason'));
        if ($request->filled('keyword')) {
            $keyword = $request->input('keyword');
            $query->whereHas('items.item', fn ($q) => $q->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%"));
        }
        if ($request->filled('date_from')) $query->whereDate('adjustment_date', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $query->whereDate('adjustment_date', '<=', $request->input('date_to'));
        $statsQuery = clone $query;
        $stats = [
            'posted_count' => (clone $statsQuery)->where('adjustment_status', 'posted')->count(),
            'submitted_count' => (clone $statsQuery)->where('adjustment_status', 'submitted')->count(),
            'draft_count' => (clone $statsQuery)->where('adjustment_status', 'draft')->count(),
        ];
        if ($request->filled('status')) $query->where('adjustment_status', $request->input('status'));
        $paginator = $query->paginate($this->perPage($request));
        return response()->json([...$paginator->toArray(), 'stats' => $stats]);
    }

    public function store(Request $request, InventoryAdjustmentApplicationService $service)
    {
        $payload = $this->validatePayload($request);
        return response()->json(['message' => '调整单已保存', 'data' => $service->save($payload)], 201);
    }

    public function generateSerials(Request $request, InventorySerialApplicationService $serials)
    {
        $data = $request->validate([
            'item_id' => 'required|exists:erp_items,id',
            'quantity' => 'required|integer|min:1|max:500',
        ]);

        return response()->json([
            'data' => $serials->generateForItem(Item::findOrFail($data['item_id']), (int) $data['quantity']),
        ]);
    }

    public function show(int $id)
    {
        return response()->json(InventoryAdjustment::with(['items.item.unit', 'items.warehouse', 'items.location', 'items.serials'])->findOrFail($id));
    }

    public function update(Request $request, int $id, InventoryAdjustmentApplicationService $service)
    {
        $payload = $this->validatePayload($request);
        return response()->json(['message' => '调整单已更新', 'data' => $service->save($payload, $id)]);
    }

    public function submit(int $id, InventoryAdjustmentApplicationService $service)
    {
        return response()->json(['message' => '调整单已提交', 'data' => $service->submit($id)]);
    }

    public function post(int $id, InventoryService $service)
    {
        $transaction = $service->postAdjustment($id);
        return response()->json(['message' => '调整已过账', 'data' => $transaction]);
    }

    public function cancel(int $id, InventoryAdjustmentApplicationService $service)
    {
        return response()->json(['message' => '调整单已取消', 'data' => $service->cancel($id)]);
    }

    public function destroy(int $id, InventoryAdjustmentApplicationService $service)
    {
        $service->deleteDraft($id);
        return response()->json(['message' => '草稿调整单已删除']);
    }

    private function validatePayload(Request $request): array
    {
        return $request->validate([
            'adjustment_no' => 'nullable|string|max:80',
            'adjustment_date' => 'nullable|date',
            'reason' => 'required|string|max:160',
            'remark' => 'nullable|string',
            'items' => 'required|array|min:1',
            'items.*.item_id' => 'required|exists:erp_items,id',
            'items.*.warehouse_id' => 'required|exists:erp_warehouses,id',
            'items.*.location_id' => 'required|exists:erp_locations,id',
            'items.*.batch_no' => 'required|string|max:80',
            'items.*.unit_id' => 'nullable|exists:erp_units,id',
            'items.*.change_qty' => 'required|numeric|not_in:0',
            'items.*.remark' => 'nullable|string',
            'items.*.serial_entries' => 'nullable|array|max:500',
            'items.*.serial_entries.*.serial_no' => 'required|string|max:120',
            'items.*.serial_entries.*.source' => 'nullable|in:manual,system,supplier',
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 20)));
    }
}
