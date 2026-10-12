<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_finance_allocations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_finance_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cash_document_id` bigint(20) unsigned NOT NULL,
  `source_business_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_document_id` bigint(20) unsigned NOT NULL,
  `source_document_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_line_id` bigint(20) unsigned DEFAULT NULL,
  `party_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `party_id` bigint(20) unsigned NOT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cash_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `cash_allocated_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `business_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `business_allocated_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `base_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `exchange_rate_id` bigint(20) unsigned DEFAULT NULL,
  `allocation_exchange_rate` decimal(24,10) NOT NULL DEFAULT '1.0000000000',
  `exchange_rate_date` date DEFAULT NULL,
  `exchange_rate_source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_amount_snapshot` decimal(18,4) NOT NULL,
  `allocated_amount` decimal(18,4) NOT NULL,
  `base_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `reversal_of_id` bigint(20) unsigned DEFAULT NULL,
  `allocated_by` bigint(20) unsigned DEFAULT NULL,
  `allocated_at` timestamp NOT NULL,
  `reversed_by` bigint(20) unsigned DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `reversal_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idempotency_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_finance_allocations_idempotency_key_unique` (`idempotency_key`),
  KEY `erp_finance_allocations_cash_document_id_foreign` (`cash_document_id`),
  KEY `erp_finance_allocations_reversal_of_id_foreign` (`reversal_of_id`),
  KEY `erp_fin_alloc_source_status_idx` (`source_business_type`,`source_document_id`,`status`),
  KEY `erp_fin_alloc_party_status_idx` (`party_type`,`party_id`,`status`),
  KEY `erp_finance_allocations_source_business_type_index` (`source_business_type`),
  KEY `erp_finance_allocations_status_index` (`status`),
  KEY `erp_finance_allocations_exchange_rate_id_foreign` (`exchange_rate_id`),
  CONSTRAINT `erp_finance_allocations_cash_document_id_foreign` FOREIGN KEY (`cash_document_id`) REFERENCES `erp_finance_cash_documents` (`id`),
  CONSTRAINT `erp_finance_allocations_exchange_rate_id_foreign` FOREIGN KEY (`exchange_rate_id`) REFERENCES `erp_finance_exchange_rates` (`id`),
  CONSTRAINT `erp_finance_allocations_reversal_of_id_foreign` FOREIGN KEY (`reversal_of_id`) REFERENCES `erp_finance_allocations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
