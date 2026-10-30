<?php

namespace Database\Seeders;

use App\Models\Erp\{Bom, BomItem, BomLog, Item, Product, Sku};
use App\Services\Erp\{CuttingQuantityCalculator, DocumentNumberService, ItemManagementScopeService, MasterDataApplicationService, SkuItemDefaultRelationService, UnitConversionDomainService};
use Database\Seeders\Support\RunsSafeSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 手动补充双滤商品、SKU 默认物料关系和 BOM 草稿；须先执行双滤物料 Seeder。 */
class ErpDualFilterProductBomSeeder extends Seeder
{
    use RunsSafeSeed;

    private const PRODUCT_NAME = '双滤一体机智能款带杀菌灯';

    private function seed(): void
    {
        $unitId = DB::table('erp_units')->where('unit_code', 'TAI')->where('status', 'enabled')->value('id');
        if (! $unitId) throw new RuntimeException('缺少启用的台单位，请先执行双滤物料 Seeder。');
        $productId = $this->product((int) $unitId);
        // 用户确认每台电线用10米；每批25米仅作采购换算，不是单台耗用。
        $common = [
            'ITEM00000012' => 1, 'ITEM00000013' => 1, 'ITEM00000014' => 2,
            'ITEM00000015' => 1, 'ITEM00000016' => 1, 'ITEM00000047' => 1,
            'ITEM00000020' => 1, 'ITEM00000022' => 1, 'ITEM00000051' => 1,
            'ITEM00000048' => 1, 'ITEM00000049' => 1, 'ITEM00000050' => 1,
            'ITEM00000024' => 1, 'ITEM00000054' => 1, 'ITEM00000055' => 1,
            'ITEM00000056' => 1, 'ITEM00000057' => 10, 'ITEM00000053' => 1,
        ];
        $commonLines = [];
        foreach ($common as $code => $qty) {
            $commonLines[] = ['item' => $this->item(['item_code' => $code]), 'qty' => $qty];
        }

        foreach (['260' => ['260升', '3方', '25瓦'], '300' => ['300升', '3方', '25瓦'],
            '450' => ['450升', '5方', '30瓦'], '550' => ['550升', '5方', '30瓦']] as $model => [$tank, $filter, $lamp]) {
            $output = $this->item(['item_name' => $model.self::PRODUCT_NAME, 'spec' => (string) $model]);
            $skuId = $this->sku($productId, $output);
            $this->primaryRelation($skuId, $output);
            $lines = $commonLines;
            foreach (['压力罐' => $tank, '超滤' => $filter, '紫外线杀菌灯' => $lamp] as $name => $spec) {
                $lines[] = ['item' => $this->item(['item_name' => $name, 'spec' => $spec]), 'qty' => 1];
            }
            // 售价未确认，SKU 保留草稿；物料级 BOM 不绕过关联 SKU 必须启用的正式校验。
            $this->bom($output, $lines);
        }

        $frameLines = [];
        foreach (['ITEM00000026' => '3000', 'ITEM00000027' => '2900', 'ITEM00000028' => '12600', 'ITEM00000029' => '600'] as $code => $length) {
            $frameLines[] = ['item' => $this->item(['item_code' => $code]), 'total_length_mm' => $length];
        }
        $this->bom($this->item(['item_code' => 'ITEM00000053']), $frameLines);
    }

    private function product(int $unitId): int
    {
        $matches = Product::query()->where('product_name', self::PRODUCT_NAME)->lockForUpdate()->get();
        if ($matches->count() > 1) throw new RuntimeException('双滤商品名称存在歧义，资料补缺已回滚。');
        if ($product = $matches->first()) {
            if ($product->product_type !== 'standard' || (int) $product->unit_id !== $unitId) {
                throw new RuntimeException('双滤商品的类型或单位与本批资料冲突；保留现有记录，资料补缺已回滚。');
            }
            return (int) $product->id;
        }
        return (int) app(MasterDataApplicationService::class)->create('products', Product::class, [
            'product_code' => app(DocumentNumberService::class)->next('product'),
            'product_name' => self::PRODUCT_NAME, 'product_type' => 'standard',
            'unit_id' => $unitId, 'status' => 'enabled',
        ], null)->id;
    }

