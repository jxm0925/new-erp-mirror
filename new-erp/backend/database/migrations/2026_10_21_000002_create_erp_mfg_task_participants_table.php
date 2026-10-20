<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 工序任务的接单人和协同人：派工或自己接单；协同人可设份额，不设按实际工时分。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_task_participants';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_task_participants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `work_order_operation_id` bigint(20) unsigned NOT NULL,
  `employee_legacy_id` bigint(20) unsigned NOT NULL,
  `employee_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `role` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'collaborator',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `share_percent` decimal(7,4) DEFAULT NULL,
  `assigned_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `assigned_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `joined_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_task_part_uq` (`work_order_operation_id`,`employee_legacy_id`),
  KEY `mfg_task_part_emp_idx` (`employee_legacy_id`,`is_active`),
  KEY `mfg_task_part_wo_idx` (`work_order_id`),
  CONSTRAINT `mfg_task_part_op_fk` FOREIGN KEY (`work_order_operation_id`) REFERENCES `erp_mfg_work_order_operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'mfg_task_part_role_chk' => "(`role` IN ('owner', 'collaborator'))",
    ];
};
