<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_finance_cash_purchase_allocations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_finance_cash_purchase_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cash_document_id` bigint(20) unsigned NOT NULL,
  `purchase_order_id` bigint(20) unsigned NOT NULL,
  `payment_plan_id` bigint(20) unsigned DEFAULT NULL,
  `allocation_version` int(10) unsigned NOT NULL,
  `purpose_key` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `direction` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL,
  `amount` decimal(18,4) NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `purchase_order_no_snapshot` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `plan_title_snapshot` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trigger_type_snapshot` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sequence_no_snapshot` int(10) unsigned DEFAULT NULL,
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_cash_purchase_version_purpose_uq` (`cash_document_id`,`allocation_version`,`purpose_key`),
  KEY `erp_cash_purchase_order_status_idx` (`purchase_order_id`,`status`,`cash_document_id`),
  KEY `erp_cash_purchase_plan_status_idx` (`payment_plan_id`,`status`),
  CONSTRAINT `erp_finance_cash_purchase_allocations_cash_document_id_foreign` FOREIGN KEY (`cash_document_id`) REFERENCES `erp_finance_cash_documents` (`id`),
  CONSTRAINT `erp_finance_cash_purchase_allocations_payment_plan_id_foreign` FOREIGN KEY (`payment_plan_id`) REFERENCES `erp_purchase_payment_plan_items` (`id`),
  CONSTRAINT `erp_finance_cash_purchase_allocations_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `erp_purchase_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
