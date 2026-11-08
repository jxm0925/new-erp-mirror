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
     * User-approved supplier/category, sales references and the official product/SKU/Item
     * catalog are permanent master-data baselines. Do not add other customers, suppliers,
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
            ErpFinanceReferenceSeeder::class,
            ErpProductionCatalogSeeder::class,
            ErpSalesReferenceSeeder::class,
            ErpStandardUnitSeeder::class,
            // 用户批准的正式目录：仅补缺，保留人工维护及业务事实。
            ErpOfficialCatalogSeeder::class
        ]);
    }
}
