<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_lead_logs';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_lead_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lead_id` bigint unsigned NOT NULL,
  `action` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `before_snapshot` json DEFAULT NULL,
  `after_snapshot` json DEFAULT NULL,
  `reason` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator_legacy_id` bigint unsigned DEFAULT NULL,
  `operator_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_crm_lead_logs_lead_index` (`lead_id`,`id`),
  CONSTRAINT `erp_crm_lead_logs_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `erp_crm_leads` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
