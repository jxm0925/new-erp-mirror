<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_defect_handlings';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_defect_handlings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `handling_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `receipt_id` bigint(20) unsigned NOT NULL,
  `receipt_item_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `handling_method` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `handling_qty` decimal(14,4) NOT NULL,
  `amount_excl_tax` decimal(18,4) DEFAULT NULL,
  `tax_amount` decimal(18,4) DEFAULT NULL,
  `amount_incl_tax` decimal(18,4) DEFAULT NULL,
  `settlement_effect_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `finance_fact_status` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `handling_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `current_step` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_doc_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_doc_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `replacement_receipt_id` bigint(20) unsigned DEFAULT NULL,
  `defect_reason` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `defect_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `responsible_party` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `result_description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `handled_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `approved_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_purchase_defect_handlings_handling_no_unique` (`handling_no`),
  KEY `erp_purchase_defect_handlings_receipt_id_foreign` (`receipt_id`),
  KEY `erp_purchase_defect_handlings_item_id_foreign` (`item_id`),
  KEY `erp_purchase_defect_handlings_supplier_id_foreign` (`supplier_id`),
  KEY `erp_defect_line_method_idx` (`receipt_item_id`,`handling_method`),
  KEY `erp_defect_status_method_idx` (`handling_status`,`handling_method`),
  KEY `erp_purchase_defect_handlings_replacement_receipt_id_foreign` (`replacement_receipt_id`),
  KEY `erp_purchase_defect_handlings_current_step_index` (`current_step`),
  KEY `epdh_finance_fact_status_idx` (`finance_fact_status`),
  CONSTRAINT `erp_purchase_defect_handlings_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_purchase_defect_handlings_receipt_id_foreign` FOREIGN KEY (`receipt_id`) REFERENCES `erp_purchase_receipts` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_purchase_defect_handlings_receipt_item_id_foreign` FOREIGN KEY (`receipt_item_id`) REFERENCES `erp_purchase_receipt_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_purchase_defect_handlings_replacement_receipt_id_foreign` FOREIGN KEY (`replacement_receipt_id`) REFERENCES `erp_purchase_receipts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_defect_handlings_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
