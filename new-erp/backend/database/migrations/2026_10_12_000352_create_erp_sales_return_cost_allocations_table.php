<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_return_cost_allocations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_return_cost_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sales_return_item_id` bigint(20) unsigned NOT NULL,
  `sales_shipment_id` bigint(20) unsigned NOT NULL,
  `sales_shipment_line_id` bigint(20) unsigned NOT NULL,
  `outbound_transaction_item_id` bigint(20) unsigned NOT NULL,
  `allocated_base_qty` decimal(18,8) NOT NULL,
  `posted_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `unit_cost_snapshot` decimal(18,8) NOT NULL,
  `cost_amount_snapshot` decimal(18,4) NOT NULL,
  `allocation_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'reserved',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_srca_shipment_fk` (`sales_shipment_id`),
  KEY `erp_srca_shipment_line_fk` (`sales_shipment_line_id`),
  KEY `erp_srca_return_item_status_idx` (`sales_return_item_id`,`allocation_status`),
  KEY `erp_srca_outbound_item_idx` (`outbound_transaction_item_id`),
  KEY `erp_sales_return_cost_allocations_allocation_status_index` (`allocation_status`),
  CONSTRAINT `erp_srca_outbound_fk` FOREIGN KEY (`outbound_transaction_item_id`) REFERENCES `erp_inventory_transaction_items` (`id`),
  CONSTRAINT `erp_srca_return_item_fk` FOREIGN KEY (`sales_return_item_id`) REFERENCES `erp_sales_return_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_srca_shipment_fk` FOREIGN KEY (`sales_shipment_id`) REFERENCES `erp_sales_shipments` (`id`),
  CONSTRAINT `erp_srca_shipment_line_fk` FOREIGN KEY (`sales_shipment_line_id`) REFERENCES `erp_sales_shipment_lines` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
