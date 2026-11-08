<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/** 用户确认的正式目录基线；仅补缺，不重放旧迁移、库存或业务日志。 */
class ErpOfficialCatalogSeeder extends Seeder
{
    use RunsSafeSeed;

    private const TABLES = [
        'erp_units' => ['unit_code', 'standard_unit_id'],
        'erp_item_categories' => ['category_code', 'parent_id'],
        'erp_products' => ['product_code', null],
        'erp_items' => ['item_code', 'base_item_id'],
        'erp_skus' => ['sku_code', 'base_sku_id'],
    ];

    private function seed(): void
    {
        $bytes = file_get_contents(__DIR__.'/data/erp_official_catalog.json');
        if ($bytes === false) throw new RuntimeException('正式目录数据包不可读取。');
        $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
        if (($data['contract_version'] ?? null) !== 1 || ($data['timestamps_timezone'] ?? null) !== 'UTC'
            || !isset($data['tables']) || !is_array($data['tables'])
            || !isset($data['number_floors']) || !is_array($data['number_floors']) || !array_is_list($data['number_floors'])
            || array_diff(array_keys($data['tables']), [...array_keys(self::TABLES), 'erp_sku_item_relations'])) {
            throw new RuntimeException('正式目录数据包契约不匹配。');
        }
        $tables = [];
        foreach (self::TABLES as $table => [$code]) $tables[$table] = $this->rows($data, $table, $code);
        $tables['erp_sku_item_relations'] = $this->rows($data, 'erp_sku_item_relations', null);
        $timezone = DB::selectOne('SELECT @@SESSION.time_zone AS time_zone')->time_zone;
        try {
            // 数据包中的timestamp为UTC，必须隔离部署服务器和当前连接的时区差异。
            DB::statement("SET SESSION time_zone = '+00:00'");
            foreach (self::TABLES as $table => [$code, $parent]) $this->insertMissing($table, $code, $tables[$table], $parent);
            $this->insertRelations($tables['erp_sku_item_relations']);
            foreach ($data['number_floors'] ?? [] as $floor) $this->advanceCounter($floor);
        } finally {
            DB::statement('SET SESSION time_zone = ?', [$timezone]);
        }
    }

    private function rows(array $data, string $table, ?string $code): array
    {
        $rows = $data['tables'][$table] ?? null;
        if (!is_array($rows) || !array_is_list($rows)) throw new RuntimeException('正式目录缺少完整记录数组：'.$table);
        if (!isset($data['table_counts'][$table]) || !is_int($data['table_counts'][$table])
            || count($rows) !== $data['table_counts'][$table]) throw new RuntimeException('正式目录记录数量不完整：'.$table);
        $ids = [];
        $identities = [];
        foreach ($rows as $row) {
            if (!is_array($row) || !isset($row['id']) || !is_int($row['id']) || $row['id'] <= 0 || isset($ids[$row['id']])) {
                throw new RuntimeException('正式目录ID无效或重复：'.$table);
            }
            if ($code !== null) {
                if (!isset($row[$code]) || !is_string($row[$code]) || trim($row[$code]) === '') throw new RuntimeException('正式目录编码缺失：'.$table);
                $identity = $row[$code];
            } else {
                if (!isset($row['sku_id'], $row['relation_type']) || !is_int($row['sku_id']) || $row['sku_id'] <= 0
                    || !array_key_exists('item_id', $row) || ($row['item_id'] !== null && (!is_int($row['item_id']) || $row['item_id'] <= 0))
                    || !is_string($row['relation_type']) || $row['relation_type'] === '' || array_key_exists('enabled_primary_sku_id', $row)) {
                    throw new RuntimeException('正式目录关系身份无效或包含生成列。');
                }
                $identity = json_encode([$row['sku_id'], $row['item_id'], $row['relation_type']], JSON_THROW_ON_ERROR);
            }
            if (isset($identities[$identity])) throw new RuntimeException('正式目录身份重复：'.$table.' / '.$identity);
            $ids[$row['id']] = true;
            $identities[$identity] = true;
        }
        return $rows;
    }

