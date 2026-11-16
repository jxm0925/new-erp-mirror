<?php

namespace Database\Seeders;

use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;
use Illuminate\Database\Seeder;

/** 用户已维护的正式工序，保留原工序编码、ID、公共工序及自动派工配置。 */
class ErpApprovedProductionOperationSeeder extends Seeder
{
    use RunsSafeSeed;

    private function seed(): void
    {
        $package = json_decode(file_get_contents(__DIR__.'/data/erp_production_operations.json'), true, 512, JSON_THROW_ON_ERROR);
        SeedSafety::restoreRows('erp_production_operations', $package['rows'], ['operation_no']);
        SeedSafety::restoreCounterMinimum($package['numbering']);
    }
}
