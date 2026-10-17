<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\InventoryAdjustment;
use App\Models\Erp\Item;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\InventoryAdjustmentApplicationService;
use App\Services\Erp\InventorySerialApplicationService;
use App\Services\Erp\InventoryService;
use Illuminate\Http\Request;

class InventoryAdjustmentController extends Controller
{
    public function reasons(Request $request)
    {
        $this->authorizePermission($request, 'inventory.adjustment.view');
        return response()->json(collect(InventoryAdjustmentApplicationService::REASONS)
            ->map(fn (string $label, string $value) => ['value' => $value, 'label' => $label])->values());
    }

    public function index(Request $request)
    {
        $this->authorizePermission($request, 'inventory.adjustment.view');
        $query = InventoryAdjustment::with(['items.item.unit', 'items.warehouse', 'items.location', 'items.serials', 'items.physicalEntries'])->latest('updated_at');
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
        $this->attachOperatorNames($paginator->getCollection());
        return response()->json([...$paginator->toArray(), 'stats' => $stats]);
    }

    public function store(Request $request, InventoryAdjustmentApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'inventory.adjustment.create');
        $payload = $this->validatePayload($request);
        return response()->json(['message' => '调整单已保存', 'data' => $service->save($payload, null, (int) $user->legacy_id)], 201);
    }

    public function generateSerials(Request $request, InventorySerialApplicationService $serials)
    {
        $this->authorizePermission($request, 'inventory.adjustment.create');
        $data = $request->validate([
            'item_id' => 'required|exists:erp_items,id',
            'quantity' => 'required|integer|min:1|max:500',
        ]);

        return response()->json([
            'data' => $serials->generateForItem(Item::findOrFail($data['item_id']), (int) $data['quantity']),
        ]);
    }

    public function show(Request $request, int $id)
    {
        $this->authorizePermission($request, 'inventory.adjustment.view');
        return response()->json(InventoryAdjustment::with(['items.item.unit', 'items.warehouse', 'items.location', 'items.serials', 'items.physicalEntries'])->findOrFail($id));
    }

    public function update(Request $request, int $id, InventoryAdjustmentApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'inventory.adjustment.create');
        $payload = $this->validatePayload($request);
        return response()->json(['message' => '调整单已更新', 'data' => $service->save($payload, $id, (int) $user->legacy_id)]);
    }

    public function submit(Request $request, int $id, InventoryAdjustmentApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'inventory.adjustment.create');
        return response()->json(['message' => '调整单已提交', 'data' => $service->submit($id, (int) $user->legacy_id)]);
    }

    public function post(Request $request, int $id, InventoryService $service)
    {
        // 过账直接改变库存余额，必须单独授权，不能只凭能建调整单就过账。
        $user = $this->authorizePermission($request, 'inventory.adjustment.confirm');
        $transaction = $service->postAdjustment($id, (int) $user->legacy_id);
        return response()->json(['message' => '调整已过账', 'data' => $transaction]);
    }

    public function cancel(Request $request, int $id, InventoryAdjustmentApplicationService $service)
    {
        $user = $this->authorizeAnyPermission($request, ['inventory.adjustment.create', 'inventory.adjustment.confirm']);
        return response()->json(['message' => '调整单已取消', 'data' => $service->cancel($id, (int) $user->legacy_id)]);
    }

    public function destroy(Request $request, int $id, InventoryAdjustmentApplicationService $service)
    {
        $this->authorizePermission($request, 'inventory.adjustment.delete');
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
            'items.*.physical_entries' => 'nullable|array|max:500',
            'items.*.physical_entries.*.physical_material_id' => 'nullable|integer|distinct|exists:erp_material_physicals,id',
            'items.*.physical_entries.*.dimensions' => 'nullable|array',
            'items.*.physical_entries.*.dimensions.length_mm' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'items.*.physical_entries.*.dimensions.width_mm' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'items.*.physical_entries.*.dimensions.thickness_mm' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'items.*.physical_entries.*.dimensions.nominal_thickness_mm' => ['nullable', 'regex:/^\d{1,10}(?:\.\d{1,2})?$/'],
            'items.*.physical_entries.*.total_cost' => ['nullable', 'regex:/^\d{1,14}(?:\.\d{1,4})?$/'],
        ]);
    }

    private function perPage(Request $request): int
    {
        return min(100, max(10, (int) $request->input('per_page', 10)));
    }

    // 列表与详情展示制单、提交、过账、取消人的姓名，便于追溯“谁改了库存”。
    private function attachOperatorNames($adjustments): void
    {
        $ids = collect($adjustments)->flatMap(fn ($row) => [$row->created_by, $row->submitted_by, $row->posted_by, $row->cancelled_by])->filter()->unique()->values();
        $names = $ids->isEmpty() ? collect() : \Illuminate\Support\Facades\DB::table('erp_legacy_admin_users')->whereIn('legacy_id', $ids)
            ->get(['legacy_id', 'nickname', 'username'])->mapWithKeys(fn ($user) => [(int) $user->legacy_id => $user->nickname ?: $user->username]);
        foreach ($adjustments as $row) {
            foreach (['created_by', 'submitted_by', 'posted_by', 'cancelled_by'] as $field) {
                $row->setAttribute($field.'_name', $row->{$field} ? ($names[(int) $row->{$field}] ?? null) : null);
            }
        }
    }

    private function authorizeAnyPermission(Request $request, array $permissions): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless(
            $auth->isSuperAdmin($user) || array_intersect($permissions, $auth->permissionCodes($user)),
            403,
            '无按钮权限：'.implode(' 或 ', $permissions),
        );

        return $user;
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class);
        $user = $auth->currentUser($request);
        abort_unless($user, 401, '未登录或登录已过期。');
        abort_unless(
            $auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true),
            403,
            '无按钮权限：'.$permission,
        );

        return $user;
    }
}
