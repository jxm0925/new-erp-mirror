<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_plan_allocations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_plan_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cutting_order_id` bigint(20) unsigned NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `target_material_requirement_id` bigint(20) unsigned NOT NULL,
  `output_item_id` bigint(20) unsigned NOT NULL,
  `configuration_id` bigint(20) unsigned DEFAULT NULL,
  `stage_id` bigint(20) unsigned NOT NULL,
  `planned_qty` decimal(18,8) NOT NULL,
  `closed_planned_qty` decimal(18,8) DEFAULT NULL,
  `source_snapshot` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `target_identity_id` bigint(20) unsigned GENERATED ALWAYS AS (coalesce(`target_material_requirement_id`,0)) STORED,
  `demand_id` bigint(20) unsigned NOT NULL,
  `input_material_requirement_id` bigint(20) unsigned NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cut_plan_target_source_uq` (`cutting_order_id`,`work_order_id`,`stage_id`,`target_identity_id`),
  KEY `erp_cutting_plan_allocations_work_order_id_foreign` (`work_order_id`),
  KEY `erp_cutting_plan_allocations_output_item_id_foreign` (`output_item_id`),
  KEY `erp_cutting_plan_allocations_configuration_id_foreign` (`configuration_id`),
  KEY `erp_cutting_plan_allocations_stage_id_foreign` (`stage_id`),
  KEY `cut_plan_demand_order_idx` (`demand_id`,`cutting_order_id`),
  KEY `cut_plan_target_nn_fk` (`target_material_requirement_id`),
  KEY `cut_plan_input_nn_fk` (`input_material_requirement_id`),
  KEY `cut_plan_demand_source_nn_fk` (`demand_id`,`target_material_requirement_id`),
  CONSTRAINT `cut_plan_demand_nn_fk` FOREIGN KEY (`demand_id`) REFERENCES `erp_cutting_demands` (`id`),
  CONSTRAINT `cut_plan_demand_source_nn_fk` FOREIGN KEY (`demand_id`, `target_material_requirement_id`) REFERENCES `erp_cutting_demands` (`id`, `source_requirement_id`),
  CONSTRAINT `cut_plan_input_nn_fk` FOREIGN KEY (`input_material_requirement_id`) REFERENCES `erp_work_order_material_requirements` (`id`),
  CONSTRAINT `cut_plan_target_nn_fk` FOREIGN KEY (`target_material_requirement_id`) REFERENCES `erp_production_target_material_requirements` (`id`),
  CONSTRAINT `erp_cutting_plan_allocations_configuration_id_foreign` FOREIGN KEY (`configuration_id`) REFERENCES `erp_custom_configurations` (`id`),
  CONSTRAINT `erp_cutting_plan_allocations_cutting_order_id_foreign` FOREIGN KEY (`cutting_order_id`) REFERENCES `erp_cutting_orders` (`id`),
  CONSTRAINT `erp_cutting_plan_allocations_output_item_id_foreign` FOREIGN KEY (`output_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_cutting_plan_allocations_stage_id_foreign` FOREIGN KEY (`stage_id`) REFERENCES `erp_production_routing_operations` (`id`),
  CONSTRAINT `erp_cutting_plan_allocations_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
