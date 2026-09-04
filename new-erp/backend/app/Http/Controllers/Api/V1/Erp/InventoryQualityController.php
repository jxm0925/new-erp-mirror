<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryQualityEvent;
use App\Models\Erp\InventoryTransactionItem;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\InventoryQualityApplicationService;
use Illuminate\Http\Request;

class InventoryQualityController extends Controller
{
    public function index(Request $request)
    {
        $query = InventoryQualityEvent::query()
            ->with(['item.unit', 'warehouse', 'location', 'receipt.order', 'supplier'])
            ->latest('created_at');

        if ($request->filled('keyword')) {
            $keyword = trim((string) $request->input('keyword'));
            $query->where(fn ($q) => $q->where('event_no', 'like', "%{$keyword}%")
                ->orWhere('serial_no', 'like', "%{$keyword}%")
                ->orWhere('batch_no', 'like', "%{$keyword}%")
                ->orWhereHas('item', fn ($item) => $item->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%")));
        }
        if ($request->filled('event_status')) $query->where('event_status', $request->input('event_status'));
        if ($request->filled('handling_method')) $query->where('handling_method', $request->input('handling_method'));

        return response()->json($query->paginate($this->perPage($request)));
    }

    public function context(int $balanceId)
    {
        $balance = InventoryBalance::query()
            ->with(['item.unit', 'warehouse', 'location', 'serials' => fn ($q) => $q->where('serial_status', 'available')->orderBy('serial_no')])
            ->findOrFail($balanceId);
        $sourceQuery = InventoryTransactionItem::query()
            ->with(['purchaseReceiptItem.receipt.order', 'purchaseReceiptItem.receipt.supplier'])
            ->where('source_type', 'purchase_receipt')
            ->where('item_id', $balance->item_id)
            ->where('batch_no', $balance->batch_no)
            ->where('change_qty', '>', 0);
        $source = (clone $sourceQuery)
            ->where('warehouse_id', $balance->warehouse_id)
            ->where('location_id', $balance->location_id)
            ->latest('id')->first();
        $source ??= $sourceQuery->latest('id')->first();
        $receipt = $source?->purchaseReceiptItem?->receipt;

        return response()->json([
            'balance' => $balance,
            'source' => [
                'receipt_id' => $receipt?->id,
                'receipt_no' => $receipt?->receipt_no,
                'purchase_order_id' => $receipt?->order_id,
                'purchase_order_no' => $receipt?->order?->purchase_order_no,
                'supplier_id' => $receipt?->supplier_id,
                'supplier_name' => $receipt?->supplier?->supplier_name,
                'source_receipt_item_id' => $source?->source_item_id,
            ],
            'active_events' => InventoryQualityEvent::query()
                ->with('logs')
                ->where('inventory_balance_id', $balance->id)
                ->whereNotIn('event_status', ['completed', 'cancelled'])
                ->latest('created_at')
                ->get(),
        ]);
    }

    public function show(int $id)
    {
        return response()->json(InventoryQualityEvent::with([
            'item.unit', 'warehouse', 'location', 'serial', 'receipt.order', 'supplier', 'logs',
        ])->findOrFail($id));
    }

    public function store(Request $request, InventoryQualityApplicationService $service)
    {
        $payload = $request->validate([
            'inventory_balance_id' => 'required|exists:erp_inventory_balances,id',
            'serial_no' => 'nullable|string|max:120',
            'issue_qty' => 'required|numeric|min:0.00000001',
            'issue_category' => 'required|in:function_failure,appearance_damage,performance_abnormal,missing_parts,other',
            'issue_description' => 'required|string|max:1000',
            'handling_method' => 'required|in:return_supplier,exchange',
            'responsible_party' => 'required|in:supplier,warehouse,logistics,internal,undetermined',
            'attachments' => 'nullable|array',
            'attachments.*.name' => 'required_with:attachments|string|max:255',
            'attachments.*.url' => 'required_with:attachments|string|max:1000',
            'remark' => 'nullable|string|max:1000',
        ]);
        $user = app(AuthContextService::class)->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');

        $event = $service->create(
            $payload,
            $user?->legacy_id,
            $user?->nickname ?: $user?->username,
        );

        return response()->json([
            'message' => '库存质量事件已创建，问题库存已冻结并生成对应处理单。',
            'data' => $event,
        ], 201);
    }

    public function start(Request $request, int $id, InventoryQualityApplicationService $service)
    {
        [$operatorId, $operatorName] = $this->operator($request);
        return response()->json([
            'message' => '质量处理已开始，问题库存继续保持冻结。',
            'data' => $service->start($id, $operatorId, $operatorName),
        ]);
    }

    public function complete(Request $request, int $id, InventoryQualityApplicationService $service)
    {
        $payload = $request->validate(['result_description' => 'required|string|max:1000']);
        [$operatorId, $operatorName] = $this->operator($request);
        return response()->json([
            'message' => '质量处理已完成并复检合格，对应库存已恢复可用。',
            'data' => $service->complete($id, $payload, $operatorId, $operatorName),
        ]);
    }

    private function operator(Request $request): array
    {
        $user = app(AuthContextService::class)->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        return [$user?->legacy_id, $user?->nickname ?: $user?->username];
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 20)));
    }
}
