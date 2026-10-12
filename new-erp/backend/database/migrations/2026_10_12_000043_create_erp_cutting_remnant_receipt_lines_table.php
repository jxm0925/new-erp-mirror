<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_remnant_receipt_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_remnant_receipt_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_id` bigint(20) unsigned NOT NULL,
  `result_id` bigint(20) unsigned NOT NULL,
  `source_holding_id` bigint(20) unsigned NOT NULL,
  `physical_material_id` bigint(20) unsigned DEFAULT NULL,
  `material_lot_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `posted_qty` decimal(20,8) NOT NULL,
  `posted_cost` decimal(20,4) NOT NULL,
  `line_snapshot` json NOT NULL,
  `inventory_balance_id` bigint(20) unsigned DEFAULT NULL,
  `warehouse_holding_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_cutting_remnant_receipt_lines_result_id_unique` (`result_id`),
  UNIQUE KEY `erp_cutting_remnant_receipt_lines_source_holding_id_unique` (`source_holding_id`),
  KEY `erp_cutting_remnant_receipt_lines_receipt_id_foreign` (`receipt_id`),
  KEY `erp_cutting_remnant_receipt_lines_physical_material_id_foreign` (`physical_material_id`),
  KEY `erp_cutting_remnant_receipt_lines_material_lot_id_foreign` (`material_lot_id`),
  KEY `erp_cutting_remnant_receipt_lines_item_id_foreign` (`item_id`),
  KEY `erp_cutting_remnant_receipt_lines_unit_id_foreign` (`unit_id`),
  KEY `erp_cutting_remnant_receipt_lines_inventory_balance_id_foreign` (`inventory_balance_id`),
  KEY `erp_cutting_remnant_receipt_lines_warehouse_holding_id_foreign` (`warehouse_holding_id`),
  CONSTRAINT `erp_cutting_remnant_receipt_lines_inventory_balance_id_foreign` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `erp_cutting_remnant_receipt_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_cutting_remnant_receipt_lines_material_lot_id_foreign` FOREIGN KEY (`material_lot_id`) REFERENCES `erp_material_lots` (`id`),
  CONSTRAINT `erp_cutting_remnant_receipt_lines_physical_material_id_foreign` FOREIGN KEY (`physical_material_id`) REFERENCES `erp_material_physicals` (`id`),
  CONSTRAINT `erp_cutting_remnant_receipt_lines_receipt_id_foreign` FOREIGN KEY (`receipt_id`) REFERENCES `erp_cutting_remnant_receipts` (`id`),
  CONSTRAINT `erp_cutting_remnant_receipt_lines_result_id_foreign` FOREIGN KEY (`result_id`) REFERENCES `erp_cutting_results` (`id`),
  CONSTRAINT `erp_cutting_remnant_receipt_lines_source_holding_id_foreign` FOREIGN KEY (`source_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `erp_cutting_remnant_receipt_lines_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `erp_cutting_remnant_receipt_lines_warehouse_holding_id_foreign` FOREIGN KEY (`warehouse_holding_id`) REFERENCES `erp_material_holdings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
