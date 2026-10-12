<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_order_changes';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_order_changes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `change_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sales_order_id` bigint(20) unsigned NOT NULL,
  `candidate_id` bigint(20) unsigned DEFAULT NULL,
  `version_no` int(10) unsigned DEFAULT NULL,
  `change_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'commercial_quantity',
  `change_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'applied',
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `before_snapshot` json NOT NULL,
  `after_snapshot` json NOT NULL,
  `structured_diffs` json DEFAULT NULL,
  `impact_summary` json DEFAULT NULL,
  `approval_requirements` json DEFAULT NULL,
  `approval_reasons` json DEFAULT NULL,
  `immediate_effect` tinyint(1) NOT NULL DEFAULT '0',
  `operator` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `applied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_sales_order_changes_change_no_unique` (`change_no`),
  UNIQUE KEY `erp_soc_order_version_uq` (`sales_order_id`,`version_no`),
  KEY `erp_soc_order_status_idx` (`sales_order_id`,`change_status`),
  KEY `erp_sales_order_changes_candidate_id_foreign` (`candidate_id`),
  CONSTRAINT `erp_sales_order_changes_candidate_id_foreign` FOREIGN KEY (`candidate_id`) REFERENCES `erp_sales_order_change_candidates` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_soc_order_fk` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
