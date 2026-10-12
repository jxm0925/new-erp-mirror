<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_work_order_completions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_work_order_completions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `completion_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_command_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING_REVIEW',
  `submitted_base_qty` decimal(18,8) NOT NULL,
  `qualified_base_qty` decimal(18,8) NOT NULL,
  `unqualified_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `scrapped_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `defect_reason` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` varchar(2000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_snapshot` json DEFAULT NULL,
  `preflight_snapshot` json NOT NULL,
  `submitted_by_legacy_id` bigint(20) unsigned NOT NULL,
  `submitted_at` timestamp NOT NULL,
  `reviewed_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_reason` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `organization_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_work_order_completions_completion_no_unique` (`completion_no`),
  UNIQUE KEY `erp_work_order_completions_client_command_id_unique` (`client_command_id`),
  KEY `erp_wo_completion_wo_status_idx` (`work_order_id`,`status`),
  CONSTRAINT `erp_wo_completion_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
