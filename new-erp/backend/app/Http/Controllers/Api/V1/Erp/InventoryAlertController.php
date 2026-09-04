<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryAlert;
use App\Models\Erp\InventoryAlertHistory;
use App\Models\Erp\InventoryAlertPolicy;
use App\Models\Erp\Item;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\InventoryAlertApplicationService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;

class InventoryAlertController extends Controller
{
    public function index(Request $request)
    {
        $this->authorize($request, 'inventory.alert.view');
        $query = InventoryAlert::query()->with(['item.unit', 'warehouse', 'policy'])
            ->when($request->filled('keyword'), fn ($q) => $q->whereHas('item', fn ($items) => $items->where('item_code', 'like', '%'.$request->input('keyword').'%')->orWhere('item_name', 'like', '%'.$request->input('keyword').'%')))
            ->when($request->filled('category_id'), fn ($q) => $q->whereHas('item', fn ($items) => $items->where('category_id', $request->input('category_id'))))
            ->when($request->filled('warehouse_id'), fn ($q) => $q->where('warehouse_id', $request->input('warehouse_id')))
            ->when($request->filled('alert_status'), fn ($q) => $q->where('alert_status', $request->input('alert_status')))
            ->when($request->filled('is_active'), fn ($q) => $q->where('is_active', filter_var($request->input('is_active'), FILTER_VALIDATE_BOOLEAN)))
            ->orderByDesc('is_active')->orderByDesc('last_changed_at');
        $statsQuery = clone $query;
        $stats = [
            'active' => (clone $statsQuery)->where('is_active', true)->count(),
            'out' => (clone $statsQuery)->where('alert_status', 'out_of_stock')->count(),
            'low' => (clone $statsQuery)->where('alert_status', 'low_stock')->count(),
            'over' => (clone $statsQuery)->where('alert_status', 'over_stock')->count(),
        ];
        $page = $query->paginate($this->perPage($request));
        return response()->json(array_merge($page->toArray(), ['stats' => $stats]));
    }

    public function policy(Request $request, int $itemId)
    {
        $this->authorize($request, 'inventory.alert.view');
        $item = Item::query()->with(['unit', 'category', 'activeMaterialPolicy'])->findOrFail($itemId);
        return response()->json([
            'item' => $item,
            'policies' => InventoryAlertPolicy::query()->with('warehouse')->where('item_id', $itemId)->orderByDesc('warehouse_id')->get(),
            'balances' => InventoryAlert::query()->with('warehouse')->where('item_id', $itemId)->orderBy('warehouse_id')->get(),
        ]);
    }

    public function show(Request $request, int $id, InventoryAlertApplicationService $service)
    {
        $this->authorize($request, 'inventory.alert.view');
        $alert = InventoryAlert::query()
            ->with(['item.unit', 'item.category', 'warehouse', 'policy', 'purchaseRequest'])
            ->findOrFail($id);
        // Preserve raw audit rows, but expose a coherent status chain. Legacy
        // rows from before the unified evaluator that become same-state events
        // after interpretation are not displayed as a false state transition.
        $effective = collect();
        $previous = null;
        InventoryAlertHistory::query()->where('alert_id', $alert->id)->orderBy('occurred_at')->orderBy('id')->get()->each(function ($history) use ($alert, $service, &$previous, $effective) {
            $evaluation = $service->presentationForHistory($history, $alert);
            $old = $previous ?: ['status' => $history->old_status, 'severity' => $history->old_severity];
            if ($old['status'] !== $evaluation['status'] || $old['severity'] !== $evaluation['severity']) {
                $history->setAttribute('evaluation', $evaluation);
                $history->setAttribute('display_old_status', $old['status']);
                $history->setAttribute('display_old_severity', $old['severity']);
                $effective->push($history);
            }
            $previous = $evaluation;
        });
        $effective = $effective->sortByDesc('occurred_at')->values();
        $historyPage = max(1, (int) $request->input('history_page', 1));
        $historyPerPage = $this->perPage($request);
        $histories = new LengthAwarePaginator(
            $effective->forPage($historyPage, $historyPerPage)->values(),
            $effective->count(),
            $historyPerPage,
            $historyPage,
            ['path' => $request->url(), 'pageName' => 'history_page'],
        );

        return response()->json([
            'data' => $alert,
            'evaluation' => $service->presentationFor($alert),
            'histories' => $histories,
        ]);
    }

    public function savePolicy(Request $request, int $itemId, InventoryAlertApplicationService $service)
    {
        $user = $this->authorize($request, 'inventory.alert.configure');
        $data = $request->validate([
            'warehouse_id' => 'nullable|integer|exists:erp_warehouses,id',
            'enabled' => 'nullable|boolean',
            'min_stock' => 'nullable|numeric|min:0', 'safety_stock' => 'nullable|numeric|min:0',
            'max_stock' => 'nullable|numeric|min:0', 'suggested_replenishment_qty' => 'nullable|numeric|min:0',
        ]);
        return response()->json(['data' => $service->savePolicy($itemId, $data, (int) $user->legacy_id, false), 'message' => '库存预警草稿已保存。']);
    }

    public function activatePolicy(Request $request, int $itemId, InventoryAlertApplicationService $service)
    {
        $user = $this->authorize($request, 'inventory.alert.configure');
        $data = $request->validate([
            'warehouse_id' => 'nullable|integer|exists:erp_warehouses,id',
            'enabled' => 'nullable|boolean',
            'min_stock' => 'required|numeric|min:0', 'safety_stock' => 'required|numeric|min:0',
            'max_stock' => 'nullable|numeric|min:0', 'suggested_replenishment_qty' => 'required|numeric|min:0',
        ]);
        return response()->json(['data' => $service->savePolicy($itemId, $data, (int) $user->legacy_id, true), 'message' => '库存预警规则已启用。']);
    }

    public function disablePolicy(Request $request, int $itemId, InventoryAlertApplicationService $service)
    {
        $user = $this->authorize($request, 'inventory.alert.configure');
        $data = $request->validate(['warehouse_id' => 'nullable|integer|exists:erp_warehouses,id']);
        $policy = $service->disablePolicy($itemId, isset($data['warehouse_id']) ? (int) $data['warehouse_id'] : null, (int) $user->legacy_id);
        return response()->json(['data' => $policy, 'message' => '库存预警规则已停用，历史预警记录已保留。']);
    }

    public function unread(Request $request, InventoryAlertApplicationService $service)
    {
        $user = $this->authorize($request, 'inventory.alert.view');
        return response()->json($service->unreadForUser((int) $user->legacy_id, $this->perPage($request)));
    }

    public function markRead(Request $request, int $id, InventoryAlertApplicationService $service)
    {
        $this->authorize($request, 'inventory.alert.view');
        $alert = $service->markRead($id);
        return response()->json(['data' => $alert]);
    }

    public function createPurchaseRequest(Request $request, int $id, InventoryAlertApplicationService $service)
    {
        $user = $this->authorize($request, 'inventory.alert.create_request');
        $record = $service->createPurchaseRequestFromAlert($id, (int) $user->legacy_id);
        return response()->json(['message' => '已生成采购需求草稿，不会自动生成采购订单。', 'data' => $record]);
    }

    private function perPage(Request $request): int { return min(100, max(10, (int) $request->input('per_page', 20))); }
    private function authorize(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        abort_unless($user, 401, '登录已过期。');
        abort_unless($auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true), 403, '无按钮权限：'.$permission);
        return $user;
    }
}