    private function insertMissing(string $table, string $code, array $rows, ?string $parent): void
    {
        $pending = array_column($rows, null, 'id');
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $id => $row) {
                $matches = DB::table($table)->where('id', $id)->orWhere($code, $row[$code])->lockForUpdate()->get(['id', $code]);
                foreach ($matches as $existing) if ((int) $existing->id !== $id || $existing->{$code} !== $row[$code]) {
                    throw new RuntimeException('正式目录ID/编码冲突：'.$table.' / '.$id.' / '.$row[$code]);
                }
                if ($matches->isEmpty()) {
                    if ($parent !== null && ($row[$parent] ?? null) !== null
                        && !($table === 'erp_units' && (int) $row[$parent] === $id)
                        && !DB::table($table)->where('id', $row[$parent])->lockForUpdate()->exists()) continue;
                    // 保留既有整行；名称、状态、价格、成本、关联及时间均不能被基线覆盖。
                    DB::table($table)->insert($row);
                }
                unset($pending[$id]);
                $progress = true;
            }
            if (!$progress) throw new RuntimeException('正式目录存在缺失或循环的同表引用：'.$table);
        }
    }

    private function insertRelations(array $rows): void
    {
        $table = 'erp_sku_item_relations';
        $preservedSkus = array_fill_keys(DB::table($table)->lockForUpdate()->pluck('sku_id')->all(), true);
        $pending = [];
        foreach ($rows as $row) {
            $matches = DB::table($table)->where('id', $row['id'])->orWhere(fn ($query) => $query
                ->where('sku_id', $row['sku_id'])->where('item_id', $row['item_id'])->where('relation_type', $row['relation_type']))
                ->lockForUpdate()->get(['id', 'sku_id', 'item_id', 'relation_type']);
            foreach ($matches as $existing) if ((int) $existing->id !== $row['id'] || (int) $existing->sku_id !== $row['sku_id']
                || (string) $existing->item_id !== (string) $row['item_id'] || $existing->relation_type !== $row['relation_type']) {
                throw new RuntimeException('正式目录默认关系ID或自然身份冲突：'.$row['id']);
            }
            // 已有任意关系即保留整个SKU关系组，不把人工替换过的主关系补回旧值。
            if ($matches->isEmpty() && !isset($preservedSkus[$row['sku_id']])) $pending[$row['id']] = $row;
        }
        while ($pending !== []) {
            $progress = false;
            foreach ($pending as $id => $row) {
                if (($row['base_relation_id'] ?? null) !== null
                    && !DB::table($table)->where('id', $row['base_relation_id'])->lockForUpdate()->exists()) continue;
                DB::table($table)->insert($row);
                unset($pending[$id]);
                $progress = true;
            }
            if (!$progress) throw new RuntimeException('正式目录默认关系存在缺失或循环的基础关系。');
        }
    }

    private function advanceCounter(array $floor): void
    {
        $types = ['product' => ['erp_products', 'product_code'], 'sku' => ['erp_skus', 'sku_code'],
            'item' => ['erp_items', 'item_code'], 'item_category' => ['erp_item_categories', 'category_code'],
            'unit' => ['erp_units', 'unit_code']];
        $type = $floor['document_type'] ?? '';
        if (!isset($types[$type], $floor['prefix'], $floor['sequence_length'], $floor['current_sequence'])
            || ($floor['date_format'] ?? null) !== '' || ($floor['reset_cycle'] ?? null) !== 'none'
            || !is_int($floor['sequence_length']) || $floor['sequence_length'] < 1
            || !is_int($floor['current_sequence']) || $floor['current_sequence'] < 0) {
            throw new RuntimeException('正式目录编号下限契约不匹配。');
        }
        $rule = DB::table('erp_document_number_rules')->where('document_type', $type)->lockForUpdate()->first();
        if (!$rule) throw new RuntimeException('正式目录缺少编号规则，请先运行默认基础Seeder：'.$type);
        if (!$rule->enabled || $rule->prefix !== $floor['prefix'] || $rule->date_format !== ''
            || $rule->reset_cycle !== 'none' || (int) $rule->sequence_length !== $floor['sequence_length']) return;
        [$table, $code] = $types[$type];
        $minimum = $floor['current_sequence'];
        $pattern = '/^'.preg_quote($floor['prefix'], '/').'(\d{'.$floor['sequence_length'].',})$/D';
        $codes = DB::table($table)->lockForUpdate()->pluck($code)->all();
        $reservations = DB::table('erp_document_number_reservations')->where('document_type', $type)->lockForUpdate()->pluck('document_no')->all();
        foreach (array_merge($codes, $reservations) as $value) if (preg_match($pattern, $value, $match)) $minimum = max($minimum, (int) $match[1]);
        $key = ['document_type' => $type, 'number_date' => '1970-01-01'];
        $counter = DB::table('erp_document_numbers')->where($key)->lockForUpdate()->first();
        $timestamp = gmdate('Y-m-d H:i:s');
        if (!$counter) {
            DB::table('erp_document_numbers')->insert($key + ['current_sequence' => $minimum, 'created_at' => $timestamp, 'updated_at' => $timestamp]);
        } elseif ((int) $counter->current_sequence < $minimum) {
            // 正式目录占用已批准编码区间；仅前进，保留较大计数、自定义规则和全部预占记录。
            DB::table('erp_document_numbers')->where($key)->update(['current_sequence' => $minimum, 'updated_at' => $timestamp]);
        }
    }
}
