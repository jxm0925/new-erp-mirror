<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_work_order_material_supply_rules';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_work_order_material_supply_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `material_requirement_id` bigint(20) unsigned NOT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `source_rule_id` bigint(20) unsigned DEFAULT NULL,
  `target_routing_operation_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `target_operation_code_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_operation_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `required_base_qty_snapshot` decimal(18,8) NOT NULL,
  `supply_mode_snapshot` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `requires_delivery_snapshot` tinyint(1) NOT NULL,
  `participates_in_kitting_snapshot` tinyint(1) NOT NULL,
  `allow_partial_delivery_snapshot` tinyint(1) NOT NULL,
  `delivery_location_type_snapshot` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rule_snapshot` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_wo_supply_target_idx` (`work_order_id`,`target_routing_operation_id_snapshot`),
  KEY `erp_wo_supply_requirement_fk` (`material_requirement_id`),
  KEY `erp_wo_supply_item_fk` (`component_item_id`),
  KEY `erp_wo_supply_source_rule_fk` (`source_rule_id`),
  KEY `erp_wo_supply_target_op_fk` (`target_routing_operation_id_snapshot`),
  CONSTRAINT `erp_wo_supply_item_fk` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_wo_supply_requirement_fk` FOREIGN KEY (`material_requirement_id`) REFERENCES `erp_work_order_material_requirements` (`id`),
  CONSTRAINT `erp_wo_supply_source_rule_fk` FOREIGN KEY (`source_rule_id`) REFERENCES `erp_routing_operation_material_supply_rules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_wo_supply_target_op_fk` FOREIGN KEY (`target_routing_operation_id_snapshot`) REFERENCES `erp_production_routing_operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_wo_supply_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
