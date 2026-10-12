<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_settlement_sources';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_settlement_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_document_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_document_id` bigint(20) unsigned NOT NULL,
  `source_document_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_receipt_id` bigint(20) unsigned NOT NULL,
  `source_line_id` bigint(20) unsigned NOT NULL,
  `purchase_order_id` bigint(20) unsigned DEFAULT NULL,
  `purchase_order_no_snapshot` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `supplier_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `base_currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `exchange_rate_id` bigint(20) unsigned DEFAULT NULL,
  `business_exchange_rate` decimal(24,10) NOT NULL DEFAULT '1.0000000000',
  `exchange_rate_date` date DEFAULT NULL,
  `exchange_rate_source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_date` date NOT NULL,
  `original_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `base_original_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `eligible_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `base_eligible_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `frozen_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `base_frozen_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `ap_offset_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `base_ap_offset_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `allocated_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `base_allocated_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unallocated_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `base_unallocated_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `invoice_matched_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `invoice_unmatched_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `source_version` int(10) unsigned NOT NULL DEFAULT '1',
  `eligible_at` timestamp NULL DEFAULT NULL,
  `frozen_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_purchase_settlement_source_version_unique` (`source_type`,`source_document_type`,`source_document_id`,`source_line_id`,`source_version`),
  KEY `erp_purchase_settlement_sources_source_receipt_id_foreign` (`source_receipt_id`),
  KEY `erp_purchase_settlement_sources_source_line_id_foreign` (`source_line_id`),
  KEY `erp_purchase_settlement_supplier_status_idx` (`supplier_id`,`currency`,`status`,`business_date`),
  KEY `erp_purchase_settlement_order_status_idx` (`purchase_order_id`,`status`),
  KEY `erp_purchase_settlement_sources_status_index` (`status`),
  KEY `erp_purchase_settlement_sources_exchange_rate_id_foreign` (`exchange_rate_id`),
  CONSTRAINT `erp_purchase_settlement_sources_exchange_rate_id_foreign` FOREIGN KEY (`exchange_rate_id`) REFERENCES `erp_finance_exchange_rates` (`id`),
  CONSTRAINT `erp_purchase_settlement_sources_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `erp_purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_settlement_sources_source_line_id_foreign` FOREIGN KEY (`source_line_id`) REFERENCES `erp_purchase_receipt_items` (`id`),
  CONSTRAINT `erp_purchase_settlement_sources_source_receipt_id_foreign` FOREIGN KEY (`source_receipt_id`) REFERENCES `erp_purchase_receipts` (`id`),
  CONSTRAINT `erp_purchase_settlement_sources_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
