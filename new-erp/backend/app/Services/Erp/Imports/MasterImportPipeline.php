<?php

namespace App\Services\Erp\Imports;

use App\Models\Erp\{ImportBatch, ImportRow, Item, ItemCategory, Location, Product, Sku, SkuItemRelation, Supplier, Unit, Warehouse};
use App\Services\Erp\{ItemImportApplicationService, ItemManagementScopeService, MasterDataApplicationService, SkuAggregateApplicationService, SkuItemDefaultRelationService};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

/** Each entity owns its normalization; formal writes stay in the existing application services. */
class MasterImportPipeline
{
    // label, rule description, example, compatibility aliases
    private const ADAPTERS = [
        'Product' => [
            'entity' => 'products', 'model' => Product::class, 'key' => 'product_code', 'code_max' => 60,
            'name' => 'product_name', 'name_max' => 160, 'statuses' => 'draft,enabled,disabled', 'default_status' => 'enabled',
            'normalize' => 'normalizeProduct', 'persist' => 'persistMaster', 'fields' => [
            'product_code' => ['商品编码', '必填，唯一，最多60字', 'SP001'],
            'product_name' => ['商品名称', '必填，最多160字', '桌子'],
            'product_type' => ['商品类型', 'standard/标准商品；不支持bundle', 'standard'],
            'unit_code' => ['单位编码', '启用的标准单位编码或名称；启用商品必填', ''],
            'category_code' => ['类目编码', '可空；启用的商品类目编码', '', '商品类目编码'],
            'model' => ['型号', '可空，最多100字', ''],
            'brand' => ['品牌', '可空，最多100字', ''],
            'status' => ['状态', 'draft/草稿、enabled/启用、disabled/停用；默认启用，无单位只能草稿', 'draft'],
            'remark' => ['备注', '可空', ''],
            ],
        ],
        'SKU' => [
            'entity' => 'skus', 'model' => Sku::class, 'key' => 'sku_code', 'code_max' => 80,
            'name' => 'sku_name', 'name_max' => 160, 'statuses' => 'draft,enabled,disabled', 'default_status' => 'draft',
            'normalize' => 'normalizeSku', 'persist' => 'persistSku', 'fields' => [
            'product_code' => ['商品编码', '必填，已存在的商品编码', 'SP001', '所属商品编码'],
            'sku_code' => ['SKU编码', '必填，唯一，最多80字', 'SKU001'],
            'sku_name' => ['SKU名称', '必填，最多160字', '桌子'],
            'spec_text' => ['规格', '启用时必填，最多255字', '', '规格型号', 'spec_model'],
            'order_line_type' => ['订单行类型', 'physical/实物、service/服务、no_delivery/无需发货、deduction/扣减', 'physical', 'line_type'],
            'sales_unit_code' => ['销售单位编码', '可空，默认继承商品单位；须为启用的标准单位', '', '销售单位', 'sales_unit_name'],
            'default_item_code' => ['默认物料编码', '可空；实物SKU启用时必填，须为启用的工厂物料', '', '默认Item编码'],
            'default_item_factor' => ['换算系数', '默认1；大于0，最多10位整数和8位小数', 1, 'factor', '履约因子'],
            'status' => ['状态', 'draft/草稿、enabled/启用、disabled/停用；默认草稿', 'draft'],
            'remark' => ['备注', '可空', ''],
            ],
        ],
        'Item' => [
            'key' => 'item_code', 'normalize' => 'normalizeItem', 'persist' => 'persistItem', 'fields' => [
            'item_code' => ['物料编码', '必填，唯一，最多80字', 'WL001'],
            'item_name' => ['物料名称', '必填，最多160字', '木板'],
            'item_type' => ['物料类型', '成品、半成品、原材料、包装物、服务、办公用品或对应系统值', '原材料', '物料类型（中文选择）'],
            'management_scope' => ['管理范围', 'factory/工厂物料、office/办公用品；须与上传范围一致', 'factory', '物料管理范围'],
            'unit_code' => ['单位编码', '单位编码或基本单位必填；不存在时自动建档', ''],
            'unit_name' => ['基本单位', '可填写单位名称；不存在时自动建档', '件', '单位名称'],
            'category_code' => ['类目编码', '同范围启用末级物料类目；不存在时按填写的类目建档', ''],
            'category_name' => ['Item类目', '可填类目路径，如原材料/木材', '', '类目名称'],
            'spec' => ['规格', '可空，最多255字', ''],
            'supplier_code' => ['默认供应商编码', '可空；不存在时自动建档', '', '供应商编码'],
            'supplier_name' => ['默认供应商', '可空；不存在时自动建档', '', '供应商名称'],
            'warehouse_code' => ['默认仓库编码', '可空；仓库须启用且同管理范围，不存在时同范围建档', '', '仓库编码'],
            'warehouse_name' => ['默认仓库', '可空；不存在时同范围建档', '', '仓库名称'],
            'is_purchase_item' => ['可采购', '是/否，默认是', '是'],
            'is_stock_item' => ['库存管理', '是/否，默认是', '是'],
            'is_production_item' => ['可生产', '是/否，默认否；办公用品不可生产', '否'],
            'status' => ['状态', 'enabled/启用、disabled/停用，默认启用', 'enabled'],
            'remark' => ['备注', '可空', ''],
            ],
        ],
        'Supplier' => [
            'entity' => 'suppliers', 'model' => Supplier::class, 'key' => 'supplier_code', 'code_max' => 60,
            'name' => 'supplier_name', 'name_max' => 160, 'statuses' => 'enabled,disabled', 'default_status' => 'enabled',
            'normalize' => 'normalizeSupplier', 'persist' => 'persistMaster', 'fields' => [
            'supplier_code' => ['供应商编码', '必填，唯一，最多60字', 'GY001'],
            'supplier_name' => ['供应商名称', '必填，最多160字', '建国五金'],
            'supplier_type' => ['供应商类型', 'manufacturer/生产制造商、trader/贸易商、service/服务商', 'manufacturer'],
            'contact_name' => ['联系人', '可空，最多80字', '张建国'],
            'contact_phone' => ['联系电话', '可空，最多40字', ''],
            'address' => ['地址', '可空，最多255字', ''],
            'status' => ['状态', 'enabled/启用、disabled/停用，默认启用', 'enabled'],
            'remark' => ['备注', '可空', ''],
            ],
        ],
        'Warehouse' => [
            'entity' => 'warehouses', 'model' => Warehouse::class, 'key' => 'warehouse_code', 'code_max' => 60,
            'name' => 'warehouse_name', 'name_max' => 120, 'statuses' => 'enabled,disabled', 'default_status' => 'enabled',
            'normalize' => 'normalizeWarehouse', 'persist' => 'persistMaster', 'fields' => [
            'warehouse_code' => ['仓库编码', '必填，唯一，最多60字', 'CK001'],
            'warehouse_name' => ['仓库名称', '必填，最多120字', '原材料仓'],
            'management_scope' => ['管理范围', 'factory/工厂物料仓、office/办公用品仓；默认factory，不允许未知范围', 'factory'],
            'warehouse_type' => ['仓库类型', 'general、raw_material、finished_goods、semi_finished、wip、packaging、defective', 'general'],
            'status' => ['状态', 'enabled/启用、disabled/停用，默认启用', 'enabled'],
            'remark' => ['备注', '可空', ''],
            ],
        ],
        'Location' => [
            'entity' => 'locations', 'model' => Location::class, 'key' => 'location_code', 'code_max' => 80,
            'name' => 'location_name', 'name_max' => 120, 'statuses' => 'enabled,disabled', 'default_status' => 'enabled',
            'normalize' => 'normalizeLocation', 'persist' => 'persistMaster', 'fields' => [
            'warehouse_code' => ['仓库编码', '必填，已存在且启用的仓库编码', 'CK001'],
            'location_code' => ['库位编码', '必填，唯一，最多80字', 'KW001'],
            'location_name' => ['库位名称', '必填，最多120字', '一号库位'],
            'location_purpose' => ['库位用途', 'general/普通库位、finished/成品区、shipping_staging/待发区、repair/返修区', 'general'],
            'status' => ['状态', 'enabled/启用、disabled/停用，默认启用', 'enabled'],
            'remark' => ['备注', '可空', ''],
            ],
        ],
        'SKU-Item Relation' => [
            'key' => 'sku_id', 'normalize' => 'normalizeRelation', 'persist' => 'persistRelation', 'fields' => [
            'sku_code' => ['SKU编码', '必填，实物SKU编码；每批同一SKU只允许一行', 'SKU001'],
            'item_code' => ['物料编码', '必填，启用的工厂物料，基本单位须启用', 'WL001'],
            'factor' => ['换算系数', '默认1；大于0，最多10位整数和8位小数', 1, 'qty', '数量', '履约因子'],
            'change_reason' => ['变更原因', '默认批量导入；填其他时必须填备注，最多80字', '批量导入'],
            'remark' => ['备注', '可空，最多1000字；旧主关系自动失效并记日志', ''],
            ],
        ],
    ];

