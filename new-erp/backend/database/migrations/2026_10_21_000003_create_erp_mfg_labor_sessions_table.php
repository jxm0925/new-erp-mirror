<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 报工计时：每人每次开始到暂停/完成算一段，返工时段单独标记。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_labor_sessions';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_labor_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `work_order_operation_id` bigint(20) unsigned NOT NULL,
  `employee_legacy_id` bigint(20) unsigned NOT NULL,
  `employee_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_rework` tinyint(1) NOT NULL DEFAULT '0',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `labor_minutes` decimal(12,2) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mfg_labor_emp_status_idx` (`employee_legacy_id`,`status`),
  KEY `mfg_labor_op_idx` (`work_order_operation_id`,`status`),
  KEY `mfg_labor_wo_idx` (`work_order_id`),
  KEY `mfg_labor_started_idx` (`started_at`),
  CONSTRAINT `mfg_labor_op_fk` FOREIGN KEY (`work_order_operation_id`) REFERENCES `erp_mfg_work_order_operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'mfg_labor_status_chk' => "(`status` IN ('active', 'paused', 'completed'))",
    ];
};
