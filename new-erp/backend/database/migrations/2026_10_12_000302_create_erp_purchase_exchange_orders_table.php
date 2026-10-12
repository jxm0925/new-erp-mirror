<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_exchange_orders';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_exchange_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `exchange_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `defect_handling_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_quality_event_id` bigint(20) unsigned DEFAULT NULL,
  `source_receipt_id` bigint(20) unsigned NOT NULL,
  `source_receipt_item_id` bigint(20) unsigned NOT NULL,
  `purchase_order_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `replacement_receipt_id` bigint(20) unsigned DEFAULT NULL,
  `exchange_base_qty` decimal(18,8) NOT NULL,
  `replacement_received_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `contract_fulfilled_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `base_unit_name_snapshot` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_contract_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `original_payable_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `exchange_additional_payable_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `replacement_payable_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `currency_snapshot` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `finance_fact_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `replacement_inventory_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `exchange_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'processing',
  `current_step` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_original_return',
  `source_scope` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'receipt_inspection',
  `return_warehouse_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `return_location_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `returned_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `return_logistics_company` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `return_tracking_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `supplier_receiver` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `supplier_received_at` timestamp NULL DEFAULT NULL,
  `replacement_shipped_date` date DEFAULT NULL,
  `replacement_logistics_company` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `replacement_tracking_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `replacement_expected_date` date DEFAULT NULL,
  `replacement_shipped_at` timestamp NULL DEFAULT NULL,
  `replacement_accepted_at` timestamp NULL DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `management_scope` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_purchase_exchange_orders_exchange_no_unique` (`exchange_no`),
  UNIQUE KEY `erp_purchase_exchange_orders_defect_handling_id_unique` (`defect_handling_id`),
  UNIQUE KEY `peo_quality_event_uq` (`inventory_quality_event_id`),
  KEY `erp_purchase_exchange_orders_source_receipt_item_id_foreign` (`source_receipt_item_id`),
  KEY `erp_purchase_exchange_orders_purchase_order_id_foreign` (`purchase_order_id`),
  KEY `erp_purchase_exchange_orders_item_id_foreign` (`item_id`),
  KEY `erp_purchase_exchange_orders_replacement_receipt_id_foreign` (`replacement_receipt_id`),
  KEY `erp_peo_supplier_step_idx` (`supplier_id`,`current_step`),
  KEY `erp_peo_receipt_item_idx` (`source_receipt_id`,`item_id`),
  KEY `erp_purchase_exchange_orders_exchange_status_index` (`exchange_status`),
  KEY `erp_purchase_exchange_orders_current_step_index` (`current_step`),
  KEY `erp_purchase_exchange_orders_source_scope_index` (`source_scope`),
  KEY `epeo_finance_fact_status_idx` (`finance_fact_status`),
  KEY `erp_purchase_exchange_orders_management_scope_idx` (`management_scope`),
  CONSTRAINT `erp_purchase_exchange_orders_defect_handling_id_foreign` FOREIGN KEY (`defect_handling_id`) REFERENCES `erp_purchase_defect_handlings` (`id`),
  CONSTRAINT `erp_purchase_exchange_orders_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_purchase_exchange_orders_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `erp_purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_exchange_orders_replacement_receipt_id_foreign` FOREIGN KEY (`replacement_receipt_id`) REFERENCES `erp_purchase_receipts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_exchange_orders_source_receipt_id_foreign` FOREIGN KEY (`source_receipt_id`) REFERENCES `erp_purchase_receipts` (`id`),
  CONSTRAINT `erp_purchase_exchange_orders_source_receipt_item_id_foreign` FOREIGN KEY (`source_receipt_item_id`) REFERENCES `erp_purchase_receipt_items` (`id`),
  CONSTRAINT `erp_purchase_exchange_orders_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`),
  CONSTRAINT `peo_quality_event_fk` FOREIGN KEY (`inventory_quality_event_id`) REFERENCES `erp_inventory_quality_events` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
        'erp_purchase_exchange_orders_scope_ck' => 'management_scope IS NULL OR management_scope IN (\'factory\',\'office\')',
    ];
};
