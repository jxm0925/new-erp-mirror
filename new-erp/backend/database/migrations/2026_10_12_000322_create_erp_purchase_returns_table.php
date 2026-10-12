<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_returns';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_returns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `return_scope` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_receipt_id` bigint(20) unsigned NOT NULL,
  `source_order_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `currency_snapshot` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `settlement_effect_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `return_date` date NOT NULL,
  `return_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `audit_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `stock_post_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_required',
  `return_reason` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount_excl_tax` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `tax_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `amount_incl_tax` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `settlement_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `finance_fact_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `submitted_by` bigint(20) unsigned DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `approved_by` bigint(20) unsigned DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `closed_by` bigint(20) unsigned DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `management_scope` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_purchase_returns_return_no_unique` (`return_no`),
  KEY `erp_purchase_returns_supplier_id_foreign` (`supplier_id`),
  KEY `erp_pr_status_audit_idx` (`return_status`,`audit_status`),
  KEY `erp_pr_receipt_scope_idx` (`source_receipt_id`,`return_scope`),
  KEY `eprt_source_order_idx` (`source_order_id`),
  KEY `eprt_settlement_effect_idx` (`settlement_effect_type`),
  KEY `eprt_finance_fact_status_idx` (`finance_fact_status`),
  KEY `erp_purchase_returns_management_scope_idx` (`management_scope`),
  CONSTRAINT `erp_purchase_returns_source_receipt_id_foreign` FOREIGN KEY (`source_receipt_id`) REFERENCES `erp_purchase_receipts` (`id`),
  CONSTRAINT `erp_purchase_returns_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
        'erp_purchase_returns_scope_ck' => 'management_scope IS NULL OR management_scope IN (\'factory\',\'office\')',
    ];
};
