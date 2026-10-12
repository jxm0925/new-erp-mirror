<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_picking_task_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_picking_task_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_id` bigint(20) unsigned NOT NULL,
  `material_requirement_id` bigint(20) unsigned NOT NULL,
  `material_supply_rule_snapshot_id` bigint(20) unsigned DEFAULT NULL,
  `target_routing_operation_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `target_operation_code_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_operation_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `production_target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `production_target_id` bigint(20) unsigned DEFAULT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `required_qty_snapshot` decimal(18,8) NOT NULL,
  `planned_pick_qty` decimal(18,8) NOT NULL,
  `actual_pick_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `delivered_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `received_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `unit_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inventory_balance_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `serial_control_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `serial_snapshot` json DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_PICK',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `fulfillment_mode_snapshot` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'delivery',
  PRIMARY KEY (`id`),
  KEY `erp_material_pick_line_req_idx` (`material_requirement_id`,`status`),
  KEY `erp_material_pick_line_task_fk` (`task_id`),
  KEY `erp_material_pick_line_item_fk` (`component_item_id`),
  KEY `erp_material_pick_line_unit_fk` (`unit_id`),
  KEY `erp_material_pick_line_balance_fk` (`inventory_balance_id`),
  KEY `erp_material_pick_line_wh_fk` (`warehouse_id`),
  KEY `erp_material_pick_line_location_fk` (`location_id`),
  KEY `erp_pick_line_prod_target_idx` (`production_target_type`,`production_target_id`),
  KEY `erp_pick_line_supply_rule_fk` (`material_supply_rule_snapshot_id`),
  CONSTRAINT `erp_material_pick_line_balance_fk` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `erp_material_pick_line_item_fk` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_material_pick_line_location_fk` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_material_pick_line_req_fk` FOREIGN KEY (`material_requirement_id`) REFERENCES `erp_work_order_material_requirements` (`id`),
  CONSTRAINT `erp_material_pick_line_task_fk` FOREIGN KEY (`task_id`) REFERENCES `erp_material_picking_tasks` (`id`),
  CONSTRAINT `erp_material_pick_line_unit_fk` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_material_pick_line_wh_fk` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`),
  CONSTRAINT `erp_pick_line_supply_rule_fk` FOREIGN KEY (`material_supply_rule_snapshot_id`) REFERENCES `erp_work_order_material_supply_rules` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
