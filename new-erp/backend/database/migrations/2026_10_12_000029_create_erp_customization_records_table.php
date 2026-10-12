<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_customization_records';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_customization_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `customization_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `custom_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'light',
  `custom_scope` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'order',
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `base_sku_id` bigint(20) unsigned DEFAULT NULL,
  `custom_sku_id` bigint(20) unsigned DEFAULT NULL,
  `base_item_id` bigint(20) unsigned DEFAULT NULL,
  `custom_item_id` bigint(20) unsigned DEFAULT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_line_id` bigint(20) unsigned DEFAULT NULL,
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `requirements_json` json DEFAULT NULL,
  `drawing_files_json` json DEFAULT NULL,
  `process_notes` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_customization_records_customization_no_unique` (`customization_no`),
  KEY `erp_customization_records_product_id_foreign` (`product_id`),
  KEY `erp_customization_records_base_sku_id_foreign` (`base_sku_id`),
  KEY `erp_customization_records_custom_sku_id_foreign` (`custom_sku_id`),
  KEY `erp_customization_records_base_item_id_foreign` (`base_item_id`),
  KEY `erp_customization_records_custom_item_id_foreign` (`custom_item_id`),
  CONSTRAINT `erp_customization_records_base_item_id_foreign` FOREIGN KEY (`base_item_id`) REFERENCES `erp_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_customization_records_base_sku_id_foreign` FOREIGN KEY (`base_sku_id`) REFERENCES `erp_skus` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_customization_records_custom_item_id_foreign` FOREIGN KEY (`custom_item_id`) REFERENCES `erp_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_customization_records_custom_sku_id_foreign` FOREIGN KEY (`custom_sku_id`) REFERENCES `erp_skus` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_customization_records_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `erp_products` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
