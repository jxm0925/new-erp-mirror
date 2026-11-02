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
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `confirmation_source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `work_order_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `requested_warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `requested_location_id` bigint(20) unsigned DEFAULT NULL,
  `requested_at` timestamp NULL DEFAULT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unit_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `cost_source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending_confirmation',
  `serial_nos` json DEFAULT NULL,
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `operator_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `operator_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmed_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `confirmed_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `rejected_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `rejected_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `reject_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancelled_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_receipt_no_uq` (`receipt_no`),
  KEY `mfg_receipt_wo_idx` (`work_order_id`),
  KEY `mfg_receipt_status_requested_idx` (`status`,`requested_at`,`id`),
  CONSTRAINT `mfg_receipt_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'mfg_receipt_status_chk' => "(`status` IN ('pending', 'confirmed', 'rejected', 'cancelled'))",
        'mfg_receipt_confirmation_source_chk' => "(`confirmation_source` IN ('manual', 'auto_handoff', 'legacy_posted'))",
    ];
};
