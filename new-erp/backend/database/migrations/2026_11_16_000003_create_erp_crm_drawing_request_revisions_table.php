<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_drawing_request_revisions';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_drawing_request_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint unsigned NOT NULL,
  `revision_id` bigint unsigned NOT NULL,
  `usage_id` bigint unsigned NOT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `confirmed_by_legacy_id` bigint unsigned DEFAULT NULL,
  `confirmed_by_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmation_note` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_legacy_id` bigint unsigned NOT NULL,
  `created_by_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `crm_drawing_request_revision_unique` (`request_id`,`revision_id`),
  UNIQUE KEY `crm_drawing_request_usage_unique` (`usage_id`),
  KEY `crm_drawing_request_revision_confirmed_index` (`request_id`,`confirmed_at`),
  KEY `crm_drawing_request_revision_fk` (`revision_id`),
  CONSTRAINT `crm_drawing_request_revision_request_fk` FOREIGN KEY (`request_id`) REFERENCES `erp_crm_drawing_requests` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `crm_drawing_request_revision_revision_fk` FOREIGN KEY (`revision_id`) REFERENCES `erp_technical_drawing_revisions` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `crm_drawing_request_revision_usage_fk` FOREIGN KEY (`usage_id`) REFERENCES `erp_technical_drawing_usages` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
