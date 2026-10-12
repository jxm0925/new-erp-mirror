<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_finance_account_transfers';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_finance_account_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_account_id` bigint(20) unsigned NOT NULL,
  `target_account_id` bigint(20) unsigned NOT NULL,
  `source_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_amount` decimal(18,4) NOT NULL,
  `exchange_source_amount` decimal(18,4) DEFAULT NULL,
  `target_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_amount` decimal(18,4) NOT NULL,
  `gross_target_amount` decimal(18,4) DEFAULT NULL,
  `net_target_amount` decimal(18,4) DEFAULT NULL,
  `base_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `actual_exchange_rate` decimal(24,10) NOT NULL DEFAULT '1.0000000000',
  `reference_exchange_rate` decimal(24,10) DEFAULT NULL,
  `reference_difference_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `source_base_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `target_base_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `realized_fx_gain_loss` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `fee_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `fee_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fee_bearer` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'source',
  `fee_account_id` bigint(20) unsigned DEFAULT NULL,
  `fee_base_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `business_date` date NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `confirmed_by` bigint(20) unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_finance_account_transfers_transfer_no_unique` (`transfer_no`),
  KEY `erp_fin_transfer_source_idx` (`source_account_id`,`status`,`business_date`),
  KEY `erp_fin_transfer_target_idx` (`target_account_id`,`status`,`business_date`),
  KEY `erp_finance_account_transfers_business_date_index` (`business_date`),
  KEY `erp_finance_account_transfers_status_index` (`status`),
  KEY `erp_finance_account_transfers_fee_account_id_foreign` (`fee_account_id`),
  CONSTRAINT `erp_finance_account_transfers_fee_account_id_foreign` FOREIGN KEY (`fee_account_id`) REFERENCES `erp_finance_accounts` (`id`),
  CONSTRAINT `erp_finance_account_transfers_source_account_id_foreign` FOREIGN KEY (`source_account_id`) REFERENCES `erp_finance_accounts` (`id`),
  CONSTRAINT `erp_finance_account_transfers_target_account_id_foreign` FOREIGN KEY (`target_account_id`) REFERENCES `erp_finance_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
