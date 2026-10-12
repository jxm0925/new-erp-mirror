<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_demands';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_demands` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `demand_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_requirement_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `configuration_id` bigint(20) unsigned DEFAULT NULL,
  `stage_id` bigint(20) unsigned NOT NULL,
  `required_base_qty_snapshot` decimal(18,8) NOT NULL,
  `cut_length_mm_snapshot` decimal(18,2) DEFAULT NULL,
  `required_piece_qty_snapshot` decimal(18,8) DEFAULT NULL,
  `source_business_version` int(10) unsigned NOT NULL,
  `source_snapshot` json NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_by_legacy_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cut_demand_formal_source_uq` (`source_type`,`source_requirement_id`),
  UNIQUE KEY `erp_cutting_demands_demand_no_unique` (`demand_no`),
  UNIQUE KEY `cut_demand_formal_identity_uq` (`id`,`source_requirement_id`),
  KEY `erp_cutting_demands_source_requirement_id_foreign` (`source_requirement_id`),
  KEY `erp_cutting_demands_item_id_foreign` (`item_id`),
  KEY `erp_cutting_demands_configuration_id_foreign` (`configuration_id`),
  KEY `erp_cutting_demands_stage_id_foreign` (`stage_id`),
  CONSTRAINT `erp_cutting_demands_configuration_id_foreign` FOREIGN KEY (`configuration_id`) REFERENCES `erp_custom_configurations` (`id`),
  CONSTRAINT `erp_cutting_demands_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_cutting_demands_source_requirement_id_foreign` FOREIGN KEY (`source_requirement_id`) REFERENCES `erp_production_target_material_requirements` (`id`),
  CONSTRAINT `erp_cutting_demands_stage_id_foreign` FOREIGN KEY (`stage_id`) REFERENCES `erp_production_routing_operations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