    private function sku(int $productId, Item $output): int
    {
        $matches = Sku::query()->where('product_id', $productId)->where('spec_text', $output->spec)->lockForUpdate()->get();
        if ($matches->count() > 1) throw new RuntimeException('双滤 SKU 规格存在歧义：'.$output->spec.'，资料补缺已回滚。');
        if ($sku = $matches->first()) {
            if ($sku->order_line_type !== 'physical' || (int) $sku->sales_unit_id !== (int) $output->unit_id) {
                throw new RuntimeException('双滤 SKU 类型或销售单位冲突：'.$output->spec.'；保留现有记录，资料补缺已回滚。');
            }
            return (int) $sku->id;
        }
        return (int) app(MasterDataApplicationService::class)->create('skus', Sku::class, [
            'sku_code' => app(DocumentNumberService::class)->next('sku'),
            'product_id' => $productId, 'sku_name' => $output->item_name, 'spec_text' => $output->spec,
            'sales_unit_id' => $output->unit_id, 'sales_unit_snapshot' => $output->unit->unit_name,
            'sale_price' => null, 'order_line_type' => 'physical', 'fulfillment_type' => 'physical',
            'is_sale_item' => true, 'is_need_production' => true, 'is_need_bom' => true, 'status' => 'draft',
        ], null)->id;
    }

    private function primaryRelation(int $skuId, Item $output): void
    {
        $relations = DB::table('erp_sku_item_relations')->where('sku_id', $skuId)->lockForUpdate()->get();
        if ($relations->isNotEmpty()) {
            $primary = $relations->where('status', 'active')->where('is_primary', 1);
            if ($primary->count() !== 1 || (int) $primary->first()->item_id !== (int) $output->id
                || (int) $primary->first()->unit_id !== (int) $output->unit_id
                || bccomp((string) $primary->first()->qty, '1', 8) !== 0) {
                throw new RuntimeException('双滤 SKU 默认物料关系需人工核对；保留现有配置，资料补缺已回滚。');
            }
            return;
        }
        app(SkuItemDefaultRelationService::class)->setPrimary($skuId, (int) $output->id, '首次设置', null, null, '系统');
    }

    private function item(array $identity): Item
    {
        $matches = Item::with('unit.standardUnit')->where($identity)->lockForUpdate()->get();
        if ($matches->count() !== 1) {
            throw new RuntimeException('双滤物料缺失或存在歧义：'.implode(' ', $identity).'，请先核对物料资料。');
        }
        $item = $matches->first();
        if ($item->status !== 'enabled' || ! $item->unit || $item->unit->status !== 'enabled') {
            throw new RuntimeException('双滤物料及库存单位必须启用：'.$item->item_name.'，资料补缺已回滚。');
        }
        app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'items');
        return $item;
    }

    private function bom(Item $output, array $lines): void
    {
        $matches = Bom::withTrashed()->where('output_item_id', $output->id)->where('bom_type', 'standard')
            ->where('version', 'V1.0')->lockForUpdate()->get();
        if ($matches->count() > 1) throw new RuntimeException('双滤 BOM 版本存在歧义：'.$output->item_name.'，资料补缺已回滚。');
        // 已存在的整份版本视为人工维护资料，不能补行、改量、改状态或恢复回收站记录。
        if ($matches->isNotEmpty()) return;
        if (! $output->is_production_item) throw new RuntimeException('BOM 产出物料必须允许生产：'.$output->item_name.'，资料补缺已回滚。');
        $bom = Bom::create([
            'bom_no' => app(DocumentNumberService::class)->next('bom'), 'bom_name' => $output->item_name,
            'product_id' => null, 'sku_id' => null, 'output_item_id' => $output->id,
            'bom_type' => 'standard', 'version' => 'V1.0', 'status' => 'draft',
            'audit_status' => 'pending', 'submitted_at' => null, 'is_default' => false,
        ]);
        foreach ($lines as $index => $line) {
            $item = $line['item'];
            $unit = app(UnitConversionDomainService::class)->canonicalUnit($item->unit);
            if (! $unit || $unit->status !== 'enabled') throw new RuntimeException('BOM 物料缺少有效库存基本单位：'.$item->item_name.'，资料补缺已回滚。');
            $snapshot = isset($line['total_length_mm']) ? app(CuttingQuantityCalculator::class)->calculate($item, $unit, [
                'cutting_quantity_basis' => 'total_length', 'total_length_mm' => $line['total_length_mm'],
            ]) : null;
            BomItem::create([
                'bom_id' => $bom->id, 'line_no' => ($index + 1) * 10,
                'component_item_id' => $item->id, 'component_item_code' => $item->item_code,
                'component_item_name' => $item->item_name, 'unit_id' => $unit->id,
                'qty' => $snapshot['stored_qty'] ?? $line['qty'], 'cutting_quantity_snapshot' => $snapshot,
            ]);
        }
        BomLog::create(['bom_id' => $bom->id, 'action' => 'create', 'message' => '新增 BOM 草稿']);
    }
}
