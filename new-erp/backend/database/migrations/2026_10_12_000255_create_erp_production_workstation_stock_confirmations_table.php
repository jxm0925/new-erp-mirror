<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_workstation_stock_confirmations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_workstation_stock_confirmations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `task_id` bigint(20) unsigned NOT NULL,
  `target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `target_material_requirement_id` bigint(20) unsigned NOT NULL,
  `attempt_no` int(10) unsigned NOT NULL DEFAULT '1',
  `client_command_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `kitting_confirmation_id` bigint(20) unsigned DEFAULT NULL,
  `workstation_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `required_base_qty_snapshot` decimal(18,8) NOT NULL,
  `onsite_available_base_qty_snapshot` decimal(18,8) NOT NULL,
  `shortage_base_qty_snapshot` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `result` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'SUFFICIENT',
  `confirmed_base_qty` decimal(18,8) NOT NULL,
  `confirmed_by_legacy_id` bigint(20) unsigned NOT NULL,
  `confirmed_at` timestamp NOT NULL,
  `fact_snapshot` json DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_workstation_stock_requirement_attempt_uq` (`target_material_requirement_id`,`attempt_no`),
  UNIQUE KEY `erp_workstation_stock_command_requirement_uq` (`client_command_id`,`target_material_requirement_id`),
  KEY `erp_workstation_stock_target_idx` (`target_type`,`target_id`,`confirmed_at`),
  KEY `erp_workstation_stock_wo_fk` (`work_order_id`),
  KEY `erp_workstation_stock_task_fk` (`task_id`),
  KEY `erp_workstation_stock_kitting_fk` (`kitting_confirmation_id`),
  KEY `erp_workstation_stock_item_fk` (`component_item_id`),
  KEY `erp_workstation_stock_requirement_fk_idx` (`target_material_requirement_id`),
  KEY `erp_workstation_stock_requirement_history_idx` (`target_material_requirement_id`,`confirmed_at`,`id`),
  CONSTRAINT `erp_workstation_stock_item_fk` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_workstation_stock_kitting_fk` FOREIGN KEY (`kitting_confirmation_id`) REFERENCES `erp_production_kitting_confirmations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_workstation_stock_req_fk` FOREIGN KEY (`target_material_requirement_id`) REFERENCES `erp_production_target_material_requirements` (`id`),
  CONSTRAINT `erp_workstation_stock_task_fk` FOREIGN KEY (`task_id`) REFERENCES `erp_production_tasks` (`id`),
  CONSTRAINT `erp_workstation_stock_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
