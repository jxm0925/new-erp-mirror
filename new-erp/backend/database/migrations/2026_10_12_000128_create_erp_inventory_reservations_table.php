<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_reservations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_reservations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_order_id` bigint(20) unsigned DEFAULT NULL,
  `source_order_line_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_fulfillment_id` bigint(20) unsigned DEFAULT NULL,
  `source_document_no` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_balance_id` bigint(20) unsigned DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reserved_qty` decimal(14,4) NOT NULL,
  `reservation_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `reserved_at` timestamp NULL DEFAULT NULL,
  `reserved_by` bigint(20) unsigned DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `release_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `idempotency_key` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reservation_snapshot` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_inventory_reservations_idempotency_key_unique` (`idempotency_key`),
  KEY `erp_inventory_reservations_source_order_id_index` (`source_order_id`),
  KEY `erp_inventory_reservations_source_order_line_id_index` (`source_order_line_id`),
  KEY `erp_inventory_reservations_item_id_index` (`item_id`),
  KEY `erp_inventory_reservations_inventory_balance_id_index` (`inventory_balance_id`),
  KEY `erp_inventory_reservations_warehouse_id_index` (`warehouse_id`),
  KEY `erp_inventory_reservations_location_id_index` (`location_id`),
  KEY `erp_inventory_reservations_batch_no_index` (`batch_no`),
  KEY `erp_inventory_reservations_reservation_status_index` (`reservation_status`),
  KEY `eir_source_document_no_idx` (`source_document_no`),
  KEY `erp_inv_res_fulfillment_status_idx` (`sales_order_fulfillment_id`,`reservation_status`),
  CONSTRAINT `erp_inv_res_sales_fulfillment_fk` FOREIGN KEY (`sales_order_fulfillment_id`) REFERENCES `erp_sales_order_fulfillments` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
