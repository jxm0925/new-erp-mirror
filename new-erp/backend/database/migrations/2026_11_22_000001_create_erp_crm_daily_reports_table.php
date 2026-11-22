<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_daily_reports';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_daily_reports` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `kind` varchar(12) COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_id` bigint unsigned NOT NULL,
  `department_id` bigint unsigned DEFAULT NULL,
  `report_date` date NOT NULL,
  `author_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `body` json NOT NULL,
  `analysis` json DEFAULT NULL,
  `confirmed_at` datetime DEFAULT NULL,
  `version` int unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `crm_daily_scope_date_uq` (`kind`,`scope_id`,`report_date`),
  KEY `crm_daily_team_date_idx` (`department_id`,`report_date`,`kind`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
