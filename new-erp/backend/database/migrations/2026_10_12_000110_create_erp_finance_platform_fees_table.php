<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_finance_platform_fees';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_finance_platform_fees` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `fee_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `finance_account_id` bigint(20) unsigned NOT NULL,
  `cash_document_id` bigint(20) unsigned DEFAULT NULL,
  `transfer_id` bigint(20) unsigned DEFAULT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(18,4) NOT NULL,
  `base_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `exchange_rate_id` bigint(20) unsigned DEFAULT NULL,
  `exchange_rate` decimal(24,10) NOT NULL DEFAULT '1.0000000000',
  `base_amount` decimal(18,4) NOT NULL,
  `fee_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'platform',
  `business_date` date NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmed',
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_finance_platform_fees_fee_no_unique` (`fee_no`),
  KEY `erp_finance_platform_fees_cash_document_id_foreign` (`cash_document_id`),
  KEY `erp_finance_platform_fees_transfer_id_foreign` (`transfer_id`),
  KEY `erp_finance_platform_fees_exchange_rate_id_foreign` (`exchange_rate_id`),
  KEY `erp_fin_platform_fee_account_idx` (`finance_account_id`,`status`,`business_date`),
  KEY `erp_finance_platform_fees_fee_type_index` (`fee_type`),
  KEY `erp_finance_platform_fees_business_date_index` (`business_date`),
  KEY `erp_finance_platform_fees_status_index` (`status`),
  CONSTRAINT `erp_finance_platform_fees_cash_document_id_foreign` FOREIGN KEY (`cash_document_id`) REFERENCES `erp_finance_cash_documents` (`id`),
  CONSTRAINT `erp_finance_platform_fees_exchange_rate_id_foreign` FOREIGN KEY (`exchange_rate_id`) REFERENCES `erp_finance_exchange_rates` (`id`),
  CONSTRAINT `erp_finance_platform_fees_finance_account_id_foreign` FOREIGN KEY (`finance_account_id`) REFERENCES `erp_finance_accounts` (`id`),
  CONSTRAINT `erp_finance_platform_fees_transfer_id_foreign` FOREIGN KEY (`transfer_id`) REFERENCES `erp_finance_account_transfers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
