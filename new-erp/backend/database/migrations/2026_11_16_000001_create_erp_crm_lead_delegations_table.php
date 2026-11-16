<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_lead_delegations';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_lead_delegations` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `owner_legacy_id` bigint unsigned NOT NULL,
  `owner_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `delegate_legacy_id` bigint unsigned NOT NULL,
  `delegate_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_department_id` bigint unsigned NOT NULL,
  `delegate_department_id` bigint unsigned NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date NOT NULL,
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by_legacy_id` bigint unsigned NOT NULL,
  `created_by_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `ended_at` timestamp NULL DEFAULT NULL,
  `ended_by_legacy_id` bigint unsigned DEFAULT NULL,
  `ended_by_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `end_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `crm_lead_delegate_period` (`delegate_legacy_id`,`ended_at`,`effective_to`),
  KEY `crm_lead_owner_period` (`owner_legacy_id`,`effective_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