    public static function types(): array
    {
        return array_keys(self::ADAPTERS);
    }

    public static function supportsScope(string $type): bool
    {
        return isset(self::ADAPTERS[$type]['fields']['management_scope']);
    }

    public function preview(ImportBatch $source, object $operator): ImportBatch
    {
        return DB::transaction(function () use ($source, $operator) {
            $batch = ImportBatch::query()->lockForUpdate()->findOrFail($source->id);
            $this->assertType($batch->import_type);
            abort_unless(in_array($batch->status, ['uploaded', 'previewed'], true) && $batch->confirmed_at === null,
                422, '已确认的导入批次不能重新预检。');
            $path = Storage::path((string) $batch->stored_path);
            if (!is_file($path)) $path = storage_path('app/private/'.$batch->stored_path);
            if (!is_file($path)) $path = storage_path('app/'.$batch->stored_path);
            abort_unless(is_file($path), 404, '导入文件不存在。');
            $book = IOFactory::load($path);
            try {
                $sheet = $book->getSheet(0)->toArray(null, true, true, false);
            } finally {
                $book->disconnectWorksheets();
            }
            abort_if(count($sheet) < 2, 422, '文件没有可导入的数据。');
            $headers = array_map(fn ($value) => trim(ltrim((string) $value, "\xEF\xBB\xBF")), array_shift($sheet));
            $namedHeaders = array_filter($headers, fn ($header) => $header !== '');
            abort_if(count($namedHeaders) !== count(array_unique($namedHeaders)), 422, '文件存在重复表头，请使用标准模板。');
            $batch->rows()->delete();
            $counts = ['valid' => 0, 'warning' => 0, 'error' => 0];
            $seen = [];
            foreach ($sheet as $index => $values) {
                if (!array_filter($values, fn ($value) => $value !== null && trim((string) $value) !== '')) continue;
                $raw = [];
                foreach ($headers as $column => $header) if ($header !== '') $raw[$header] = $values[$column] ?? null;
                $result = ['normalized_data' => null, 'validation_status' => 'valid'];
                try {
                    $normalized = $this->normalize($batch->import_type, $raw, $batch->management_scope, $operator);
                    $this->assertDistinct($batch->import_type, $normalized, $seen);
                    $result['normalized_data'] = $normalized;
                } catch (ValidationException $error) {
                    $errors = $error->errors();
                    $field = (string) array_key_first($errors);
                    $result += ['error_field' => $field, 'error_type' => $field === 'management_scope' ? 'management_scope' : 'invalid',
                        'error_reason' => $errors[$field][0], 'suggestion' => '按字段规范修正后重新预检。'];
                    $result['validation_status'] = 'error';
                } catch (HttpExceptionInterface $error) {
                    if ($error->getStatusCode() !== 422) throw $error;
                    $result += ['error_field' => null, 'error_type' => 'invalid', 'error_reason' => $error->getMessage(),
                        'suggestion' => '修正主数据或文件后重新预检。'];
                    $result['validation_status'] = 'error';
                }
                $counts[$result['validation_status']]++;
                ImportRow::create(['batch_id' => $batch->id, 'row_no' => $index + 2, 'raw_data' => $raw, ...$result]);
            }
            abort_if(array_sum($counts) === 0, 422, '文件没有可导入的数据。');
            $batch->update(['status' => 'previewed', 'total_rows' => array_sum($counts),
                'valid_rows' => $counts['valid'], 'warning_rows' => $counts['warning'], 'error_rows' => $counts['error']]);
            return $batch->fresh();
        }, 5);
    }

