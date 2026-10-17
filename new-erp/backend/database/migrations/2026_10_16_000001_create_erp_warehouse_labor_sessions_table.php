<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// The complete owner of this new business table; no existing table or historical labor is changed.
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_warehouse_labor_sessions';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_warehouse_labor_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `target_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `employee_legacy_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `active_employee_legacy_id` bigint(20) unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'ACTIVE') then `employee_legacy_id` else NULL end)) STORED,
  `started_at` timestamp NOT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `end_reason` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `previous_session_id` bigint(20) unsigned DEFAULT NULL,
  `actual_labor_minutes` decimal(14,2) NOT NULL DEFAULT '0.00',
  `source_snapshot` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `warehouse_labor_one_active_employee_uq` (`active_employee_legacy_id`),
  KEY `warehouse_labor_target_actor_idx` (`target_type`,`target_id`,`employee_legacy_id`,`status`),
  KEY `warehouse_labor_employee_history_idx` (`employee_legacy_id`,`status`,`ended_at`),
  KEY `warehouse_labor_previous_fk` (`previous_session_id`),
  CONSTRAINT `warehouse_labor_previous_fk` FOREIGN KEY (`previous_session_id`) REFERENCES `erp_warehouse_labor_sessions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // Polymorphic business objects are resolved and locked by the service, never fake production-task FKs.
    public const DECLARED_CHECKS = [
        'warehouse_labor_target_chk' => "(`target_type` IN ('public_preparation', 'picking', 'delivery'))",
        'warehouse_labor_status_chk' => "(`status` IN ('ACTIVE', 'ENDED'))",
        'warehouse_labor_minutes_chk' => '(`actual_labor_minutes` >= 0)',
    ];
};
