<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

/** 余料入库申请、确认及独立报废事实；历史已结算开料不回建申请。 */
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_cutting_remnant_receipts';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_cutting_remnant_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cutting_batch_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `requested_lines` json NOT NULL,
  `confirmed_lines` json DEFAULT NULL,
  `disposition` json DEFAULT NULL,
  `requested_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `requested_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requested_at` timestamp NULL DEFAULT NULL,
  `confirmed_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `confirmed_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `rejected_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `rejected_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `reject_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancelled_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_remnant_receipt_no_uq` (`receipt_no`),
  KEY `mfg_remnant_receipt_batch_idx` (`cutting_batch_id`,`status`),
  KEY `mfg_remnant_receipt_pending_idx` (`status`,`requested_at`,`id`),
  KEY `mfg_remnant_receipt_tx_fk` (`inventory_transaction_id`),
  CONSTRAINT `mfg_remnant_receipt_batch_fk` FOREIGN KEY (`cutting_batch_id`) REFERENCES `erp_mfg_cutting_batches` (`id`),
  CONSTRAINT `mfg_remnant_receipt_tx_fk` FOREIGN KEY (`inventory_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'mfg_remnant_receipt_status_chk' => "(`status` IN ('pending', 'confirmed', 'rejected', 'cancelled'))",
    ];
};
