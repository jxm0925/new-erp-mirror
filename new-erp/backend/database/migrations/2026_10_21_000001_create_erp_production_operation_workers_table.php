<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 工序能做的人：工人在小程序只看到自己能做的工序任务。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_operation_workers';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_operation_workers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` bigint(20) unsigned NOT NULL,
  `employee_legacy_id` bigint(20) unsigned NOT NULL,
  `employee_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_op_worker_uq` (`operation_id`,`employee_legacy_id`),
  KEY `prod_op_worker_emp_idx` (`employee_legacy_id`),
  CONSTRAINT `prod_op_worker_op_fk` FOREIGN KEY (`operation_id`) REFERENCES `erp_production_operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
