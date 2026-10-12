<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_plan_supplier_splits';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_plan_supplier_splits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `plan_id` bigint(20) unsigned NOT NULL,
  `plan_item_id` bigint(20) unsigned NOT NULL,
  `request_id` bigint(20) unsigned DEFAULT NULL,
  `request_item_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `purchase_qty` decimal(14,4) NOT NULL,
  `ordered_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `unit_price` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `tax_rate` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `delivery_days` int(10) unsigned NOT NULL DEFAULT '0',
  `moq_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `expected_date` date DEFAULT NULL,
  `amount` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `order_item_id` bigint(20) unsigned DEFAULT NULL,
  `split_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_ordered',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
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
  `recommended_supplier_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `recommended_price_snapshot` decimal(14,4) DEFAULT NULL,
  `recommendation_basis_snapshot` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recommendation_time` timestamp NULL DEFAULT NULL,
  `actual_supplier_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_override_reason` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_override_remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `supplier_override_by` bigint(20) unsigned DEFAULT NULL,
  `supplier_override_at` timestamp NULL DEFAULT NULL,
  `purchase_conversion_snapshot` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_purchase_plan_supplier_splits_plan_id_foreign` (`plan_id`),
  KEY `erp_purchase_plan_supplier_splits_plan_item_id_foreign` (`plan_item_id`),
  KEY `erp_purchase_plan_supplier_splits_item_id_foreign` (`item_id`),
  KEY `erp_purchase_plan_supplier_splits_supplier_id_foreign` (`supplier_id`),
  KEY `erp_purchase_plan_supplier_splits_request_id_foreign` (`request_id`),
  KEY `erp_purchase_plan_supplier_splits_request_item_id_foreign` (`request_item_id`),
  KEY `erp_purchase_plan_supplier_splits_order_id_foreign` (`order_id`),
  KEY `erp_purchase_plan_supplier_splits_order_item_id_foreign` (`order_item_id`),
  KEY `epss_recommended_supplier_snapshot_idx` (`recommended_supplier_id_snapshot`),
  KEY `epss_actual_supplier_idx` (`actual_supplier_id`),
  CONSTRAINT `erp_purchase_plan_supplier_splits_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_purchase_plan_supplier_splits_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `erp_purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_plan_supplier_splits_order_item_id_foreign` FOREIGN KEY (`order_item_id`) REFERENCES `erp_purchase_order_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_plan_supplier_splits_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `erp_purchase_plans` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_purchase_plan_supplier_splits_plan_item_id_foreign` FOREIGN KEY (`plan_item_id`) REFERENCES `erp_purchase_plan_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_purchase_plan_supplier_splits_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `erp_purchase_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_plan_supplier_splits_request_item_id_foreign` FOREIGN KEY (`request_item_id`) REFERENCES `erp_purchase_request_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_plan_supplier_splits_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
