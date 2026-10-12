<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_items';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'raw_material',
  `management_scope` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'factory',
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `spec` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `material_grade` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `standard_stock_length_mm` decimal(12,2) DEFAULT NULL,
  `is_length_cut_material` tinyint(1) NOT NULL DEFAULT '0',
  `unit_id` bigint(20) unsigned NOT NULL,
  `brand` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `model` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_purchase_item` tinyint(1) NOT NULL DEFAULT '0',
  `is_stock_item` tinyint(1) NOT NULL DEFAULT '1',
  `is_production_item` tinyint(1) NOT NULL DEFAULT '0',
  `is_custom_item` tinyint(1) NOT NULL DEFAULT '0',
  `base_item_id` bigint(20) unsigned DEFAULT NULL,
  `custom_scope` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `custom_source_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_source_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_line_id` bigint(20) unsigned DEFAULT NULL,
  `design_version` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `custom_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_batch_managed` tinyint(1) NOT NULL DEFAULT '0',
  `is_serial_managed` tinyint(1) NOT NULL DEFAULT '0',
  `serial_tracking_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `serial_number_prefix` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `production_execution_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unit',
  `serial_generation_stage` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'before_finished_goods_posting',
  `serial_generation_routing_operation_id` bigint(20) unsigned DEFAULT NULL,
  `equipment_identity_requirement` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_applicable',
  `cost_method` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'weighted_average',
  `standard_cost` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `last_purchase_price` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `default_supplier_id` bigint(20) unsigned DEFAULT NULL,
  `default_warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled',
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `material_management_mode` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quantity',
  `cutting_mode` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manufacturing_strategy` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unspecified',
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_items_item_code_unique` (`item_code`),
  KEY `erp_items_category_id_foreign` (`category_id`),
  KEY `erp_items_unit_id_foreign` (`unit_id`),
  KEY `erp_items_default_supplier_id_foreign` (`default_supplier_id`),
  KEY `erp_items_default_warehouse_id_foreign` (`default_warehouse_id`),
  KEY `erp_items_base_item_id_foreign` (`base_item_id`),
  KEY `erp_items_serial_tracking_mode_idx` (`serial_tracking_mode`),
  KEY `erp_items_execution_mode_idx` (`production_execution_mode`),
  KEY `erp_items_serial_route_operation_fk` (`serial_generation_routing_operation_id`),
  KEY `erp_items_equipment_identity_req_idx` (`equipment_identity_requirement`),
  KEY `erp_items_length_cut_idx` (`is_length_cut_material`),
  KEY `erp_items_manufacturing_strategy_index` (`manufacturing_strategy`),
  KEY `erp_items_management_scope_status_idx` (`management_scope`,`status`),
  CONSTRAINT `erp_items_base_item_id_foreign` FOREIGN KEY (`base_item_id`) REFERENCES `erp_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_items_category_id_foreign` FOREIGN KEY (`category_id`) REFERENCES `erp_item_categories` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_items_default_supplier_id_foreign` FOREIGN KEY (`default_supplier_id`) REFERENCES `erp_suppliers` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_items_default_warehouse_id_foreign` FOREIGN KEY (`default_warehouse_id`) REFERENCES `erp_warehouses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_items_serial_route_operation_fk` FOREIGN KEY (`serial_generation_routing_operation_id`) REFERENCES `erp_production_routing_operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
        'erp_items_management_scope_ck' => 'management_scope IN (\'factory\',\'office\')',
    ];
};
