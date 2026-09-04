<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{InventoryAdjustment, InventoryTransaction, InventoryTransactionItem};
use Illuminate\Http\Request;

class InventoryTransactionController extends Controller
{
    public function index(Request $request)
    {
        if ($request->input('view') === 'lines') {
            return $this->lineIndex($request);
        }

        $itemId = $request->filled('item_id') ? $request->integer('item_id') : null;
        $keyword = $request->filled('keyword') ? trim((string) $request->input('keyword')) : null;
        $query = InventoryTransaction::with([
            'items' => function ($lineQuery) use ($itemId, $keyword) {
                if ($itemId) $lineQuery->where('item_id', $itemId);
                if ($keyword) $lineQuery->where(fn ($q) => $q->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%"));
                $lineQuery->with(['item.unit', 'warehouse', 'location']);
            },
            'warehouse',
            'location',
        ])->latest('posted_at')->latest();
        if ($request->filled('transaction_type')) $query->where('transaction_type', $request->input('transaction_type'));
        if ($request->filled('source_no')) $query->where('source_no', 'like', '%' . $request->input('source_no') . '%');
        if ($keyword) {
            $query->whereHas('items', fn ($q) => $q->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%"));
        }
        if ($itemId) $query->whereHas('items', fn ($q) => $q->where('item_id', $itemId));
        if ($request->filled('warehouse_id')) $query->whereHas('items', fn ($q) => $q->where('warehouse_id', $request->input('warehouse_id')));
        if ($request->filled('location_id')) $query->whereHas('items', fn ($q) => $q->where('location_id', $request->input('location_id')));
        if ($request->filled('batch_no')) $query->whereHas('items', fn ($q) => $q->where('batch_no', 'like', '%' . $request->input('batch_no') . '%'));
        if ($request->filled('date_from')) $query->whereDate('transaction_date', '>=', $request->input('date_from'));
        if ($request->filled('date_to')) $query->whereDate('transaction_date', '<=', $request->input('date_to'));
        return response()->json($query->paginate($this->perPage($request)));
    }

    private function lineIndex(Request $request)
    {
        $query = InventoryTransactionItem::query()
            ->with(['transaction', 'item.unit', 'warehouse', 'location'])
            ->whereHas('transaction', function ($transactionQuery) use ($request) {
                if ($request->filled('transaction_type')) $transactionQuery->where('transaction_type', $request->input('transaction_type'));
                if ($request->filled('source_no')) $transactionQuery->where('source_no', 'like', '%' . $request->input('source_no') . '%');
                if ($request->filled('date_from')) $transactionQuery->whereDate('transaction_date', '>=', $request->input('date_from'));
                if ($request->filled('date_to')) $transactionQuery->whereDate('transaction_date', '<=', $request->input('date_to'));
            })
            ->when($request->filled('item_id'), fn ($q) => $q->where('item_id', $request->integer('item_id')))
            ->when($request->filled('keyword'), function ($q) use ($request) {
                $keyword = trim((string) $request->input('keyword'));
                $q->where(fn ($lineQuery) => $lineQuery->where('item_code', 'like', "%{$keyword}%")->orWhere('item_name', 'like', "%{$keyword}%"));
            })
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->input('warehouse_id')))
            ->when($request->filled('location_id'), fn ($q) => $q->where('location_id', $request->input('location_id')))
            ->when($request->filled('batch_no'), fn ($q) => $q->where('batch_no', 'like', '%' . $request->input('batch_no') . '%'))
            ->orderByDesc('id');

        $statsQuery = clone $query;
        $stats = [
            'today_in_count' => (clone $statsQuery)->whereHas('transaction', fn ($q) => $q
                ->whereDate('transaction_date', now()->toDateString())
                ->where('transaction_type', 'purchase_receipt_posting'))->count(),
            'today_adjust_count' => (clone $statsQuery)->whereHas('transaction', fn ($q) => $q
                ->whereDate('transaction_date', now()->toDateString())
                ->where('transaction_type', 'manual_adjustment'))->count(),
            'pending_adjustment_count' => InventoryAdjustment::query()->where('adjustment_status', 'submitted')->count(),
            'reversed_count' => InventoryTransaction::query()->where('posting_status', 'reversed')->count(),
        ];
        $paginator = $query->paginate($this->perPage($request));
        return response()->json([...$paginator->toArray(), 'stats' => $stats]);
    }

    public function show(int $id)
    {
        return response()->json(InventoryTransaction::with(['items.item.unit', 'items.warehouse', 'items.location'])->findOrFail($id));
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 20)));
    }
}
