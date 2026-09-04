<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed only stable ERP bootstrap/reference data.
     *
     * Do not put customers, suppliers, warehouses, items, SKUs, BOMs,
     * historical repairs, QA fixtures, or acceptance accounts here.
     */
    public function run(): void
    {
        $this->call([
            ErpStandardUnitSeeder::class,
            ErpDocumentNumberRuleSeeder::class,
            ErpFinanceReferenceSeeder::class,
            ErpSalesReferenceSeeder::class,
            ErpRbacSeeder::class,
            ErpApprovalConfigurationSeeder::class,
            ErpAdministratorSeeder::class,
        ]);
    }
}

