<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_order_change_candidates';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_order_change_candidates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `candidate_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sales_order_id` bigint(20) unsigned NOT NULL,
  `base_version` int(10) unsigned NOT NULL,
  `candidate_version` int(10) unsigned NOT NULL,
  `candidate_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING_APPROVAL',
  `submitted_by` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `change_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `candidate_order_snapshot` json NOT NULL,
  `structured_diffs` json NOT NULL,
  `impact_summary` json NOT NULL,
  `approval_requirements` json NOT NULL,
  `approval_reasons` json DEFAULT NULL,
  `production_impact` json DEFAULT NULL,
  `activated_by` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activated_at` timestamp NULL DEFAULT NULL,
  `conflict_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_so_candidate_version_unique` (`sales_order_id`,`candidate_version`),
  UNIQUE KEY `erp_sales_order_change_candidates_candidate_no_unique` (`candidate_no`),
  KEY `erp_so_candidate_status_idx` (`sales_order_id`,`candidate_status`),
  CONSTRAINT `erp_sales_order_change_candidates_sales_order_id_foreign` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
