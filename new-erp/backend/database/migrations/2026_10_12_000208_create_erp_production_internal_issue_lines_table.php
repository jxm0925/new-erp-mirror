<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_internal_issue_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_internal_issue_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `issue_task_id` bigint(20) unsigned NOT NULL,
  `output_record_id` bigint(20) unsigned DEFAULT NULL,
  `production_inventory_reservation_id` bigint(20) unsigned DEFAULT NULL,
  `target_material_requirement_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `inventory_balance_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial_id` bigint(20) unsigned DEFAULT NULL,
  `serial_no_snapshot` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issue_base_qty` decimal(18,8) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `cutting_inventory_reservation_id` bigint(20) unsigned DEFAULT NULL,
  `issue_total_cost` decimal(18,4) DEFAULT NULL,
  `material_holding_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_continuation_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_prod_issue_output_uq` (`issue_task_id`,`output_record_id`),
  KEY `erp_prod_issue_line_output_fk` (`output_record_id`),
  KEY `erp_prod_issue_line_item_fk` (`item_id`),
  KEY `erp_prod_issue_line_balance_fk` (`inventory_balance_id`),
  KEY `erp_prod_issue_line_wh_fk` (`warehouse_id`),
  KEY `erp_prod_issue_line_location_fk` (`location_id`),
  KEY `erp_prod_issue_line_serial_fk` (`serial_id`),
  KEY `erp_prod_issue_task_guard_idx` (`issue_task_id`),
  KEY `erp_issue_line_target_material_fk` (`target_material_requirement_id`),
  KEY `erp_prod_issue_line_reservation_fk` (`production_inventory_reservation_id`),
  KEY `prod_issue_cut_inventory_res_fk` (`cutting_inventory_reservation_id`),
  KEY `prod_issue_material_holding_fk` (`material_holding_id`),
  KEY `erp_prod_issue_cont_fk` (`inventory_continuation_id`),
  CONSTRAINT `erp_issue_line_target_material_fk` FOREIGN KEY (`target_material_requirement_id`) REFERENCES `erp_production_target_material_requirements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_issue_cont_fk` FOREIGN KEY (`inventory_continuation_id`) REFERENCES `erp_work_order_inventory_continuations` (`id`),
  CONSTRAINT `erp_prod_issue_line_balance_fk` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `erp_prod_issue_line_item_fk` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_prod_issue_line_location_fk` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_prod_issue_line_output_fk` FOREIGN KEY (`output_record_id`) REFERENCES `erp_production_output_records` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_issue_line_reservation_fk` FOREIGN KEY (`production_inventory_reservation_id`) REFERENCES `erp_production_inventory_reservations` (`id`),
  CONSTRAINT `erp_prod_issue_line_serial_fk` FOREIGN KEY (`serial_id`) REFERENCES `erp_inventory_serials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_issue_line_task_fk` FOREIGN KEY (`issue_task_id`) REFERENCES `erp_production_internal_issue_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_prod_issue_line_wh_fk` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`),
  CONSTRAINT `prod_issue_cut_inventory_res_fk` FOREIGN KEY (`cutting_inventory_reservation_id`) REFERENCES `erp_cutting_inventory_reservations` (`id`),
  CONSTRAINT `prod_issue_material_holding_fk` FOREIGN KEY (`material_holding_id`) REFERENCES `erp_material_holdings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
