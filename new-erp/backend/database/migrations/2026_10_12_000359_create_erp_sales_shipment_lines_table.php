<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_shipment_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_shipment_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shipment_id` bigint(20) unsigned NOT NULL,
  `sales_order_line_id` bigint(20) unsigned NOT NULL,
  `sales_order_fulfillment_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_reservation_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `sales_qty` decimal(18,8) NOT NULL,
  `base_qty` decimal(18,8) NOT NULL,
  `unit_cost_snapshot` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `cost_amount_snapshot` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `line_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `serial_snapshot` json DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `packing_routing_snapshot` json DEFAULT NULL,
  `packing_source_snapshot` json DEFAULT NULL,
  `preparation_snapshot` json DEFAULT NULL,
  `quality_round` int(10) unsigned NOT NULL DEFAULT '1',
  `prepared_at` timestamp NULL DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `replaces_line_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_sales_shipment_lines_sales_order_line_id_foreign` (`sales_order_line_id`),
  KEY `erp_sales_shipment_lines_sales_order_fulfillment_id_foreign` (`sales_order_fulfillment_id`),
  KEY `erp_sales_shipment_lines_inventory_reservation_id_foreign` (`inventory_reservation_id`),
  KEY `erp_sales_shipment_lines_item_id_foreign` (`item_id`),
  KEY `erp_sales_shipment_lines_warehouse_id_foreign` (`warehouse_id`),
  KEY `erp_sales_shipment_lines_location_id_foreign` (`location_id`),
  KEY `erp_sales_shipment_lines_unit_id_foreign` (`unit_id`),
  KEY `erp_sales_shipment_line_order_idx` (`shipment_id`,`sales_order_line_id`),
  KEY `erp_sales_shipment_lines_line_status_index` (`line_status`),
  CONSTRAINT `erp_sales_shipment_lines_inventory_reservation_id_foreign` FOREIGN KEY (`inventory_reservation_id`) REFERENCES `erp_inventory_reservations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_shipment_lines_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_sales_shipment_lines_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_sales_shipment_lines_sales_order_fulfillment_id_foreign` FOREIGN KEY (`sales_order_fulfillment_id`) REFERENCES `erp_sales_order_fulfillments` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_shipment_lines_sales_order_line_id_foreign` FOREIGN KEY (`sales_order_line_id`) REFERENCES `erp_sales_order_lines` (`id`),
  CONSTRAINT `erp_sales_shipment_lines_shipment_id_foreign` FOREIGN KEY (`shipment_id`) REFERENCES `erp_sales_shipments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_sales_shipment_lines_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_shipment_lines_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
