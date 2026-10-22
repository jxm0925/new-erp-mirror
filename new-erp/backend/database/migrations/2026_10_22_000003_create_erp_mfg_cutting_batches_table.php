<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

/** 一张表保存同一工单集中开料批次，不恢复已删除的旧下料单据层级。 */
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_cutting_batches';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_cutting_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `work_order_operation_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'issued',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `unit_qty` int(10) unsigned NOT NULL,
  `completed_unit_qty` int(10) unsigned NOT NULL DEFAULT '0',
  `idempotency_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `settlement_key` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `settlement_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `bom_snapshot` json NOT NULL,
  `input_lines` json NOT NULL,
  `planned_outputs` json NOT NULL,
  `actual_outputs` json DEFAULT NULL,
  `remnants` json DEFAULT NULL,
  `input_cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `remnant_cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `net_cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `inventory_issue_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_return_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `issued_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `issued_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issued_at` timestamp NULL DEFAULT NULL,
  `settled_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `settled_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `settled_at` timestamp NULL DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_cut_batch_no_uq` (`batch_no`),
  UNIQUE KEY `mfg_cut_request_uq` (`idempotency_key`),
  UNIQUE KEY `mfg_cut_settlement_uq` (`settlement_key`),
  KEY `mfg_cut_wo_op_idx` (`work_order_id`,`work_order_operation_id`,`status`),
  KEY `mfg_cut_op_fk` (`work_order_operation_id`),
  KEY `mfg_cut_issue_tx_fk` (`inventory_issue_transaction_id`),
  KEY `mfg_cut_return_tx_fk` (`inventory_return_transaction_id`),
  CONSTRAINT `mfg_cut_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`),
  CONSTRAINT `mfg_cut_op_fk` FOREIGN KEY (`work_order_operation_id`) REFERENCES `erp_mfg_work_order_operations` (`id`),
  CONSTRAINT `mfg_cut_issue_tx_fk` FOREIGN KEY (`inventory_issue_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`),
  CONSTRAINT `mfg_cut_return_tx_fk` FOREIGN KEY (`inventory_return_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
