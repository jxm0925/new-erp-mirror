<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_approval_node_decisions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_approval_node_decisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `approval_task_id` bigint(20) unsigned NOT NULL,
  `approval_task_node_id` bigint(20) unsigned NOT NULL,
  `round_no` int(10) unsigned NOT NULL DEFAULT '1',
  `approver_id` bigint(20) unsigned NOT NULL,
  `approver_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `decision` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `comment` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `decided_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_approval_decisions_node_user_round_unique` (`approval_task_node_id`,`approver_id`,`round_no`),
  KEY `erp_approval_node_decisions_task_time_index` (`approval_task_id`,`decided_at`),
  KEY `erp_approval_decisions_node_fk_index` (`approval_task_node_id`),
  KEY `erp_approval_decisions_node_round_index` (`approval_task_node_id`,`round_no`),
  CONSTRAINT `erp_approval_node_decisions_approval_task_id_foreign` FOREIGN KEY (`approval_task_id`) REFERENCES `erp_approval_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_approval_node_decisions_approval_task_node_id_foreign` FOREIGN KEY (`approval_task_node_id`) REFERENCES `erp_approval_task_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
