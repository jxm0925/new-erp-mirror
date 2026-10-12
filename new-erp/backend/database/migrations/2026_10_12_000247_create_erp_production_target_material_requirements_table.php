<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_target_material_requirements';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_target_material_requirements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `material_requirement_id` bigint(20) unsigned NOT NULL,
  `material_supply_rule_snapshot_id` bigint(20) unsigned NOT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `cut_length_mm_snapshot` decimal(12,2) DEFAULT NULL,
  `required_piece_qty_snapshot` decimal(18,8) DEFAULT NULL,
  `requirement_kind` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard',
  `required_base_qty` decimal(18,8) NOT NULL,
  `satisfied_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `consumed_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `returned_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'OPEN',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `cutting_requirement_snapshot` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_prod_target_material_uq` (`target_type`,`target_id`,`material_supply_rule_snapshot_id`,`requirement_kind`),
  KEY `erp_prod_target_material_wo_idx` (`work_order_id`,`status`),
  KEY `erp_prod_target_material_requirement_fk` (`material_requirement_id`),
  KEY `erp_prod_target_material_supply_fk` (`material_supply_rule_snapshot_id`),
  KEY `erp_prod_target_material_item_fk` (`component_item_id`),
  CONSTRAINT `erp_prod_target_material_item_fk` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_prod_target_material_requirement_fk` FOREIGN KEY (`material_requirement_id`) REFERENCES `erp_work_order_material_requirements` (`id`),
  CONSTRAINT `erp_prod_target_material_supply_fk` FOREIGN KEY (`material_supply_rule_snapshot_id`) REFERENCES `erp_work_order_material_supply_rules` (`id`),
  CONSTRAINT `erp_prod_target_material_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
