<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_approval_notifications';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_approval_notifications` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `approval_task_id` bigint(20) unsigned NOT NULL,
  `approval_task_node_id` bigint(20) unsigned DEFAULT NULL,
  `user_id` bigint(20) unsigned NOT NULL,
  `notification_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'UNREAD',
  `dedup_key` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `notified_at` timestamp NOT NULL,
  `read_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_approval_notifications_dedup_key_unique` (`dedup_key`),
  KEY `erp_approval_notifications_approval_task_id_foreign` (`approval_task_id`),
  KEY `erp_approval_notifications_approval_task_node_id_foreign` (`approval_task_node_id`),
  KEY `erp_approval_notifications_user_id_index` (`user_id`),
  KEY `erp_approval_notifications_notification_type_index` (`notification_type`),
  KEY `erp_approval_notifications_status_index` (`status`),
  KEY `erp_approval_notifications_notified_at_index` (`notified_at`),
  CONSTRAINT `erp_approval_notifications_approval_task_id_foreign` FOREIGN KEY (`approval_task_id`) REFERENCES `erp_approval_tasks` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_approval_notifications_approval_task_node_id_foreign` FOREIGN KEY (`approval_task_node_id`) REFERENCES `erp_approval_task_nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
