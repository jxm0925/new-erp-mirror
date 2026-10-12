<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_task_assignments';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_task_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_id` bigint(20) unsigned NOT NULL,
  `active_task_id` bigint(20) unsigned DEFAULT NULL,
  `offered_to_legacy_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `algorithm_version` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `score_snapshot` json NOT NULL,
  `offered_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `offered_at` timestamp NOT NULL,
  `decided_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `decided_at` timestamp NULL DEFAULT NULL,
  `rejection_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_pt_assignment_active_uq` (`active_task_id`),
  KEY `erp_pt_assignment_recipient_idx` (`offered_to_legacy_id`,`status`,`id`),
  KEY `erp_pt_assignment_history_idx` (`task_id`,`status`,`offered_to_legacy_id`),
  CONSTRAINT `erp_production_task_assignments_task_id_foreign` FOREIGN KEY (`task_id`) REFERENCES `erp_production_tasks` (`id`),
  CONSTRAINT `erp_pt_assignment_active_fk` FOREIGN KEY (`active_task_id`) REFERENCES `erp_production_tasks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
