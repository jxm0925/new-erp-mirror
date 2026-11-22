<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_daily_actions';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_daily_actions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `report_id` bigint unsigned NOT NULL,
  `owner_id` bigint unsigned NOT NULL,
  `subject_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `priority` varchar(16) COLLATE utf8mb4_unicode_ci NOT NULL,
  `planned_at` datetime NOT NULL,
  `action` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expected_result` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `help_request` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `help_status` varchar(16) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manager_id` bigint unsigned DEFAULT NULL,
  `manager_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `manager_note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `help_due` date DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `crm_daily_action_subject_uq` (`report_id`,`subject_type`,`subject_id`),
  KEY `crm_daily_action_due_idx` (`owner_id`,`planned_at`,`priority`),
  KEY `crm_daily_action_help_idx` (`help_status`,`report_id`),
  CONSTRAINT `crm_daily_action_report_fk` FOREIGN KEY (`report_id`) REFERENCES `erp_crm_daily_reports` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
