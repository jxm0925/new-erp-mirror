<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Illuminate\Database\Seeder;

/**
 * 先执行 php artisan db:seed 建立编号规则和已批准分类，
 * 再手动执行 php artisan db:seed --class=ErpTestDataSeeder；不纳入默认入口。
 * 当前基准来自 2026-11-06 SQL；旧双滤 Seeder 保留为独立历史入口，不再混入。
 */
class ErpTestDataSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        $this->call([
            ErpTestMasterDataSeeder::class,
        ]);
    }
}
