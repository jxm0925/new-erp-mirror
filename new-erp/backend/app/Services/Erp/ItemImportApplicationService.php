<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, ItemCategory, Supplier, Unit, Warehouse};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class ItemImportApplicationService
{
    public function __construct(private readonly DocumentNumberService $numbers)
    {
    }

    public function create(array $row, ?string $batchScope = null, ?int $operatorId = null): Item
    {
        return DB::transaction(function () use ($row, $batchScope, $operatorId) {
            $this->validateRow($row, $batchScope);
            if (! app(InventoryAmountVisibilityService::class)->canView()) {
                foreach (['standard_cost', '标准成本', 'last_purchase_price', '最近采购价'] as $field) {
                    if (isset($row[$field]) && $row[$field] !== '') {
                        throw ValidationException::withMessages([$field => '导入库存成本需要查看库存金额与成本权限。']);
                    }
                }
            }
            $scope = $this->validateScopeRow($row, $batchScope);
            $unit = $this->resolveUnit($row, $operatorId);
            $category = $this->resolveCategory($row, $scope, $operatorId);
            $supplier = $this->resolveSupplier($row, $operatorId);
            $warehouse = $this->resolveWarehouse($row, $scope, $operatorId);

            $data = [
                'item_code' => $this->required($row, ['item_code', '物料编码'], '物料编码'),
                'item_name' => $this->required($row, ['item_name', '物料名称'], '物料名称'),
                'item_type' => $this->itemType($this->value($row, ['item_type', '物料类型', '物料类型（中文选择）'])),
                'management_scope' => $scope,
                'category_id' => $category?->id,
                'spec' => $this->value($row, ['spec', '规格']),
                'unit_id' => $unit->id,
                'brand' => $this->value($row, ['brand', '品牌']),
                'model' => $this->value($row, ['model', '型号']),
                'is_purchase_item' => $this->boolean($this->value($row, ['is_purchase_item', '可采购']), true),
                'is_stock_item' => $this->boolean($this->value($row, ['is_stock_item', '库存管理']), true),
                'is_production_item' => $this->boolean($this->value($row, ['is_production_item', '可生产']), false),
                'is_custom_item' => $this->boolean($this->value($row, ['is_custom_item', '定制物料']), false),
                'custom_scope' => $this->value($row, ['custom_scope', '定制范围']) ?: 'none',
                'design_version' => $this->value($row, ['design_version', '设计版本']),
                'custom_status' => $this->value($row, ['custom_status', '定制状态']) ?: 'active',
                'custom_description' => $this->value($row, ['custom_description', '定制描述']),
                'is_batch_managed' => $this->boolean($this->value($row, ['is_batch_managed', '批次管理']), false),
                'is_serial_managed' => $this->boolean($this->value($row, ['is_serial_managed', '序列号管理']), false),
                'serial_tracking_mode' => $this->serialMode($this->value($row, ['serial_tracking_mode', '序列号规则'])),
                'serial_number_prefix' => $this->value($row, ['serial_number_prefix', '序列号前缀']),
                'cost_method' => $this->costMethod($this->value($row, ['cost_method', '成本方法'])),
                'standard_cost' => $this->decimal($this->value($row, ['standard_cost', '标准成本'])),
                'last_purchase_price' => $this->decimal($this->value($row, ['last_purchase_price', '最近采购价'])),
                'default_supplier_id' => $supplier?->id,
                'default_warehouse_id' => $warehouse?->id,
                'status' => $this->status($this->value($row, ['status', '状态'])),
                'remark' => $this->value($row, ['remark', '备注']),
            ];
            $item = Item::create(app(ItemManagementScopeService::class)->prepareItem($data));
            DB::table('erp_operation_logs')->insert(['module' => 'item', 'action' => 'import_management_scope',
                'target_type' => 'erp_items', 'target_id' => $item->id, 'old_snapshot' => null,
                'new_snapshot' => json_encode(['management_scope' => $scope, 'item_type' => $item->item_type, 'category_id' => $item->category_id],
                    JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'reason' => '按文件与上传批次的明确范围导入',
                'operator_id' => $operatorId, 'created_at' => now()]);

            if ($supplier) {
                app(SupplierCapabilityService::class)->importItemRelation($supplier->id, [
                    'item_id' => $item->id, 'relation_status' => 'active', 'is_default' => true,
                    'effective_at' => now(), 'remark' => '随 Item 导入自动建立', 'change_reason' => '随 Item 导入设置默认供应商',
                ], $operatorId);
            }

            return app(ItemManagementScopeService::class)->exposeCategoryScope($item->fresh(['category', 'unit', 'defaultSupplier', 'defaultWarehouse']));
        }, 5);
    }

    /** Preflight shares the import rules without creating its optional reference data. */
    public function validateRow(array $row, ?string $batchScope = null): void
    {
        $scope = $this->validateScopeRow($row, $batchScope);
        $data = [
            'item_code' => $this->value($row, ['item_code', '物料编码']),
            'item_name' => $this->value($row, ['item_name', '物料名称']),
            'unit_code' => $this->value($row, ['unit_code', '单位编码']),
            'unit_name' => $this->value($row, ['unit_name', '单位名称', '基本单位']),
            'spec' => $this->value($row, ['spec', '规格']),
        ];
        Validator::make($data, [
            'item_code' => 'required|string|max:80|unique:erp_items,item_code',
            'item_name' => 'required|string|max:160',
            'unit_code' => 'nullable|required_without:unit_name|string|max:40',
            'unit_name' => 'nullable|required_without:unit_code|string|max:80',
            'spec' => 'nullable|string|max:255',
        ])->validate();
        foreach (['brand' => ['品牌', 100], 'model' => ['型号', 100], 'design_version' => ['设计版本', 80],
            'category_code' => ['类目编码', 60], 'supplier_code' => ['默认供应商编码', 60],
            'supplier_name' => ['默认供应商', 160], 'warehouse_code' => ['默认仓库编码', 60],
            'warehouse_name' => ['默认仓库', 120]] as $key => [$label, $max]) {
            Validator::make([$key => $this->value($row, [$key, $label])], [$key => 'nullable|string|max:'.$max])->validate();
        }
        $prefix = $this->value($row, ['serial_number_prefix', '序列号前缀']);
        Validator::make(['serial_number_prefix' => $prefix], ['serial_number_prefix' => 'nullable|string|max:10|regex:/^[A-Za-z]+$/'])->validate();
        $categoryPath = $this->value($row, ['category_name', '类目名称', 'Item类目']);
        foreach (preg_split('/\s*(?:\/|＞|>)\s*/u', (string) $categoryPath, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $name) {
            Validator::make(['category_name' => $name], ['category_name' => 'string|max:120'])->validate();
        }
        foreach ([
            ['status', '状态', ['enabled', 'disabled', '启用', '停用']],
            ['serial_tracking_mode', '序列号规则', ['none', 'optional', 'required', '不启用', '按需编号', '必须逐件编号']],
            ['cost_method', '成本方法', ['weighted_average', 'fifo', 'standard', '移动加权平均', '先进先出', '标准成本']],
            ['custom_scope', '定制范围', ['none', 'order', 'customer', 'reusable']],
            ['custom_status', '定制状态', ['draft', 'active', 'disabled', 'archived']],
        ] as [$key, $label, $allowed]) {
            $value = $this->value($row, [$key, $label]);
            if ($value !== null && !in_array($value, $allowed, true)) {
                throw ValidationException::withMessages([$key => $label.'不合法。']);
            }
        }
        foreach (['is_purchase_item' => '可采购', 'is_stock_item' => '库存管理', 'is_production_item' => '可生产',
            'is_custom_item' => '定制物料', 'is_batch_managed' => '批次管理', 'is_serial_managed' => '序列号管理'] as $key => $label) {
            $value = $this->value($row, [$key, $label]);
            if (is_string($value)) $value = strtolower(trim($value));
            if ($value !== null && !in_array($value, [true, false, 1, 0, '1', '0', 'true', 'false', '是', '否', '启用', '停用'], true)) {
                throw ValidationException::withMessages([$key => $label.'只能填写是或否。']);
            }
        }
        foreach (['standard_cost' => '标准成本', 'last_purchase_price' => '最近采购价'] as $key => $label) {
            $value = $this->value($row, [$key, $label]);
            if ($value === null) continue;
            if (!app(InventoryAmountVisibilityService::class)->canView()) {
                throw ValidationException::withMessages([$key => '导入库存成本需要查看库存金额与成本权限。']);
            }
            Validator::make([$key => $value], [$key => 'numeric|min:0|max:9999999999.9999'])->validate();
        }
        $unit = $data['unit_code'] ? Unit::where('unit_code', $data['unit_code'])->first() : null;
        $unit ??= $data['unit_name'] ? Unit::where('unit_name', $this->plainLabel($data['unit_name']))->first() : null;
        if ($unit && ($unit->status !== 'enabled' || $unit->is_legacy)) {
            throw ValidationException::withMessages(['unit_code' => '基本单位必须是启用的标准业务单位。']);
        }
        $this->findWarehouse($row, $scope);
    }

    public function validateScopeRow(array $row, ?string $batchScope = null): string
    {
        $scope = app(ItemManagementScopeService::class)->importScope($row, $batchScope);
        $type = $this->itemType($this->value($row, ['item_type', '物料类型', '物料类型（中文选择）']));
        $categoryCode = $this->value($row, ['category_code', '类目编码']);
        $category = $categoryCode ? ItemCategory::where('category_code', $categoryCode)->first() : null;
        if ($category) {
            $this->assertCategory($category, $scope);
        } else {
            $label = $this->value($row, ['category_name', '类目名称', 'Item类目']);
            $parts = preg_split('/\s*(?:\/|＞|>)\s*/u', (string) ($label ?: $categoryCode), -1, PREG_SPLIT_NO_EMPTY) ?: [];
            $parentId = null;
            foreach ($parts as $index => $name) {
                $node = ItemCategory::where('category_type', 'item')->where('category_name', $name)
                    ->where('management_scope', $scope)->where('parent_id', $parentId)->first();
                if (!$node) break;
                if ($node->status !== 'enabled') throw ValidationException::withMessages(['category_code' => '类目路径中的已有上级必须启用。']);
                app(ItemCategoryApplicationService::class)->assertEnabledAncestors($node);
                if ($index === count($parts) - 1) $this->assertCategory($node, $scope);
                $parentId = $node->id;
            }
        }
        app(ItemManagementScopeService::class)->prepareItem(['management_scope' => $scope, 'item_type' => $type,
            'is_production_item' => $this->boolean($this->value($row, ['is_production_item', '可生产']), false)]);
        return $scope;
    }

    private function resolveUnit(array $row, ?int $operatorId): Unit
    {
        $code = $this->value($row, ['unit_code', '单位编码']);
        $label = $this->value($row, ['unit_name', '单位名称', '基本单位']);
        $name = $this->plainLabel($label);
        $unit = $code ? Unit::where('unit_code', $code)->first() : null;
        $unit ??= $name ? Unit::where('unit_name', $name)->first() : null;
        if ($unit) return $unit;
        if (! $code && ! $name) throw ValidationException::withMessages(['unit_code' => '单位编码或基本单位不能为空。']);

        return app(MasterDataApplicationService::class)->create('units', Unit::class, [
            'unit_code' => $code ?: $this->numbers->next('unit', 'UNIT'),
            'unit_name' => $name ?: $code,
            'symbol' => mb_substr($name ?: $code, 0, 20),
            'unit_type' => 'quantity',
            'allow_decimal' => false,
            'decimal_places' => 0,
            'sort_order' => 0,
            'is_base' => false,
            'is_legacy' => false,
            'status' => 'enabled',
            'remark' => '随 Item 导入自动新增',
        ], $operatorId);
    }

    private function resolveCategory(array $row, string $scope, ?int $operatorId): ?ItemCategory
    {
        $code = $this->value($row, ['category_code', '类目编码']);
        $label = $this->value($row, ['category_name', '类目名称', 'Item类目']);
        if (! $code && ! $label) return null;
        if ($code && ($existing = ItemCategory::where('category_code', $code)->first())) {
            $this->assertCategory($existing, $scope); return $existing;
        }

        $parts = preg_split('/\s*(?:\/|＞|>)\s*/u', (string) ($label ?: $code), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parentId = null;
        $category = null;
        foreach ($parts as $index => $name) {
            $category = ItemCategory::where('category_type', 'item')->where('category_name', $name)
                ->where('management_scope', $scope)->where('parent_id', $parentId)->first();
            if (! $category) {
                $category = app(ItemCategoryApplicationService::class)->create([
                    'category_code' => $index === count($parts) - 1 && $code ? $code : $this->numbers->next('item_category', 'IC'),
                    'category_name' => $name,
                    'parent_id' => $parentId,
                    'category_type' => 'item',
                    'management_scope' => $scope,
                    'sort_order' => 0,
                    'status' => 'enabled',
                    'remark' => '随 Item 导入自动新增',
                ], $operatorId);
            }
            $parentId = $category->id;
        }
        if ($category) $this->assertCategory($category, $scope);
        return $category;
    }

    private function assertCategory(ItemCategory $category, string $scope): void
    {
        if ($category->managementScope() !== $scope || $category->category_type !== 'item' || $category->status !== 'enabled'
            || $category->children()->where('category_type', 'item')->exists()) {
            throw ValidationException::withMessages(['category_code' => '导入类目必须是同管理范围的启用末级 Item 类目。']);
        }
        app(ItemCategoryApplicationService::class)->assertEnabledAncestors($category);
    }

    private function resolveSupplier(array $row, ?int $operatorId): ?Supplier
    {
        $code = $this->value($row, ['supplier_code', '默认供应商编码', '供应商编码']);
        $name = $this->value($row, ['supplier_name', '默认供应商', '供应商名称']);
        if (! $code && ! $name) return null;
        $supplier = $code ? Supplier::where('supplier_code', $code)->first() : null;
        $supplier ??= $name ? Supplier::where('supplier_name', $name)->first() : null;
        if ($supplier) return $supplier;

        return app(MasterDataApplicationService::class)->create('suppliers', Supplier::class, [
            'supplier_code' => $code ?: $this->numbers->next('supplier', 'SUP'),
            'supplier_name' => $name ?: $code,
            'supplier_type' => 'manufacturer',
            'approval_status' => 'approved',
            'is_blacklisted' => false,
            'cooperation_status' => 'normal',
            'purchase_restricted' => false,
            'quality_status' => 'normal',
            'status' => 'enabled',
            'remark' => '随 Item 导入自动新增',
        ], $operatorId);
    }

    private function findWarehouse(array $row, string $scope): ?Warehouse
    {
        $code = $this->value($row, ['warehouse_code', '默认仓库编码', '仓库编码']);
        $name = $this->value($row, ['warehouse_name', '默认仓库', '仓库名称']);
        if (! $code && ! $name) return null;
        $warehouse = $code ? Warehouse::where('warehouse_code', $code)->first() : null;
        $warehouse ??= $name ? Warehouse::where('warehouse_name', $name)->where('management_scope', $scope)->first() : null;
        if ($warehouse && ($warehouse->managementScope() !== $scope || $warehouse->status !== 'enabled')) {
            throw ValidationException::withMessages(['management_scope' => '默认仓库必须属于物料的管理范围且处于启用状态。']);
        }
        return $warehouse;
    }

    private function resolveWarehouse(array $row, string $scope, ?int $operatorId): ?Warehouse
    {
        $code = $this->value($row, ['warehouse_code', '默认仓库编码', '仓库编码']);
        $name = $this->value($row, ['warehouse_name', '默认仓库', '仓库名称']);
        if (!$code && !$name) return null;
        if ($warehouse = $this->findWarehouse($row, $scope)) return $warehouse;

        return app(MasterDataApplicationService::class)->create('warehouses', Warehouse::class, [
            'warehouse_code' => $code ?: $this->numbers->next('warehouse', 'WH'),
            'warehouse_name' => $name ?: $code,
            'warehouse_type' => 'general',
            'management_scope' => $scope,
            'status' => 'enabled',
            'remark' => '随 Item 导入自动新增',
        ], $operatorId);
    }

    private function itemType(mixed $value): string
    {
        $map = ['成品' => 'finished_product', '半成品' => 'semi_finished', '原材料' => 'raw_material', '包装物' => 'packaging', '服务' => 'service', '办公耗材' => 'office_consumable', '办公用品' => 'office_consumable'];
        $type = $map[trim((string) $value)] ?? trim((string) $value);
        if (! in_array($type, array_values($map), true)) {
            throw ValidationException::withMessages(['item_type' => '物料类型只能填写：成品、半成品、原材料、包装物、服务、办公用品。']);
        }
        return $type;
    }

    private function serialMode(mixed $value): string
    {
        return ['不启用' => 'none', '按需编号' => 'optional', '必须逐件编号' => 'required'][trim((string) $value)]
            ?? (in_array($value, ['none', 'optional', 'required'], true) ? $value : 'none');
    }

    private function costMethod(mixed $value): string
    {
        return ['移动加权平均' => 'weighted_average', '先进先出' => 'fifo', '标准成本' => 'standard'][trim((string) $value)]
            ?? (in_array($value, ['weighted_average', 'fifo', 'standard'], true) ? $value : 'weighted_average');
    }

    private function status(mixed $value): string
    {
        return ['启用' => 'enabled', '停用' => 'disabled'][trim((string) $value)]
            ?? (in_array($value, ['enabled', 'disabled'], true) ? $value : 'enabled');
    }

    private function boolean(mixed $value, bool $default): bool
    {
        if (is_string($value)) $value = strtolower(trim($value));
        if ($value === null || $value === '') return $default;
        if (in_array($value, [true, 1, '1', 'true', '是', '启用'], true)) return true;
        if (in_array($value, [false, 0, '0', 'false', '否', '停用'], true)) return false;
        return $default;
    }

    private function decimal(mixed $value): float
    {
        return is_numeric($value) ? (float) $value : 0;
    }

    private function plainLabel(mixed $value): ?string
    {
        if ($value === null || trim((string) $value) === '') return null;
        return trim((string) preg_replace('/\s*\([^)]*\)\s*$/u', '', trim((string) $value)));
    }

    private function required(array $row, array $keys, string $label): mixed
    {
        $value = $this->value($row, $keys);
        if ($value === null) throw ValidationException::withMessages([$keys[0] => "{$label}不能为空。"]);
        return $value;
    }

    private function value(array $row, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null
                && (is_bool($row[$key]) || trim((string) $row[$key]) !== '')) return $row[$key];
        }
        return null;
    }
}
