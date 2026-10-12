<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_order_production_requirements';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_order_production_requirements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `requirement_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `requirement_version` int(10) unsigned NOT NULL DEFAULT '1',
  `sales_order_id` bigint(20) unsigned NOT NULL,
  `sales_order_line_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `sku_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned DEFAULT NULL,
  `base_unit_id` bigint(20) unsigned DEFAULT NULL,
  `base_unit_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_match_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_configuration',
  `production_qty` decimal(14,4) NOT NULL,
  `item_base_required_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `consumed_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `remaining_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `closed_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `superseded_by_id` bigint(20) unsigned DEFAULT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `requirement_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmed',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `configuration_snapshot` json DEFAULT NULL,
  `bom_snapshot` json DEFAULT NULL,
  `bom_match_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_checked',
  `bom_block_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bom_id` bigint(20) unsigned DEFAULT NULL,
  `bom_version_id` bigint(20) unsigned DEFAULT NULL,
  `bom_version` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `routing_snapshot` json DEFAULT NULL,
  `routing_match_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_configured',
  `routing_block_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `routing_id` bigint(20) unsigned DEFAULT NULL,
  `routing_version_id` bigint(20) unsigned DEFAULT NULL,
  `drawing_snapshot` json DEFAULT NULL,
  `technical_attachment_snapshot` json DEFAULT NULL,
  `inspection_snapshot` json DEFAULT NULL,
  `required_delivery_date` date DEFAULT NULL,
  `is_urgent` tinyint(1) NOT NULL DEFAULT '0',
  `is_delay` tinyint(1) NOT NULL DEFAULT '0',
  `delay_date` date DEFAULT NULL,
  `is_ready_for_work_order` tinyint(1) NOT NULL DEFAULT '0',
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `confirmed_by` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `allocated_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `superseded_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_sales_order_production_requirements_requirement_no_unique` (`requirement_no`),
  KEY `erp_sales_order_production_requirements_product_id_foreign` (`product_id`),
  KEY `erp_sales_order_production_requirements_sku_id_foreign` (`sku_id`),
  KEY `erp_sales_order_production_requirements_item_id_foreign` (`item_id`),
  KEY `erp_so_prod_req_order_status_idx` (`sales_order_id`,`requirement_status`),
  KEY `erp_sales_order_production_requirements_is_active_index` (`is_active`),
  KEY `erp_sales_order_production_requirements_superseded_by_id_index` (`superseded_by_id`),
  KEY `erp_sales_order_production_requirements_bom_id_index` (`bom_id`),
  KEY `erp_sales_order_production_requirements_routing_id_index` (`routing_id`),
  KEY `erp_sales_order_production_requirements_base_unit_id_foreign` (`base_unit_id`),
  KEY `erp_prod_req_line_active_status_idx` (`sales_order_line_id`,`is_active`,`requirement_status`),
  KEY `erp_prod_req_superseded_by_idx` (`superseded_by_id`),
  CONSTRAINT `erp_prod_req_superseded_by_fk` FOREIGN KEY (`superseded_by_id`) REFERENCES `erp_sales_order_production_requirements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_order_production_requirements_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_order_production_requirements_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_order_production_requirements_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `erp_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_order_production_requirements_sku_id_foreign` FOREIGN KEY (`sku_id`) REFERENCES `erp_skus` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_so_prod_req_line_fk` FOREIGN KEY (`sales_order_line_id`) REFERENCES `erp_sales_order_lines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_so_prod_req_order_fk` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
