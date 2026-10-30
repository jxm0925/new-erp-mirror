<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Illuminate\Database\Seeder;

/**
 * 先执行 php artisan db:seed 建立编号规则和已批准分类，
 * 再手动执行 php artisan db:seed --class=ErpTestDataSeeder；不纳入默认入口。
 */
class ErpTestDataSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        $this->call([
            ErpDualFilterMaterialSeeder::class,
            ErpDualFilterProductBomSeeder::class,
            ErpTestWarehouseSeeder::class,
        ]);
    }
}
