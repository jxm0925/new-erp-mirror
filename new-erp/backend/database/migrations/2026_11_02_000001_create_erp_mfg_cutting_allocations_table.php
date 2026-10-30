<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 集中开料池分配：工单下料点「已备料」时，按 BOM 定额从该板材已结算的集中开料批次里先进先出扣量计成本。
// qty 为工单物料单位（张／平方米…）的定额数量，measure 为折算的面积（m²）或长度（m），cost_amount 为分到的净耗成本。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_cutting_allocations';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_cutting_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cutting_batch_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `work_order_operation_id` bigint(20) unsigned NOT NULL,
  `work_order_material_id` bigint(20) unsigned NOT NULL,
  `unit_qty` int(10) unsigned NOT NULL DEFAULT '0',
  `qty` decimal(18,4) NOT NULL,
  `measure` decimal(18,8) NOT NULL,
  `measure_unit` varchar(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `allocated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `allocated_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mfg_cut_alloc_batch_idx` (`cutting_batch_id`),
  KEY `mfg_cut_alloc_wo_idx` (`work_order_id`,`work_order_operation_id`),
  KEY `mfg_cut_alloc_op_fk` (`work_order_operation_id`),
  KEY `mfg_cut_alloc_material_idx` (`work_order_material_id`),
  CONSTRAINT `mfg_cut_alloc_batch_fk` FOREIGN KEY (`cutting_batch_id`) REFERENCES `erp_mfg_cutting_batches` (`id`),
  CONSTRAINT `mfg_cut_alloc_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`),
  CONSTRAINT `mfg_cut_alloc_op_fk` FOREIGN KEY (`work_order_operation_id`) REFERENCES `erp_mfg_work_order_operations` (`id`),
  CONSTRAINT `mfg_cut_alloc_material_fk` FOREIGN KEY (`work_order_material_id`) REFERENCES `erp_mfg_work_order_materials` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
