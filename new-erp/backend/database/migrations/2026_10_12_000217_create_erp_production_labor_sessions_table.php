<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_labor_sessions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_labor_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `execution_task_type` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PRODUCTION_TASK',
  `task_id` bigint(20) unsigned DEFAULT NULL,
  `cutting_task_id` bigint(20) unsigned DEFAULT NULL,
  `target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `employee_legacy_id` bigint(20) unsigned NOT NULL,
  `active_employee_legacy_id` bigint(20) unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'ACTIVE') then `employee_legacy_id` else NULL end)) STORED,
  `role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'owner',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `started_at` timestamp NOT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `end_reason` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `previous_labor_session_id` bigint(20) unsigned DEFAULT NULL,
  `actual_labor_minutes` decimal(14,2) NOT NULL DEFAULT '0.00',
  `responsibility_weight_snapshot` decimal(8,4) NOT NULL DEFAULT '1.0000',
  `credited_labor_minutes` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `job_bundle_id` bigint(20) unsigned DEFAULT NULL,
  `bundle_checkpoint_minutes` decimal(14,2) NOT NULL DEFAULT '0.00',
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_prod_labor_one_active_employee_uq` (`active_employee_legacy_id`),
  KEY `erp_prod_labor_target_user_idx` (`target_type`,`target_id`,`employee_legacy_id`,`status`),
  KEY `erp_prod_labor_employee_history_idx` (`employee_legacy_id`,`status`,`ended_at`),
  KEY `erp_prod_labor_previous_session_fk` (`previous_labor_session_id`),
  KEY `cut_labor_task_employee_status_idx` (`cutting_task_id`,`employee_legacy_id`,`status`),
  KEY `erp_prod_labor_task_fk` (`task_id`),
  KEY `erp_production_labor_sessions_job_bundle_id_foreign` (`job_bundle_id`),
  CONSTRAINT `erp_prod_labor_previous_session_fk` FOREIGN KEY (`previous_labor_session_id`) REFERENCES `erp_production_labor_sessions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_labor_task_fk` FOREIGN KEY (`task_id`) REFERENCES `erp_production_tasks` (`id`),
  CONSTRAINT `erp_production_labor_sessions_cutting_task_id_foreign` FOREIGN KEY (`cutting_task_id`) REFERENCES `erp_cutting_tasks` (`id`),
  CONSTRAINT `erp_production_labor_sessions_job_bundle_id_foreign` FOREIGN KEY (`job_bundle_id`) REFERENCES `erp_production_job_bundles` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
