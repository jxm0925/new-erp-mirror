<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_warehouse_receipts';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_warehouse_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `route_id` bigint(20) unsigned NOT NULL,
  `cutting_order_id` bigint(20) unsigned NOT NULL,
  `result_id` bigint(20) unsigned NOT NULL,
  `source_holding_id` bigint(20) unsigned NOT NULL,
  `warehouse_holding_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_balance_id` bigint(20) unsigned DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `posted_qty` decimal(18,8) NOT NULL,
  `posted_cost` decimal(18,4) NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'POSTING',
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `posted_by_legacy_id` bigint(20) unsigned NOT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_cutting_warehouse_receipts_receipt_no_unique` (`receipt_no`),
  UNIQUE KEY `erp_cutting_warehouse_receipts_inventory_transaction_id_unique` (`inventory_transaction_id`),
  KEY `erp_cutting_warehouse_receipts_cutting_order_id_foreign` (`cutting_order_id`),
  KEY `erp_cutting_warehouse_receipts_result_id_foreign` (`result_id`),
  KEY `erp_cutting_warehouse_receipts_source_holding_id_foreign` (`source_holding_id`),
  KEY `erp_cutting_warehouse_receipts_warehouse_holding_id_foreign` (`warehouse_holding_id`),
  KEY `erp_cutting_warehouse_receipts_inventory_balance_id_foreign` (`inventory_balance_id`),
  KEY `erp_cutting_warehouse_receipts_warehouse_id_foreign` (`warehouse_id`),
  KEY `erp_cutting_warehouse_receipts_location_id_foreign` (`location_id`),
  KEY `cut_wh_receipt_route_status_idx` (`route_id`,`status`),
  CONSTRAINT `erp_cutting_warehouse_receipts_cutting_order_id_foreign` FOREIGN KEY (`cutting_order_id`) REFERENCES `erp_cutting_orders` (`id`),
  CONSTRAINT `erp_cutting_warehouse_receipts_inventory_balance_id_foreign` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `erp_cutting_warehouse_receipts_inventory_transaction_id_foreign` FOREIGN KEY (`inventory_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`),
  CONSTRAINT `erp_cutting_warehouse_receipts_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_cutting_warehouse_receipts_result_id_foreign` FOREIGN KEY (`result_id`) REFERENCES `erp_cutting_results` (`id`),
  CONSTRAINT `erp_cutting_warehouse_receipts_route_id_foreign` FOREIGN KEY (`route_id`) REFERENCES `erp_cutting_result_routes` (`id`),
  CONSTRAINT `erp_cutting_warehouse_receipts_source_holding_id_foreign` FOREIGN KEY (`source_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `erp_cutting_warehouse_receipts_warehouse_holding_id_foreign` FOREIGN KEY (`warehouse_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `erp_cutting_warehouse_receipts_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
