<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 生产退料明细：每行对应一条原收料出库流水（source_transaction_item_id），按原批次、原单价冲回；
// 按编号退的每个编号一行（qty=1），记下从哪台拆下；warehouse_id/location_id 为实际退入库位，审核通过时写入。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_material_return_lines';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_material_return_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_id` bigint(20) unsigned NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `material_id` bigint(20) unsigned NOT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `component_item_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_name` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `issue_id` bigint(20) unsigned NOT NULL,
  `source_transaction_item_id` bigint(20) unsigned NOT NULL,
  `source_warehouse_id` bigint(20) unsigned NOT NULL,
  `source_location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `inventory_serial_id` bigint(20) unsigned DEFAULT NULL,
  `serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `unit_serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `component_id` bigint(20) unsigned DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `unit_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mfg_return_line_return_idx` (`return_id`),
  KEY `mfg_return_line_material_idx` (`work_order_id`,`material_id`),
  KEY `mfg_return_line_source_idx` (`source_transaction_item_id`),
  KEY `mfg_return_line_serial_idx` (`inventory_serial_id`),
  CONSTRAINT `mfg_return_line_return_fk` FOREIGN KEY (`return_id`) REFERENCES `erp_mfg_material_returns` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
