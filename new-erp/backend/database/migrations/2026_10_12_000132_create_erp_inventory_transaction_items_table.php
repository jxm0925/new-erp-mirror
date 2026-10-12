<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_transaction_items';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_transaction_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_id` bigint(20) unsigned NOT NULL,
  `transaction_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `item_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `change_qty` decimal(14,4) NOT NULL,
  `unit_cost` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `purchase_amount_snapshot` decimal(18,4) DEFAULT NULL,
  `freight_amount_snapshot` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `other_purchase_cost_amount_snapshot` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `cost_source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `balance_after_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `source_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `source_item_id` bigint(20) unsigned DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `material_lot_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_inventory_transaction_items_transaction_id_foreign` (`transaction_id`),
  KEY `erp_inventory_transaction_items_item_id_foreign` (`item_id`),
  KEY `erp_inventory_transaction_items_warehouse_id_foreign` (`warehouse_id`),
  KEY `erp_inventory_transaction_items_location_id_foreign` (`location_id`),
  KEY `erp_inventory_transaction_items_unit_id_foreign` (`unit_id`),
  KEY `eiti_cost_source_type_idx` (`cost_source_type`),
  KEY `erp_inventory_transaction_items_material_lot_id_foreign` (`material_lot_id`),
  CONSTRAINT `erp_inventory_transaction_items_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_inventory_transaction_items_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_inventory_transaction_items_material_lot_id_foreign` FOREIGN KEY (`material_lot_id`) REFERENCES `erp_material_lots` (`id`),
  CONSTRAINT `erp_inventory_transaction_items_transaction_id_foreign` FOREIGN KEY (`transaction_id`) REFERENCES `erp_inventory_transactions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_inventory_transaction_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_inventory_transaction_items_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
