<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class ErpRegionSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        $rows = json_decode(file_get_contents(__DIR__.'/data/erp_regions.json'), true, 512, JSON_THROW_ON_ERROR);
        if (!is_array($rows) || !array_is_list($rows) || !$rows) {
            throw new RuntimeException('地区基础数据必须是非空节点列表。');
        }
        $nodes = [];
        foreach ($rows as $row) {
            if (!is_array($row) || count($row) !== 4 || !is_int($row['id'] ?? null) || $row['id'] <= 0
                || !is_int($row['parent_id'] ?? null) || $row['parent_id'] < 0
                || !is_int($row['level'] ?? null) || $row['level'] < 0 || $row['level'] > 3
                || !is_string($row['name'] ?? null) || trim($row['name']) === '' || mb_strlen($row['name']) > 80
                || isset($nodes[$row['id']])) {
                throw new RuntimeException('地区基础数据存在无效或重复节点。');
            }
            $nodes[$row['id']] = $row;
        }
        foreach ($rows as $row) {
            $parent = $nodes[$row['parent_id']] ?? null;
            if (($row['level'] === 0 && $row['parent_id'] !== 0)
                || ($row['level'] > 0 && (!$parent || $parent['level'] !== $row['level'] - 1))) {
                throw new RuntimeException('地区父级缺失或层级不一致，停止填充：'.$row['id']);
            }
        }
        usort($rows, fn ($left, $right) => $left['level'] <=> $right['level']);
        foreach (array_chunk($rows, 500) as $chunk) {
            $existing = DB::table('erp_regions')->whereIn('id', array_column($chunk, 'id'))
                ->lockForUpdate()->get(['id', 'parent_id', 'name', 'level'])->keyBy('id');
            $missing = [];
            foreach ($chunk as $row) {
                $current = $existing->get($row['id']);
                // ID 已被历史单据引用，身份不一致时必须回滚，不能挪用或覆盖人工维护的地区。
                if ($current && ((int) $current->parent_id !== $row['parent_id']
                    || $current->name !== $row['name'] || (int) $current->level !== $row['level'])) {
                    throw new RuntimeException('地区ID与现有记录冲突，填充已回滚：'.$row['id']);
                }
                if (!$current) $missing[] = $row;
            }
            if ($missing) DB::table('erp_regions')->insert($missing);
        }
    }
}
