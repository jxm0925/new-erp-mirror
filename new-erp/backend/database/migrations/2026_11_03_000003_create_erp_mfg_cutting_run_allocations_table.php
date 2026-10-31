<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 集中开料领用：工单下料点「已备料」时按件数从开料记录领走，labor_minutes = 这批总工时 × pieces ÷ 这批总件数。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_cutting_run_allocations';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_cutting_run_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cutting_run_id` bigint(20) unsigned NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `work_order_operation_id` bigint(20) unsigned NOT NULL,
  `work_order_material_id` bigint(20) unsigned DEFAULT NULL,
  `line_key` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_qty` int(10) unsigned NOT NULL DEFAULT '0',
  `pieces` int(10) unsigned NOT NULL,
  `labor_minutes` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `allocated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `allocated_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mfg_cut_run_alloc_run_idx` (`cutting_run_id`),
  KEY `mfg_cut_run_alloc_wo_idx` (`work_order_id`,`work_order_operation_id`),
  KEY `mfg_cut_run_alloc_op_fk` (`work_order_operation_id`),
  KEY `mfg_cut_run_alloc_material_fk` (`work_order_material_id`),
  CONSTRAINT `mfg_cut_run_alloc_run_fk` FOREIGN KEY (`cutting_run_id`) REFERENCES `erp_mfg_cutting_runs` (`id`),
  CONSTRAINT `mfg_cut_run_alloc_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`),
  CONSTRAINT `mfg_cut_run_alloc_op_fk` FOREIGN KEY (`work_order_operation_id`) REFERENCES `erp_mfg_work_order_operations` (`id`),
  CONSTRAINT `mfg_cut_run_alloc_material_fk` FOREIGN KEY (`work_order_material_id`) REFERENCES `erp_mfg_work_order_materials` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
