<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_assembly_component_demands';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_assembly_component_demands` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `assembly_plan_id` bigint(20) unsigned NOT NULL,
  `root_work_order_id` bigint(20) unsigned NOT NULL,
  `parent_work_order_id` bigint(20) unsigned NOT NULL,
  `child_work_order_id` bigint(20) unsigned DEFAULT NULL,
  `bom_item_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `base_unit_id` bigint(20) unsigned NOT NULL,
  `path_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `required_base_qty` decimal(28,8) NOT NULL,
  `inventory_reserved_base_qty` decimal(28,8) NOT NULL DEFAULT '0.00000000',
  `production_base_qty` decimal(28,8) NOT NULL DEFAULT '0.00000000',
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PREPARED',
  `demand_snapshot` json NOT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `assembly_demand_plan_path_unique` (`assembly_plan_id`,`path_key`),
  UNIQUE KEY `erp_assembly_component_demands_child_work_order_id_unique` (`child_work_order_id`),
  KEY `erp_assembly_component_demands_root_work_order_id_foreign` (`root_work_order_id`),
  KEY `erp_assembly_component_demands_parent_work_order_id_foreign` (`parent_work_order_id`),
  KEY `erp_assembly_component_demands_item_id_foreign` (`item_id`),
  KEY `erp_assembly_component_demands_base_unit_id_foreign` (`base_unit_id`),
  CONSTRAINT `erp_assembly_component_demands_assembly_plan_id_foreign` FOREIGN KEY (`assembly_plan_id`) REFERENCES `erp_assembly_production_plans` (`id`),
  CONSTRAINT `erp_assembly_component_demands_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `erp_assembly_component_demands_child_work_order_id_foreign` FOREIGN KEY (`child_work_order_id`) REFERENCES `erp_work_orders` (`id`),
  CONSTRAINT `erp_assembly_component_demands_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_assembly_component_demands_parent_work_order_id_foreign` FOREIGN KEY (`parent_work_order_id`) REFERENCES `erp_work_orders` (`id`),
  CONSTRAINT `erp_assembly_component_demands_root_work_order_id_foreign` FOREIGN KEY (`root_work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
