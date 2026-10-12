<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_approval_form_submissions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_approval_form_submissions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `submission_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `form_template_id` bigint(20) unsigned NOT NULL,
  `form_version_id` bigint(20) unsigned NOT NULL,
  `subject` varchar(240) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `submission_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `form_data` json NOT NULL,
  `submitted_by` bigint(20) unsigned DEFAULT NULL,
  `submitted_by_name` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_approval_form_submissions_submission_no_unique` (`submission_no`),
  KEY `erp_approval_form_submissions_form_template_id_foreign` (`form_template_id`),
  KEY `erp_approval_form_submissions_form_version_id_foreign` (`form_version_id`),
  KEY `erp_approval_form_submissions_submission_status_index` (`submission_status`),
  KEY `erp_approval_form_submissions_submitted_by_index` (`submitted_by`),
  KEY `erp_approval_form_submissions_submitted_at_index` (`submitted_at`),
  CONSTRAINT `erp_approval_form_submissions_form_template_id_foreign` FOREIGN KEY (`form_template_id`) REFERENCES `erp_approval_form_templates` (`id`),
  CONSTRAINT `erp_approval_form_submissions_form_version_id_foreign` FOREIGN KEY (`form_version_id`) REFERENCES `erp_approval_form_versions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
