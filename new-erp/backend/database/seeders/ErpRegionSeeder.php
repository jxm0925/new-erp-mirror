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
        foreach ($rows as $row) {
            $existing = DB::table('erp_regions')->where('id', $row['id'])->lockForUpdate()->first();
            if ($existing) continue;
            if ($row['parent_id'] && !DB::table('erp_regions')->where('id', $row['parent_id'])->exists()) {
                throw new RuntimeException('地区父级缺失，停止填充：'.$row['id']);
            }
            DB::table('erp_regions')->insert($row);
        }
    }
}
