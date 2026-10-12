<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_price_histories';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_price_histories` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `base_unit_id` bigint(20) unsigned DEFAULT NULL,
  `conversion_factor_snapshot` decimal(18,8) NOT NULL DEFAULT '1.00000000',
  `order_id` bigint(20) unsigned DEFAULT NULL,
  `receipt_id` bigint(20) unsigned DEFAULT NULL,
  `price` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `base_unit_price` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `currency` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `tax_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'tax_included',
  `tax_rate` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `receipt_cost` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `effective_date` date DEFAULT NULL,
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
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_purchase_price_histories_legacy_unique` (`legacy_system`,`legacy_table`,`legacy_id`),
  KEY `erp_purchase_price_histories_supplier_id_foreign` (`supplier_id`),
  KEY `erp_purchase_price_histories_item_id_foreign` (`item_id`),
  KEY `erp_purchase_price_histories_order_id_foreign` (`order_id`),
  KEY `erp_purchase_price_histories_receipt_id_foreign` (`receipt_id`),
  KEY `erp_purchase_price_histories_unit_id_foreign` (`unit_id`),
  KEY `erp_purchase_price_histories_base_unit_id_foreign` (`base_unit_id`),
  CONSTRAINT `erp_purchase_price_histories_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_price_histories_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_purchase_price_histories_order_id_foreign` FOREIGN KEY (`order_id`) REFERENCES `erp_purchase_orders` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_price_histories_receipt_id_foreign` FOREIGN KEY (`receipt_id`) REFERENCES `erp_purchase_receipts` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_purchase_price_histories_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`),
  CONSTRAINT `erp_purchase_price_histories_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
