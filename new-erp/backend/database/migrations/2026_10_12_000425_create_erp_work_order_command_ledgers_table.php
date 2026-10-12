<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_work_order_command_ledgers';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_work_order_command_ledgers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_command_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `command_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `aggregate_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'work_order',
  `aggregate_id` bigint(20) unsigned DEFAULT NULL,
  `request_hash` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'processing',
  `result_type` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `result_id` bigint(20) unsigned DEFAULT NULL,
  `response_snapshot` json DEFAULT NULL,
  `error_code` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `error_message` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `initiated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `organization_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `processing_started_at` timestamp NULL DEFAULT NULL,
  `processing_finished_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_work_order_command_client_unique` (`client_command_id`),
  KEY `erp_work_order_command_aggregate_idx` (`aggregate_type`,`aggregate_id`,`command_type`),
  KEY `erp_work_order_command_recovery_idx` (`status`,`processing_started_at`),
  KEY `erp_work_order_command_result_fk` (`result_id`),
  CONSTRAINT `erp_work_order_command_result_fk` FOREIGN KEY (`result_id`) REFERENCES `erp_work_orders` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
