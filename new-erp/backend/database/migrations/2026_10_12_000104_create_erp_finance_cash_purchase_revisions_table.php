<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_finance_cash_purchase_revisions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_finance_cash_purchase_revisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cash_document_id` bigint(20) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `idempotency_key` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` text COLLATE utf8mb4_unicode_ci,
  `before_snapshot` json NOT NULL,
  `after_snapshot` json NOT NULL,
  `operator_id` bigint(20) unsigned DEFAULT NULL,
  `operator_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_cash_purchase_revision_version_uq` (`cash_document_id`,`version`),
  UNIQUE KEY `erp_cash_purchase_revision_key_uq` (`idempotency_key`),
  CONSTRAINT `erp_finance_cash_purchase_revisions_cash_document_id_foreign` FOREIGN KEY (`cash_document_id`) REFERENCES `erp_finance_cash_documents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
