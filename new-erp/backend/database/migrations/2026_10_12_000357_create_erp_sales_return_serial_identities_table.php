<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_return_serial_identities';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_return_serial_identities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sales_return_receipt_item_id` bigint(20) unsigned NOT NULL,
  `sales_return_item_id` bigint(20) unsigned NOT NULL,
  `inventory_serial_id` bigint(20) unsigned NOT NULL,
  `sales_shipment_line_id` bigint(20) unsigned NOT NULL,
  `outbound_transaction_item_id` bigint(20) unsigned NOT NULL,
  `cost_allocation_id` bigint(20) unsigned NOT NULL,
  `inventory_transaction_item_id` bigint(20) unsigned DEFAULT NULL,
  `serial_no_snapshot` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_batch_no` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `disposition` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_cost_snapshot` decimal(18,8) NOT NULL,
  `cost_amount_snapshot` decimal(18,4) NOT NULL,
  `received_by` bigint(20) unsigned DEFAULT NULL,
  `received_at` timestamp NOT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_srsi_outbound_serial_uq` (`outbound_transaction_item_id`,`inventory_serial_id`),
  UNIQUE KEY `erp_srsi_receipt_serial_uq` (`sales_return_receipt_item_id`,`inventory_serial_id`),
  KEY `erp_srsi_serial_fk` (`inventory_serial_id`),
  KEY `erp_srsi_shipment_line_fk` (`sales_shipment_line_id`),
  KEY `erp_srsi_cost_fk` (`cost_allocation_id`),
  KEY `erp_srsi_inbound_fk` (`inventory_transaction_item_id`),
  KEY `erp_srsi_return_disposition_idx` (`sales_return_item_id`,`disposition`),
  CONSTRAINT `erp_srsi_cost_fk` FOREIGN KEY (`cost_allocation_id`) REFERENCES `erp_sales_return_cost_allocations` (`id`),
  CONSTRAINT `erp_srsi_inbound_fk` FOREIGN KEY (`inventory_transaction_item_id`) REFERENCES `erp_inventory_transaction_items` (`id`),
  CONSTRAINT `erp_srsi_outbound_fk` FOREIGN KEY (`outbound_transaction_item_id`) REFERENCES `erp_inventory_transaction_items` (`id`),
  CONSTRAINT `erp_srsi_receipt_line_fk` FOREIGN KEY (`sales_return_receipt_item_id`) REFERENCES `erp_sales_return_receipt_items` (`id`),
  CONSTRAINT `erp_srsi_return_line_fk` FOREIGN KEY (`sales_return_item_id`) REFERENCES `erp_sales_return_items` (`id`),
  CONSTRAINT `erp_srsi_serial_fk` FOREIGN KEY (`inventory_serial_id`) REFERENCES `erp_inventory_serials` (`id`),
  CONSTRAINT `erp_srsi_shipment_line_fk` FOREIGN KEY (`sales_shipment_line_id`) REFERENCES `erp_sales_shipment_lines` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
