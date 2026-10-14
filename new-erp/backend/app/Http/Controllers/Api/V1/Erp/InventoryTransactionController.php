<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Concerns\AuthorizesErpPermissions;
use App\Models\Erp\{InventoryAdjustment, InventoryTransaction, InventoryTransactionItem};
use Illuminate\Http\Request;

class InventoryTransactionController extends Controller
{
    use AuthorizesErpPermissions;

    public function index(Request $request)
    {
        $this->erpAuthorize($request, 'inventory.transaction.view');
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
                ->whereIn('transaction_type', ['purchase_receipt_posting', 'purchase_receipt_quality_release']))->count(),
            'today_adjust_count' => (clone $statsQuery)->whereHas('transaction', fn ($q) => $q
                ->whereDate('transaction_date', now()->toDateString())
                ->where('transaction_type', 'manual_adjustment'))->count(),
            'pending_adjustment_count' => InventoryAdjustment::query()->where('adjustment_status', 'submitted')->count(),
            'reversed_count' => InventoryTransaction::query()->where('posting_status', 'reversed')->count(),
            'today_out_count' => (clone $statsQuery)->where('change_qty', '<', 0)->whereHas('transaction', fn ($q) => $q
                ->whereDate('transaction_date', now()->toDateString()))->count(),
        ];
        $paginator = $query->paginate($this->perPage($request));
        // 流水行展示过账人姓名；历史流水未记录操作人时前端显示“未记录”。
        $ids = $paginator->getCollection()->map(fn ($line) => $line->transaction?->posted_by)->filter()->unique()->values();
        $names = $ids->isEmpty() ? collect() : \Illuminate\Support\Facades\DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)
            ->get(['legacy_id', 'nickname', 'username'])->mapWithKeys(fn ($user) => [(int) $user->legacy_id => $user->nickname ?: $user->username]);
        $paginator->getCollection()->each(fn ($line) => $line->setAttribute('posted_by_name', $line->transaction?->posted_by ? ($names[(int) $line->transaction->posted_by] ?? null) : null));
        return response()->json([...$paginator->toArray(), 'stats' => $stats]);
    }

    public function show(Request $request, int $id)
    {
        $this->erpAuthorize($request, 'inventory.transaction.view');
        return response()->json(InventoryTransaction::with(['items.item.unit', 'items.warehouse', 'items.location'])->findOrFail($id));
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 20)));
    }
}
