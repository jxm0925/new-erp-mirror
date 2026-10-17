<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;
use Illuminate\Support\Facades\DB;

/** 稳定生产目录原来夹在建表迁移中；这里只补缺，不覆盖用户已维护的名称、状态或版本。 */
class ErpProductionCatalogSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        $catalogs = [
            'erp_production_stages' => [
                ['code' => 'CUTTING', 'name' => '下料', 'sort' => 5],
                ['code' => 'WELDING', 'name' => '焊接', 'sort' => 10],
                ['code' => 'GRINDING', 'name' => '打磨', 'sort' => 15],
                ['code' => 'ASSEMBLY', 'name' => '总装', 'sort' => 20],
                ['code' => 'WATER_TEST', 'name' => '试水', 'sort' => 25],
                ['code' => 'PACKING', 'name' => '打包', 'sort' => 28],
                ['code' => 'SHIPPING', 'name' => '发货', 'sort' => 30],
            ],
            'erp_production_packaging_schemes' => [
                ['code' => 'WOODEN_CASE', 'name' => '木箱', 'sort' => 10],
                ['code' => 'CARTON', 'name' => '纸箱', 'sort' => 20],
            ],
        ];

        DB::transaction(function () use ($catalogs): void {
            foreach ($catalogs as $table => $rows) {
                foreach ($rows as $row) {
                    SeedSafety::insertMissing($table, ['code' => $row['code']], $row);
                }
            }
        });
    }
}
