<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_disposition_labor_sessions';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_disposition_labor_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `disposition_id` bigint(20) unsigned NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `employee_legacy_id` bigint(20) unsigned NOT NULL,
  `employee_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `activity` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `started_at` timestamp NOT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `labor_minutes` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mfg_disposition_labor_disposition_idx` (`disposition_id`,`status`),
  KEY `mfg_disposition_labor_employee_idx` (`employee_legacy_id`,`status`),
  KEY `mfg_disposition_labor_wo_idx` (`work_order_id`),
  KEY `mfg_disposition_labor_ended_idx` (`ended_at`),
  CONSTRAINT `mfg_disposition_labor_disposition_fk` FOREIGN KEY (`disposition_id`) REFERENCES `erp_cancellation_production_dispositions` (`id`),
  CONSTRAINT `mfg_disposition_labor_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'mfg_disposition_labor_activity_chk' => "(`activity` IN ('material_return', 'dismantle', 'scrap'))",
        'mfg_disposition_labor_status_chk' => "(`status` IN ('active', 'paused', 'completed'))",
    ];
};
