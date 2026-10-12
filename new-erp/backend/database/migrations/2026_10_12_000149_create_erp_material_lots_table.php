<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_lots';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_lots` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `lot_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `configuration_id` bigint(20) unsigned DEFAULT NULL,
  `stage_id` bigint(20) unsigned DEFAULT NULL,
  `material_form` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cut_length_mm` decimal(18,2) DEFAULT NULL,
  `source_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `parent_lot_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_material_lots_lot_no_unique` (`lot_no`),
  KEY `erp_material_lots_item_id_foreign` (`item_id`),
  KEY `erp_material_lots_configuration_id_foreign` (`configuration_id`),
  KEY `erp_material_lots_stage_id_foreign` (`stage_id`),
  KEY `erp_material_lots_parent_lot_id_foreign` (`parent_lot_id`),
  CONSTRAINT `erp_material_lots_configuration_id_foreign` FOREIGN KEY (`configuration_id`) REFERENCES `erp_custom_configurations` (`id`),
  CONSTRAINT `erp_material_lots_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_material_lots_parent_lot_id_foreign` FOREIGN KEY (`parent_lot_id`) REFERENCES `erp_material_lots` (`id`),
  CONSTRAINT `erp_material_lots_stage_id_foreign` FOREIGN KEY (`stage_id`) REFERENCES `erp_production_routing_operations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
