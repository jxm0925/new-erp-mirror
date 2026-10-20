<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 成品入库：总装合格的台由仓库确认入库，按暂估成本记库存，状态为待试水。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_receipts';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT '0',
  `unit_cost` decimal(18,4) NOT NULL DEFAULT '0',
  `cost_amount` decimal(18,4) NOT NULL DEFAULT '0',
  `cost_source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'material_estimate',
  `serial_nos` json DEFAULT NULL,
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `operator_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `operator_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_receipt_no_uq` (`receipt_no`),
  KEY `mfg_receipt_wo_idx` (`work_order_id`),
  CONSTRAINT `mfg_receipt_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
