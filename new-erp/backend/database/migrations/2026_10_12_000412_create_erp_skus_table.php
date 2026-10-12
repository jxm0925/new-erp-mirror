<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_skus';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_skus` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `product_id` bigint(20) unsigned NOT NULL,
  `sales_unit_id` bigint(20) unsigned DEFAULT NULL,
  `sales_unit_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sku_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `spec_text` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `search_aliases` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `search_keywords` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `image` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sale_price` decimal(14,2) DEFAULT NULL,
  `reference_cost` decimal(14,2) DEFAULT NULL,
  `default_tax_rate` decimal(10,6) NOT NULL DEFAULT '0.000000',
  `default_price_tax_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tax_inclusive',
  `product_structure_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'single',
  `production_policy` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'stock',
  `fulfillment_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'physical',
  `order_line_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'physical',
  `is_customizable` tinyint(1) NOT NULL DEFAULT '0',
  `allow_customized` tinyint(1) NOT NULL DEFAULT '0',
  `allow_special_customized` tinyint(1) NOT NULL DEFAULT '0',
  `special_custom_drawing_required` tinyint(1) NOT NULL DEFAULT '0',
  `special_custom_agreement_required` tinyint(1) NOT NULL DEFAULT '0',
  `special_custom_description_required` tinyint(1) NOT NULL DEFAULT '0',
  `delivery_inspection_required` tinyint(1) NOT NULL DEFAULT '0',
  `supports_electric` tinyint(1) NOT NULL DEFAULT '0',
  `electric_required` tinyint(1) NOT NULL DEFAULT '0',
  `electric_options` json DEFAULT NULL,
  `electric_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hidden',
  `supports_need_pump` tinyint(1) NOT NULL DEFAULT '0',
  `need_pump_required` tinyint(1) NOT NULL DEFAULT '0',
  `need_pump_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'hidden',
  `is_custom_sku` tinyint(1) NOT NULL DEFAULT '0',
  `base_sku_id` bigint(20) unsigned DEFAULT NULL,
  `custom_scope` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `custom_source_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_source_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_line_id` bigint(20) unsigned DEFAULT NULL,
  `custom_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `custom_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `is_need_production` tinyint(1) NOT NULL DEFAULT '0',
  `is_need_bom` tinyint(1) NOT NULL DEFAULT '0',
  `is_sale_item` tinyint(1) NOT NULL DEFAULT '1',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled',
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_skus_sku_code_unique` (`sku_code`),
  KEY `erp_skus_product_id_foreign` (`product_id`),
  KEY `erp_skus_base_sku_id_foreign` (`base_sku_id`),
  KEY `erp_skus_sales_unit_id_foreign` (`sales_unit_id`),
  KEY `erp_skus_sales_search_code_idx` (`status`,`is_sale_item`,`sku_code`),
  KEY `erp_skus_sales_search_product_idx` (`status`,`is_sale_item`,`product_id`),
  CONSTRAINT `erp_skus_base_sku_id_foreign` FOREIGN KEY (`base_sku_id`) REFERENCES `erp_skus` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_skus_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `erp_products` (`id`),
  CONSTRAINT `erp_skus_sales_unit_id_foreign` FOREIGN KEY (`sales_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
