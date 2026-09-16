<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{ItemCategory, Supplier, SupplierItemRelation, Unit, Warehouse};
use App\Services\Erp\ItemImportApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class ItemImportApplicationServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_chinese_free_text_options_create_master_data_and_bind_item(): void
    {
        $suffix = strtoupper(Str::random(8));
        $item = app(ItemImportApplicationService::class)->create([
            '物料编码' => 'IMP-'.$suffix,
            '物料名称' => '自由填写导入物料-'.$suffix,
            '物料类型（中文选择）' => '原材料',
            '基本单位' => '测试单位'.$suffix,
            'Item类目' => '导入类目'.$suffix.' / 子类目'.$suffix,
            '默认供应商' => '导入供应商'.$suffix,
            '默认仓库' => '导入仓库'.$suffix,
            '规格' => '规格-'.$suffix,
            '可采购' => '是',
            '库存管理' => '是',
            '可生产' => '否',
            '序列号规则' => '按需编号',
            '成本方法' => '移动加权平均',
            '标准成本' => '12.34',
            '状态' => '启用',
        ]);

        $this->assertSame('raw_material', $item->item_type);
        $this->assertSame('optional', $item->serial_tracking_mode);
        $this->assertSame(12.34, (float) $item->standard_cost);
        $this->assertSame('测试单位'.$suffix, Unit::findOrFail($item->unit_id)->unit_name);
        $this->assertSame('子类目'.$suffix, ItemCategory::findOrFail($item->category_id)->category_name);
        $this->assertSame('导入供应商'.$suffix, Supplier::findOrFail($item->default_supplier_id)->supplier_name);
        $this->assertSame('导入仓库'.$suffix, Warehouse::findOrFail($item->default_warehouse_id)->warehouse_name);
        $this->assertTrue(SupplierItemRelation::where('item_id', $item->id)->where('supplier_id', $item->default_supplier_id)->where('is_default', true)->exists());
    }

    public function test_existing_options_are_reused_instead_of_duplicated(): void
    {
        $suffix = strtoupper(Str::random(8));
        $unit = Unit::where('status', 'enabled')->firstOrFail();
        $supplier = Supplier::create(['supplier_code' => 'SUP-'.$suffix, 'supplier_name' => '复用供应商-'.$suffix, 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'WH-'.$suffix, 'warehouse_name' => '复用仓库-'.$suffix, 'warehouse_type' => 'general', 'status' => 'enabled']);

        $item = app(ItemImportApplicationService::class)->create([
            '物料编码' => 'IMP-REUSE-'.$suffix,
            '物料名称' => '复用选项测试-'.$suffix,
            '物料类型' => 'finished_product',
            '单位编码' => $unit->unit_code,
            '默认供应商编码' => $supplier->supplier_code,
            '默认仓库编码' => $warehouse->warehouse_code,
        ]);

        $this->assertSame($unit->id, $item->unit_id);
        $this->assertSame($supplier->id, $item->default_supplier_id);
        $this->assertSame($warehouse->id, $item->default_warehouse_id);
        $this->assertSame(1, Supplier::where('supplier_code', $supplier->supplier_code)->count());
        $this->assertSame(1, Warehouse::where('warehouse_code', $warehouse->warehouse_code)->count());
    }
}
