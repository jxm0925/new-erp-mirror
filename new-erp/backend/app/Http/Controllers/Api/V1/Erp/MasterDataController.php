<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Http\Controllers\Controller;
use App\Models\Erp\{Item, ItemCategory, Location, Product, Sku, Supplier, Unit, Warehouse};
use App\Services\Erp\AuthContextService;
use App\Services\Erp\MasterDataApplicationService;
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
        'warehouses' => [Warehouse::class, 'warehouse_code', 'warehouse_name', ['locations']],
        'locations' => [Location::class, 'location_code', 'location_name', ['warehouse', 'parent']],
    ];

    public function index(Request $request)
    {
        [$model, $code, $name, $relations] = $this->config($request);
        // Product/SKU selectors only need Product master fields. Loading every SKU
        // and Item relation makes a selector wait on the entire master catalogue.
        if ($request->route('entity') === 'products' && !$request->boolean('include_skus')) {
            $relations = ['category', 'unit'];
        }
        if ($request->route('entity') === 'items') {
            $relations = ['category', 'unit.standardUnit', 'baseItem', 'defaultSupplier', 'activeDefaultSupplierRelation.supplier', 'activeMaterialPolicy'];
        }
        $query = $model::query()->with($relations);
        if ($request->route('entity') === 'products') {
            $query->withCount('skus');
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
                if ($request->route('entity') === 'items') {
                    $q->orWhere('spec', 'like', "%{$keyword}%")->orWhere('model', 'like', "%{$keyword}%");
                }
            });
        }
        foreach (['status', 'product_id', 'category_id', 'unit_id', 'warehouse_id', 'item_type', 'unit_type', 'category_type', 'supplier_type', 'approval_status', 'cooperation_status', 'quality_status', 'is_purchase_item'] as $field) {
            if (!$request->filled($field)) continue;
            if ($request->route('entity') === 'suppliers' && $field === 'category_id') {
                $categoryId = $request->integer('category_id');
                $query->whereHas('categoryCapabilities', fn ($scope) => $scope
                    ->where('item_category_id', $categoryId)->where('status', 'active'));
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
            $query->where('order_line_type', 'physical')->where('status', 'enabled')->whereDoesntHave('itemRelations', function (Builder $relation) {
                $relation->where('status', 'active')->where('is_primary', true)->whereHas('item', fn (Builder $item) => $item->where('status', 'enabled'));
            });
        }
        if ($request->route('entity') === 'skus' && $request->boolean('order_available')) {
            $query->where('status', 'enabled')
                ->where('is_sale_item', true)
                ->whereNotNull('sales_unit_id')
                ->whereNotNull('sale_price')
                ->whereHas('product', fn (Builder $product) => $product->where('status', 'enabled'))
                ->where(function (Builder $sku) {
                    $sku->whereIn('order_line_type', ['service', 'no_delivery'])
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
                        $available->whereIn('order_line_type', ['service', 'no_delivery'])
                            ->orWhereHas('itemRelations', function (Builder $relation) {
                                $relation->where('status', 'active')->where('is_primary', true)
                                    ->whereHas('item', fn (Builder $item) => $item->where('status', 'enabled'));
                            });
                    });
            });
        }
        $data = $query->latest('updated_at')->paginate(min(100, max(5, (int) $request->input('per_page', 20))));
        if ($request->route('entity') === 'items') {
            $data->getCollection()->transform(function (Item $item): Item {
                $relationSupplier = $item->activeDefaultSupplierRelation?->supplier;
                $legacySupplier = $item->defaultSupplier && $item->defaultSupplier->status === 'enabled' ? $item->defaultSupplier : null;
                $item->setRelation('defaultSupplier', $relationSupplier ?: $legacySupplier);
                $item->unsetRelation('activeDefaultSupplierRelation');
                return $item;
            });
        }
        return response()->json($data);
    }

    public function show(Request $request, int $id)
    {
        [$model, , , $relations] = $this->config($request);
        $record = $model::with($relations)->findOrFail($id);
        if ($record instanceof Item) {
            $record->setAttribute('base_unit_locked', $this->itemBaseUnitLocked((int) $record->id));
            $record->load('activeMaterialPolicy');
        }
        if ($record instanceof Sku) {
            $record->setAttribute('sales_unit_locked', $this->skuSalesUnitLocked((int) $record->id));
        }
        return response()->json($record);
    }

    public function store(Request $request, MasterDataApplicationService $service)
    {
        [$model] = $this->config($request);
        $this->prepareSkuCanonicalInput($request);
        $this->prepareSkuOrderLineType($request);
        $data = $request->validate($this->rules($request));
        if ($request->route('entity') === 'units' && empty($data['allow_decimal'])) $data['decimal_places'] = 0;
        if ($request->route('entity') === 'units') $data += ['is_legacy' => false, 'standard_unit_id' => null];
        if ($request->route('entity') === 'categories' && ($data['category_type'] ?? null) === 'item') {
            abort(422, 'Item 类目请使用专用 Item 类目接口维护。');
        }
        if ($request->route('entity') === 'items') {
            $this->assertValidItemCategory($data['category_id'] ?? null, false);
            $this->assertStandardBusinessUnit((int) $data['unit_id']);
            $this->normalizeItemSerialTracking($data);
        }
        if (in_array($request->route('entity'), ['products', 'skus', 'items', 'suppliers', 'warehouses', 'locations'], true)) {
            $data = array_merge($data, $request->validate([
                'reservation_token' => 'nullable|uuid',
                'creation_session_id' => 'nullable|uuid',
            ]));
        }
        $this->normalizeSkuOrderAttributeCapabilities($request, $data);
        if ($request->route('entity') === 'skus') $this->assertPhysicalSkuCanBeEnabled($data);
        $this->enforceUnitBaseRule($request, $data);
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
        [$model, $code] = $this->config($request);
        $record = $model::findOrFail($id);
        $this->prepareSkuCanonicalInput($request);
        $this->prepareSkuOrderLineType($request);
        $data = $request->validate($this->rules($request, $id));
        if ($request->route('entity') === 'units' && empty($data['allow_decimal'])) $data['decimal_places'] = 0;
        if ($request->route('entity') === 'categories' && ($data['category_type'] ?? null) === 'item') {
            abort(422, 'Item 类目请使用专用 Item 类目接口维护。');
        }
        if ($request->route('entity') === 'items' && !empty($data['category_id'])) $this->assertValidItemCategory($data['category_id'], true);
        if ($record instanceof Item) $this->normalizeItemSerialTracking($data, $record);
        if ($record instanceof Item && array_key_exists('unit_id', $data)
            && (int) $data['unit_id'] !== (int) $record->unit_id) {
            abort_if($this->itemBaseUnitLocked((int) $record->id), 422, '该 Item 已产生业务数据，基本单位不能直接修改。');
            $this->assertStandardBusinessUnit((int) $data['unit_id']);
        }
        if ($request->route('entity') === 'units') unset($data[$code]);
        if ($record instanceof Sku && array_key_exists('sales_unit_id', $data)
            && (int) $data['sales_unit_id'] !== (int) $record->sales_unit_id
            && $this->skuSalesUnitLocked((int) $record->id)) {
            abort(422, '该 SKU 已存在确认销售订单，销售单位不能直接修改。');
        }
        $this->normalizeSkuOrderAttributeCapabilities($request, $data);
        if ($record instanceof Sku) $this->assertPhysicalSkuCanBeEnabled($data, $record->id);
        $this->enforceUnitBaseRule($request, $data, $id);
        $record = $service->update(
            (string) $request->route('entity'),
            $record,
            $data,
            app(AuthContextService::class)->currentUser($request)?->legacy_id
        );
        return response()->json(['message' => '保存成功', 'data' => $record]);
    }

    public function disable(Request $request, int $id, MasterDataApplicationService $service)
    {
        [$model] = $this->config($request);
        $record = $model::findOrFail($id);
        $this->assertCanBeDisabled($record);
        $record = $service->setStatus($record, 'disabled');
        return response()->json(['message' => '已禁用', 'data' => $record]);
    }

    public function enable(Request $request, int $id, MasterDataApplicationService $service)
    {
        [$model] = $this->config($request);
        $record = $model::findOrFail($id);
        if ($record instanceof Sku) {
            $data = $record->getAttributes();
            $data['status'] = 'enabled';
            $this->assertPhysicalSkuCanBeEnabled($data, $record->id);
        }
        if ($record instanceof Product) $this->assertProductCanBeEnabled($record);
        if ($record instanceof Item) $this->assertItemCanBeEnabled($record);
        $record = $service->setStatus($record, 'enabled');
        return response()->json(['message' => '已启用', 'data' => $record->fresh()]);
    }

    public function destroy(Request $request, int $id, MasterDataApplicationService $service)
    {
        [$model] = $this->config($request);
        $entity = (string) $request->route('entity');
        $record = $model::findOrFail($id);
        $service->deleteUnused($entity, $record);

        return response()->json(['message' => '删除成功']);
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
            abort_if($usedByEnabledPhysicalSku, 422, '该 Item 是启用实物 SKU 的默认物料，请先替换默认 Item 或停用相关 SKU。');
        }
        if ($record instanceof Unit) {
            $references = $record->items()->exists()
                || $record->skus()->exists()
                || $record->products()->exists()
                || $record->purchaseConversions()->where('status', 'active')->exists();
            abort_if($references, 422, '该单位仍被 Item、SKU、商品或有效采购换算引用，不能停用。');
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
            ['erp_sales_order_production_requirements', 'item_id'],
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

    /** Upload a Product display image directly to OSS. */
    public function uploadProductImage(Request $request)
    {
        return $this->uploadMasterImage($request, 'product-images');
    }

    /** Upload a SKU sales-display image directly to OSS. */
    public function uploadSkuImage(Request $request)
    {
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
        $extension = strtolower($file->getClientOriginalExtension() ?: $file->extension() ?: 'jpg');
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
        return self::CONFIG[$request->route('entity')];
    }

    /**
     * SKU 仅维护“订单行是否支持/是否必填/允许值”的能力边界。
     * 未支持的能力不能残留必填或选项，避免前端与确认校验出现矛盾状态。
     */
    private function normalizeSkuOrderAttributeCapabilities(Request $request, array &$data): void
    {
        if ($request->route('entity') !== 'skus') return;

        $electricMode = $data['electric_mode'] ?? 'hidden';
        $pumpMode = $data['need_pump_mode'] ?? 'hidden';
        $data['electric_mode'] = $electricMode;
        $data['need_pump_mode'] = $pumpMode;
        unset($data['supports_electric'], $data['electric_required'], $data['electric_options'], $data['supports_need_pump'], $data['need_pump_required']);
        $data['allow_customized'] = (bool) ($data['allow_customized'] ?? false);
        $data['allow_special_customized'] = (bool) ($data['allow_special_customized'] ?? false);
        if (!$data['allow_special_customized']) {
            $data['special_custom_drawing_required'] = false;
            $data['special_custom_agreement_required'] = false;
            $data['special_custom_description_required'] = false;
        }
        // 电压选项属于订单行的统一选项，不在 SKU 层保存重复规则。
    }

    /** Maps legacy storage names to the formal SKU API fields. */
    private function prepareSkuCanonicalInput(Request $request): void
    {
        if ($request->route('entity') !== 'skus') return;

        $request->merge([
            'spec_text' => $request->input('spec_model', $request->input('spec_text')),
            'order_line_type' => $request->input('line_type', $request->input('order_line_type')),
            'is_sale_item' => $request->input('is_sellable', $request->input('is_sale_item')),
            'is_customizable' => $request->boolean('allow_customized') || $request->boolean('allow_special_customized'),
        ]);
    }

    private function prepareSkuOrderLineType(Request $request): void
    {
        if ($request->route('entity') !== 'skus') return;

        $type = $request->input('order_line_type');
        if (!$type) {
            $legacy = $request->input('fulfillment_type', 'physical');
            $type = $legacy === 'virtual' ? 'no_delivery' : $legacy;
        }
        // fulfillment_type 仅是旧接口兼容字段，页面与业务判断只使用 order_line_type。
        $request->merge([
            'order_line_type' => $type,
            'fulfillment_type' => $type === 'no_delivery' ? 'virtual' : $type,
        ]);
    }

    private function assertPhysicalSkuCanBeEnabled(array $data, ?int $skuId = null): void
    {
        if (($data['status'] ?? 'draft') !== 'enabled') return;

        abort_unless(!empty($data['sku_code']) && !empty($data['sku_name']) && !empty($data['product_id']) && !blank($data['spec_text'] ?? null), 422, 'SKU 编码、名称、所属 Product 与规格型号必须完整后才能启用。');
        abort_unless(!empty($data['sales_unit_id']), 422, '请先维护销售单位后再启用 SKU。');
        abort_unless(array_key_exists('sale_price', $data) && $data['sale_price'] !== null && $data['sale_price'] !== '', 422, '请先维护默认销售价格后再启用 SKU。');
        abort_unless(in_array($data['order_line_type'] ?? '', ['physical', 'service', 'no_delivery'], true), 422, '订单行类型无效，不能启用 SKU。');
        abort_unless(in_array($data['electric_mode'] ?? 'hidden', ['hidden', 'optional', 'required'], true), 422, '电压属性模式无效。');
        abort_unless(in_array($data['need_pump_mode'] ?? 'hidden', ['hidden', 'optional', 'required'], true), 422, '原水泵控制属性模式无效。');
        abort_unless(DB::table('erp_products')->where('id', $data['product_id'])->where('status', 'enabled')->exists(), 422, '所属 Product 必须处于启用状态。');
        abort_unless(DB::table('erp_units')->where('id', $data['sales_unit_id'])->where('status', 'enabled')->exists(), 422, '销售单位必须处于启用状态。');

        if (($data['order_line_type'] ?? 'physical') !== 'physical') return;

        $hasDefaultItem = $skuId && DB::table('erp_sku_item_relations as relation')
            ->join('erp_items as item', 'item.id', '=', 'relation.item_id')
            ->where('relation.sku_id', $skuId)
            ->where('relation.status', 'active')
            ->where('relation.is_primary', true)
            ->where('item.status', 'enabled')
            ->exists();
        abort_unless($hasDefaultItem, 422, '实物 SKU 保存并启用前必须绑定一个有效默认 Item；可先保存草稿。');
    }

    private function rules(Request $request, ?int $id = null): array
    {
        $entity = $request->route('entity');
        $unique = fn (string $table, string $column) => ['required', 'string', 'max:120', Rule::unique($table, $column)->ignore($id)];
        $currentSalesUnitId = $entity === 'skus' && $id ? Sku::whereKey($id)->value('sales_unit_id') : null;
        $salesUnitExists = Rule::exists('erp_units', 'id')->where(function ($query) use ($currentSalesUnitId) {
            $query->where('status', 'enabled')->where(function ($unit) use ($currentSalesUnitId) {
                $unit->where('is_legacy', false);
                if ($currentSalesUnitId) $unit->orWhere('id', $currentSalesUnitId);
            });
        });
        return match ($entity) {
            'products' => [
                'product_code' => $unique('erp_products', 'product_code'), 'product_name' => 'required|string|max:160',
                'product_type' => 'required|string|max:40', 'category_id' => 'nullable|exists:erp_item_categories,id',
                'model' => 'nullable|string|max:100', 'unit_id' => 'nullable|exists:erp_units,id',
                'search_aliases' => 'nullable|string|max:500', 'search_keywords' => 'nullable|string',
                'brand' => 'nullable|string|max:100', 'origin' => 'nullable|string|max:120',
                'image' => 'nullable|string|max:255', 'description' => 'nullable|string', 'status' => 'required|in:enabled,disabled',
                'remark' => 'nullable|string',
            ],
            'skus' => [
                'product_id' => 'required|exists:erp_products,id', 'sku_code' => $unique('erp_skus', 'sku_code'),
                'sku_name' => 'required|string|max:160', 'spec_text' => 'nullable|string|max:255', 'image' => 'nullable|string|max:255',
                'search_aliases' => 'nullable|string|max:500', 'search_keywords' => 'nullable|string',
                'sale_price' => 'nullable|numeric|min:0', 'reference_cost' => 'nullable|numeric|min:0',
                'default_tax_rate' => 'nullable|numeric|min:0|max:1',
                'default_price_tax_mode' => 'nullable|in:tax_inclusive,tax_exclusive',
                'product_structure_type' => 'nullable|string|max:40', 'production_policy' => 'nullable|string|max:40',
                'order_line_type' => 'required|in:physical,service,no_delivery',
                'fulfillment_type' => 'nullable|in:physical,service,virtual',
                'sales_unit_id' => ['nullable', $salesUnitExists], 'sales_unit_snapshot' => 'nullable|string|max:80',
                'is_customizable' => 'boolean', 'is_need_production' => 'boolean', 'is_need_bom' => 'boolean',
                'is_sale_item' => 'boolean', 'is_custom_sku' => 'boolean',
                'supports_electric' => 'boolean', 'electric_required' => 'boolean',
                'electric_options' => 'nullable|array', 'electric_options.*' => 'string|max:40',
                'supports_need_pump' => 'boolean', 'need_pump_required' => 'boolean',
                'electric_mode' => 'nullable|in:hidden,optional,required', 'need_pump_mode' => 'nullable|in:hidden,optional,required',
                'allow_customized' => 'boolean', 'allow_special_customized' => 'boolean',
                'special_custom_drawing_required' => 'boolean', 'special_custom_agreement_required' => 'boolean',
                'special_custom_description_required' => 'boolean', 'delivery_inspection_required' => 'boolean',
                'base_sku_id' => 'nullable|exists:erp_skus,id',
                'custom_scope' => 'nullable|in:none,order,customer,reusable',
                'custom_source_type' => 'nullable|in:sales_order,manual,import',
                'custom_source_id' => 'nullable|integer|min:0',
                'customer_id' => 'nullable|integer|min:0',
                'sales_order_id' => 'nullable|integer|min:0',
                'sales_order_line_id' => 'nullable|integer|min:0',
                'custom_status' => 'nullable|in:draft,active,disabled,archived',
                'custom_description' => 'nullable|string',
                'status' => 'required|in:draft,enabled,disabled', 'remark' => 'nullable|string',
            ],
            'items' => [
                'item_code' => $unique('erp_items', 'item_code'), 'item_name' => 'required|string|max:160',
                'item_type' => 'required|in:finished_product,semi_finished,raw_material,packaging,service,office_consumable',
                'category_id' => [$id ? 'nullable' : 'required', 'integer', 'exists:erp_item_categories,id'], 'spec' => 'nullable|string|max:255',
                'unit_id' => 'required|exists:erp_units,id', 'brand' => 'nullable|string|max:100', 'model' => 'nullable|string|max:100',
                'is_purchase_item' => 'boolean', 'is_stock_item' => 'boolean', 'is_production_item' => 'boolean',
                'is_batch_managed' => 'boolean', 'is_serial_managed' => 'boolean',
                'serial_tracking_mode' => 'nullable|in:none,optional,required',
                'serial_number_prefix' => 'nullable|string|max:30|regex:/^[A-Za-z0-9_-]+$/',
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
                'parent_id' => 'nullable|exists:erp_item_categories,id', 'category_type' => 'required|in:product,item,supplier',
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
                'warehouse_type' => 'required|string|max:40', 'manager' => 'nullable|string|max:80',
                'status' => 'required|in:enabled,disabled', 'remark' => 'nullable|string',
            ],
            'locations' => [
                'location_code' => $unique('erp_locations', 'location_code'), 'location_name' => 'required|string|max:120',
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
            abort_if($hasSerialHistory, 422, '该 Item 已存在单件追溯档案，不能改为仅批次追溯。');
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
