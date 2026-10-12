<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_order_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_order_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `line_uuid` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sales_order_id` bigint(20) unsigned NOT NULL,
  `line_no` int(10) unsigned NOT NULL DEFAULT '1',
  `sort_order` int(10) unsigned NOT NULL DEFAULT '0',
  `legacy_order_product_id` bigint(20) unsigned DEFAULT NULL,
  `legacy_goods_id` bigint(20) unsigned DEFAULT NULL,
  `legacy_sku_id` bigint(20) unsigned DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `sku_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned DEFAULT NULL,
  `item_base_unit_id` bigint(20) unsigned DEFAULT NULL,
  `item_base_unit_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_base_unit_code_snapshot` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fulfillment_factor_snapshot` decimal(18,8) DEFAULT NULL,
  `item_base_required_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `item_match_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_configuration',
  `item_match_rule` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_match_block_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_match_snapshot` json DEFAULT NULL,
  `product_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_goods_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `line_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'physical',
  `commercial_role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sale',
  `order_qty` decimal(14,4) NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `unit_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_code_snapshot` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_conversion_ratio_snapshot` decimal(18,8) NOT NULL DEFAULT '1.00000000',
  `unit_price` decimal(14,2) NOT NULL DEFAULT '0.00',
  `price_tax_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tax_inclusive',
  `discount_rate` decimal(10,6) NOT NULL DEFAULT '1.000000',
  `tax_rate` decimal(10,6) NOT NULL DEFAULT '0.000000',
  `amount` decimal(14,2) NOT NULL DEFAULT '0.00',
  `amount_excl_tax` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `tax_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `amount_incl_tax` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `inventory_fulfilled_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `production_required_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `production_replenished_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `service_fulfilled_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `no_delivery_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `undetermined_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `shipped_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `cancelled_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `fulfillment_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `fulfillment_method` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'auto',
  `line_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `need_pump` tinyint(1) DEFAULT NULL,
  `electric` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_customized` tinyint(1) NOT NULL DEFAULT '0',
  `is_special_customized` tinyint(1) NOT NULL DEFAULT '0',
  `customization_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `configuration_snapshot` json DEFAULT NULL,
  `product_snapshot` json DEFAULT NULL,
  `sku_snapshot` json DEFAULT NULL,
  `commercial_snapshot` json DEFAULT NULL,
  `item_snapshot` json DEFAULT NULL,
  `bom_snapshot` json DEFAULT NULL,
  `routing_snapshot` json DEFAULT NULL,
  `drawing_snapshot` json DEFAULT NULL,
  `technical_attachment_snapshot` json DEFAULT NULL,
  `inspection_snapshot` json DEFAULT NULL,
  `image` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `design` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_sales_order_lines_product_id_foreign` (`product_id`),
  KEY `erp_sales_order_lines_sku_id_foreign` (`sku_id`),
  KEY `erp_sales_order_lines_item_id_foreign` (`item_id`),
  KEY `erp_sales_order_lines_sales_order_id_line_status_index` (`sales_order_id`,`line_status`),
  KEY `erp_sales_order_lines_legacy_order_product_id_index` (`legacy_order_product_id`),
  KEY `erp_sales_order_lines_legacy_goods_id_index` (`legacy_goods_id`),
  KEY `erp_sales_order_lines_legacy_sku_id_index` (`legacy_sku_id`),
  KEY `erp_sales_order_lines_line_uuid_index` (`line_uuid`),
  KEY `erp_sales_order_lines_unit_id_index` (`unit_id`),
  KEY `erp_sales_order_lines_sort_order_index` (`sort_order`),
  KEY `erp_sales_order_lines_item_base_unit_id_foreign` (`item_base_unit_id`),
  KEY `erp_sales_order_lines_fulfillment_method_index` (`fulfillment_method`),
  KEY `erp_sales_order_lines_commercial_role_index` (`commercial_role`),
  CONSTRAINT `erp_sales_order_lines_item_base_unit_id_foreign` FOREIGN KEY (`item_base_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_order_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_order_lines_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `erp_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_order_lines_sales_order_id_foreign` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_sales_order_lines_sku_id_foreign` FOREIGN KEY (`sku_id`) REFERENCES `erp_skus` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
