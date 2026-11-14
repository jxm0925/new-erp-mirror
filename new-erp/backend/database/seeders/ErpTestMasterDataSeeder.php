<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * 2026-11-06 SQL 导出的业务主数据基准；仅由测试数据入口手动调用。
 * 按业务编码补缺并重映射 ID，不恢复登录状态、历史日志或业务单据。
 */
class ErpTestMasterDataSeeder extends Seeder
{
    use RunsSafeSeed;

    private array $ids = [];
    private array $created = [];
    private array $preservedGroups = [];

    // 路线和 BOM 是整体版本；已有版本的明细不能混入另一份导出的配置。
    private const VERSION_CHILDREN = [
        'erp_bom_items' => ['bom_id', 'erp_boms'],
        'erp_production_routing_operations' => ['routing_id', 'erp_production_routings'],
        'erp_production_routing_applicabilities' => ['routing_id', 'erp_production_routings'],
    ];

    private const PRESERVED_GROUPS = [
        'erp_item_material_policies' => 'item_id',
        'erp_item_purchase_conversions' => 'item_id',
        'erp_sku_item_relations' => 'sku_id',
    ];

    private function seed(): void
    {
        if (!app()->environment(['local', 'testing'])) {
            throw new RuntimeException('业务测试资料只允许在 local/testing 环境导入。');
        }
        $this->ids = $this->created = $this->preservedGroups = [];
        $data = json_decode(file_get_contents(__DIR__.'/data/erp_test_master_data.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->prepareNumberRules($data['number_rules']);

        $pending = [];
        foreach ($data['tables'] as $table => $definition) {
            foreach ($definition['rows'] as $row) {
                $pending[$table][$row['id']] = $row;
            }
        }

        // 分类树、自引用及跨表引用均等待依赖映射就绪，不关闭外键约束。
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $table => $rows) {
                $definition = $data['tables'][$table];
                foreach ($rows as $sourceId => $source) {
                    $row = $this->mapReferences($source, $definition['references']);
                    if ($row === null) {
                        continue;
                    }
                    $row = $this->mapSnapshots($table, $row);
                    $key = array_intersect_key($row, array_flip($definition['key']));
                    $matches = DB::table($table)->where($key)->lockForUpdate()->get();
                    if ($matches->count() > 1) {
                        throw new RuntimeException($table.' 的业务键不唯一，已回滚，请先处理重复资料。');
                    }
                    $existing = $matches->first();
                    if ($existing) {
                        $this->checkIdentity($table, $row, (array) $existing);
                        $this->ids[$table][$sourceId] = (int) $existing->id;
                    } elseif (!$this->preserveGroup($table, $row)) {
                        unset($row['id']);
                        $id = DB::table($table)->insertGetId($row);
                        $this->ids[$table][$sourceId] = $id;
                        $this->created[$table][$id] = true;
                    }
                    unset($pending[$table][$sourceId]);
                    $progress = true;
                }
                if ($pending[$table] === []) {
                    unset($pending[$table]);
                }
            }
            if (!$progress) {
                throw new RuntimeException('测试资料存在缺失或循环依赖，或引用了被保留版本中不存在的明细；导入已回滚。');
            }
        }
        $this->advanceCounters($data['number_counters']);
    }

    private function mapReferences(array $row, array $references): ?array
    {
        foreach ($references as $column => $target) {
            if ($row[$column] === null) {
                continue;
            }
            if (!isset($this->ids[$target][$row[$column]])) {
                return null;
            }
            $row[$column] = $this->ids[$target][$row[$column]];
        }

        return $row;
    }

    private function mapSnapshots(string $table, array $row): array
    {
        if ($table === 'erp_production_routing_applicabilities') {
            $row['scope_key'] = match ($row['scope_type']) {
                'item' => 'item:'.$row['item_id'],
                'category' => 'category:'.$row['item_category_id'],
                default => throw new RuntimeException('未知的工艺路线适用范围。'),
            };
        }
        foreach (['parameter_snapshot', 'cutting_quantity_snapshot', 'packaging_materials'] as $column) {
            if (isset($row[$column])) {
                $value = json_decode($row[$column], true, 512, JSON_THROW_ON_ERROR);
                $row[$column] = json_encode($this->mapJsonReferences($value), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
        }

        return $row;
    }

    private function mapJsonReferences(array $value, ?string $context = null): array
    {
        $references = [
            'item_id' => 'erp_items', 'unit_id' => 'erp_units',
            'serial_generation_routing_operation_id' => 'erp_production_routing_operations',
        ];
        foreach ($value as $key => $entry) {
            if (is_array($entry)) {
                $value[$key] = $this->mapJsonReferences($entry, (string) $key);
                continue;
            }
            $target = $key === 'id' && $context === 'unit' ? 'erp_units' : ($references[$key] ?? null);
            if ($target && $entry !== null) {
                if (!isset($this->ids[$target][$entry])) {
                    throw new RuntimeException('JSON 配置引用缺失：'.$target.' / '.$entry);
                }
                $value[$key] = $this->ids[$target][$entry];
            }
        }

        return $value;
    }

    private function preserveGroup(string $table, array $row): bool
    {
        if (isset(self::VERSION_CHILDREN[$table])) {
            [$column, $parent] = self::VERSION_CHILDREN[$table];

            return !isset($this->created[$parent][$row[$column]]);
        }
        $column = self::PRESERVED_GROUPS[$table] ?? null;
        if (!$column) {
            return false;
        }
        $group = $table.':'.$row[$column];

        return $this->preservedGroups[$group] ??= DB::table($table)->where($column, $row[$column])->lockForUpdate()->exists();
    }

    private function checkIdentity(string $table, array $incoming, array $existing): void
    {
        // 编码相同但计量或产品归属不同，不能把新 BOM 静默关联到另一种资料。
        $columns = match ($table) {
            'erp_units' => ['unit_type', 'allow_decimal', 'decimal_places'],
            'erp_items' => ['unit_id', 'item_type'],
            'erp_skus' => ['product_id', 'sales_unit_id'],
            'erp_boms' => ['output_item_id', 'bom_type', 'version'],
            default => [],
        };
        foreach ($columns as $column) {
            if ((string) $incoming[$column] !== (string) $existing[$column]) {
                throw new RuntimeException($table.' 的 '.$column.' 与测试基准冲突，已回滚；不会覆盖已有资料。');
            }
        }
    }

    private function prepareNumberRules(array $rules): void
    {
        foreach ($rules as $row) {
            $key = ['document_type' => $row['document_type']];
            SeedSafety::insertMissing('erp_document_number_rules', $key, $row);
            $existing = (array) DB::table('erp_document_number_rules')->where($key)->lockForUpdate()->first();
            foreach (['prefix', 'date_format', 'sequence_length', 'reset_cycle'] as $column) {
                if ((string) $existing[$column] !== (string) $row[$column]) {
                    throw new RuntimeException('编号规则与测试基准不同：'.$row['document_type'].'；导入已回滚，不覆盖现有规则。');
                }
            }
        }
    }

    private function advanceCounters(array $counters): void
    {
        foreach ($counters as $row) {
            $key = ['document_type' => $row['document_type'], 'number_date' => $row['number_date']];
            SeedSafety::insertMissing('erp_document_numbers', $key, $row);
            // 基准保留原业务编号；计数只向上推进，防止随后创建资料重号。
            DB::table('erp_document_numbers')->where($key)
                ->where('current_sequence', '<', $row['current_sequence'])
                ->update(['current_sequence' => $row['current_sequence'], 'updated_at' => now()]);
        }
    }
}
