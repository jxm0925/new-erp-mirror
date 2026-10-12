<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_order_fulfillments';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_order_fulfillments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sales_order_id` bigint(20) unsigned NOT NULL,
  `sales_order_line_id` bigint(20) unsigned NOT NULL,
  `fulfillment_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `fulfillment_qty` decimal(14,4) NOT NULL,
  `sales_qty` decimal(18,8) DEFAULT NULL,
  `sales_unit_id` bigint(20) unsigned DEFAULT NULL,
  `sales_unit_code_snapshot` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sales_unit_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `fulfillment_factor_snapshot` decimal(18,8) DEFAULT NULL,
  `item_base_qty` decimal(18,8) DEFAULT NULL,
  `item_id` bigint(20) unsigned DEFAULT NULL,
  `base_unit_id` bigint(20) unsigned DEFAULT NULL,
  `base_unit_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inventory_balance_id` bigint(20) unsigned DEFAULT NULL,
  `reservation_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `production_requirement_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_required',
  `demand_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'confirmed',
  `match_snapshot` json DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_sales_order_fulfillments_item_id_foreign` (`item_id`),
  KEY `erp_sales_order_fulfillments_warehouse_id_foreign` (`warehouse_id`),
  KEY `erp_sales_order_fulfillments_location_id_foreign` (`location_id`),
  KEY `erp_so_fulfill_line_fk` (`sales_order_line_id`),
  KEY `erp_so_fulfill_order_type_idx` (`sales_order_id`,`fulfillment_type`),
  KEY `erp_sales_order_fulfillments_base_unit_id_foreign` (`base_unit_id`),
  CONSTRAINT `erp_sales_order_fulfillments_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_order_fulfillments_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_order_fulfillments_location_id_foreign` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_sales_order_fulfillments_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_so_fulfill_line_fk` FOREIGN KEY (`sales_order_line_id`) REFERENCES `erp_sales_order_lines` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_so_fulfill_order_fk` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
