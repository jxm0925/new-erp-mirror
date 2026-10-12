<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_serials';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_serials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `inventory_balance_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `origin_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'purchase',
  `number_source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'existing',
  `manufacturer_serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_document_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `source_document_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_receipt_id` bigint(20) unsigned DEFAULT NULL,
  `source_receipt_item_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `legacy_source_table` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_source_id` bigint(20) unsigned DEFAULT NULL,
  `legacy_device_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'available',
  `received_at` timestamp NULL DEFAULT NULL,
  `registered_at` timestamp NULL DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `reserved_at` timestamp NULL DEFAULT NULL,
  `outbound_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_inventory_serials_serial_no_unique` (`serial_no`),
  KEY `erp_inventory_serials_item_id_foreign` (`item_id`),
  KEY `erp_inventory_serials_warehouse_id_foreign` (`warehouse_id`),
  KEY `erp_inventory_serials_location_id_foreign` (`location_id`),
  KEY `erp_inventory_serials_source_receipt_id_foreign` (`source_receipt_id`),
  KEY `erp_inventory_serials_source_receipt_item_id_foreign` (`source_receipt_item_id`),
  KEY `erp_inv_serial_balance_status_idx` (`inventory_balance_id`,`serial_status`),
  KEY `erp_inventory_serials_supplier_id_foreign` (`supplier_id`),
  KEY `erp_inv_serial_origin_status_idx` (`origin_type`,`serial_status`),
  KEY `erp_inv_serial_source_doc_idx` (`source_document_type`,`source_document_id`),
  KEY `erp_inv_serial_mfr_item_idx` (`manufacturer_serial_no`,`item_id`),
  KEY `erp_inv_serial_legacy_idx` (`legacy_source_table`,`legacy_source_id`),
  CONSTRAINT `erp_inventory_serials_inventory_balance_id_foreign` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `erp_inventory_serials_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_inventory_serials_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_inventory_serials_source_receipt_id_foreign` FOREIGN KEY (`source_receipt_id`) REFERENCES `erp_purchase_receipts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_inventory_serials_source_receipt_item_id_foreign` FOREIGN KEY (`source_receipt_item_id`) REFERENCES `erp_purchase_receipt_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_inventory_serials_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_inventory_serials_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
