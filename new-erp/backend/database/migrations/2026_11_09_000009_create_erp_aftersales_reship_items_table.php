<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 补发明细：计划阶段只确定商品+数量（可挂订单行/处理明细），出库时再落库存来源与成本。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_aftersales_reship_items';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_aftersales_reship_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reship_id` bigint(20) unsigned NOT NULL,
  `action_item_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_line_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `item_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qty` decimal(20,8) NOT NULL DEFAULT '0.00000000',
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `batch_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inventory_balance_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_serial_id` bigint(20) unsigned DEFAULT NULL,
  `serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_cost` decimal(20,6) DEFAULT NULL,
  `cost_amount` decimal(20,4) DEFAULT NULL,
  `transaction_item_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_as_reship_items_reship_idx` (`reship_id`),
  KEY `erp_as_reship_items_action_item_idx` (`action_item_id`),
  KEY `erp_as_reship_items_line_idx` (`sales_order_line_id`),
  KEY `erp_as_reship_items_item_idx` (`item_id`),
  KEY `erp_as_reship_items_serial_idx` (`inventory_serial_id`),
  CONSTRAINT `erp_as_reship_items_reship_fk` FOREIGN KEY (`reship_id`) REFERENCES `erp_aftersales_reships` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_as_reship_items_action_item_fk` FOREIGN KEY (`action_item_id`) REFERENCES `erp_aftersales_action_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
