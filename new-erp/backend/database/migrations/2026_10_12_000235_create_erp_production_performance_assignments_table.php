<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_performance_assignments';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_performance_assignments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope_type` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_id` bigint(20) unsigned NOT NULL,
  `owner_legacy_id` bigint(20) unsigned NOT NULL,
  `version_no` int(10) unsigned NOT NULL,
  `active_scope_key` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmed',
  `credited_share_ratio` decimal(12,8) NOT NULL,
  `noncredited_share_ratio` decimal(12,8) NOT NULL,
  `noncredited_confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `noncredited_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scope_snapshot` json NOT NULL,
  `confirmed_by_legacy_id` bigint(20) unsigned NOT NULL,
  `confirmed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_perf_scope_version_uq` (`scope_type`,`scope_id`,`version_no`),
  UNIQUE KEY `erp_production_performance_assignments_active_scope_key_unique` (`active_scope_key`),
  KEY `prod_perf_owner_time_idx` (`owner_legacy_id`,`confirmed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
