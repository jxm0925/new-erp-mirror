<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ErpApprovedMasterDataSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        // These two exports were approved as permanent reset baselines on
        // 2026-09-21. Explicit IDs also preserve category parent references.
        $categories = $this->loadRows('erp_item_categories', 'category_code');
        $suppliers = $this->loadRows('erp_suppliers', 'supplier_code');

        $this->assertDependencies($categories, $suppliers);

        DB::transaction(function () use ($categories, $suppliers) {
            $pending = array_column($categories, null, 'id');
            $inserted = [];
            while ($pending !== []) {
                $progress = false;
                foreach ($pending as $id => $row) {
                    if ($row['parent_id'] !== null && !isset($inserted[$row['parent_id']])) {
                        continue;
                    }
                    $this->insertMissing('erp_item_categories', 'category_code', $row);
                    $inserted[$id] = true;
                    unset($pending[$id]);
                    $progress = true;
                }
                if (!$progress) {
                    throw new RuntimeException('基础分类存在循环或缺失的父分类，导入已回滚。');
                }
            }
            foreach ($suppliers as $row) {
                $this->insertMissing('erp_suppliers', 'supplier_code', $row);
            }

            $this->advanceCounter('item_category', 'erp_item_categories', 'category_code', 'IC');
            $this->advanceCounter('supplier', 'erp_suppliers', 'supplier_code', 'SUP');
        });
    }

    private function assertDependencies(array $categories, array $suppliers): void
    {
        $categoryIds = array_column($categories, null, 'id');
        foreach ($categories as $row) {
            $seen = [];
            $parent = $row['parent_id'];
            while ($parent !== null) {
                if (isset($seen[$parent]) || !isset($categoryIds[$parent])) throw new RuntimeException('基础分类存在循环或缺失父分类。');
                $seen[$parent] = true;
                $parent = $categoryIds[$parent]['parent_id'];
            }
        }
        foreach ([['erp_item_categories', 'category_code', 'item_category', 'IC', $categories], ['erp_suppliers', 'supplier_code', 'supplier', 'SUP', $suppliers]] as [$table, $code, $type, $prefix, $rows]) {
            foreach ($rows as $row) {
                foreach (DB::table($table)->where('id', $row['id'])->orWhere($code, $row[$code])->lockForUpdate()->get(['id', $code]) as $existing) {
                    if ((int) $existing->id !== $row['id'] || $existing->{$code} !== $row[$code]) throw new RuntimeException($table.' 基础ID/编码冲突。');
                }
            }
            $rule = DB::table('erp_document_number_rules')->where('document_type', $type)->lockForUpdate()->first();
            if (!$rule) throw new RuntimeException('基础资料缺少编号规则：'.$type.'，请先补齐编号规则。');
            if ($rule->prefix !== $prefix || $rule->date_format !== '' || $rule->reset_cycle !== 'none') continue;
            $maximum = 0;
            foreach (array_merge(array_column($rows, $code), DB::table($table)->pluck($code)->all()) as $value) {
                if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/D', $value, $m)) $maximum = max($maximum, (int) $m[1]);
            }
            $counter = DB::table('erp_document_numbers')->where(['document_type' => $type, 'number_date' => '1970-01-01'])->lockForUpdate()->first();
            if ($counter && (int) $counter->current_sequence < $maximum) throw new RuntimeException('现有编号计数不足，请通过编号维护处理；Seeder不改已有计数。');
        }
    }

    private function loadRows(string $table, string $code): array
    {
        $data = json_decode(file_get_contents(__DIR__.'/data/'.$table.'.json'), true, 512, JSON_THROW_ON_ERROR);
        $rows = $data['rows'];
        $ids = [];
        $codes = [];
        foreach ($rows as $row) {
            if (!is_int($row['id']) || $row['id'] <= 0 || empty($row[$code])
                || isset($ids[$row['id']]) || isset($codes[$row[$code]])) {
                throw new RuntimeException($table.' 基础数据ID或编码无效。');
            }
            $ids[$row['id']] = true;
            $codes[$row[$code]] = true;
        }

        return $rows;
    }

    private function insertMissing(string $table, string $code, array $row): void
    {
        $matches = DB::table($table)->where('id', $row['id'])
            ->orWhere($code, $row[$code])->lockForUpdate()->get(['id', $code]);
        foreach ($matches as $existing) {
            // Do not renumber existing records or overwrite another identity:
            // either would silently redirect business foreign keys after reset.
            if ((int) $existing->id !== $row['id'] || $existing->{$code} !== $row[$code]) {
                throw new RuntimeException($table.' 基础ID/编码冲突：'.$row['id'].' / '.$row[$code]);
            }
        }
        // Re-seeding fills missing records; user edits and timestamps survive.
        if ($matches->isEmpty()) {
            DB::table($table)->insert($row);
        }
    }

    private function advanceCounter(string $type, string $table, string $code, string $prefix): void
    {
        $rule = DB::table('erp_document_number_rules')->where('document_type', $type)->lockForUpdate()->first();
        if ($rule && ($rule->prefix !== $prefix || $rule->date_format !== '' || $rule->reset_cycle !== 'none')) {
            return;
        }

        // Only a missing counter may be initialized. Existing counters are user data;
        // an insufficient sequence requires explicit number-rule maintenance.
        $maximum = 0;
        foreach (DB::table($table)->pluck($code) as $value) {
            if (preg_match('/^'.preg_quote($prefix, '/').'(\d+)$/D', $value, $match)) {
                $maximum = max($maximum, (int) $match[1]);
            }
        }
        $key = ['document_type' => $type, 'number_date' => '1970-01-01'];
        $counter = DB::table('erp_document_numbers')->where($key)->lockForUpdate()->first();
        if (!$counter) {
            DB::table('erp_document_numbers')->insert($key + [
                'current_sequence' => $maximum, 'created_at' => now(), 'updated_at' => now(),
            ]);
        } elseif ((int) $counter->current_sequence < $maximum) {
            throw new RuntimeException('现有编号计数不足，请通过编号维护处理；Seeder不改已有计数。');
        }
    }
}