    public function confirm(ImportBatch $source, object $operator): array
    {
        return DB::transaction(function () use ($source, $operator) {
            $batch = ImportBatch::query()->lockForUpdate()->findOrFail($source->id);
            $this->assertType($batch->import_type);
            abort_unless($batch->status === 'previewed' && $batch->confirmed_at === null,
                422, '请先完成预检；已经确认的批次不能重复导入。');
            abort_if($batch->rows()->where('validation_status', 'error')->where('error_type', 'management_scope')->exists(),
                422, '导入批次存在管理范围冲突，请修正文件并重新预检。');
            $rows = $batch->rows()->whereIn('validation_status', ['valid', 'warning'])->orderBy('row_no')->orderBy('id')->lockForUpdate()->get();
            abort_if($rows->isEmpty(), 422, '没有可以确认导入的数据，请修正文件并重新预检。');
            $seen = [];
            foreach ($rows as $row) {
                // Old batches stored raw columns in normalized_data. Resolve codes again at
                // confirmation so neither stale IDs nor a preflight snapshot bypass current rules.
                $raw = $row->raw_data ?: ($row->normalized_data ?? []);
                try {
                    $data = $this->normalize($batch->import_type, $raw, $batch->management_scope, $operator, true);
                    $this->assertDistinct($batch->import_type, $data, $seen);
                    $target = $this->persist($batch->import_type, $data, $batch->management_scope, $operator);
                } catch (ValidationException $error) {
                    throw ValidationException::withMessages(['rows.'.$row->row_no => '第'.$row->row_no.'行：'.collect($error->errors())->flatten()->first().' 请重新预检。']);
                }
                $row->update(['normalized_data' => $data, 'target_id' => $target->getKey()]);
            }
            $batch->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            return ['batch' => $batch->fresh(), 'imported_rows' => $rows->count()];
        }, 5);
    }

