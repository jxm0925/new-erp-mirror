<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed only stable ERP bootstrap/reference data.
     *
     * The user-approved supplier/category baseline is the explicit exception
     * for business master data. Do not add other customers, suppliers,
     * warehouses, items, SKUs, BOMs, QA fixtures, or acceptance accounts.
     */
    public function run(): void
    {
        $this->call([
            ErpStandardUnitSeeder::class,
            ErpDocumentNumberRuleSeeder::class,
            ErpApprovedMasterDataSeeder::class,
            ErpFinanceReferenceSeeder::class,
            ErpSalesReferenceSeeder::class,
            ErpRbacSeeder::class,
            ErpApprovalConfigurationSeeder::class,
            ErpAdministratorSeeder::class,
        ]);
    }
}
