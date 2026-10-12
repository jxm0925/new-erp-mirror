<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_routings';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_routings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `routing_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `routing_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `output_item_id` bigint(20) unsigned NOT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `sku_id` bigint(20) unsigned DEFAULT NULL,
  `version` int(10) unsigned NOT NULL DEFAULT '1',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `default_scope_key` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_prod_routing_no_version_uq` (`routing_no`,`version`),
  UNIQUE KEY `erp_prod_routing_default_scope_uq` (`default_scope_key`),
  KEY `erp_prod_routing_item_status_idx` (`output_item_id`,`status`),
  KEY `erp_prod_routing_product_fk` (`product_id`),
  KEY `erp_prod_routing_sku_fk` (`sku_id`),
  CONSTRAINT `erp_prod_routing_output_item_fk` FOREIGN KEY (`output_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_prod_routing_product_fk` FOREIGN KEY (`product_id`) REFERENCES `erp_products` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_routing_sku_fk` FOREIGN KEY (`sku_id`) REFERENCES `erp_skus` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
