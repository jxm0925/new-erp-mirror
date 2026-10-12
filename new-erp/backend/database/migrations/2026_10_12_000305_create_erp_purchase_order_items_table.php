<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_order_items';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_order_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `order_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned DEFAULT NULL,
  `plan_item_id` bigint(20) unsigned DEFAULT NULL,
  `plan_split_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_split_id` bigint(20) unsigned DEFAULT NULL,
  `request_id` bigint(20) unsigned DEFAULT NULL,
  `request_item_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `spec_model` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_unit_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_unit_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `conversion_factor_snapshot` decimal(18,8) NOT NULL DEFAULT '1.00000000',
  `allow_actual_conversion_snapshot` tinyint(1) NOT NULL DEFAULT '0',
  `base_unit_id` bigint(20) unsigned DEFAULT NULL,
  `base_unit_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `planned_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `order_qty` decimal(14,4) NOT NULL,
  `received_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `remaining_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `unit_price` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `purchase_unit_price` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `base_unit_price` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `tax_rate` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `currency_snapshot` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_mode_snapshot` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receipt_cost` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `amount` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `amount_excl_tax` decimal(18,4) DEFAULT NULL,
  `tax_amount_snapshot` decimal(18,4) DEFAULT NULL,
  `amount_incl_tax` decimal(18,4) DEFAULT NULL,
  `freight_allocated_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `other_purchase_cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `contract_amount_snapshot` decimal(18,4) DEFAULT NULL,
  `commercial_snapshot_at` timestamp NULL DEFAULT NULL,
  `expected_arrival_date` date DEFAULT NULL,
  `target_warehouse_id` bigint(20) unsigned DEFAULT NULL,
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
  `recommended_supplier_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `recommended_price_snapshot` decimal(14,4) DEFAULT NULL,
  `recommendation_basis_snapshot` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recommendation_time` timestamp NULL DEFAULT NULL,
  `actual_supplier_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_override_reason` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_override_remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `supplier_override_by` bigint(20) unsigned DEFAULT NULL,
  `supplier_override_at` timestamp NULL DEFAULT NULL,
  `material_policy_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `material_policy_version_snapshot` int(10) unsigned DEFAULT NULL,
  `material_policy_snapshot` json DEFAULT NULL,
  `purchase_conversion_snapshot` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_purchase_order_items_legacy_unique` (`legacy_system`,`legacy_table`,`legacy_id`),
  KEY `erp_purchase_order_items_order_id_foreign` (`order_id`),
  KEY `erp_purchase_order_items_plan_item_id_foreign` (`plan_item_id`),
  KEY `erp_purchase_order_items_item_id_foreign` (`item_id`),
  KEY `erp_purchase_order_items_plan_split_id_foreign` (`plan_split_id`),
  KEY `erp_purchase_order_items_target_warehouse_id_foreign` (`target_warehouse_id`),
  KEY `erp_purchase_order_items_supplier_split_id_foreign` (`supplier_split_id`),
  KEY `erp_purchase_order_items_request_id_foreign` (`request_id`),
  KEY `erp_purchase_order_items_request_item_id_foreign` (`request_item_id`),
  KEY `erp_purchase_order_items_supplier_id_foreign` (`supplier_id`),
  KEY `erp_purchase_order_items_plan_id_foreign` (`plan_id`),
  KEY `epoi_recommended_supplier_snapshot_idx` (`recommended_supplier_id_snapshot`),
  KEY `epoi_actual_supplier_idx` (`actual_supplier_id`),
  KEY `erp_purchase_order_items_purchase_unit_id_foreign` (`purchase_unit_id`),
  KEY `erp_purchase_order_items_base_unit_id_foreign` (`base_unit_id`),
  KEY `erp_purchase_order_items_material_policy_id_snapshot_index` (`material_policy_id_snapshot`),
  CONSTRAINT `erp_purchase_order_items_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_order_items_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_purchase_order_items_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `erp_purchase_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_purchase_order_items_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `erp_purchase_plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_order_items_plan_item_id_foreign` FOREIGN KEY (`plan_item_id`) REFERENCES `erp_purchase_plan_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_order_items_plan_split_id_foreign` FOREIGN KEY (`plan_split_id`) REFERENCES `erp_purchase_plan_supplier_splits` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_order_items_purchase_unit_id_foreign` FOREIGN KEY (`purchase_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_order_items_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `erp_purchase_requests` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_order_items_request_item_id_foreign` FOREIGN KEY (`request_item_id`) REFERENCES `erp_purchase_request_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_order_items_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_order_items_supplier_split_id_foreign` FOREIGN KEY (`supplier_split_id`) REFERENCES `erp_purchase_plan_supplier_splits` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_order_items_target_warehouse_id_foreign` FOREIGN KEY (`target_warehouse_id`) REFERENCES `erp_warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
