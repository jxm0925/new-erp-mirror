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
     * The user-approved supplier/category and sales-reference baselines are explicit exceptions
     * for business master data. Do not add other customers, suppliers,
     * warehouses, items, SKUs, BOMs, QA fixtures, or acceptance accounts.
     */
    private function seed(): void
    {
        $this->call([
            ErpAdministratorSeeder::class,
            ErpRbacSeeder::class,
            ErpApprovalConfigurationSeeder::class,
            // 已批准的分类及供应商基础资料依赖编号规则。
            ErpDocumentNumberRuleSeeder::class,
            ErpApprovedMasterDataSeeder::class,
            ErpApprovedSalesReferenceSeeder::class,
            ErpFinanceReferenceSeeder::class,
            ErpProductionCatalogSeeder::class,
            ErpSalesReferenceSeeder::class,
            ErpStandardUnitSeeder::class
        ]);
    }
}
