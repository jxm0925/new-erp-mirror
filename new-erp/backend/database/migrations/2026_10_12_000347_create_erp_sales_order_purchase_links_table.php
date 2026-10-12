<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_order_purchase_links';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_order_purchase_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sales_order_id` bigint(20) unsigned NOT NULL,
  `purchase_order_id` bigint(20) unsigned NOT NULL,
  `purchase_order_item_id` bigint(20) unsigned NOT NULL,
  `purchase_qty` decimal(18,8) NOT NULL,
  `contract_amount` decimal(18,4) DEFAULT NULL,
  `currency` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_snapshot` json NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `idempotency_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reversed_by` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reverse_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `so_purchase_link_request_uq` (`idempotency_key`),
  KEY `erp_sales_order_purchase_links_purchase_order_id_foreign` (`purchase_order_id`),
  KEY `so_purchase_link_status_idx` (`sales_order_id`,`status`),
  KEY `so_purchase_link_item_idx` (`purchase_order_item_id`,`status`),
  CONSTRAINT `erp_sales_order_purchase_links_purchase_order_id_foreign` FOREIGN KEY (`purchase_order_id`) REFERENCES `erp_purchase_orders` (`id`),
  CONSTRAINT `erp_sales_order_purchase_links_purchase_order_item_id_foreign` FOREIGN KEY (`purchase_order_item_id`) REFERENCES `erp_purchase_order_items` (`id`),
  CONSTRAINT `erp_sales_order_purchase_links_sales_order_id_foreign` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
