<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_work_order_preparation_materials';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_work_order_preparation_materials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `preparation_id` bigint(20) unsigned NOT NULL,
  `preparation_version` int(10) unsigned NOT NULL,
  `bom_item_id` bigint(20) unsigned DEFAULT NULL,
  `identity_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `base_unit_id` bigint(20) unsigned NOT NULL,
  `required_base_qty` decimal(18,8) NOT NULL,
  `material_snapshot` json NOT NULL,
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `material_requirement_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_wopm_identity_uq` (`work_order_id`,`identity_key`),
  UNIQUE KEY `erp_wopm_formal_uq` (`material_requirement_id`),
  KEY `erp_wopm_prep_fk` (`preparation_id`),
  KEY `erp_work_order_preparation_materials_component_item_id_foreign` (`component_item_id`),
  CONSTRAINT `erp_wopm_formal_fk` FOREIGN KEY (`material_requirement_id`) REFERENCES `erp_work_order_material_requirements` (`id`),
  CONSTRAINT `erp_wopm_prep_fk` FOREIGN KEY (`preparation_id`) REFERENCES `erp_work_order_material_preparations` (`id`),
  CONSTRAINT `erp_work_order_preparation_materials_component_item_id_foreign` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_work_order_preparation_materials_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
