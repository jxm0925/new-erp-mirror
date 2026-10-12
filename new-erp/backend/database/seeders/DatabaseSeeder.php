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
     * The user-approved supplier/category baseline is the explicit exception
     * for business master data. Do not add other customers, suppliers,
     * warehouses, items, SKUs, BOMs, QA fixtures, or acceptance accounts.
     */
    private function seed(): void
    {
        $this->call([
            ErpStandardUnitSeeder::class,
            ErpDocumentNumberRuleSeeder::class,
            ErpApprovedMasterDataSeeder::class,
            ErpProductionCatalogSeeder::class,
            ErpFinanceReferenceSeeder::class,
            ErpSalesReferenceSeeder::class,
            ErpRbacSeeder::class,
            ErpApprovalConfigurationSeeder::class,
        ]);
    }
}
