<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_finance_invoice_allocations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_finance_invoice_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `invoice_id` bigint(20) unsigned NOT NULL,
  `source_business_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_document_id` bigint(20) unsigned NOT NULL,
  `source_document_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_amount_snapshot` decimal(18,4) NOT NULL,
  `allocated_amount` decimal(18,4) NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `reversal_of_id` bigint(20) unsigned DEFAULT NULL,
  `idempotency_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_finance_invoice_allocations_idempotency_key_unique` (`idempotency_key`),
  KEY `erp_finance_invoice_allocations_invoice_id_foreign` (`invoice_id`),
  KEY `erp_finance_invoice_allocations_reversal_of_id_foreign` (`reversal_of_id`),
  KEY `erp_fin_inv_alloc_source_idx` (`source_business_type`,`source_document_id`,`status`),
  CONSTRAINT `erp_finance_invoice_allocations_invoice_id_foreign` FOREIGN KEY (`invoice_id`) REFERENCES `erp_finance_invoices` (`id`),
  CONSTRAINT `erp_finance_invoice_allocations_reversal_of_id_foreign` FOREIGN KEY (`reversal_of_id`) REFERENCES `erp_finance_invoice_allocations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
