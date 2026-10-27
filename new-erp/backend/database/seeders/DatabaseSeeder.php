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
            ErpApprovalConfigurationSeeder::class,
            ErpApprovedMasterDataSeeder::class,
            ErpApprovedSalesReferenceSeeder::class,
            ErpDocumentNumberRuleSeeder::class,
            ErpDualFilterMaterialSeeder::class,
            ErpFinanceReferenceSeeder::class,
            ErpProductionCatalogSeeder::class,
            ErpRbacSeeder::class,
            ErpSalesReferenceSeeder::class,
            ErpStandardUnitSeeder::class
        ]);
    }
}
