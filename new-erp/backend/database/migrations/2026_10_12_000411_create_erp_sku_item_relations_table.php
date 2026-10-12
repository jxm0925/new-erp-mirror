<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sku_item_relations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sku_item_relations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sku_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned DEFAULT NULL,
  `relation_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'finished_product',
  `relation_context` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard',
  `base_relation_id` bigint(20) unsigned DEFAULT NULL,
  `custom_source_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `custom_source_id` bigint(20) unsigned DEFAULT NULL,
  `qty` decimal(18,8) NOT NULL DEFAULT '1.00000000',
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `is_primary` tinyint(1) NOT NULL DEFAULT '1',
  `is_bundle_item` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled',
  `effective_at` timestamp NULL DEFAULT NULL,
  `expired_at` timestamp NULL DEFAULT NULL,
  `operator_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `change_reason` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `enabled_primary_sku_id` bigint(20) unsigned GENERATED ALWAYS AS ((case when ((`status` = _utf8mb4'active') and (`is_primary` = 1)) then `sku_id` else NULL end)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_sku_item_relation_unique` (`sku_id`,`item_id`,`relation_type`),
  UNIQUE KEY `erp_sku_item_one_enabled_primary` (`enabled_primary_sku_id`),
  KEY `erp_sku_item_relations_item_id_foreign` (`item_id`),
  KEY `erp_sku_item_relations_unit_id_foreign` (`unit_id`),
  KEY `erp_sku_item_relations_base_relation_id_foreign` (`base_relation_id`),
  CONSTRAINT `erp_sku_item_relations_base_relation_id_foreign` FOREIGN KEY (`base_relation_id`) REFERENCES `erp_sku_item_relations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sku_item_relations_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_sku_item_relations_sku_id_foreign` FOREIGN KEY (`sku_id`) REFERENCES `erp_skus` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_sku_item_relations_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
