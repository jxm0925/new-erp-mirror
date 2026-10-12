<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_supplier_item_stats';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_supplier_item_stats` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `total_order_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `total_received_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `last_price` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `avg_price` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `last_receipt_at` timestamp NULL DEFAULT NULL,
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
  `total_order_count` int(10) unsigned NOT NULL DEFAULT '0',
  `total_order_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `total_receipt_count` int(10) unsigned NOT NULL DEFAULT '0',
  `on_time_receipt_count` int(10) unsigned NOT NULL DEFAULT '0',
  `total_qualified_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `total_unqualified_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `return_count` int(10) unsigned NOT NULL DEFAULT '0',
  `total_return_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `on_time_rate` decimal(8,4) DEFAULT NULL,
  `qualified_rate` decimal(8,4) DEFAULT NULL,
  `return_rate` decimal(8,4) DEFAULT NULL,
  `last_return_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_supplier_item_stats_unique` (`supplier_id`,`item_id`),
  UNIQUE KEY `erp_supplier_item_stats_legacy_unique` (`legacy_system`,`legacy_table`,`legacy_id`),
  KEY `erp_supplier_item_stats_item_id_foreign` (`item_id`),
  CONSTRAINT `erp_supplier_item_stats_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_supplier_item_stats_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
