<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Database\Seeders\Support\RunsSafeSeed;

class DatabaseSeeder extends Seeder
{
    use RunsSafeSeed;

    /** 正式初始化使用已确认的基础资料，CRM 测试单据及其客户不随安装创建。 */
    private function seed(): void
    {
        $this->call([
            ErpRbacSeeder::class,
            ErpOfficialUsersSeeder::class,
            ErpApprovalConfigurationSeeder::class,
            // 已批准的分类及供应商基础资料依赖编号规则。
            ErpDocumentNumberRuleSeeder::class,
            ErpApprovedMasterDataSeeder::class,
            ErpApprovedWarehouseSeeder::class,
            ErpApprovedSalesReferenceSeeder::class,
            ErpBusinessLineSeeder::class,
            ErpCrmCustomerPoolSeeder::class,
            ErpRegionSeeder::class,
            ErpFinanceReferenceSeeder::class,
            ErpProductionCatalogSeeder::class,
            ErpApprovedProductionOperationSeeder::class,
            ErpSalesReferenceSeeder::class,
            ErpStandardUnitSeeder::class,
        ]);
    }
}
