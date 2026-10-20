<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 工单工序：下达时按工艺路线快照生成；车间生产和发货作业都在这里，报工在第 2 阶段接入。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_work_order_operations';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_work_order_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `routing_operation_id` bigint(20) unsigned DEFAULT NULL,
  `operation_id` bigint(20) unsigned DEFAULT NULL,
  `sequence` int(10) unsigned NOT NULL DEFAULT '0',
  `operation_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operation_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `execution_context` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'production',
  `production_stage_id` bigint(20) unsigned DEFAULT NULL,
  `stage_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `work_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `output_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `quality_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_key_operation` tinyint(1) NOT NULL DEFAULT '0',
  `setup_standard_minutes` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unit_standard_minutes` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `performance_rate` decimal(10,6) DEFAULT NULL,
  `packaging_scheme_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mfg_wo_op_order_idx` (`work_order_id`,`sequence`),
  CONSTRAINT `mfg_wo_op_order_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
