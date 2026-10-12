<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_finance_account_movements';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_finance_account_movements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `finance_account_id` bigint(20) unsigned NOT NULL,
  `movement_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint(20) unsigned NOT NULL,
  `direction` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_amount` decimal(18,4) NOT NULL,
  `base_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_amount` decimal(18,4) NOT NULL,
  `business_date` date NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmed',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_fin_account_movement_source_unique` (`source_type`,`source_id`,`direction`),
  KEY `erp_fin_account_movement_balance_idx` (`finance_account_id`,`status`,`business_date`),
  KEY `erp_finance_account_movements_movement_type_index` (`movement_type`),
  KEY `erp_finance_account_movements_business_date_index` (`business_date`),
  KEY `erp_finance_account_movements_status_index` (`status`),
  CONSTRAINT `erp_finance_account_movements_finance_account_id_foreign` FOREIGN KEY (`finance_account_id`) REFERENCES `erp_finance_accounts` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
