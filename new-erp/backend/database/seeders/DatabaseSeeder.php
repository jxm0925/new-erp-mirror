<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Database\Seeders\Support\RunsSafeSeed;
use Database\Seeders\Support\SeedSafety;

class DatabaseSeeder extends Seeder
{
    use RunsSafeSeed;

    /**
     * Seed only stable ERP bootstrap/reference data.
     *
     * User-approved supplier/category and sales references
     * are permanent master-data baselines. Do not add other customers, suppliers,
     * warehouses, items, SKUs, BOMs, QA fixtures, or acceptance accounts.
     */
    private function seed(): void
    {
        $this->call([
            ErpRbacSeeder::class,
            ErpOfficialUsersSeeder::class,
            ErpAdministratorSeeder::class,
            ErpApprovalConfigurationSeeder::class,
            // 已批准的分类及供应商基础资料依赖编号规则。
            ErpDocumentNumberRuleSeeder::class,
            ErpApprovedMasterDataSeeder::class,
            ErpApprovedSalesReferenceSeeder::class,
            ErpRegionSeeder::class,
            ErpFinanceReferenceSeeder::class,
            ErpProductionCatalogSeeder::class,
            ErpSalesReferenceSeeder::class,
            ErpStandardUnitSeeder::class,
        ]);
    }
}
