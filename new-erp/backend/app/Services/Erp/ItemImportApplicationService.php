<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, ItemCategory, Supplier, SupplierItemRelation, Unit, Warehouse};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ItemImportApplicationService
{
    public function __construct(private readonly DocumentNumberService $numbers)
    {
    }

    public function create(array $row): Item
    {
        return DB::transaction(function () use ($row) {
            $unit = $this->resolveUnit($row);
            $category = $this->resolveCategory($row);
            $supplier = $this->resolveSupplier($row);
            $warehouse = $this->resolveWarehouse($row);

            $item = Item::create([
                'item_code' => $this->required($row, ['item_code', '物料编码'], '物料编码'),
                'item_name' => $this->required($row, ['item_name', '物料名称'], '物料名称'),
                'item_type' => $this->itemType($this->value($row, ['item_type', '物料类型', '物料类型（中文选择）'])),
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
            ]);

            if ($supplier) {
                SupplierItemRelation::updateOrCreate(
                    ['supplier_id' => $supplier->id, 'item_id' => $item->id],
                    ['capability_source' => 'import', 'relation_status' => 'active', 'is_default' => true, 'effective_at' => now(), 'remark' => '随 Item 导入自动建立']
                );
            }

            return $item->fresh(['category', 'unit', 'defaultSupplier', 'defaultWarehouse']);
        }, 5);
    }

    private function resolveUnit(array $row): Unit
    {
        $code = $this->value($row, ['unit_code', '单位编码']);
        $label = $this->value($row, ['unit_name', '单位名称', '基本单位']);
        $name = $this->plainLabel($label);
        $unit = $code ? Unit::where('unit_code', $code)->first() : null;
        $unit ??= $name ? Unit::where('unit_name', $name)->first() : null;
        if ($unit) return $unit;
        if (! $code && ! $name) throw ValidationException::withMessages(['unit_code' => '单位编码或基本单位不能为空。']);

        return Unit::create([
            'unit_code' => $code ?: $this->numbers->next('unit', 'UNIT'),
            'unit_name' => $name ?: $code,
            'symbol' => $name ?: $code,
            'unit_type' => 'quantity',
            'allow_decimal' => false,
            'decimal_places' => 0,
            'sort_order' => 0,
            'is_base' => false,
            'is_legacy' => false,
            'status' => 'enabled',
            'remark' => '随 Item 导入自动新增',
        ]);
    }

    private function resolveCategory(array $row): ?ItemCategory
    {
        $code = $this->value($row, ['category_code', '类目编码']);
        $label = $this->value($row, ['category_name', '类目名称', 'Item类目']);
        if (! $code && ! $label) return null;
        if ($code && ($existing = ItemCategory::where('category_code', $code)->first())) return $existing;

        $parts = preg_split('/\s*(?:\/|＞|>)\s*/u', (string) ($label ?: $code), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        $parentId = null;
        $category = null;
        foreach ($parts as $index => $name) {
            $category = ItemCategory::where('category_type', 'item')->where('category_name', $name)
                ->where('parent_id', $parentId)->first();
            if (! $category) {
                $category = ItemCategory::create([
                    'category_code' => $index === count($parts) - 1 && $code ? $code : $this->numbers->next('item_category', 'IC'),
                    'category_name' => $name,
                    'parent_id' => $parentId,
                    'category_type' => 'item',
                    'sort_order' => 0,
                    'status' => 'enabled',
                    'remark' => '随 Item 导入自动新增',
                ]);
            }
            $parentId = $category->id;
        }
        return $category;
    }

    private function resolveSupplier(array $row): ?Supplier
    {
        $code = $this->value($row, ['supplier_code', '默认供应商编码', '供应商编码']);
        $name = $this->value($row, ['supplier_name', '默认供应商', '供应商名称']);
        if (! $code && ! $name) return null;
        $supplier = $code ? Supplier::where('supplier_code', $code)->first() : null;
        $supplier ??= $name ? Supplier::where('supplier_name', $name)->first() : null;
        if ($supplier) return $supplier;

        return Supplier::create([
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
        ]);
    }

    private function resolveWarehouse(array $row): ?Warehouse
    {
        $code = $this->value($row, ['warehouse_code', '默认仓库编码', '仓库编码']);
        $name = $this->value($row, ['warehouse_name', '默认仓库', '仓库名称']);
        if (! $code && ! $name) return null;
        $warehouse = $code ? Warehouse::where('warehouse_code', $code)->first() : null;
        $warehouse ??= $name ? Warehouse::where('warehouse_name', $name)->first() : null;
        if ($warehouse) return $warehouse;

        return Warehouse::create([
            'warehouse_code' => $code ?: $this->numbers->next('warehouse', 'WH'),
            'warehouse_name' => $name ?: $code,
            'warehouse_type' => 'general',
            'status' => 'enabled',
            'remark' => '随 Item 导入自动新增',
        ]);
    }

    private function itemType(mixed $value): string
    {
        $map = ['成品' => 'finished_product', '半成品' => 'semi_finished', '原材料' => 'raw_material', '包装物' => 'packaging', '服务' => 'service', '办公耗材' => 'office_consumable'];
        $type = $map[trim((string) $value)] ?? trim((string) $value);
        if (! in_array($type, array_values($map), true)) {
            throw ValidationException::withMessages(['item_type' => '物料类型只能填写：成品、半成品、原材料、包装物、服务、办公耗材。']);
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
            if (array_key_exists($key, $row) && $row[$key] !== null && trim((string) $row[$key]) !== '') return $row[$key];
        }
        return null;
    }
}
