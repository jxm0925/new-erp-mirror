<?php

namespace Database\Seeders;

use App\Services\Erp\DocumentNumberService;
use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 仅补双滤配置所需物料，不恢复旧库存、采购价格或历史业务关联。 */
class ErpDualFilterMaterialSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        $rows = json_decode(file_get_contents(__DIR__.'/data/dual_filter_materials.json'), true, 512, JSON_THROW_ON_ERROR);
        $maximum = max(array_map(fn (array $row) => (int) substr((string) $row['item_code'], 4), $rows));
        $this->prepareCounter('item', 'erp_items', 'item_code', 'ITEM', 8, $maximum);
        $this->prepareCounter('item_category', 'erp_item_categories', 'category_code', 'IC', 6);
        $units = $this->units();
        $categories = [];

        foreach ($rows as $row) {
            $categoryKey = $row['category_code'] ?? $row['category_name'];
            $categories[$categoryKey] ??= $this->category($row['category_code'], $row['category_name']);
            $row['category_id'] = $categories[$categoryKey];
            $row['unit_id'] = $units[$row['unit_code']];
            unset($row['category_code'], $row['category_name'], $row['unit_code']);

            $query = DB::table('erp_items');
            if ($row['item_code'] === null) {
                // 新型号动态取号，不为固定新编码覆盖既有编号计数。
                $query->where('item_name', $row['item_name'])->where('spec', $row['spec']);
            } else {
                $query->where('item_code', $row['item_code']);
            }
            $matches = $query->lockForUpdate()->get();
            if ($matches->count() > 1) {
                throw new RuntimeException('物料名称和规格存在歧义：'.$row['item_name'].' '.$row['spec'].'，物料补缺已回滚。');
            }
            $item = $matches->first();
            if ($row['item_code'] === null) unset($row['item_code']);
            if (($row['serial_tracking_mode'] ?? 'none') !== 'none'
                && DB::table('erp_items')->where('serial_number_prefix', $row['serial_number_prefix'])
                    ->where('serial_tracking_mode', '!=', 'none')
                    ->when($item, fn ($query) => $query->where('id', '!=', $item->id))->lockForUpdate()->exists()) {
                throw new RuntimeException('序列号前缀已被其他物料使用：'.$row['serial_number_prefix'].'，物料补缺已回滚。');
            }
            if ($item) {
                // 编码相同但规格或管理口径不同不能当作缺失；备注、品牌及人工价格不覆盖。
                $this->assertCompatible($item, array_diff_key($row, ['brand' => true, 'remark' => true]), $item->item_code);
                $itemId = (int) $item->id;
            } else {
                $row['item_code'] ??= app(DocumentNumberService::class)->next('item');
                $itemId = DB::table('erp_items')->insertGetId($row + ['created_at' => now(), 'updated_at' => now()]);
            }

            $this->policy($itemId, $row['production_execution_mode'], $row['serial_tracking_mode']);
            if ($row['cutting_mode'] === 'length') {
                $this->conversion($itemId, $units['M'], $units['ROOT'], '0.20000000', '方管每根5米，1米对应0.2根');
            } elseif (($row['item_code'] ?? null) === 'ITEM00000057') {
                $this->conversion($itemId, $units['BATCH'], $units['M'], '25.00000000', '电线每批25米');
            }
        }
    }

    private function units(): array
    {
        $definitions = [
            'EA' => ['个', 'quantity', 0, 0, 0, 70],
            'ROOT' => ['根', 'quantity', 0, 0, 0, 80],
            'M' => ['米', 'length', 1, 3, 1, 30],
            'BATCH' => ['批', 'quantity', 0, 0, 0, 90],
            'SET' => ['套', 'quantity', 0, 0, 0, 100],
            'SHEET' => ['张', 'quantity', 0, 0, 0, 110],
            'TAI' => ['台', 'quantity', 0, 0, 0, 120],
        ];
        $ids = [];
        foreach ($definitions as $code => [$name, $type, $decimal, $places, $base, $sort]) {
            $expected = ['unit_name' => $name, 'unit_type' => $type, 'allow_decimal' => $decimal, 'decimal_places' => $places, 'status' => 'enabled'];
            SeedSafety::insertMissing('erp_units', ['unit_code' => $code], $expected + [
                'symbol' => $code === 'M' ? 'm' : $name, 'is_base' => $base,
                'is_legacy' => 0, 'standard_unit_id' => null, 'sort_order' => $sort,
            ]);
            $unit = DB::table('erp_units')->where('unit_code', $code)->lockForUpdate()->first();
            $this->assertCompatible($unit, $expected, '单位 '.$code);
            $ids[$code] = (int) $unit->id;
        }
        return $ids;
    }

    private function category(?string $code, string $name): int
    {
        $query = DB::table('erp_item_categories');
        if ($code !== null) {
            $query->where('category_code', $code);
        } else {
            $query->where('category_name', $name)->where('category_type', 'item')->where('management_scope', 'factory');
        }
        $matches = $query->lockForUpdate()->get();
        if ($matches->count() > 1) {
            throw new RuntimeException('分类名称存在歧义：'.$name.'，物料补缺已回滚。');
        }
        $expected = ['category_name' => $name, 'category_type' => 'item', 'management_scope' => 'factory', 'status' => 'enabled'];
        if ($matches->isNotEmpty()) {
            $this->assertCompatible($matches->first(), $expected, '分类 '.$name);
            return (int) $matches->first()->id;
        }
        if ($code !== null) {
            throw new RuntimeException('缺少已批准的基础分类：'.$code.'，请先补齐指定基础资料。');
        }
        return DB::table('erp_item_categories')->insertGetId($expected + [
            'category_code' => app(DocumentNumberService::class)->next('item_category'),
            'parent_id' => null, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function policy(int $itemId, string $executionMode, string $serialTrackingMode): void
    {
        $parameters = [
            'template_code' => 'inventory_material', 'is_stock_managed' => true,
            'inventory_management_mode' => 'standard', 'requires_custodian' => false,
            'is_returnable' => false, 'requires_capitalization' => false,
            'serial_tracking_mode' => $serialTrackingMode, 'production_execution_mode' => $executionMode,
            'serial_generation_stage' => 'before_finished_goods_posting',
            'serial_generation_routing_operation_id' => null,
            'post_purchase_action' => 'inventory_receipt', 'consumption_confirmation_mode' => 'none',
            'future_route' => 'inventory', 'future_bearer_type' => 'company',
        ];
        $existing = DB::table('erp_item_material_policies')->where('item_id', $itemId)->lockForUpdate()->get();
        if ($existing->isNotEmpty()) {
            $active = $existing->where('status', 'active');
            if ($active->count() !== 1) {
                throw new RuntimeException('物料 '.$itemId.' 的既有策略需人工核对，补缺已回滚。');
            }
            $this->assertCompatible($active->first(), $parameters + ['expired_at' => null], '物料策略 '.$itemId);
            return;
        }
        SeedSafety::insertMissing('erp_item_material_policies', ['item_id' => $itemId, 'version_no' => 1], $parameters + [
            'status' => 'active', 'parameter_snapshot' => json_encode($parameters, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            'change_reason' => '物料建档', 'effective_at' => now(),
        ]);
    }

    private function conversion(int $itemId, int $purchaseUnitId, int $baseUnitId, string $factor, string $reason): void
    {
        $expected = [
            'purchase_unit_id' => $purchaseUnitId, 'base_unit_id' => $baseUnitId,
            'factor' => $factor, 'is_default' => 1, 'allow_actual_conversion' => 0,
            'status' => 'active', 'effective_to' => null,
        ];
        $existing = DB::table('erp_item_purchase_conversions')->where('item_id', $itemId)->lockForUpdate()->get();
        if ($existing->isNotEmpty()) {
            $active = $existing->where('status', 'active');
            if ($active->count() !== 1) {
                throw new RuntimeException('物料 '.$itemId.' 的既有换算需人工核对，补缺已回滚。');
            }
            $this->assertCompatible($active->first(), $expected, '物料采购换算 '.$itemId);
            return;
        }
        DB::table('erp_item_purchase_conversions')->insert(['item_id' => $itemId] + $expected + [
            'effective_from' => now(), 'change_reason' => $reason,
            'remark' => $reason, 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function prepareCounter(string $type, string $table, string $column, string $prefix, int $digits, int $minimum = 0): void
    {
        $rule = DB::table('erp_document_number_rules')->where('document_type', $type)->lockForUpdate()->first();
        if (!$rule || !$rule->enabled || $rule->prefix !== $prefix || $rule->date_format !== ''
            || $rule->reset_cycle !== 'none' || (int) $rule->sequence_length !== $digits) {
            throw new RuntimeException('编号规则与本批基础资料不匹配：'.$type.'，请先人工核对。');
        }
        foreach (DB::table($table)->pluck($column) as $value) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/D', $value, $match)) {
                $minimum = max($minimum, (int) $match[1]);
            }
        }
        $key = ['document_type' => $type, 'number_date' => '1970-01-01'];
        $counter = DB::table('erp_document_numbers')->where($key)->lockForUpdate()->first();
        if ($counter && (int) $counter->current_sequence < $minimum) {
            throw new RuntimeException('已有编号计数不足：'.$type.'，Seeder不覆盖人工计数，请通过编号维护处理。');
        }
        // 固定物料编码也必须占用编号区间，否则页面下次建档可能撞上本批编码。
        SeedSafety::insertMissing('erp_document_numbers', $key, ['current_sequence' => $minimum]);
    }

    private function assertCompatible(object $existing, array $expected, string $label): void
    {
        foreach ($expected as $field => $value) {
            $value = is_bool($value) ? (int) $value : $value;
            $actual = $existing->{$field};
            if (($actual === null) !== ($value === null) || (string) $actual !== (string) $value) {
                throw new RuntimeException($label.' 的 '.$field.' 与本批资料冲突；保留现有记录，补缺已回滚。');
            }
        }
    }
}
