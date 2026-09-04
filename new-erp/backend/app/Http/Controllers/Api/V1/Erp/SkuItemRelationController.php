<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{Sku, SkuItemRelation, SkuItemRelationLog};
use App\Services\Erp\SkuItemDefaultRelationService;
use App\Services\Erp\SkuItemRelationAuditService;
use App\Services\Erp\AuthContextService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SkuItemRelationController extends Controller
{
    private const TYPES = ['finished_product', 'sales_bundle_item', 'shipping_accessory', 'packaging', 'service_none'];

    /** Stage 4 list: one row per SKU; never exposes legacy mapping/role concepts. */
    public function defaultRelationIndex(Request $request, SkuItemRelationAuditService $audit)
    {
        $this->authorizePermission($request, 'sku_item_relation.view');
        $query = Sku::with(['product', 'itemRelations' => fn ($q) => $q->where('status', 'active')->where('is_primary', true)->with('item.unit')]);
        if ($request->filled('product_id')) $query->where('product_id', $request->integer('product_id'));
        if ($request->filled('sku_keyword')) $query->where(fn ($q) => $q->where('sku_code', 'like', '%'.$request->sku_keyword.'%')->orWhere('sku_name', 'like', '%'.$request->sku_keyword.'%'));
        if ($request->filled('line_type')) $query->where('order_line_type', $request->line_type);
        if ($request->filled('sku_status')) $query->where('status', $request->sku_status);
        if ($request->filled('item_keyword')) $query->whereHas('itemRelations', function ($q) use ($request) {
            $q->where('status', 'active')->where('is_primary', true)->whereHas('item', fn ($items) => $items->where('item_code', 'like', '%'.$request->item_keyword.'%')->orWhere('item_name', 'like', '%'.$request->item_keyword.'%'));
        });
        if ($request->filled('item_status')) $query->whereHas('itemRelations', function ($q) use ($request) {
            $q->where('status', 'active')->where('is_primary', true)->whereHas('item', fn ($items) => $items->where('status', $request->item_status));
        });
        return response()->json($this->paginateAuditRows($query, $request, $audit, $request->relation_status));
    }

    public function showDefaultRelation(int $skuId, SkuItemRelationAuditService $audit)
    {
        $this->authorizePermission(request(), 'sku_item_relation.view');
        $sku = Sku::with(['product', 'salesUnit', 'itemRelations' => fn ($q) => $q->with('item.unit')->orderByDesc('effective_at')])->findOrFail($skuId);
        return response()->json(['data' => ['sku' => $sku, 'audit' => $audit->inspect($sku)]]);
    }

    public function relationHistory(int $skuId)
    {
        $this->authorizePermission(request(), 'sku_item_relation.history');
        Sku::findOrFail($skuId);
        return response()->json(['data' => [
            'relations' => SkuItemRelation::with('item.unit')->where('sku_id', $skuId)->orderByDesc('effective_at')->get(),
            'logs' => SkuItemRelationLog::with(['oldItem','newItem'])->where('sku_id', $skuId)->latest('created_at')->get(),
        ]]);
    }

    public function setDefaultItem(Request $request, int $skuId, SkuItemDefaultRelationService $service)
    {
        $hasCurrent = SkuItemRelation::where('sku_id', $skuId)->where('status', 'active')->where('is_primary', true)->exists();
        $this->authorizePermission($request, $hasCurrent ? 'sku_item_relation.change' : 'sku_item_relation.set');
        $data = $request->validate(['item_id' => 'required|exists:erp_items,id', 'factor' => 'required|numeric|gt:0', 'change_reason' => 'required|string|max:80', 'remark' => 'nullable|string|max:1000']);
        $relation = $service->setPrimary($skuId, $data['item_id'], $data['change_reason'], $data['remark'] ?? null, $this->operatorId($request), $this->operatorName($request), (float) $data['factor']);
        return response()->json(['message' => '默认 Item 已保存并立即生效。', 'data' => $relation->load('item')], 201);
    }

    /** Compatibility endpoint for the pre-Stage-4 client; it now follows the same one-default-item transaction. */
    public function legacySetDefaultItem(Request $request, SkuItemDefaultRelationService $service)
    {
        $data = $request->validate(['sku_id' => 'required|exists:erp_skus,id', 'item_id' => 'required|exists:erp_items,id', 'factor' => 'required|numeric|gt:0', 'change_reason' => 'required|string|max:80', 'remark' => 'nullable|string|max:1000']);
        $hasCurrent = SkuItemRelation::where('sku_id', $data['sku_id'])->where('status', 'active')->where('is_primary', true)->exists();
        $this->authorizePermission($request, $hasCurrent ? 'sku_item_relation.change' : 'sku_item_relation.set');
        $relation = $service->setPrimary($data['sku_id'], $data['item_id'], $data['change_reason'], $data['remark'] ?? null, $this->operatorId($request), $this->operatorName($request), (float) $data['factor']);
        return response()->json(['message' => '默认 Item 已保存并立即生效。', 'data' => $relation->load('item')], 201);
    }

    public function audit(Request $request, SkuItemRelationAuditService $audit)
    {
        $this->authorizePermission($request, 'sku_item_relation.audit');
        $query = Sku::with(['product', 'itemRelations' => fn ($q) => $q->where('status', 'active')->where('is_primary', true)->with('item.unit')])->orderBy('id');
        if ($request->filled('sku_keyword')) {
            $keyword = trim((string) $request->input('sku_keyword'));
            $query->where(fn ($q) => $q->where('sku_code', 'like', '%'.$keyword.'%')->orWhere('sku_name', 'like', '%'.$keyword.'%'));
        }
        return response()->json($this->paginateAuditRows($query, $request, $audit, $request->input('status')));
    }

    public function resolveDuplicate(Request $request, int $skuId, SkuItemDefaultRelationService $service)
    {
        $this->authorizePermission($request, 'sku_item_relation.repair');
        $data = $request->validate(['keep_relation_id' => 'required|integer|exists:erp_sku_item_relations,id', 'change_reason' => 'required|string|max:80', 'remark' => 'nullable|string|max:1000']);
        $service->resolveDuplicate($skuId, $data['keep_relation_id'], $data['change_reason'], $data['remark'] ?? null, $this->operatorId($request), $this->operatorName($request));
        return response()->json(['message' => '重复默认 Item 已处理，处理记录已写入日志。']);
    }

    public function removeWrongBinding(Request $request, int $skuId, SkuItemDefaultRelationService $service)
    {
        $this->authorizePermission($request, 'sku_item_relation.repair');
        $data = $request->validate(['change_reason' => 'required|string|max:80', 'remark' => 'nullable|string|max:1000']);
        $service->removeWrongBindings($skuId, $data['change_reason'], $data['remark'] ?? null, $this->operatorId($request), $this->operatorName($request));
        return response()->json(['message' => '错误绑定已解除，处理记录已写入日志。']);
    }

    public function index(Request $request)
    {
        $this->authorizePermission($request, 'sku_item_relation.view');
        $query = SkuItemRelation::with(['sku.product', 'item', 'unit', 'baseRelation']);
        if ($request->filled('sku_id')) $query->where('sku_id', $request->integer('sku_id'));
        if ($request->filled('status')) $query->where('status', $request->status);
        return response()->json($query->latest()->paginate(min(100, max(5, $request->integer('per_page', 20)))));
    }

    public function store(Request $request)
    {
        $data = $this->validated($request);
        $relation = DB::transaction(function () use ($data, $request) {
            $sku = Sku::lockForUpdate()->findOrFail($data['sku_id']);
            $data = $this->normalize($data, $sku);
            $existing = SkuItemRelation::where('sku_id', $data['sku_id'])->where('status', 'active')->lockForUpdate()->get();
            if ($existing->isNotEmpty()) {
                abort_unless($request->boolean('multi_item_confirmed'), 409, 'SECOND_ITEM_CONFIRMATION_REQUIRED');
                abort_unless(in_array($data['relation_type'], ['sales_bundle_item', 'shipping_accessory', 'packaging'], true), 422, '第二个 Item 仅允许套装、组合销售或固定随货配件');
                $data['is_primary'] = false;
            }
            if ($this->isEnabledPrimary($data)) {
                SkuItemRelation::where('sku_id', $data['sku_id'])->where('status', 'active')->where('is_primary', true)->update(['is_primary' => false, 'status' => 'inactive', 'expired_at' => now(), 'operator_name' => $this->operatorName($request)]);
            }
            return SkuItemRelation::create($data + ['effective_at' => now(), 'operator_name' => $this->operatorName($request)]);
        });
        return response()->json(['message' => '关系保存成功', 'data' => $relation->load(['sku.product', 'item', 'unit', 'baseRelation'])], 201);
    }

    public function update(Request $request, int $id)
    {
        $relation = SkuItemRelation::findOrFail($id);
        $data = $this->validated($request, $id);
        abort_if($relation->status === 'active' && $relation->is_primary && (int) $relation->item_id !== (int) ($data['item_id'] ?? 0), 422, '默认 Item 更换必须使用“替换默认 Item”，以保留历史关系。');
        DB::transaction(function () use ($relation, &$data, $request) {
            $sku = Sku::lockForUpdate()->findOrFail($data['sku_id']);
            $data = $this->normalize($data, $sku);
            SkuItemRelation::where('sku_id', $data['sku_id'])->lockForUpdate()->get();
            if ($this->isEnabledPrimary($data)) {
                SkuItemRelation::where('sku_id', $data['sku_id'])->where('status', 'active')->where('is_primary', true)->whereKeyNot($relation->id)->update(['is_primary' => false, 'status' => 'inactive', 'expired_at' => now(), 'operator_name' => $this->operatorName($request)]);
            }
            if (($data['status'] ?? 'active') === 'inactive' && $relation->status === 'active') $data['expired_at'] = now();
            $data['operator_name'] = $this->operatorName($request);
            $relation->update($data);
        });
        return response()->json(['message' => '关系保存成功', 'data' => $relation->fresh()->load(['sku.product', 'item', 'unit', 'baseRelation'])]);
    }

    public function destroy(Request $request, int $id)
    {
        SkuItemRelation::findOrFail($id)->update([
            'status' => 'inactive',
            'is_primary' => false,
            'expired_at' => now(),
            'operator_name' => $this->operatorName($request),
        ]);
        return response()->json(['message' => '关系已删除']);
    }

    public function replacePrimary(Request $request)
    {
        $data = $request->validate([
            'sku_id' => 'required|exists:erp_skus,id', 'item_id' => 'required|exists:erp_items,id',
            'unit_id' => 'nullable|exists:erp_units,id', 'remark' => 'nullable|string',
        ]);
        $relation = DB::transaction(function () use ($data, $request) {
            $sku = Sku::lockForUpdate()->findOrFail($data['sku_id']);
            abort_if($this->orderLineType($sku) !== 'physical', 422, '服务或无需发货 SKU 无需默认 Item');
            $itemEnabled = \App\Models\Erp\Item::whereKey($data['item_id'])->where('status', 'enabled')->exists();
            abort_unless($itemEnabled, 422, '默认 Item 必须处于启用状态');
            SkuItemRelation::where('sku_id', $sku->id)->where('status', 'active')->where('is_primary', true)->lockForUpdate()->get();
            SkuItemRelation::where('sku_id', $sku->id)->where('status', 'active')->where('is_primary', true)->update(['is_primary' => false, 'status' => 'inactive', 'expired_at' => now(), 'operator_name' => $this->operatorName($request)]);
            return SkuItemRelation::create([
                'sku_id' => $sku->id, 'item_id' => $data['item_id'], 'unit_id' => $data['unit_id'] ?? null,
                'relation_type' => 'finished_product', 'qty' => 1, 'is_primary' => true, 'status' => 'active',
                'remark' => $data['remark'] ?? null, 'effective_at' => now(), 'operator_name' => $this->operatorName($request),
            ]);
        });
        return response()->json(['message' => '默认 Item 已更新，旧关系已进入历史', 'data' => $relation->load(['item', 'unit'])]);
    }

    private function validated(Request $request, ?int $id = null): array
    {
        return $request->validate([
            'sku_id' => 'required|exists:erp_skus,id', 'item_id' => 'nullable|exists:erp_items,id',
            'relation_type' => ['required', Rule::in(self::TYPES)], 'qty' => 'required|numeric|min:0.0001',
            'unit_id' => 'nullable|exists:erp_units,id', 'is_primary' => 'boolean', 'is_bundle_item' => 'boolean',
            'relation_context' => 'nullable|in:standard,custom',
            'base_relation_id' => 'nullable|exists:erp_sku_item_relations,id',
            'custom_source_type' => 'nullable|in:sales_order,manual,import',
            'custom_source_id' => 'nullable|integer|min:0',
            'status' => 'required|in:active,inactive', 'remark' => 'nullable|string',
        ]);
    }

    private function normalize(array $data, Sku $sku): array
    {
        $data['is_primary'] = (bool) ($data['is_primary'] ?? true);
        if ($data['relation_type'] === 'service_none') {
            abort_unless($this->orderLineType($sku) !== 'physical', 422, '实体 SKU 不能使用无实体服务关系');
            $data['item_id'] = null;
            $data['is_primary'] = false;
        } else {
            abort_if(empty($data['item_id']), 422, '请选择 Item');
        }
        return $data;
    }

    private function isEnabledPrimary(array $data): bool
    {
        return ($data['status'] ?? 'active') === 'active' && !empty($data['is_primary']);
    }

    private function orderLineType(Sku $sku): string
    {
        $type = $sku->order_line_type ?: $sku->fulfillment_type;
        return $type === 'virtual' ? 'no_delivery' : (string) $type;
    }

    /**
     * ERP authentication is token-based and intentionally does not populate
     * Laravel's default request user resolver.  Operation attribution must use
     * the same custom auth context as the permission check below.
     */
    private function operatorId(Request $request): ?int
    {
        return app(AuthContextService::class)->currentUser($request)?->legacy_id;
    }

    private function operatorName(Request $request): string
    {
        $user = app(AuthContextService::class)->currentUser($request);
        return $user?->nickname ?: $user?->name ?: $user?->username ?: '系统';
    }

    private function authorizePermission(Request $request, string $permission): void
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        if (!$user) abort(401, '未登录或登录已过期');
        abort_unless($auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true), 403, '无按钮权限：'.$permission);
    }

    private function paginateAuditRows($query, Request $request, SkuItemRelationAuditService $audit, ?string $status = null): array
    {
        $summaryQuery = clone $query;
        $activePrimary = fn ($relation) => $relation->where('status', 'active')->where('is_primary', true);
        $normalPrimary = fn ($relation) => $activePrimary($relation)->whereHas('item', fn ($item) => $item->where('status', 'enabled'));
        $summary = [
            'physical' => (clone $summaryQuery)->where('order_line_type', 'physical')->count(),
            'configured' => (clone $summaryQuery)->where('order_line_type', 'physical')->whereHas('itemRelations', $normalPrimary)->count(),
            'missing' => (clone $summaryQuery)->where('order_line_type', 'physical')->whereDoesntHave('itemRelations', $activePrimary)->count(),
            'abnormal' => (clone $summaryQuery)->where('order_line_type', 'physical')->whereHas('itemRelations', fn ($relation) => $activePrimary($relation)->whereHas('item', fn ($item) => $item->where('status', '<>', 'enabled')))->count(),
            'not_required' => (clone $summaryQuery)->where('order_line_type', '<>', 'physical')->count(),
        ];
        $summary['checked'] = (clone $summaryQuery)->count();
        $summary['normal'] = $summary['configured'];
        $summary['fix'] = $summary['missing'] + $summary['abnormal'];
        $summary['none'] = $summary['not_required'];
        if ($status && $status !== 'all') {
            if (in_array($status, ['normal'], true)) $query->where('order_line_type', 'physical')->whereHas('itemRelations', $normalPrimary);
            if ($status === 'missing') $query->where('order_line_type', 'physical')->whereDoesntHave('itemRelations', $activePrimary);
            if ($status === 'not_required') $query->where('order_line_type', '<>', 'physical');
            if (in_array($status, ['fix', 'abnormal'], true)) $query->where('order_line_type', 'physical')->where(function ($scope) use ($activePrimary) {
                $scope->whereDoesntHave('itemRelations', $activePrimary)
                    ->orWhereHas('itemRelations', fn ($relation) => $activePrimary($relation)->whereHas('item', fn ($item) => $item->where('status', '<>', 'enabled')));
            });
        }
        $perPage = min(100, max(5, $request->integer('per_page', 20)));
        $paginator = $query->paginate($perPage);
        $paginator->setCollection($paginator->getCollection()->map(function (Sku $sku) use ($audit) {
            $check = $audit->inspect($sku); $relation = $check['relations']->first();
            return ['sku' => $sku, 'product' => $sku->product, 'line_type' => $sku->line_type, 'default_relation' => $relation, 'default_item' => $relation?->item, 'audit' => $check];
        }));
        return $paginator->toArray() + ['summary' => $summary];
    }
}
