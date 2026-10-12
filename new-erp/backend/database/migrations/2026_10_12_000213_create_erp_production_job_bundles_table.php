<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_job_bundles';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_job_bundles` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bundle_no` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `title` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_CLAIM',
  `operation_id_snapshot` bigint(20) unsigned NOT NULL,
  `operation_code_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `operation_name_snapshot` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `compatibility_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `compatibility_snapshot` json NOT NULL,
  `assignee_user_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned NOT NULL,
  `organization_code` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `actual_labor_minutes` decimal(14,2) NOT NULL DEFAULT '0.00',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `claimed_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `paused_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_production_job_bundles_bundle_no_unique` (`bundle_no`),
  KEY `prod_bundle_owner_status_idx` (`assignee_user_legacy_id`,`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
