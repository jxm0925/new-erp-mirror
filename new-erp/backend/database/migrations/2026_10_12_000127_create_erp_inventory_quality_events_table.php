<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_quality_events';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_quality_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `event_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `inventory_balance_id` bigint(20) unsigned NOT NULL,
  `inventory_serial_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_receipt_id` bigint(20) unsigned DEFAULT NULL,
  `source_receipt_item_id` bigint(20) unsigned DEFAULT NULL,
  `source_order_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `unit_name_snapshot` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issue_qty` decimal(18,8) NOT NULL,
  `issue_category` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `issue_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `handling_method` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsible_party` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `event_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_action',
  `business_doc_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_doc_id` bigint(20) unsigned DEFAULT NULL,
  `business_doc_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachments` json DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_by_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_inventory_quality_events_event_no_unique` (`event_no`),
  KEY `erp_inventory_quality_events_inventory_balance_id_foreign` (`inventory_balance_id`),
  KEY `erp_inventory_quality_events_inventory_serial_id_foreign` (`inventory_serial_id`),
  KEY `erp_inventory_quality_events_warehouse_id_foreign` (`warehouse_id`),
  KEY `erp_inventory_quality_events_location_id_foreign` (`location_id`),
  KEY `erp_inventory_quality_events_source_receipt_id_foreign` (`source_receipt_id`),
  KEY `erp_inventory_quality_events_source_receipt_item_id_foreign` (`source_receipt_item_id`),
  KEY `erp_inventory_quality_events_source_order_id_foreign` (`source_order_id`),
  KEY `erp_inventory_quality_events_supplier_id_foreign` (`supplier_id`),
  KEY `erp_inventory_quality_events_unit_id_foreign` (`unit_id`),
  KEY `erp_iqe_status_method_idx` (`event_status`,`handling_method`),
  KEY `erp_iqe_locator_idx` (`item_id`,`warehouse_id`,`location_id`,`batch_no`),
  KEY `erp_iqe_serial_status_idx` (`serial_no`,`event_status`),
  KEY `erp_inventory_quality_events_business_doc_id_index` (`business_doc_id`),
  CONSTRAINT `erp_inventory_quality_events_inventory_balance_id_foreign` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `erp_inventory_quality_events_inventory_serial_id_foreign` FOREIGN KEY (`inventory_serial_id`) REFERENCES `erp_inventory_serials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_inventory_quality_events_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_inventory_quality_events_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_inventory_quality_events_source_order_id_foreign` FOREIGN KEY (`source_order_id`) REFERENCES `erp_purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_inventory_quality_events_source_receipt_id_foreign` FOREIGN KEY (`source_receipt_id`) REFERENCES `erp_purchase_receipts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_inventory_quality_events_source_receipt_item_id_foreign` FOREIGN KEY (`source_receipt_item_id`) REFERENCES `erp_purchase_receipt_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_inventory_quality_events_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_inventory_quality_events_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_inventory_quality_events_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