    public function normalize(string $type, array $raw, ?string $scope, object $operator, bool $lock = false): array
    {
        $this->assertType($type);
        $adapter = self::ADAPTERS[$type];
        $data = [];
        foreach ($adapter['fields'] as $field => $definition) {
            $data[$field] = $this->value($raw, [$field, $definition[0], ...array_slice($definition, 3)]);
        }
        if (isset($adapter['name'])) {
            $data['status'] = $this->enum($data['status'], ['启用' => 'enabled', '停用' => 'disabled', '草稿' => 'draft']) ?? $adapter['default_status'];
            $modelClass = $adapter['model'];
            $model = new $modelClass;
            $this->validate($data, [
                $adapter['key'] => 'required|string|max:'.$adapter['code_max'].'|unique:'.$model->getTable().','.$adapter['key'],
                $adapter['name'] => 'required|string|max:'.$adapter['name_max'],
                'status' => 'in:'.$adapter['statuses'], 'remark' => 'nullable|string',
            ], $type);
        }
        return $this->{$adapter['normalize']}($data, $raw, $scope, $operator, $lock);
    }

    private function normalizeItem(array $data, array $raw, ?string $scope, object $operator, bool $lock): array
    {
        $importer = app(ItemImportApplicationService::class);
        try {
            $importer->validateScopeRow($raw, $scope);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(['management_scope' => collect($error->errors())->flatten()->first()]);
        }
        $normalized = array_replace(array_map(fn ($value) => is_string($value) ? trim($value) : $value, $raw),
            array_filter($data, fn ($value) => $value !== null));
        $importer->validateRow($normalized, $scope);
        return $normalized;
    }

