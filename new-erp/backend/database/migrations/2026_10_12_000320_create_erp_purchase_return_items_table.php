<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_return_items';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_return_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_id` bigint(20) unsigned NOT NULL,
  `source_receipt_item_id` bigint(20) unsigned NOT NULL,
  `original_purchase_line_id` bigint(20) unsigned DEFAULT NULL,
  `original_inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `source_defect_handling_id` bigint(20) unsigned DEFAULT NULL,
  `source_inventory_quality_event_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_unit_id` bigint(20) unsigned DEFAULT NULL,
  `return_unit_id` bigint(20) unsigned DEFAULT NULL,
  `return_unit_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `return_conversion_factor_snapshot` decimal(18,8) NOT NULL DEFAULT '1.00000000',
  `requested_return_qty` decimal(18,8) DEFAULT NULL,
  `requested_base_qty` decimal(18,8) NOT NULL,
  `approved_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `posted_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `unit_cost_snapshot` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `currency_snapshot` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_mode_snapshot` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_rate_snapshot` decimal(8,4) DEFAULT NULL,
  `return_unit_price` decimal(18,8) DEFAULT NULL,
  `return_amount_excl_tax` decimal(18,4) DEFAULT NULL,
  `return_tax_amount` decimal(18,4) DEFAULT NULL,
  `return_amount_incl_tax` decimal(18,4) DEFAULT NULL,
  `settlement_amount` decimal(18,4) DEFAULT NULL,
  `inventory_cost_amount` decimal(18,4) DEFAULT NULL,
  `finance_fact_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_purchase_return_items_return_id_foreign` (`return_id`),
  KEY `erp_purchase_return_items_source_defect_handling_id_foreign` (`source_defect_handling_id`),
  KEY `erp_purchase_return_items_item_id_foreign` (`item_id`),
  KEY `erp_purchase_return_items_warehouse_id_foreign` (`warehouse_id`),
  KEY `erp_purchase_return_items_location_id_foreign` (`location_id`),
  KEY `erp_purchase_return_items_base_unit_id_foreign` (`base_unit_id`),
  KEY `erp_pri_source_batch_idx` (`source_receipt_item_id`,`batch_no`),
  KEY `pri_quality_event_fk` (`source_inventory_quality_event_id`),
  KEY `erp_purchase_return_items_return_unit_id_foreign` (`return_unit_id`),
  KEY `eprti_purchase_line_idx` (`original_purchase_line_id`),
  KEY `eprti_inventory_tx_idx` (`original_inventory_transaction_id`),
  KEY `eprti_finance_fact_status_idx` (`finance_fact_status`),
  CONSTRAINT `erp_purchase_return_items_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_return_items_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_purchase_return_items_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_purchase_return_items_return_id_foreign` FOREIGN KEY (`return_id`) REFERENCES `erp_purchase_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_purchase_return_items_return_unit_id_foreign` FOREIGN KEY (`return_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_return_items_source_defect_handling_id_foreign` FOREIGN KEY (`source_defect_handling_id`) REFERENCES `erp_purchase_defect_handlings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_return_items_source_receipt_item_id_foreign` FOREIGN KEY (`source_receipt_item_id`) REFERENCES `erp_purchase_receipt_items` (`id`),
  CONSTRAINT `erp_purchase_return_items_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`),
  CONSTRAINT `pri_quality_event_fk` FOREIGN KEY (`source_inventory_quality_event_id`) REFERENCES `erp_inventory_quality_events` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
