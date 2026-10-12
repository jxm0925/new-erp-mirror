<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_master_orders';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_master_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `master_order_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sales_order_id` bigint(20) unsigned NOT NULL,
  `active_sales_order_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_no_snapshot` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `salesperson_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `salesperson_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_snapshot` json NOT NULL,
  `order_remark_snapshot` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `required_delivery_date_snapshot` date DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_CONDITION',
  `production_progress` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `completed_unit_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `total_unit_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `in_progress_unit_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `exception_unit_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `material_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_PREPARE',
  `funding_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'blocked',
  `shipment_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'blocked',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `organization_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_production_master_orders_master_order_no_unique` (`master_order_no`),
  UNIQUE KEY `erp_production_master_orders_active_sales_order_id_unique` (`active_sales_order_id`),
  KEY `erp_mwo_status_delivery_idx` (`status`,`required_delivery_date_snapshot`),
  KEY `erp_mwo_sales_status_idx` (`salesperson_legacy_id`,`status`),
  KEY `erp_mwo_sales_order_fk` (`sales_order_id`),
  CONSTRAINT `erp_mwo_active_sales_order_fk` FOREIGN KEY (`active_sales_order_id`) REFERENCES `erp_sales_orders` (`id`),
  CONSTRAINT `erp_mwo_sales_order_fk` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
