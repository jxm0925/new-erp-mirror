<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_return_receipt_items';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_return_receipt_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_id` bigint(20) unsigned NOT NULL,
  `sales_return_item_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_unit_id` bigint(20) unsigned DEFAULT NULL,
  `received_base_qty` decimal(18,8) NOT NULL,
  `restock_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `pending_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `scrap_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `rejected_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `inspection_remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_sales_return_receipt_items_receipt_id_foreign` (`receipt_id`),
  KEY `erp_sales_return_receipt_items_sales_return_item_id_foreign` (`sales_return_item_id`),
  KEY `erp_sales_return_receipt_items_item_id_foreign` (`item_id`),
  KEY `erp_sales_return_receipt_items_warehouse_id_foreign` (`warehouse_id`),
  KEY `erp_sales_return_receipt_items_location_id_foreign` (`location_id`),
  KEY `erp_sales_return_receipt_items_base_unit_id_foreign` (`base_unit_id`),
  CONSTRAINT `erp_sales_return_receipt_items_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_return_receipt_items_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_sales_return_receipt_items_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_sales_return_receipt_items_receipt_id_foreign` FOREIGN KEY (`receipt_id`) REFERENCES `erp_sales_return_receipts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_sales_return_receipt_items_sales_return_item_id_foreign` FOREIGN KEY (`sales_return_item_id`) REFERENCES `erp_sales_return_items` (`id`),
  CONSTRAINT `erp_sales_return_receipt_items_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
