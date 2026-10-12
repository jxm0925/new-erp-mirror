<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_request_items';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_request_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `item_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `spec_model` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `request_qty` decimal(14,4) NOT NULL,
  `converted_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `remaining_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `expected_date` date DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `priority` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `line_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `legacy_system` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_table` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_code` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_status` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `sync_batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `material_policy_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `material_policy_version_snapshot` int(10) unsigned DEFAULT NULL,
  `material_policy_snapshot` json DEFAULT NULL,
  `purchase_conversion_snapshot` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_purchase_request_items_request_id_foreign` (`request_id`),
  KEY `erp_purchase_request_items_item_id_foreign` (`item_id`),
  KEY `erp_purchase_request_items_unit_id_foreign` (`unit_id`),
  KEY `erp_purchase_request_items_warehouse_id_foreign` (`warehouse_id`),
  KEY `erp_purchase_request_items_material_policy_id_snapshot_index` (`material_policy_id_snapshot`),
  CONSTRAINT `erp_purchase_request_items_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_purchase_request_items_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `erp_purchase_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_purchase_request_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_request_items_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
