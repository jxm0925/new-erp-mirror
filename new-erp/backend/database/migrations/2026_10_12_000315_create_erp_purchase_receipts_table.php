<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_receipts';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `receipt_date` date DEFAULT NULL,
  `receipt_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `confirm_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `stock_post_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `has_stock_items` tinyint(1) NOT NULL DEFAULT '1',
  `fulfillment_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `total_receipt_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `total_qualified_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `total_unqualified_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `physical_received_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `contract_fulfilled_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `replacement_received_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `total_amount` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `amount_excl_tax` decimal(18,4) DEFAULT NULL,
  `tax_amount_snapshot` decimal(18,4) DEFAULT NULL,
  `amount_incl_tax` decimal(18,4) DEFAULT NULL,
  `qualified_payable_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `quality_hold_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `rejected_claim_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `settlement_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `inventory_cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `freight_amount_snapshot` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `other_purchase_cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `finance_fact_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `settlement_mode` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `currency_snapshot` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_mode_snapshot` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tax_amount` decimal(14,4) NOT NULL DEFAULT '0.0000',
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
  `management_scope` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_purchase_receipts_receipt_no_unique` (`receipt_no`),
  UNIQUE KEY `erp_purchase_receipts_legacy_unique` (`legacy_system`,`legacy_table`,`legacy_id`),
  KEY `erp_purchase_receipts_order_id_foreign` (`order_id`),
  KEY `erp_purchase_receipts_supplier_id_foreign` (`supplier_id`),
  KEY `epr_has_stock_items_idx` (`has_stock_items`),
  KEY `epr_fulfillment_status_idx` (`fulfillment_status`),
  KEY `epr_finance_fact_status_idx` (`finance_fact_status`),
  KEY `erp_purchase_receipts_management_scope_idx` (`management_scope`),
  CONSTRAINT `erp_purchase_receipts_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `erp_purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_receipts_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
        'erp_purchase_receipts_scope_ck' => 'management_scope IS NULL OR management_scope IN (\'factory\',\'office\')',
    ];
};
