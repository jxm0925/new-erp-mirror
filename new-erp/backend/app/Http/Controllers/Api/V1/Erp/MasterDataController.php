<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{Item, ItemCategory, Location, Product, Sku, Supplier, Unit, Warehouse};
use App\Services\Erp\AuthContextService;
use App\Services\Erp\CategoryTreeEngine;
use App\Services\Erp\MasterDataApplicationService;
use App\Services\Erp\MasterDataAccessService;
use App\Services\Erp\MasterDataSummaryService;
use App\Services\Erp\ItemManagementScopeService;
use App\Services\Erp\ItemCategoryApplicationService;
use App\Services\Erp\ProductMatrixApplicationService;
use App\Services\Erp\SkuAggregateApplicationService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class MasterDataController extends Controller
{
    private const CONFIG = [
        'products' => [Product::class, 'product_code', 'product_name', ['category', 'unit.standardUnit', 'skus.itemRelations']],
        'skus' => [Sku::class, 'sku_code', 'sku_name', ['product', 'salesUnit.standardUnit', 'baseSku', 'itemRelations.item.unit.standardUnit', 'itemRelations.unit.standardUnit']],
        'items' => [Item::class, 'item_code', 'item_name', ['category', 'unit.standardUnit', 'baseItem', 'defaultSupplier', 'defaultWarehouse', 'skuRelations.sku.product']],
        'units' => [Unit::class, 'unit_code', 'unit_name', ['standardUnit']],
        'categories' => [ItemCategory::class, 'category_code', 'category_name', ['parent']],
        'suppliers' => [Supplier::class, 'supplier_code', 'supplier_name', ['categoryCapabilities.category']],
        'warehouses' => [Warehouse::class, 'warehouse_code', 'warehouse_name', ['locations', 'managerUser']],
        'locations' => [Location::class, 'location_code', 'location_name', ['warehouse', 'parent']],
    ];

    public function index(Request $request)
    {
        [$model, $code, $name, $relations] = $this->config($request);
        app(MasterDataAccessService::class)->authorizeRead($request, (string) $request->route('entity'));
        // Product/SKU selectors only need Product master fields. Loading every SKU
        // and Item relation makes a selector wait on the entire master catalogue.
        if ($request->route('entity') === 'products' && !$request->boolean('include_skus')) {
            $relations = ['category', 'unit'];
        }
        if ($request->route('entity') === 'items') {
            $relations = ['category', 'unit.standardUnit', 'baseItem', 'defaultSupplier', 'activeDefaultSupplierRelation.supplier', 'activeMaterialPolicy'];
        }
        if ($request->route('entity') === 'warehouses' && $request->boolean('include_location_summary')) {
            $relations = ['managerUser'];
        }
        $query = $model::query()->with($relations);
        if ($request->route('entity') === 'categories') $query->where('category_type', 'product');
        if ($request->has('ids')) {
            $selected = $request->validate(['ids' => 'required|array|min:1|max:1000', 'ids.*' => 'required|integer|min:1|distinct']);
            $query->whereIn('id', $selected['ids']);
        }
        if ($request->route('entity') === 'items') {
            $scopes = app(ItemManagementScopeService::class);
            $scopes->applyScope($query, $scopes->requestScope($request));
        }
        if (in_array($request->route('entity'), ['warehouses', 'locations'], true)) {
            $scope = app(ItemManagementScopeService::class)->requestScope($request);
            if ($scope !== null) {
                if ($request->route('entity') === 'warehouses') $query->where('management_scope', $scope);
                else $query->whereHas('warehouse', fn (Builder $warehouse) => $warehouse->where('management_scope', $scope));
            }
        }
        if ($request->route('entity') === 'warehouses' && $request->boolean('include_location_summary')) {
            $query->withCount('locations')->addSelect(['area_count' => Location::query()
                ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(area), ''))")
                ->whereColumn('warehouse_id', 'erp_warehouses.id')]);
        }
        if ($request->route('entity') === 'products') {
            $query->withCount('skus');
        }
        if ($request->route('entity') === 'units' && !$request->boolean('include_legacy')) {
            $query->where('is_legacy', false);
        }
        if ($request->route('entity') === 'items' && !$request->boolean('include_test_data')) {
            $query->whereDoesntHave('category', fn (Builder $category) => $category->whereIn('category_name', [
                '权限闭环一级类目', '权限闭环子类目（已编辑）', '最终收口一级类目',
            ]));
        }
        $keyword = trim((string) $request->input('keyword'));
        if ($keyword !== '') {
            $query->where(function (Builder $q) use ($request, $code, $name, $keyword): void {
                $q->where($code, 'like', "%{$keyword}%")->orWhere($name, 'like', "%{$keyword}%");
                if ($request->route('entity') === 'skus') $q->orWhere('spec_text', 'like', "%{$keyword}%");
                if ($request->route('entity') === 'warehouses') {
                    $q->orWhere('manager', 'like', "%{$keyword}%")->orWhereHas('managerUser', fn ($account) => $account
                        ->where('nickname', 'like', "%{$keyword}%")->orWhere('username', 'like', "%{$keyword}%"));
                }
                if ($request->route('entity') === 'items') {
                    $q->orWhere('spec', 'like', "%{$keyword}%")->orWhere('model', 'like', "%{$keyword}%");
                }
            });
        }
        if ($request->route('entity') === 'skus' && $request->filled('order_line_type')) {
            $request->validate(['order_line_type' => 'in:physical,service,no_delivery,deduction']);
            $query->where('order_line_type', $request->input('order_line_type'));
        }
        if ($request->route('entity') === 'locations' && $request->filled('area')) {
            $query->whereRaw('TRIM(area) = ?', [trim((string) $request->input('area'))]);
        }
        if ($request->route('entity') === 'locations' && $request->filled('location_purpose')) {
            $request->validate(['location_purpose' => 'in:general,finished,shipping_staging,repair']);
            $query->where('location_purpose', $request->input('location_purpose'));
        }
        if ($request->route('entity') === 'locations' && $request->has('location_purposes')) {
            $data = $request->validate(['location_purposes' => 'required|array|min:1|max:4',
                'location_purposes.*' => 'required|in:general,finished,shipping_staging,repair|distinct']);
            $query->whereIn('location_purpose', $data['location_purposes']);
        }
        if (in_array($request->route('entity'), ['warehouses', 'locations'], true) && $request->has('statuses')) {
            $data = $request->validate(['statuses' => 'required|array|min:1|max:2', 'statuses.*' => 'required|in:active,enabled|distinct']);
            $query->whereIn('status', $data['statuses']);
        }
        if ($request->route('entity') === 'items' && $request->filled('is_stock_item')) {
            $request->validate(['is_stock_item' => 'boolean']);
            $query->where('is_stock_item', $request->boolean('is_stock_item'));
            if ($request->boolean('is_stock_item')) $query->where('item_type', '<>', 'service');
        }
        foreach (['status', 'product_id', 'category_id', 'unit_id', 'warehouse_id', 'item_type', 'unit_type', 'category_type', 'supplier_type', 'approval_status', 'cooperation_status', 'quality_status', 'is_purchase_item', 'is_length_cut_material'] as $field) {
            if (!$request->filled($field)) continue;
            if ($request->route('entity') === 'suppliers' && $field === 'category_id') {
                $categoryId = $request->integer('category_id');
                $query->whereHas('categoryCapabilities', fn ($scope) => $scope
                    ->where('item_category_id', $categoryId)->where('status', 'active'));
                continue;
            }
            if ($request->route('entity') === 'skus' && $field === 'category_id') {
                $query->whereHas('product', fn (Builder $product) => $product->where('category_id', $request->input($field)));
                continue;
            }
            if ($request->route('entity') === 'items' && $field === 'category_id') {
                $request->validate(['category_id' => 'integer|min:1']);
                $categoryId = $request->integer('category_id');
                $query->whereIn('category_id', [$categoryId, ...app(ItemCategoryApplicationService::class)->descendantIds($categoryId)]);
                continue;
            }
            $query->where($field, $request->input($field));
        }
        if ($request->route('entity') === 'suppliers') {
            $query->withCount([
                'itemRelations as active_item_relation_count' => fn ($relations) => $relations->where('relation_status', 'active'),
                'quotations as enabled_quotation_count' => fn ($quotes) => $quotes->where('status', 'enabled'),
            ]);
            if ($request->filled('contact_keyword')) {
                $contact = trim((string) $request->input('contact_keyword'));
                $query->where(fn ($supplier) => $supplier
                    ->where('contact_name', 'like', "%{$contact}%")
                    ->orWhere('contact_phone', 'like', "%{$contact}%")
                    ->orWhere('phone', 'like', "%{$contact}%"));
            }
            if ($request->boolean('purchase_available') || $request->boolean('purchase_receipt_available')) {
                $query->whereNotNull('supplier_name')->where('supplier_name', '<>', '')->where('status', 'enabled')
                    ->whereRaw("COALESCE(NULLIF(approval_status, ''), 'approved') = 'approved'")
                    ->whereRaw('COALESCE(is_blacklisted, 0) = 0')->whereRaw('COALESCE(purchase_restricted, 0) = 0');
                if ($request->boolean('purchase_available')) {
                    $query->whereRaw("COALESCE(NULLIF(cooperation_status, ''), 'normal') = 'normal'")
                        ->whereRaw("COALESCE(NULLIF(quality_status, ''), 'normal') <> 'frozen'");
                }
            }
        }
        if ($request->route('entity') === 'units') {
            $query->withCount(['items', 'skus', 'products', 'purchaseConversions']);
        }
        if ($request->route('entity') === 'items') {
            $query->withCount([
                'skuRelations as active_sku_relation_count' => fn ($relations) => $relations
                    ->where('status', 'active')
                    ->where(fn ($active) => $active->whereNull('effective_at')->orWhere('effective_at', '<=', now()))
                    ->where(fn ($active) => $active->whereNull('expired_at')->orWhere('expired_at', '>', now())),
            ]);
        }
        if ($request->route('entity') === 'skus' && $request->boolean('missing_default_item')) {
            $query->where('order_line_type', 'physical')->whereDoesntHave('itemRelations', function (Builder $relation) {
                $relation->where('status', 'active')->where('is_primary', true)->whereHas('item', fn (Builder $item) => $item->where('status', 'enabled'));
            });
        }
        if ($request->route('entity') === 'skus' && $request->boolean('order_available')) {
            $query->where('status', 'enabled')
                ->where('is_sale_item', true)
                ->whereNotNull('sales_unit_id')
                ->whereHas('product', fn (Builder $product) => $product->where('status', 'enabled'))
                ->where(function (Builder $sku) {
                    $sku->whereIn('order_line_type', ['service', 'no_delivery', 'deduction'])
                        ->orWhereHas('itemRelations', function (Builder $relation) {
                            $relation->where('status', 'active')->where('is_primary', true)
                                ->whereHas('item', fn (Builder $item) => $item->where('status', 'enabled'));
                        });
                });
        }
        if ($request->route('entity') === 'products' && $request->boolean('order_available')) {
            $query->where('status', 'enabled')->whereHas('skus', function (Builder $sku) {
                $sku->where('status', 'enabled')
                    ->where('is_sale_item', true)
                    ->whereNotNull('sales_unit_id')
                    ->where(function (Builder $available) {
                        $available->whereIn('order_line_type', ['service', 'no_delivery', 'deduction'])
                            ->orWhereHas('itemRelations', function (Builder $relation) {
                                $relation->where('status', 'active')->where('is_primary', true)
                                    ->whereHas('item', fn (Builder $item) => $item->where('status', 'enabled'));
                            });
                    });
            });
        }
        if ($request->route('entity') === 'categories' && $request->boolean('tree')) {
            // Build paths from the complete domain even when search/status filters hide ancestors.
            $visibleIds = count($query->getQuery()->wheres) > 1 ? $query->pluck('id')->all() : null;
            $all = ItemCategory::where('category_type', 'product')->with($relations)->orderBy('sort_order')->orderBy('id')->get();
            return response()->json(['data' => (new CategoryTreeEngine($all))->tree($visibleIds)]);
        }
        $stats = $request->boolean('include_stats')
            ? app(MasterDataSummaryService::class)->summarize((string) $request->route('entity'), $query) : null;
        if ($request->route('entity') === 'items' && $stats !== null) {
            $stats['stock_managed'] = (clone $query)->where('is_stock_item', true)->count();
            $scopeBase = Item::query();
            if (!$request->boolean('include_test_data')) {
                $scopeBase->whereDoesntHave('category', fn (Builder $category) => $category->whereIn('category_name', [
                    '权限闭环一级类目', '权限闭环子类目（已编辑）', '最终收口一级类目',
                ]));
            }
            $stats['factory_total'] = (clone $scopeBase)->where(function ($q) {
                $q->where('management_scope', 'factory')
                    ->orWhere(fn ($sub) => $sub->where(fn ($null) => $null->whereNull('management_scope')->orWhere('management_scope', ''))
                        ->where('item_type', '<>', 'office_consumable'));
            })->count();
            $stats['office_total'] = (clone $scopeBase)->where(function ($q) {
                $q->where('management_scope', 'office')
                    ->orWhere(fn ($sub) => $sub->where(fn ($null) => $null->whereNull('management_scope')->orWhere('management_scope', ''))
                        ->where('item_type', 'office_consumable'));
            })->count();
            $stats['all_total'] = (clone $scopeBase)->count();
        }
        $warehouseStats = $request->route('entity') === 'locations' && $request->boolean('include_stats') && $request->filled('warehouse_id')
            ? app(MasterDataSummaryService::class)->locations($request->integer('warehouse_id')) : null;
        // 稳定的次级排序保证更新时间相同的记录不会跨页重复或遗漏。
        $perPage = isset($selected) ? count($selected['ids']) : min(100, max(5, (int) $request->input('per_page', 10)));
        $data = $query->latest('updated_at')->orderByDesc('id')->paginate($perPage);
        if ($request->route('entity') === 'items') {
            $data->getCollection()->transform(function (Item $item): Item {
                $relationSupplier = $item->activeDefaultSupplierRelation?->supplier;
                $legacySupplier = $item->defaultSupplier && $item->defaultSupplier->status === 'enabled' ? $item->defaultSupplier : null;
                $item->setRelation('defaultSupplier', $relationSupplier ?: $legacySupplier);
                $item->unsetRelation('activeDefaultSupplierRelation');
                return app(ItemManagementScopeService::class)->exposeCategoryScope($item);
            });
        }
        return response()->json([...$data->toArray(), ...($stats === null ? [] : ['stats' => $stats]),
            ...($warehouseStats === null ? [] : ['warehouse_stats' => $warehouseStats])]);
    }

    public function show(Request $request, int $id)
    {
        [$model, , , $relations] = $this->config($request);
        app(MasterDataAccessService::class)->authorizeRead($request, (string) $request->route('entity'));
        $warehouseSummary = $request->route('entity') === 'warehouses' && $request->boolean('include_location_summary');
        if ($warehouseSummary) $relations = ['managerUser'];
        $query = $model::with($relations);
        if ($request->route('entity') === 'categories') $query->where('category_type', 'product');
        if ($warehouseSummary) $query->withCount('locations')->addSelect(['area_count' => Location::query()
            ->selectRaw("COUNT(DISTINCT NULLIF(TRIM(area), ''))")->whereColumn('warehouse_id', 'erp_warehouses.id')]);
        $record = $query->findOrFail($id);
        $this->assertManagementContext($request, $record);
        if ($record instanceof Item) {
            $record->setAttribute('base_unit_locked', $this->itemBaseUnitLocked((int) $record->id));
            $record->load('activeMaterialPolicy');
            app(ItemManagementScopeService::class)->exposeCategoryScope($record);
        }
        if ($record instanceof Sku) {
            $record->setAttribute('sales_unit_locked', $this->skuSalesUnitLocked((int) $record->id));
        }
        return response()->json($record);
    }

    public function store(Request $request, MasterDataApplicationService $service)
    {
        if ($request->route('entity') === 'skus') return $this->storeIntegratedSku($request);
        app(MasterDataAccessService::class)->authorize($request, (string) $request->route('entity'), 'create');
        [$model] = $this->config($request);
        $data = $request->validate($this->rules($request));
        $this->protectHiddenCosts($request, $data);
        if ($request->route('entity') === 'units' && empty($data['allow_decimal'])) $data['decimal_places'] = 0;
        if ($request->route('entity') === 'units') $data += ['is_legacy' => false, 'standard_unit_id' => null];
        if ($request->route('entity') === 'categories') $data['category_type'] = 'product';
        if ($request->route('entity') === 'items') {
            $this->assertValidItemCategory($data['category_id'] ?? null, false);
            $this->assertStandardBusinessUnit((int) $data['unit_id']);
            $this->normalizeItemSerialTracking($data);
            $this->normalizeItemLengthCut($data);
            $data = app(ItemManagementScopeService::class)->prepareItem($data, null,
                app(ItemManagementScopeService::class)->requestScope($request));
        }
        if (in_array($request->route('entity'), ['products', 'skus', 'items', 'suppliers', 'warehouses', 'locations'], true)) {
            $data = array_merge($data, $request->validate([
                'reservation_token' => 'nullable|uuid',
                'creation_session_id' => 'nullable|uuid',
            ]));
        }
        $this->enforceUnitBaseRule($request, $data);
        if ($request->route('entity') === 'products' && $request->has('sku_matrix')) {
            app(MasterDataAccessService::class)->authorize($request, 'skus', 'create');
            $matrix = $request->validate(['sku_matrix' => 'required|array|min:1|max:500']);
            $record = app(ProductMatrixApplicationService::class)->save(null, $data, $matrix['sku_matrix'], app(AuthContextService::class)->currentUser($request)?->legacy_id);
            return response()->json(['message' => '商品与规格已保存', 'data' => $record], 201);
        }
        $record = $service->create(
            (string) $request->route('entity'),
            $model,
            $data,
            app(AuthContextService::class)->currentUser($request)?->legacy_id
        );
        return response()->json(['message' => '保存成功', 'data' => $record], 201);
    }

    public function update(Request $request, int $id, MasterDataApplicationService $service)
    {
        if ($request->route('entity') === 'skus') return $this->updateIntegratedSku($request, $id);
        app(MasterDataAccessService::class)->authorize($request, (string) $request->route('entity'), 'edit');
        [$model, $code] = $this->config($request);
        $record = $model::findOrFail($id);
        $this->assertManagementContext($request, $record);
        $data = $request->validate($this->rules($request, $id));
        $this->protectHiddenCosts($request, $data);
        if ($request->route('entity') === 'units' && empty($data['allow_decimal'])) $data['decimal_places'] = 0;
        if ($request->route('entity') === 'categories') $data['category_type'] = 'product';
        if ($request->route('entity') === 'items' && !empty($data['category_id'])) $this->assertValidItemCategory($data['category_id'], true);
        if ($record instanceof Item) {
            $this->normalizeItemSerialTracking($data, $record);
            $this->normalizeItemLengthCut($data, $record);
        }
        if ($record instanceof Item && array_key_exists('unit_id', $data)
            && (int) $data['unit_id'] !== (int) $record->unit_id) {
            abort_if($this->itemBaseUnitLocked((int) $record->id), 422, '该物料已产生业务数据，基本单位不能直接修改。');
            $this->assertStandardBusinessUnit((int) $data['unit_id']);
        }
        if ($request->route('entity') === 'units') unset($data[$code]);
        $this->enforceUnitBaseRule($request, $data, $id);
        if ($request->route('entity') === 'products' && $request->has('sku_matrix')) {
            app(MasterDataAccessService::class)->authorize($request, 'skus', 'create');
            $matrix = $request->validate(['sku_matrix' => 'required|array|min:1|max:500']);
            $record = app(ProductMatrixApplicationService::class)->save($id, $data, $matrix['sku_matrix'], app(AuthContextService::class)->currentUser($request)?->legacy_id);
            return response()->json(['message' => '商品与规格已保存', 'data' => $record], 200);
        }
        $record = $service->update(
            (string) $request->route('entity'),
            $record,
            $data,
            app(AuthContextService::class)->currentUser($request)?->legacy_id,
            $record instanceof Item ? app(ItemManagementScopeService::class)->requestScope($request) : null
        );
        return response()->json(['message' => '保存成功', 'data' => $record]);
    }

    private function protectHiddenCosts(Request $request, array &$data): void
    {
        if (app(\App\Services\Erp\InventoryAmountVisibilityService::class)->canView($request)) return;
        if ($request->route('entity') === 'items') unset($data['standard_cost'], $data['last_purchase_price']);
        if ($request->route('entity') === 'skus') unset($data['reference_cost']);
    }

    public function storeSkuMatrix(Request $request, int $id, ProductMatrixApplicationService $service)
    {
        $operator = app(MasterDataAccessService::class)->authorize($request, 'skus', 'create');
        $data = $request->validate(['sku_matrix' => 'required|array|min:1|max:500']);
        $product = $service->save($id, null, $data['sku_matrix'], $operator->legacy_id);
        return response()->json(['message' => '规格批次已保存', 'data' => $product], 201);
    }

    public function storeIntegratedSku(Request $request)
    {
        $operator = app(MasterDataAccessService::class)->authorize($request, 'skus', 'create');
        $record = app(SkuAggregateApplicationService::class)->save(null, $request->all(), $operator);
        return response()->json(['message' => '保存成功', 'data' => $record], 201);
    }

    public function updateIntegratedSku(Request $request, int $id)
    {
        $operator = app(MasterDataAccessService::class)->authorize($request, 'skus', 'edit');
        $record = app(SkuAggregateApplicationService::class)->save($id, $request->all(), $operator);
        return response()->json(['message' => '保存成功', 'data' => $record]);
    }

    public function productSkuSpecs(Request $request, int $id)
    {
        $auth = app(AuthContextService::class);
        $operator = $auth->currentUser($request);
        abort_unless($operator, 401, '未登录或登录已过期。');
        abort_unless($auth->isSuperAdmin($operator) || array_intersect([
            'master.product', 'master.product.view', 'master.product.create',
            'master.sku', 'master.sku.view', 'master.sku.create',
        ], $auth->permissionCodes($operator)), 403, '没有商品或 SKU 查看、新建权限。');
        Product::findOrFail($id);
        $specs = Sku::where('product_id', $id)->orderBy('id')->get(['id', 'sku_code', 'sku_name', 'spec_text', 'status'])
            ->map(fn (Sku $sku) => ['id' => $sku->id, 'sku_code' => $sku->sku_code, 'sku_name' => $sku->sku_name,
                'spec_text' => $sku->spec_text, 'normalized_spec' => SkuAggregateApplicationService::normalizeSpec($sku->spec_text),
                'status' => $sku->status]);
        return response()->json(['data' => $specs]);
    }

    public function disable(Request $request, int $id, MasterDataApplicationService $service)
    {
        app(MasterDataAccessService::class)->authorize($request, (string) $request->route('entity'), 'edit');
        [$model] = $this->config($request);
        $record = $model::findOrFail($id);
        $this->assertManagementContext($request, $record);
        $this->assertCanBeDisabled($record);
        $record = $service->setStatus($record, 'disabled');
        return response()->json(['message' => '已禁用', 'data' => $record]);
    }

    public function enable(Request $request, int $id, MasterDataApplicationService $service)
    {
        app(MasterDataAccessService::class)->authorize($request, (string) $request->route('entity'), 'edit');
        [$model] = $this->config($request);
        $record = $model::findOrFail($id);
        $this->assertManagementContext($request, $record);
        if ($record instanceof Product) $this->assertProductCanBeEnabled($record);
        if ($record instanceof Item) $this->assertItemCanBeEnabled($record);
        $record = $service->setStatus($record, 'enabled');
        return response()->json(['message' => '已启用', 'data' => $record->fresh()]);
    }

    public function destroy(Request $request, int $id, MasterDataApplicationService $service)
    {
        [$model] = $this->config($request);
        $entity = (string) $request->route('entity');
        $permission = match ($entity) {
            'products' => 'master.product.delete',
            'skus' => 'master.sku.delete',
            'items' => 'master.item.delete',
            'units', 'categories' => 'master.base_archive.delete',
            'suppliers' => 'master.supplier.delete',
            'warehouses' => 'master.warehouse.delete',
            'locations' => 'master.location.delete',
            default => abort(405, '当前数据类型不支持删除。'),
        };
        $this->authorizePermission($request, $permission);
        $record = $model::findOrFail($id);
        $this->assertManagementContext($request, $record);
        $service->deleteUnused($entity, $record);

        return response()->json(['message' => '删除成功']);
    }

    private function assertManagementContext(Request $request, $record): void
    {
        if ($record instanceof ItemCategory && $request->route('entity') === 'categories') {
            abort_unless($record->category_type === 'product', 404);
        }
        if ($record instanceof Item) {
            $scopes = app(ItemManagementScopeService::class);
            $scopes->assertContext($record, $scopes->requestScope($request));
        }
        if ($record instanceof Warehouse || $record instanceof Location) {
            $scope = app(ItemManagementScopeService::class)->requestScope($request);
            $actual = $record instanceof Warehouse ? $record->managementScope() : $record->warehouse?->managementScope();
            if ($scope !== null && $actual !== $scope) {
                throw \Illuminate\Validation\ValidationException::withMessages(['management_scope' => '该仓库或库位不属于所选管理范围。']);
            }
        }
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

    private function assertProductCanBeEnabled(Product $product): void
    {
        abort_unless(filled($product->product_code) && filled($product->product_name) && filled($product->product_type), 422, '商品编码、名称和类型完整后才能启用。');
        abort_unless($product->unit_id && Unit::whereKey($product->unit_id)->where('status', 'enabled')->exists(), 422, '请先维护有效的商品计量单位后再启用。');
    }

    private function assertItemCanBeEnabled(Item $item): void
    {
        abort_unless(filled($item->item_code) && filled($item->item_name) && filled($item->item_type), 422, 'Item 编码、名称和类型完整后才能启用。');
        abort_unless($item->unit_id && Unit::whereKey($item->unit_id)->where('status', 'enabled')->exists(), 422, '请先维护有效的基本单位后再启用。');
        abort_if($item->cuttingMode() === 'length' && (float) $item->standard_stock_length_mm <= 0, 422, '定长材料必须先维护标准原料长度。');
    }

    private function normalizeItemLengthCut(array &$data, ?Item $item = null): void
    {
        $mode = array_key_exists('cutting_mode', $data) ? ($data['cutting_mode'] ?: 'none')
            : (array_key_exists('is_length_cut_material', $data) ? ((bool) $data['is_length_cut_material'] ? 'length' : 'none') : ($item?->cuttingMode() ?: 'none'));
        $length = $data['standard_stock_length_mm'] ?? $item?->standard_stock_length_mm;
        abort_if($mode === 'length' && (float) $length <= 0, 422, '定长材料必须维护标准原料长度。');
        $data['cutting_mode'] = $mode;
        $data['is_length_cut_material'] = $mode === 'length';
        $data['material_management_mode'] = $data['material_management_mode'] ?? $item?->materialManagementMode() ?? 'quantity';
        abort_if($data['material_management_mode'] === 'physical' && ! in_array($mode, ['sheet', 'length'], true), 422, '逐件实物编码管理只适用于板材或管材。');
        if ($mode !== 'length') $data['standard_stock_length_mm'] = null;
    }

    private function assertCanBeDisabled(object $record): void
    {
        if ($record instanceof Product) {
            abort_if($record->skus()->where('status', 'enabled')->exists(), 422, '该商品下仍有启用 SKU，请先停用或调整其 SKU 后再停用商品。');
        }
        if ($record instanceof Item) {
            $usedByEnabledPhysicalSku = DB::table('erp_sku_item_relations as relation')
                ->join('erp_skus as sku', 'sku.id', '=', 'relation.sku_id')
                ->where('relation.item_id', $record->id)
                ->where('relation.status', 'active')
                ->where('relation.is_primary', true)
                ->where('sku.status', 'enabled')
                ->where('sku.order_line_type', 'physical')
                ->exists();
            abort_if($usedByEnabledPhysicalSku, 422, '该物料是启用实物 SKU 的默认物料，请先替换默认物料或停用相关 SKU。');
        }
        if ($record instanceof Unit) {
            $references = $record->items()->exists()
                || $record->skus()->exists()
                || $record->products()->exists()
                || $record->purchaseConversions()->where('status', 'active')->exists();
            abort_if($references, 422, '该单位仍被物料、SKU、商品或有效采购换算引用，不能停用。');
        }
    }

    private function itemBaseUnitLocked(int $itemId): bool
    {
        foreach ([
            ['erp_inventory_balances', 'item_id'],
            ['erp_inventory_location_balances', 'item_id'],
            ['erp_inventory_transaction_items', 'item_id'],
            ['erp_bom_items', 'component_item_id'],
            ['erp_purchase_order_items', 'item_id'],
            ['erp_purchase_receipt_items', 'item_id'],
            ['erp_sales_order_lines', 'item_id'],
        ] as [$table, $column]) {
            if (DB::table($table)->where($column, $itemId)->exists()) return true;
        }
        return false;
    }

    private function skuSalesUnitLocked(int $skuId): bool
    {
        return DB::table('erp_sales_order_lines as line')
            ->join('erp_sales_orders as orders', 'orders.id', '=', 'line.sales_order_id')
            ->where('line.sku_id', $skuId)
            ->whereNotIn('orders.order_status', ['draft', 'cancelled'])
            ->exists();
    }

    private function assertStandardBusinessUnit(int $unitId): void
    {
        $valid = Unit::query()
            ->whereKey($unitId)
            ->where('status', 'enabled')
            ->where('is_legacy', false)
            ->exists();
        abort_unless($valid, 422, '业务主数据只能选择已启用的标准单位。历史单位仅用于映射和审计。');
    }

    public function uploadItemImage(Request $request)
    {
        app(MasterDataAccessService::class)->authorize($request, 'items', 'image');
        return $this->uploadMasterImage($request, 'item-images');
    }

    /** Upload a Product display image directly to OSS. */
    public function uploadProductImage(Request $request)
    {
        app(MasterDataAccessService::class)->authorize($request, 'products', 'image');
        return $this->uploadMasterImage($request, 'product-images');
    }

    /** Upload a SKU sales-display image directly to OSS. */
    public function uploadSkuImage(Request $request)
    {
        app(MasterDataAccessService::class)->authorize($request, 'skus', 'image');
        return $this->uploadMasterImage($request, 'sku-images');
    }

    private function uploadMasterImage(Request $request, string $directoryName)
    {
        $payload = $request->validate([
            'image' => 'required|file|mimes:jpg,jpeg,png,webp,gif|max:5120',
        ]);
        $diskConfig = config('filesystems.disks.oss', []);
        foreach (['access_key_id', 'access_key_secret', 'bucket', 'endpoint'] as $key) {
            abort_if(blank($diskConfig[$key] ?? null), 503, 'OSS 上传配置不完整，请联系系统管理员。');
        }

        $file = $payload['image'];
        $extension = $file->extension();
        $directory = 'erp/master/'.$directoryName.'/'.date('Ymd');
        $filename = Str::uuid().'.'.$extension;

        try {
            $path = Storage::disk('oss')->putFileAs($directory, $file, $filename, ['visibility' => 'public']);
            throw_unless($path, \RuntimeException::class, 'OSS 图片上传失败。');
            $url = Storage::disk('oss')->url($path);
        } catch (\Throwable $exception) {
            report($exception);

            return response()->json([
                'message' => 'OSS 图片上传失败，请检查 OSS 连接与权限配置后重试。',
            ], 502);
        }

        return response()->json([
            'message' => '图片上传成功',
            'data' => [
                'path' => $path,
                'url' => $url,
                'storage' => 'oss',
            ],
        ], 201);
    }

    private function config(Request $request): array
    {
        abort_unless(isset(self::CONFIG[$request->route('entity')]), 404);
        $config = self::CONFIG[$request->route('entity')];
        if ($request->route('entity') === 'categories') {
            $config[3] = ['parent' => fn ($query) => $query->where('category_type', 'product')];
        }
        return $config;
    }

    private function rules(Request $request, ?int $id = null): array
    {
        $entity = $request->route('entity');
        $unique = fn (string $table, string $column) => ['required', 'string', 'max:120', Rule::unique($table, $column)->ignore($id)];
        $historicalBundle = $entity === 'products' && $id && Product::whereKey($id)->where('product_type', 'bundle')->exists();
        return match ($entity) {
            'products' => [
                'product_code' => $unique('erp_products', 'product_code'), 'product_name' => 'required|string|max:160',
                'product_type' => ['required', 'string', 'max:40', ...($historicalBundle ? [] : [Rule::notIn(['bundle'])])],
                'category_id' => ['nullable', Rule::exists('erp_item_categories', 'id')->where('category_type', 'product')],
                'model' => 'nullable|string|max:100', 'unit_id' => 'nullable|exists:erp_units,id',
                'search_aliases' => 'nullable|string|max:500', 'search_keywords' => 'nullable|string',
                'brand' => 'nullable|string|max:100', 'origin' => 'nullable|string|max:120',
                'image' => 'nullable|string|max:255', 'description' => 'nullable|string', 'status' => 'required|in:draft,enabled,disabled',
                'remark' => 'nullable|string',
            ],
            'items' => [
                'item_code' => $unique('erp_items', 'item_code'), 'item_name' => 'required|string|max:160',
                'image_url' => 'nullable|url:http,https|max:1024',
                'item_type' => 'required|in:finished_product,semi_finished,raw_material,packaging,service,office_consumable',
                'management_scope' => 'sometimes|required|in:factory,office',
                'category_id' => [$id ? 'nullable' : 'required', 'integer', 'exists:erp_item_categories,id'], 'spec' => 'nullable|string|max:255',
                'material_grade' => 'nullable|string|max:80',
                'cutting_mode' => 'nullable|in:none,sheet,length',
                'material_management_mode' => 'nullable|in:quantity,physical',
                'standard_stock_length_mm' => 'nullable|numeric|min:0.01|max:9999999999.99',
                'is_length_cut_material' => 'boolean',
                'unit_id' => 'required|exists:erp_units,id', 'brand' => 'nullable|string|max:100', 'model' => 'nullable|string|max:100',
                'is_purchase_item' => 'boolean', 'is_stock_item' => 'boolean', 'is_production_item' => 'boolean',
                'manufacturing_strategy' => 'sometimes|required|in:unspecified,purchase,make,phantom',
                'is_batch_managed' => 'boolean', 'is_serial_managed' => 'boolean',
                'serial_tracking_mode' => 'nullable|in:none,optional,required',
                'serial_number_prefix' => 'nullable|string|max:10|regex:/^[A-Za-z]+$/',
                'equipment_identity_requirement' => 'nullable|in:not_applicable,required',
                'is_custom_item' => 'boolean',
                'base_item_id' => 'nullable|exists:erp_items,id',
                'custom_scope' => 'nullable|in:none,order,customer,reusable',
                'custom_source_type' => 'nullable|in:sales_order,manual,import',
                'custom_source_id' => 'nullable|integer|min:0',
                'customer_id' => 'nullable|integer|min:0',
                'sales_order_id' => 'nullable|integer|min:0',
                'sales_order_line_id' => 'nullable|integer|min:0',
                'design_version' => 'nullable|string|max:80',
                'custom_status' => 'nullable|in:draft,active,disabled,archived',
                'custom_description' => 'nullable|string',
                'cost_method' => 'required|in:weighted_average,standard,fifo',
                'standard_cost' => 'nullable|numeric|min:0', 'last_purchase_price' => 'nullable|numeric|min:0',
                'default_supplier_id' => 'nullable|exists:erp_suppliers,id', 'default_warehouse_id' => 'nullable|exists:erp_warehouses,id',
                'status' => 'required|in:enabled,disabled', 'remark' => 'nullable|string',
            ],
            'units' => [
                'unit_code' => $unique('erp_units', 'unit_code'), 'unit_name' => 'required|string|max:80',
                'symbol' => 'nullable|string|max:20', 'unit_type' => 'required|string|max:40',
                'allow_decimal' => 'required|boolean', 'decimal_places' => 'required|integer|min:0|max:6',
                'sort_order' => 'nullable|integer|min:0|max:999999',
                'is_base' => 'boolean', 'status' => 'required|in:enabled,disabled', 'remark' => 'nullable|string',
            ],
            'categories' => [
                'category_code' => $unique('erp_item_categories', 'category_code'), 'category_name' => 'required|string|max:120',
                'parent_id' => ['nullable', 'integer', Rule::exists('erp_item_categories', 'id')->where('category_type', 'product')],
                'category_type' => 'sometimes|in:product',
                'sort_order' => 'nullable|integer|min:0', 'status' => 'required|in:enabled,disabled', 'remark' => 'nullable|string',
            ],
            'suppliers' => [
                'supplier_code' => $unique('erp_suppliers', 'supplier_code'), 'supplier_name' => 'required|string|max:160',
                'short_name' => 'nullable|string|max:100', 'supplier_type' => 'required|string|max:40',
                'contact_name' => 'nullable|string|max:80', 'contact_phone' => 'nullable|string|max:40',
                'phone' => 'nullable|string|max:40', 'email' => 'nullable|email|max:120', 'address' => 'nullable|string|max:255',
                'default_tax_rate' => 'nullable|numeric|min:0|max:100', 'settlement_method' => 'nullable|string|max:80',
                'payment_method' => 'nullable|string|max:80', 'bank_name' => 'nullable|string|max:120',
                'bank_account' => 'nullable|string|max:100', 'level' => 'nullable|string|max:20',
                'approval_status' => 'nullable|in:pending,approved,rejected',
                'is_blacklisted' => 'nullable|boolean',
                'cooperation_status' => 'nullable|in:normal,abnormal,terminated',
                'purchase_restricted' => 'nullable|boolean',
                'quality_status' => 'nullable|in:normal,frozen',
                'quality_frozen_until' => 'nullable|date',
                'category_ids' => 'nullable|array',
                'category_ids.*' => 'integer|exists:erp_item_categories,id',
                'reservation_token' => 'nullable|uuid',
                'creation_session_id' => 'nullable|uuid',
                'status' => 'required|in:enabled,disabled', 'remark' => 'nullable|string',
            ],
            'warehouses' => [
                'warehouse_code' => $unique('erp_warehouses', 'warehouse_code'), 'warehouse_name' => 'required|string|max:120',
                'warehouse_type' => 'required|string|max:40', 'manager' => 'prohibited',
                'management_scope' => 'sometimes|required|in:factory,office',
                'manager_user_id' => 'nullable|integer|min:1',
                'expected_manager_user_id' => 'nullable|integer|min:1',
                'status' => 'required|in:enabled,disabled', 'remark' => 'nullable|string',
            ],
            'locations' => [
                'location_code' => $unique('erp_locations', 'location_code'), 'location_name' => 'required|string|max:120',
                'location_purpose' => 'sometimes|required|in:general,finished,shipping_staging,repair',
                'warehouse_id' => 'required|exists:erp_warehouses,id', 'parent_id' => 'nullable|exists:erp_locations,id',
                'area' => 'nullable|string|max:60', 'aisle' => 'nullable|string|max:60', 'rack' => 'nullable|string|max:60',
                'level' => 'nullable|string|max:60', 'standard_capacity' => 'nullable|numeric|min:0',
                'allow_mixed' => 'boolean', 'status' => 'required|in:enabled,disabled', 'remark' => 'nullable|string',
            ],
        };
    }

    private function normalizeItemSerialTracking(array &$data, ?Item $item = null): void
    {
        $mode = $data['serial_tracking_mode'] ?? $item?->serialTrackingMode() ?? 'none';
        $data['serial_tracking_mode'] = $mode;
        $data['is_serial_managed'] = $mode !== 'none';
        if ($mode === 'none') $data['serial_number_prefix'] = null;

        if ($item && $mode === 'none') {
            $hasSerialHistory = DB::table('erp_inventory_serials')->where('item_id', $item->id)->exists();
            abort_if($hasSerialHistory, 422, '该物料已存在单件追溯档案，不能改为仅批次追溯。');
        }
    }

    private function enforceUnitBaseRule(Request $request, array $data, ?int $id = null): void
    {
        if ($request->route('entity') !== 'units' || empty($data['is_base'])) return;
        $exists = Unit::where('unit_type', $data['unit_type'])->when($id, fn ($q) => $q->whereKeyNot($id))->exists();
        abort_if($exists, 422, '同一单位类型只能有一个基本单位');
    }

    private function assertValidItemCategory(?int $categoryId, bool $allowHistoricalNull): void
    {
        if (!$categoryId) {
            abort_unless($allowHistoricalNull, 422, '新增 Item 必须选择一个启用的末级 Item 类目。');
            return;
        }
        $category = ItemCategory::whereKey($categoryId)->where('category_type', 'item')->where('status', 'enabled')->first();
        abort_unless($category, 422, '请选择启用的 Item 类目。');
        abort_if($category->children()->where('category_type', 'item')->exists(), 422, '只能选择末级 Item 类目。');
    }
}
