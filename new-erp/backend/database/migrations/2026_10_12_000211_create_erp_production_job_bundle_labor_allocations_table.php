<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_job_bundle_labor_allocations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_job_bundle_labor_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `job_bundle_line_id` bigint(20) unsigned NOT NULL,
  `labor_session_id` bigint(20) unsigned NOT NULL,
  `employee_legacy_id` bigint(20) unsigned NOT NULL,
  `standard_weight_minutes_snapshot` decimal(18,8) NOT NULL,
  `allocated_labor_minutes` decimal(14,2) NOT NULL DEFAULT '0.00',
  `credited_labor_minutes` decimal(14,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_bundle_labor_line_session_uq` (`job_bundle_line_id`,`labor_session_id`),
  KEY `prod_bundle_alloc_session_fk` (`labor_session_id`),
  CONSTRAINT `prod_bundle_alloc_line_fk` FOREIGN KEY (`job_bundle_line_id`) REFERENCES `erp_production_job_bundle_lines` (`id`),
  CONSTRAINT `prod_bundle_alloc_session_fk` FOREIGN KEY (`labor_session_id`) REFERENCES `erp_production_labor_sessions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
