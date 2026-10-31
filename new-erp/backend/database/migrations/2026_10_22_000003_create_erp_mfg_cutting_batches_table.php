<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

/**
 * 开料批次。batch_scope=work_order：随单开料（属于某工单某道工序）；
 * batch_scope=shared：集中开料（不属于任何工单，work_order_id 为空，只开一种板材 item_id；cutting_run_id 为所属的开料记录，一次只开一个 BOM），
 * 结算后净耗进开料池（pool_measure），工单下料点「已备料」时按定额从池里先进先出扣（allocated_*，明细见 erp_mfg_cutting_allocations）。
 */
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_cutting_batches';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_cutting_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `batch_scope` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'work_order',
  `item_id` bigint(20) unsigned DEFAULT NULL,
  `cutting_run_id` bigint(20) unsigned DEFAULT NULL,
  `work_order_id` bigint(20) unsigned DEFAULT NULL,
  `work_order_operation_id` bigint(20) unsigned DEFAULT NULL,
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
  `pool_measure` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `allocated_measure` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `allocated_cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
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
  KEY `mfg_cut_scope_item_idx` (`batch_scope`,`item_id`,`status`),
  KEY `mfg_cut_run_idx` (`cutting_run_id`),
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
