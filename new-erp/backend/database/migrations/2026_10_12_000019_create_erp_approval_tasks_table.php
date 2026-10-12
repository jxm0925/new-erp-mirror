<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_approval_tasks';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_approval_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `flow_template_id` bigint(20) unsigned DEFAULT NULL,
  `flow_version_id` bigint(20) unsigned DEFAULT NULL,
  `business_type` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_object_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `event_code` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_id` bigint(20) unsigned NOT NULL,
  `business_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject` varchar(240) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_route` varchar(240) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `risk_level` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'low',
  `task_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `active_business_key` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idempotency_key` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `launch_result` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'STARTED',
  `action_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `action_error` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `current_node_order` int(10) unsigned DEFAULT NULL,
  `initiator_id` bigint(20) unsigned DEFAULT NULL,
  `initiator_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department_id` bigint(20) unsigned DEFAULT NULL,
  `department_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_snapshot` json NOT NULL,
  `diff_snapshot` json DEFAULT NULL,
  `flow_snapshot` json NOT NULL,
  `result_snapshot` json DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_approval_tasks_task_no_unique` (`task_no`),
  UNIQUE KEY `erp_approval_tasks_active_business_key_unique` (`active_business_key`),
  UNIQUE KEY `erp_approval_tasks_idempotency_key_unique` (`idempotency_key`),
  KEY `erp_approval_tasks_flow_template_id_foreign` (`flow_template_id`),
  KEY `erp_approval_tasks_flow_version_id_foreign` (`flow_version_id`),
  KEY `erp_approval_task_workbench_idx` (`task_status`,`current_node_order`,`submitted_at`),
  KEY `erp_approval_tasks_business_type_index` (`business_type`),
  KEY `erp_approval_tasks_business_id_index` (`business_id`),
  KEY `erp_approval_tasks_business_no_index` (`business_no`),
  KEY `erp_approval_tasks_risk_level_index` (`risk_level`),
  KEY `erp_approval_tasks_task_status_index` (`task_status`),
  KEY `erp_approval_tasks_initiator_id_index` (`initiator_id`),
  KEY `erp_approval_tasks_department_id_index` (`department_id`),
  KEY `erp_approval_tasks_submitted_at_index` (`submitted_at`),
  KEY `erp_approval_tasks_business_object_code_index` (`business_object_code`),
  KEY `erp_approval_tasks_event_code_index` (`event_code`),
  CONSTRAINT `erp_approval_tasks_flow_template_id_foreign` FOREIGN KEY (`flow_template_id`) REFERENCES `erp_approval_flow_templates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_approval_tasks_flow_version_id_foreign` FOREIGN KEY (`flow_version_id`) REFERENCES `erp_approval_flow_versions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
