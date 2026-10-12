<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_approval_action_executions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_approval_action_executions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `approval_task_id` bigint(20) unsigned NOT NULL,
  `approval_task_node_id` bigint(20) unsigned DEFAULT NULL,
  `action_code` varchar(140) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `attempt_no` int(10) unsigned NOT NULL DEFAULT '1',
  `execution_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_snapshot` json DEFAULT NULL,
  `result_snapshot` json DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `operator_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_approval_action_executions_approval_task_node_id_foreign` (`approval_task_node_id`),
  KEY `erp_approval_action_task_status_idx` (`approval_task_id`,`execution_status`),
  KEY `erp_approval_action_executions_action_code_index` (`action_code`),
  KEY `erp_approval_action_executions_execution_status_index` (`execution_status`),
  CONSTRAINT `erp_approval_action_executions_approval_task_id_foreign` FOREIGN KEY (`approval_task_id`) REFERENCES `erp_approval_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_approval_action_executions_approval_task_node_id_foreign` FOREIGN KEY (`approval_task_node_id`) REFERENCES `erp_approval_task_nodes` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