    private function normalizeProduct(array $data, array $raw, ?string $scope, object $operator, bool $lock): array
    {
        $data['product_type'] = $this->enum($data['product_type'], ['标准商品' => 'standard']) ?? 'standard';
        $this->validate($data, ['product_type' => 'in:standard', 'model' => 'nullable|string|max:100', 'brand' => 'nullable|string|max:100'], 'Product');
        $unit = $this->resolveUnit($data['unit_code'] ?? $this->value($raw, ['unit_name', '单位名称', '基本单位']), 'unit_code', $lock);
        if (!$unit && $data['status'] !== 'draft') {
            throw ValidationException::withMessages(['unit_code' => '未填写基本单位的商品只能导入为草稿。']);
        }
        $data['unit_id'] = $unit?->id;
        $data['category_id'] = null;
        if ($data['category_code'] !== null) {
            $query = ItemCategory::where('category_code', $data['category_code'])->where('category_type', 'product')->where('status', 'enabled');
            $category = ($lock ? $query->lockForUpdate() : $query)->first();
            if (!$category) throw ValidationException::withMessages(['category_code' => '请选择启用的商品类目编码。']);
            $data['category_id'] = $category->id;
        }
        unset($data['unit_code'], $data['category_code']);
        return $data;
    }

    private function normalizeSku(array $data, array $raw, ?string $scope, object $operator, bool $lock): array
    {
        $this->validate($data, ['product_code' => 'required|string|max:60', 'spec_text' => 'nullable|string|max:255'], 'SKU');
        $query = Product::where('product_code', $data['product_code']);
        $product = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$product) throw ValidationException::withMessages(['product_code' => '所属商品不存在，请先导入商品。']);
        $data['product_id'] = $product->id;
        $data['order_line_type'] = $this->enum($data['order_line_type'] ?? $this->value($raw, ['fulfillment_type', '履约方式']),
            ['实物' => 'physical', '服务' => 'service', '无需发货' => 'no_delivery', '扣减' => 'deduction', 'virtual' => 'no_delivery']) ?? 'physical';
        $unit = $this->resolveUnit($data['sales_unit_code'] ?? $this->value($raw, ['unit_code', '单位编码']), 'sales_unit_code', $lock);
        if (!$unit && $product->unit_id) {
            $query = Unit::whereKey($product->unit_id)->where('status', 'enabled')->where('is_legacy', false);
            $unit = ($lock ? $query->lockForUpdate() : $query)->first();
            if (!$unit) throw ValidationException::withMessages(['sales_unit_code' => '商品基本单位已停用或不是标准单位，请明确填写销售单位。']);
        }
        $data['sales_unit_id'] = $unit?->id;
        if ($data['default_item_code'] !== null) {
            $item = $this->factoryItem($data['default_item_code'], 'default_item_code', $lock);
            $data['default_item_id'] = $item->id;
        }
        if (!is_numeric($data['default_item_factor'] ?? 1)) {
            throw ValidationException::withMessages(['default_item_factor' => '换算系数必须是数字。']);
        }
        $data['default_item_factor'] = app(SkuItemDefaultRelationService::class)->validateFactor($data['default_item_factor'] ?? 1);
        unset($data['product_code'], $data['sales_unit_code'], $data['default_item_code']);
        return app(SkuAggregateApplicationService::class)->validate(null, $data, $operator);
    }

    private function normalizeSupplier(array $data, array $raw, ?string $scope, object $operator, bool $lock): array
    {
        $data['supplier_type'] = $this->enum($data['supplier_type'], ['生产制造商' => 'manufacturer', '制造商' => 'manufacturer', '贸易商' => 'trader', '服务商' => 'service']) ?? 'manufacturer';
        $this->validate($data, ['supplier_type' => 'in:manufacturer,trader,service', 'contact_name' => 'nullable|string|max:80',
            'contact_phone' => 'nullable|string|max:40', 'address' => 'nullable|string|max:255'], 'Supplier');
        return $data;
    }

    private function normalizeWarehouse(array $data, array $raw, ?string $batchScope, object $operator, bool $lock): array
    {
        $scopeLabels = ['工厂物料仓' => 'factory', '办公用品仓' => 'office', '工厂物料' => 'factory', '办公用品' => 'office'];
        $scopes = [];
        foreach (['management_scope', '管理范围'] as $key) {
            if ($value = $this->value($raw, [$key])) $scopes[] = $this->enum($value, $scopeLabels);
        }
        if (count(array_unique($scopes)) > 1) throw ValidationException::withMessages(['management_scope' => '同一导入行的管理范围列相互冲突。']);
        $data['management_scope'] = $this->enum($data['management_scope'], $scopeLabels) ?? ($batchScope ?? 'factory');
        $data['warehouse_type'] = $this->enum($data['warehouse_type'], ['综合仓' => 'general', '原材料仓' => 'raw_material', '成品仓' => 'finished_goods',
            '半成品仓' => 'semi_finished', '线边仓' => 'wip', '包材仓' => 'packaging', '不良品仓' => 'defective']) ?? 'general';
        $this->validate($data, ['management_scope' => 'in:factory,office',
            'warehouse_type' => 'in:general,raw_material,finished_goods,semi_finished,wip,packaging,defective'], 'Warehouse');
        if ($batchScope !== null && $data['management_scope'] !== $batchScope) {
            throw ValidationException::withMessages(['management_scope' => '仓库管理范围与上传批次范围不一致。']);
        }
        return $data;
    }

    private function normalizeLocation(array $data, array $raw, ?string $scope, object $operator, bool $lock): array
    {
        $this->validate($data, ['warehouse_code' => 'required|string|max:60'], 'Location');
        $query = Warehouse::where('warehouse_code', $data['warehouse_code'])->where('status', 'enabled');
        $warehouse = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$warehouse) throw ValidationException::withMessages(['warehouse_code' => '所属仓库不存在或未启用，请先维护仓库。']);
        $data['warehouse_id'] = $warehouse->id;
        $data['location_purpose'] = $this->enum($data['location_purpose'], ['普通库位' => 'general', '成品区' => 'finished', '待发区' => 'shipping_staging', '返修区' => 'repair']) ?? 'general';
        $this->validate($data, ['location_purpose' => 'in:general,finished,shipping_staging,repair'], 'Location');
        unset($data['warehouse_code']);
        return $data;
    }

    private function normalizeRelation(array $data, array $raw, ?string $scope, object $operator, bool $lock): array
    {
        $this->validate($data, ['sku_code' => 'required|string|max:80', 'item_code' => 'required|string|max:80',
            'factor' => 'nullable|numeric', 'change_reason' => 'nullable|string|max:80', 'remark' => 'nullable|string|max:1000'], 'SKU-Item Relation');
        $query = Sku::where('sku_code', $data['sku_code']);
        $sku = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$sku || $sku->line_type !== 'physical') throw ValidationException::withMessages(['sku_code' => '默认物料只能绑定到已存在的实物SKU。']);
        $item = $this->factoryItem($data['item_code'], 'item_code', $lock);
        $relationType = $this->value($raw, ['relation_type', '关系类型']);
        if ($relationType !== null && !in_array($relationType, ['primary', 'finished_product', '默认物料', '默认关系'], true)) {
            throw ValidationException::withMessages(['relation_type' => '该入口只导入默认物料关系。']);
        }
        $primary = $this->value($raw, ['is_primary', '是否主Item']);
        if ($primary !== null && !in_array($primary, [true, 1, '1', 'true', '是'], true)) {
            throw ValidationException::withMessages(['is_primary' => '该入口只导入默认物料关系。']);
        }
        app(SkuAggregateApplicationService::class)->authorizeDefaultItem($operator);
        $factor = app(SkuItemDefaultRelationService::class)->validateFactor($data['factor'] ?? 1);
        $active = SkuItemRelation::where('sku_id', $sku->id)->where('status', 'active')->where('is_primary', true)->get();
        if ($active->count() > 1) throw ValidationException::withMessages(['sku_code' => '该SKU存在多个有效默认物料，请先修复。']);
        $old = $active->first();
        if ($old && (int) $old->item_id === (int) $item->id && bccomp((string) $old->qty, $factor, 8) === 0) {
            throw ValidationException::withMessages(['item_code' => '默认物料与换算系数和当前关系相同，无需导入。']);
        }
        $reason = $data['change_reason'] ?? '批量导入';
        if ($reason === '其他' && blank($data['remark'])) throw ValidationException::withMessages(['remark' => '变更原因选择其他时必须填写备注。']);
        return ['sku_id' => $sku->id, 'item_id' => $item->id, 'factor' => $factor, 'change_reason' => $reason, 'remark' => $data['remark']];
    }

    private function persist(string $type, array $data, ?string $scope, object $operator): Model
    {
        $adapter = self::ADAPTERS[$type];
        return $this->{$adapter['persist']}($adapter, $data, $scope, $operator);
    }

    private function persistMaster(array $adapter, array $data, ?string $scope, object $operator): Model
    {
        return app(MasterDataApplicationService::class)->create($adapter['entity'], $adapter['model'], $data, (int) $operator->legacy_id);
    }

    private function persistSku(array $adapter, array $data, ?string $scope, object $operator): Model
    {
        return app(SkuAggregateApplicationService::class)->save(null, $data, $operator);
    }

    private function persistItem(array $adapter, array $data, ?string $scope, object $operator): Model
    {
        return app(ItemImportApplicationService::class)->create($data, $scope, (int) $operator->legacy_id);
    }

    private function persistRelation(array $adapter, array $data, ?string $scope, object $operator): Model
    {
        $operatorId = (int) $operator->legacy_id;
        return app(SkuItemDefaultRelationService::class)->setPrimary($data['sku_id'], $data['item_id'], $data['change_reason'],
            $data['remark'], $operatorId, (string) (($operator->nickname ?? null) ?: ($operator->username ?? null) ?: $operatorId), $data['factor']);
    }

    private function resolveUnit(?string $value, string $field, bool $lock): ?Unit
    {
        if ($value === null) return null;
        $query = Unit::where('status', 'enabled')->where('is_legacy', false)
            ->where(fn ($query) => $query->where('unit_code', $value)->orWhere('unit_name', $value));
        $units = ($lock ? $query->lockForUpdate() : $query)->get();
        if ($units->count() !== 1) throw ValidationException::withMessages([$field => '单位编码或名称必须唯一匹配一个启用的标准单位。']);
        return $units->first();
    }

    private function factoryItem(string $code, string $field, bool $lock): Item
    {
        $query = Item::with('unit')->where('item_code', $code)->where('status', 'enabled');
        $item = ($lock ? $query->lockForUpdate() : $query)->first();
        if (!$item || !$item->unit || $item->unit->status !== 'enabled') {
            throw ValidationException::withMessages([$field => '默认物料及其基本单位必须存在且已启用。']);
        }
        try {
            app(ItemManagementScopeService::class)->assertProductionAllowed($item, $field);
        } catch (ValidationException $error) {
            throw ValidationException::withMessages(['management_scope' => collect($error->errors())->flatten()->first()]);
        }
        return $item;
    }

    private function assertDistinct(string $type, array $data, array &$seen): void
    {
        $field = self::ADAPTERS[$type]['key'];
        $key = mb_strtolower((string) $data[$field]);
        if (isset($seen[$key])) throw ValidationException::withMessages([$field => '同一文件中存在重复编码或重复的SKU默认关系。']);
        $seen[$key] = true;
    }

    private function validate(array $data, array $rules, string $type): void
    {
        $labels = [];
        foreach (self::ADAPTERS[$type]['fields'] as $key => $field) $labels[$key] = $field[0];
        Validator::make($data, $rules, [], $labels)->validate();
    }

    private function value(array $row, array $keys): ?string
    {
        foreach ($keys as $key) {
            if (isset($row[$key]) && trim((string) $row[$key]) !== '') return trim((string) $row[$key]);
        }
        return null;
    }

    private function enum(?string $value, array $labels): ?string
    {
        return $value === null ? null : ($labels[$value] ?? $value);
    }

    private function assertType(string $type): void
    {
        if (!isset(self::ADAPTERS[$type])) throw ValidationException::withMessages(['import_type' => '不支持的主数据导入类型。']);
    }

    public function template(string $type, ?string $scope = null): Spreadsheet
    {
        $this->assertType($type);
        app(ItemManagementScopeService::class)->validateScope($scope);
        abort_if($scope !== null && !self::supportsScope($type), 422, '该类型不使用管理范围。');
        $book = new Spreadsheet;
        $sheet = $book->getActiveSheet()->setTitle('导入数据');
        $dictionary = $book->createSheet()->setTitle('字段规范与枚举字典说明');
        $this->sheetRow($dictionary, 1, ['字段', '系统字段', '规则与枚举', '示例或参考']);
        $column = 1;
        $line = 2;
        $unit = Unit::where('status', 'enabled')->where('is_legacy', false)->orderBy('id')->first();
        foreach (self::ADAPTERS[$type]['fields'] as $key => [$label, $description, $example]) {
            if ($key === 'management_scope') $example = $scope ?? 'factory';
            if ($type === 'Item' && $scope === 'office' && $key === 'item_type') $example = '办公用品';
            if (in_array($key, ['unit_code', 'sales_unit_code'], true)) $example = $unit?->unit_code ?? '';
            $sheet->setCellValueExplicit([$column, 1], $label, DataType::TYPE_STRING);
            $sheet->setCellValueExplicit([$column, 2], (string) $example, DataType::TYPE_STRING);
            $sheet->getColumnDimensionByColumn($column)->setWidth(22);
            $this->sheetRow($dictionary, $line++, [$label, $key, $description, (string) $example]);
            $column++;
        }
        $this->sheetRow($dictionary, $line++, ['填写说明', '', '第一张工作表包含一行示例，请按实际业务替换后上传；编码按文本填写。预检不写主数据，确认后写入正式档案。', '']);
        $this->sheetRow($dictionary, $line++, ['导入规则', '', '仅导入预检正确的行；管理范围冲突阻断整批。已确认批次不能重检或重复执行。', '']);
        if ($type === 'Item') $this->sheetRow($dictionary, $line++, ['物料自动建档', '', '单位、物料类目、供应商和仓库可自由填写；缺失时随确认自动建立。仓库始终与物料属于同一管理范围。', '']);
        $references = [
            ['启用标准单位', Unit::where('status', 'enabled')->where('is_legacy', false)->orderBy('unit_code'), 'unit_code', 'unit_name'],
            ['启用商品类目', ItemCategory::where('status', 'enabled')->where('category_type', 'product')->orderBy('category_code'), 'category_code', 'category_name'],
            ['启用物料类目', ItemCategory::where('status', 'enabled')->where('category_type', 'item')
                ->when($scope !== null, fn ($query) => $query->where('management_scope', $scope))->orderBy('category_code'), 'category_code', 'category_name'],
            ['启用仓库', Warehouse::where('status', 'enabled')->when($scope !== null, fn ($query) => $query->where('management_scope', $scope))->orderBy('warehouse_code'), 'warehouse_code', 'warehouse_name'],
        ];
        foreach ($references as [$title, $query, $code, $name]) {
            $this->sheetRow($dictionary, $line++, [$title, '编码', '名称', '管理范围']);
            foreach ($query->cursor() as $record) {
                $this->sheetRow($dictionary, $line++, ['', $record->{$code}, $record->{$name}, $record->management_scope ?? '']);
            }
        }
        foreach ([$sheet, $dictionary] as $tab) {
            $tab->freezePane('A2');
            $tab->getStyle('1:1')->getFont()->setBold(true);
            $tab->getStyle('1:1')->getFill()->setFillType('solid')->getStartColor()->setARGB('FFE8F5EE');
            $tab->getStyle($tab->calculateWorksheetDimension())->getAlignment()->setVertical('top')->setWrapText(true);
        }
        $dictionary->getColumnDimension('A')->setWidth(24);
        $dictionary->getColumnDimension('B')->setWidth(28);
        $dictionary->getColumnDimension('C')->setWidth(90);
        $dictionary->getColumnDimension('D')->setWidth(32);
        $book->setActiveSheetIndex(0);
        return $book;
    }

    private function sheetRow(Worksheet $sheet, int $row, array $values): void
    {
        foreach ($values as $column => $value) $sheet->setCellValueExplicit([$column + 1, $row], (string) $value, DataType::TYPE_STRING);
    }
}
