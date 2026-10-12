<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_remnant_receipts';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_remnant_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `cutting_order_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `piece_count` int(10) unsigned NOT NULL,
  `posted_cost` decimal(20,4) NOT NULL,
  `posted_by_legacy_id` bigint(20) unsigned NOT NULL,
  `header_snapshot` json NOT NULL,
  `remark` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_cutting_remnant_receipts_receipt_no_unique` (`receipt_no`),
  KEY `erp_cutting_remnant_receipts_cutting_order_id_foreign` (`cutting_order_id`),
  KEY `erp_cutting_remnant_receipts_warehouse_id_foreign` (`warehouse_id`),
  KEY `erp_cutting_remnant_receipts_location_id_foreign` (`location_id`),
  KEY `erp_cutting_remnant_receipts_inventory_transaction_id_foreign` (`inventory_transaction_id`),
  CONSTRAINT `erp_cutting_remnant_receipts_cutting_order_id_foreign` FOREIGN KEY (`cutting_order_id`) REFERENCES `erp_cutting_orders` (`id`),
  CONSTRAINT `erp_cutting_remnant_receipts_inventory_transaction_id_foreign` FOREIGN KEY (`inventory_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`),
  CONSTRAINT `erp_cutting_remnant_receipts_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_cutting_remnant_receipts_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
