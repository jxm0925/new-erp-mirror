<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_boms';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_boms` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bom_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bom_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `sku_id` bigint(20) unsigned DEFAULT NULL,
  `output_item_id` bigint(20) unsigned NOT NULL,
  `bom_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard',
  `version` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'V1.0',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `audit_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `submitted_at` timestamp NULL DEFAULT NULL,
  `effective_date` date DEFAULT NULL,
  `expire_date` date DEFAULT NULL,
  `source_product_id` bigint(20) unsigned DEFAULT NULL,
  `source_sku_id` bigint(20) unsigned DEFAULT NULL,
  `source_standard_bom_id` bigint(20) unsigned DEFAULT NULL,
  `custom_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `updated_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_boms_bom_no_unique` (`bom_no`),
  KEY `erp_boms_source_product_id_foreign` (`source_product_id`),
  KEY `erp_boms_source_sku_id_foreign` (`source_sku_id`),
  KEY `erp_boms_source_standard_bom_id_foreign` (`source_standard_bom_id`),
  KEY `erp_boms_sku_id_status_is_default_index` (`sku_id`,`status`,`is_default`),
  KEY `erp_boms_output_item_id_status_is_default_index` (`output_item_id`,`status`,`is_default`),
  KEY `erp_boms_bom_type_status_audit_status_index` (`bom_type`,`status`,`audit_status`),
  KEY `erp_boms_submitted_at_index` (`submitted_at`),
  KEY `erp_bom_prod_scope_default_idx` (`product_id`,`sku_id`,`output_item_id`,`is_default`),
  KEY `erp_bom_output_active_default_idx` (`output_item_id`,`status`,`audit_status`,`is_default`),
  CONSTRAINT `erp_boms_output_item_id_foreign` FOREIGN KEY (`output_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_boms_product_id_foreign` FOREIGN KEY (`product_id`) REFERENCES `erp_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_boms_sku_id_foreign` FOREIGN KEY (`sku_id`) REFERENCES `erp_skus` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_boms_source_product_id_foreign` FOREIGN KEY (`source_product_id`) REFERENCES `erp_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_boms_source_sku_id_foreign` FOREIGN KEY (`source_sku_id`) REFERENCES `erp_skus` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_boms_source_standard_bom_id_foreign` FOREIGN KEY (`source_standard_bom_id`) REFERENCES `erp_boms` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
