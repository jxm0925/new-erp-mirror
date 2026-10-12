<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_finance_cash_documents';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_finance_cash_documents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `direction` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `party_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `party_id` bigint(20) unsigned NOT NULL,
  `party_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_date` date NOT NULL,
  `finance_account_id` bigint(20) unsigned NOT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `base_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `exchange_rate_id` bigint(20) unsigned DEFAULT NULL,
  `business_exchange_rate` decimal(24,10) NOT NULL DEFAULT '1.0000000000',
  `exchange_rate_date` date DEFAULT NULL,
  `exchange_rate_source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `amount` decimal(18,4) NOT NULL,
  `platform_fee_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `platform_fee_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform_fee_account_id` bigint(20) unsigned DEFAULT NULL,
  `platform_fee_base_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `platform_fee_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `payment_method` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `payment_method_id` bigint(20) unsigned DEFAULT NULL,
  `payment_method_snapshot` json DEFAULT NULL,
  `external_reference_no` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator_id` bigint(20) unsigned DEFAULT NULL,
  `operator_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `confirmed_by` bigint(20) unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `voided_by` bigint(20) unsigned DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `void_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reversal_of_id` bigint(20) unsigned DEFAULT NULL,
  `idempotency_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lock_version` int(10) unsigned NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `draft_allocation_items` json DEFAULT NULL,
  `purchase_order_allocations` json DEFAULT NULL,
  `purchase_order_allocation_version` int(10) unsigned NOT NULL DEFAULT '0',
  `purchase_order_allocation_reason` text COLLATE utf8mb4_unicode_ci,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_finance_cash_documents_document_no_unique` (`document_no`),
  UNIQUE KEY `erp_finance_cash_documents_idempotency_key_unique` (`idempotency_key`),
  KEY `erp_finance_cash_documents_finance_account_id_foreign` (`finance_account_id`),
  KEY `erp_finance_cash_documents_reversal_of_id_foreign` (`reversal_of_id`),
  KEY `erp_fin_cash_party_date_idx` (`party_type`,`party_id`,`business_date`),
  KEY `erp_fin_cash_dir_status_date_idx` (`direction`,`status`,`business_date`),
  KEY `erp_finance_cash_documents_direction_index` (`direction`),
  KEY `erp_finance_cash_documents_party_type_index` (`party_type`),
  KEY `erp_finance_cash_documents_party_id_index` (`party_id`),
  KEY `erp_finance_cash_documents_business_date_index` (`business_date`),
  KEY `erp_finance_cash_documents_external_reference_no_index` (`external_reference_no`),
  KEY `erp_finance_cash_documents_status_index` (`status`),
  KEY `erp_finance_cash_documents_exchange_rate_id_foreign` (`exchange_rate_id`),
  KEY `erp_finance_cash_documents_platform_fee_account_id_foreign` (`platform_fee_account_id`),
  KEY `erp_finance_cash_documents_payment_method_id_foreign` (`payment_method_id`),
  CONSTRAINT `erp_finance_cash_documents_exchange_rate_id_foreign` FOREIGN KEY (`exchange_rate_id`) REFERENCES `erp_finance_exchange_rates` (`id`),
  CONSTRAINT `erp_finance_cash_documents_finance_account_id_foreign` FOREIGN KEY (`finance_account_id`) REFERENCES `erp_finance_accounts` (`id`),
  CONSTRAINT `erp_finance_cash_documents_payment_method_id_foreign` FOREIGN KEY (`payment_method_id`) REFERENCES `erp_payment_methods` (`id`),
  CONSTRAINT `erp_finance_cash_documents_platform_fee_account_id_foreign` FOREIGN KEY (`platform_fee_account_id`) REFERENCES `erp_finance_accounts` (`id`),
  CONSTRAINT `erp_finance_cash_documents_reversal_of_id_foreign` FOREIGN KEY (`reversal_of_id`) REFERENCES `erp_finance_cash_documents` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
