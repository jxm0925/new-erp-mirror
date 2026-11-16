<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;
use Illuminate\Database\Seeder;

/** 用户确认的仓库及库位；先恢复正式人员，再按原ID补缺，已有人工配置保持。 */
class ErpApprovedWarehouseSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        foreach (['erp_warehouses' => 'warehouse_code', 'erp_locations' => 'location_code'] as $table => $code) {
            $package = json_decode(file_get_contents(__DIR__.'/data/'.$table.'.json'), true, 512, JSON_THROW_ON_ERROR);
            SeedSafety::restoreRows($table, $package['rows'], [$code]);
            SeedSafety::restoreCounterMinimum($package['numbering']);
        }
    }
}
