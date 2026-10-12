<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_job_bundle_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_job_bundle_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_bundle_id` bigint(20) unsigned NOT NULL,
  `task_id` bigint(20) unsigned NOT NULL,
  `active_task_id` bigint(20) unsigned DEFAULT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `target_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `standard_weight_minutes_snapshot` decimal(18,8) NOT NULL,
  `allocated_labor_minutes` decimal(14,2) NOT NULL DEFAULT '0.00',
  `source_snapshot` json NOT NULL,
  `material_snapshot` json NOT NULL,
  `started_material_snapshot` json DEFAULT NULL,
  `execution_completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_bundle_task_uq` (`job_bundle_id`,`task_id`),
  UNIQUE KEY `prod_bundle_one_active_task_uq` (`active_task_id`),
  KEY `erp_production_job_bundle_lines_task_id_foreign` (`task_id`),
  KEY `erp_production_job_bundle_lines_work_order_id_foreign` (`work_order_id`),
  KEY `prod_bundle_target_idx` (`target_type`,`target_id`),
  CONSTRAINT `erp_production_job_bundle_lines_job_bundle_id_foreign` FOREIGN KEY (`job_bundle_id`) REFERENCES `erp_production_job_bundles` (`id`),
  CONSTRAINT `erp_production_job_bundle_lines_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `erp_production_tasks` (`id`),
  CONSTRAINT `erp_production_job_bundle_lines_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
