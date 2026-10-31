<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 集中开料计时段：每人各自开始 → 暂停／结束，按实际时间记工时（和工单工序计时一样，超过 4 小时没停自动停）。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_cutting_run_sessions';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_cutting_run_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cutting_run_id` bigint(20) unsigned NOT NULL,
  `employee_legacy_id` bigint(20) unsigned NOT NULL,
  `employee_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `started_at` timestamp NULL DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `labor_minutes` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mfg_cut_run_sess_run_idx` (`cutting_run_id`,`status`),
  KEY `mfg_cut_run_sess_emp_idx` (`employee_legacy_id`,`status`),
  CONSTRAINT `mfg_cut_run_sess_run_fk` FOREIGN KEY (`cutting_run_id`) REFERENCES `erp_mfg_cutting_runs` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
