<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_orders';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_order_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `plan_id` bigint(20) unsigned DEFAULT NULL,
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `order_date` date DEFAULT NULL,
  `expected_arrival_date` date DEFAULT NULL,
  `currency` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `base_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `exchange_rate_id` bigint(20) unsigned DEFAULT NULL,
  `business_exchange_rate` decimal(24,10) NOT NULL DEFAULT '1.0000000000',
  `exchange_rate_date` date DEFAULT NULL,
  `exchange_rate_source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tax_included',
  `settlement_method` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_method` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `purchase_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `audit_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `receipt_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_received',
  `total_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `total_amount` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `amount_excl_tax` decimal(18,4) DEFAULT NULL,
  `base_amount_excl_tax` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `amount_incl_tax` decimal(18,4) DEFAULT NULL,
  `base_amount_incl_tax` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `tax_amount` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `freight_amount` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `other_purchase_cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `finance_fact_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `legacy_system` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_table` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_id` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_code` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `legacy_status` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `data_source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `sync_batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_synced_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `payment_plan_version` int(10) unsigned NOT NULL DEFAULT '0',
  `management_scope` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_purchase_orders_purchase_order_no_unique` (`purchase_order_no`),
  UNIQUE KEY `erp_purchase_orders_legacy_unique` (`legacy_system`,`legacy_table`,`legacy_id`),
  KEY `erp_purchase_orders_plan_id_foreign` (`plan_id`),
  KEY `erp_purchase_orders_supplier_id_foreign` (`supplier_id`),
  KEY `epo_finance_fact_status_idx` (`finance_fact_status`),
  KEY `erp_purchase_orders_exchange_rate_id_foreign` (`exchange_rate_id`),
  KEY `erp_purchase_orders_management_scope_idx` (`management_scope`),
  CONSTRAINT `erp_purchase_orders_exchange_rate_id_foreign` FOREIGN KEY (`exchange_rate_id`) REFERENCES `erp_finance_exchange_rates` (`id`),
  CONSTRAINT `erp_purchase_orders_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `erp_purchase_plans` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
        'erp_purchase_orders_scope_ck' => 'management_scope IS NULL OR management_scope IN (\'factory\',\'office\')',
    ];
};
