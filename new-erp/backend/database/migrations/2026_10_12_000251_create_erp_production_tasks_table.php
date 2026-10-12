<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_tasks';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `production_unit_id` bigint(20) unsigned DEFAULT NULL,
  `production_unit_operation_id` bigint(20) unsigned DEFAULT NULL,
  `production_quantity_operation_id` bigint(20) unsigned DEFAULT NULL,
  `execution_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `routing_operation_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `operation_code_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `operation_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sequence_no_snapshot` int(10) unsigned NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_CLAIM',
  `assignee_user_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `assignment_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assignment_score_snapshot` json DEFAULT NULL,
  `labor_allocation_rule_id` bigint(20) unsigned DEFAULT NULL,
  `labor_allocation_rule_version` int(10) unsigned DEFAULT NULL,
  `labor_allocation_rule_snapshot` json DEFAULT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `organization_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `production_stage_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `stage_code_snapshot` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stage_name_snapshot` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `performance_rate_snapshot` decimal(10,6) DEFAULT NULL,
  `auto_assignment_enabled_snapshot` tinyint(1) NOT NULL DEFAULT '0',
  `is_public_snapshot` tinyint(1) NOT NULL DEFAULT '0',
  `active_job_bundle_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_production_tasks_task_no_unique` (`task_no`),
  UNIQUE KEY `erp_prod_task_unit_operation_uq` (`production_unit_operation_id`),
  UNIQUE KEY `erp_prod_task_qty_operation_uq` (`production_quantity_operation_id`),
  KEY `erp_prod_task_status_user_idx` (`status`,`assignee_user_legacy_id`),
  KEY `erp_prod_task_wo_op_status_idx` (`work_order_id`,`sequence_no_snapshot`,`status`),
  KEY `erp_prod_task_route_op_fk` (`routing_operation_id_snapshot`),
  KEY `erp_prod_task_labor_rule_fk` (`labor_allocation_rule_id`),
  KEY `erp_prod_task_unit_seq_status_idx` (`production_unit_id`,`sequence_no_snapshot`,`status`),
  KEY `prod_task_public_status_idx` (`is_public_snapshot`,`status`),
  KEY `erp_production_tasks_active_job_bundle_id_foreign` (`active_job_bundle_id`),
  CONSTRAINT `erp_prod_task_labor_rule_fk` FOREIGN KEY (`labor_allocation_rule_id`) REFERENCES `erp_production_labor_allocation_rules` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_task_qty_operation_fk` FOREIGN KEY (`production_quantity_operation_id`) REFERENCES `erp_production_quantity_operations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_prod_task_route_op_fk` FOREIGN KEY (`routing_operation_id_snapshot`) REFERENCES `erp_production_routing_operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_task_unit_fk` FOREIGN KEY (`production_unit_id`) REFERENCES `erp_production_units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_prod_task_unit_operation_fk` FOREIGN KEY (`production_unit_operation_id`) REFERENCES `erp_production_unit_operations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_prod_task_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`),
  CONSTRAINT `erp_production_tasks_active_job_bundle_id_foreign` FOREIGN KEY (`active_job_bundle_id`) REFERENCES `erp_production_job_bundles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
