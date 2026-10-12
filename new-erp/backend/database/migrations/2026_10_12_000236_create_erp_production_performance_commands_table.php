<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_performance_commands';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_performance_commands` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `client_command_id` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_id` bigint(20) unsigned NOT NULL,
  `initiated_by_legacy_id` bigint(20) unsigned NOT NULL,
  `assignment_id` bigint(20) unsigned DEFAULT NULL,
  `response_snapshot` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_production_performance_commands_client_command_id_unique` (`client_command_id`),
  KEY `prod_perf_command_assignment_fk` (`assignment_id`),
  CONSTRAINT `prod_perf_command_assignment_fk` FOREIGN KEY (`assignment_id`) REFERENCES `erp_production_performance_assignments` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
