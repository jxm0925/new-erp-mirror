<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_approval_business_actions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_approval_business_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `business_object_id` bigint(20) unsigned DEFAULT NULL,
  `action_code` varchar(140) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `action_name` varchar(180) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `result_event` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `handler_class` varchar(240) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `config_schema` json DEFAULT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_approval_business_actions_action_code_unique` (`action_code`),
  KEY `erp_approval_business_actions_business_object_id_foreign` (`business_object_id`),
  KEY `erp_approval_business_actions_result_event_index` (`result_event`),
  KEY `erp_approval_business_actions_enabled_index` (`enabled`),
  CONSTRAINT `erp_approval_business_actions_business_object_id_foreign` FOREIGN KEY (`business_object_id`) REFERENCES `erp_approval_business_objects` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
