<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Illuminate\Database\QueryException;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use JsonException;
use RuntimeException;

class ErpOfficialCustomersSeeder extends Seeder
{
    use RunsSafeSeed;

    public const BATCH_NO = 'LEGACY-CUSTOMERS-20261109';
    private const BATCH_TYPE = 'Legacy Customers';
    private const SOURCE_TABLE = 'jt_customer';

    private function seed(): void
    {
        // 完整来源审计随正式数据包保留，默认128M不足以解码该批客户资料。
        $memoryLimit = ini_get('memory_limit');
        if ($memoryLimit !== '-1' && ini_parse_quantity($memoryLimit) < 512 * 1024 * 1024
            && ini_set('memory_limit', '512M') === false) {
            throw new RuntimeException('客户Seeder需要512M内存，请用 php -d memory_limit=512M 执行。');
        }
        $bytes = @file_get_contents(__DIR__ . '/data/erp_official_customers.json');
        if ($bytes === false) {
            throw new RuntimeException('正式客户数据包不可读取。');
        }
        try {
            $data = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR);
            unset($bytes);
            $this->validatePackage($data);

            DB::connection()->disableQueryLog();
            $timezone = DB::selectOne('SELECT @@SESSION.time_zone AS timezone', [], false)->timezone;
            DB::statement("SET SESSION time_zone = '+00:00'");
            try {
                [$actualIds, $created] = $this->seedCustomers($data['customers'], $data['import_rows']);
                $this->seedChildren('erp_sales_customer_contacts', $data['contacts'], $created);
                $this->seedChildren('erp_sales_customer_addresses', $data['addresses'], $created);
                $this->seedAudit($data['batch'], $data['import_rows'], $actualIds);
            } finally {
                DB::statement('SET SESSION time_zone = ?', [$timezone]);
            }
        } catch (JsonException) {
            throw new RuntimeException('正式客户数据包或来源审计 JSON 无效。');
        } catch (QueryException $exception) {
            // QueryException 包含姓名、地址和电话的 SQL 绑定值。
            throw new RuntimeException('正式客户补缺发生数据库冲突，SQLSTATE ' . preg_replace('/[^A-Z0-9]/', '', (string) $exception->getCode()) . '。');
        }
    }

    private function validatePackage(#[\SensitiveParameter] mixed $data): void
    {
        if (! is_array($data) || ($data['contract_version'] ?? null) !== 1 || ($data['timestamps_timezone'] ?? null) !== 'UTC'
            || ($data['batch']['batch_no'] ?? null) !== self::BATCH_NO
            || ($data['batch']['import_type'] ?? null) !== self::BATCH_TYPE
            || ($data['batch']['status'] ?? null) !== 'confirmed' || empty($data['batch']['confirmed_at'])) {
            throw new RuntimeException('正式客户数据包契约或导入批次无效。');
        }
        foreach (['customers', 'contacts', 'addresses', 'import_rows'] as $key) {
            if (! is_array($data[$key] ?? null) || ! array_is_list($data[$key])
                || count($data[$key]) !== ($data['table_counts'][$key] ?? null)) {
                throw new RuntimeException('正式客户数据包记录数无效：' . $key);
            }
        }

        $customers = $legacyIds = [];
        foreach ($data['customers'] as $row) {
            $id = $row['id'] ?? null;
            $legacy = $row['legacy_customer_id'] ?? null;
            if (! is_int($id) || $id <= 0 || ! is_int($legacy) || $legacy <= 0
                || isset($customers[$id]) || isset($legacyIds[$legacy]) || trim((string) ($row['customer_name'] ?? '')) === '') {
                throw new RuntimeException('正式客户主键或旧身份缺失、重复。');
            }
            $customers[$id] = $legacy;
            $legacyIds[$legacy] = true;
        }

        $children = [];
        foreach (['contacts', 'addresses'] as $key) {
            $children[$key] = [];
            foreach ($data[$key] as $row) {
                $id = $row['id'] ?? null;
                $owner = $row['customer_id'] ?? null;
                if (! is_int($id) || $id <= 0 || isset($children[$key][$id]) || ! is_int($owner) || ! isset($customers[$owner])) {
                    throw new RuntimeException('正式客户子记录主键或客户引用无效：' . $key);
                }
                $children[$key][$id] = $owner;
            }
        }

        $sourceIds = $mapped = $skipped = [];
        $counts = ['valid' => 0, 'warning' => 0, 'error' => 0];
        foreach ($data['import_rows'] as $index => $row) {
            $source = (string) ($row['source_id'] ?? '');
            $status = $row['validation_status'] ?? '';
            $target = $row['target_id'] ?? null;
            if ($row['row_no'] !== $index + 1 || ($row['source_table'] ?? null) !== self::SOURCE_TABLE
                || ! ctype_digit($source) || (int) $source <= 0 || isset($sourceIds[$source]) || ! isset($counts[$status])) {
                throw new RuntimeException('正式客户来源记录或顺序无效。');
            }
            $sourceIds[$source] = true;
            $counts[$status]++;
            if ($target === null && $status === 'error') {
                $skipped[] = (int) $source;
                continue;
            }
            $meta = json_decode($row['normalized_data'], true, 512, JSON_THROW_ON_ERROR);
            if ($status === 'error' || ! is_int($target) || ! isset($customers[$target])
                || ($meta['target_customer_id'] ?? null) !== $target
                || ($meta['canonical_legacy_customer_id'] ?? null) !== $customers[$target]
                || (isset($meta['target_contact_id']) && ($children['contacts'][$meta['target_contact_id']] ?? null) !== $target)
                || (isset($meta['target_address_id']) && ($children['addresses'][$meta['target_address_id']] ?? null) !== $target)) {
                throw new RuntimeException('正式客户来源映射不完整，来源 ID ' . $source . '。');
            }
            $mapped[$target] = true;
        }
        if (count($mapped) !== count($customers) || count($sourceIds) !== (int) $data['batch']['total_rows']
            || $skipped !== [3835, 4174, 4433, 9393, 10108, 10171]) {
            throw new RuntimeException('正式客户来源覆盖与批次不一致。');
        }
        foreach ($counts as $status => $count) {
            if ($count !== (int) $data['batch'][$status . '_rows']) {
                throw new RuntimeException('正式客户批次数量与来源状态不一致。');
            }
        }
    }

    private function seedCustomers(#[\SensitiveParameter] array $customers, #[\SensitiveParameter] array $audits): array
    {
        $existing = DB::table('erp_sales_customers')->orderBy('id')->lockForUpdate()->get(['id', 'legacy_customer_id']);
        $byLegacyId = [];
        foreach ($existing as $row) {
            if ($row->legacy_customer_id === null) {
                continue;
            }
            $legacy = (int) $row->legacy_customer_id;
            if (isset($byLegacyId[$legacy])) {
                throw new RuntimeException('已有多个客户使用同一个旧身份，来源 ID ' . $legacy . '。');
            }
            $byLegacyId[$legacy] = (int) $row->id;
        }
        $baselineLegacyIds = array_fill_keys(array_column($customers, 'legacy_customer_id'), true);
        foreach ($audits as $row) {
            $legacy = (int) $row['source_id'];
            if ($row['target_id'] !== null && ! isset($baselineLegacyIds[$legacy]) && isset($byLegacyId[$legacy])) {
                throw new RuntimeException('合并别名已被其他客户档案占用，来源 ID ' . $legacy . '。');
            }
        }

        $takenIds = $existing->keyBy('id');
        $actualIds = $created = $missing = [];
        foreach ($customers as $row) {
            $id = $row['id'];
            $legacy = $row['legacy_customer_id'];
            if (isset($byLegacyId[$legacy])) {
                $actualIds[$id] = $byLegacyId[$legacy];
                continue;
            }
            if (isset($takenIds[$id])) {
                throw new RuntimeException('正式客户主键已被其他身份占用，目标 ID ' . $id . '。');
            }
            $missing[] = $row;
            $actualIds[$id] = $id;
            $created[$id] = true;
        }
        foreach (array_chunk($missing, 250) as $chunk) {
            DB::table('erp_sales_customers')->insert($chunk);
        }
        return [$actualIds, $created];
    }

    private function seedChildren(string $table, #[\SensitiveParameter] array $rows, array $created): void
    {
        if ($created === []) {
            return;
        }
        // 已有客户的整个联系人和地址组由用户维护，包括有意清空的组。
        $new = array_filter($rows, static fn (array $row): bool => isset($created[$row['customer_id']]));
        if ($table === 'erp_sales_customer_addresses') {
            $regions = array_fill_keys(DB::table('erp_regions')->pluck('id')->all(), true);
            foreach ($new as $row) {
                if ($row['region_id'] !== null && ! isset($regions[$row['region_id']])) {
                    throw new RuntimeException('正式客户地址的地区缺失，地区 ID ' . $row['region_id'] . '。请先填充地区。');
                }
            }
        }
        foreach (array_chunk($new, 250) as $chunk) {
            DB::table($table)->insert($chunk);
        }
    }

    private function seedAudit(array $batch, #[\SensitiveParameter] array $rows, array $actualIds): void
    {
        $query = DB::table('erp_import_batches')->where('batch_no', self::BATCH_NO);
        $existingBatch = $query->lockForUpdate()->first();
        unset($batch['id']);
        if ($existingBatch) {
            foreach (['import_type', 'status', 'total_rows', 'valid_rows', 'warning_rows', 'error_rows'] as $field) {
                if ((string) $existingBatch->{$field} !== (string) $batch[$field] || ! $existingBatch->confirmed_at) {
                    throw new RuntimeException('正式客户批号与已有审计冲突。');
                }
            }
            $batchId = (int) $existingBatch->id;
        } else {
            $batchId = DB::table('erp_import_batches')->insertGetId($batch);
        }

        $existing = DB::table('erp_import_rows')->where('batch_id', $batchId)->lockForUpdate()
            ->get(['row_no', 'source_id', 'source_table', 'validation_status', 'target_id'])->keyBy('row_no');
        $missing = [];
        foreach ($rows as $row) {
            $current = $existing[$row['row_no']] ?? null;
            $targetId = $row['target_id'] === null ? null : $actualIds[$row['target_id']];
            if ($current !== null) {
                if ($current->source_table !== self::SOURCE_TABLE || (string) $current->source_id !== (string) $row['source_id']
                    || $current->validation_status !== $row['validation_status']
                    || ($current->target_id === null ? null : (int) $current->target_id) !== $targetId) {
                    throw new RuntimeException('正式客户来源映射与已有审计冲突，来源 ID ' . (int) $row['source_id'] . '。');
                }
                continue;
            }
            unset($row['id']);
            $row['batch_id'] = $batchId;
            $row['target_id'] = $targetId;
            $missing[] = $row;
        }
        if ($existing->count() + count($missing) !== count($rows)) {
            throw new RuntimeException('正式客户批次有多余的已有来源记录。');
        }
        if ($missing === []) {
            return;
        }

        $contacts = DB::table('erp_sales_customer_contacts')->pluck('customer_id', 'id')->all();
        $addresses = DB::table('erp_sales_customer_addresses')->pluck('customer_id', 'id')->all();
        foreach (array_chunk($missing, 250) as $chunk) {
            foreach ($chunk as &$row) {
                if ($row['target_id'] === null) {
                    continue;
                }
                $meta = json_decode($row['normalized_data'], true, 512, JSON_THROW_ON_ERROR);
                if ($meta['target_customer_id'] !== $row['target_id']) {
                    $meta['original_target_customer_id'] = $meta['target_customer_id'];
                    $meta['target_customer_id'] = $row['target_id'];
                }
                foreach (['target_contact_id' => $contacts, 'target_address_id' => $addresses] as $field => $owners) {
                    $id = $meta[$field] ?? null;
                    if ($id !== null && (int) ($owners[$id] ?? 0) !== $row['target_id']) {
                        $meta['original_' . $field] = $id;
                        $meta[$field] = null;
                    }
                }
                $row['normalized_data'] = json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            }
            unset($row);
            DB::table('erp_import_rows')->insert($chunk);
        }
    }
}
