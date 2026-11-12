<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_opportunity_files';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_opportunity_files` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `opportunity_id` bigint unsigned NOT NULL,
  `attachment_id` bigint unsigned NOT NULL,
  `category` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `version_no` int unsigned NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `note` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_legacy_id` bigint unsigned NOT NULL,
  `created_by_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `confirmed_by_legacy_id` bigint unsigned DEFAULT NULL,
  `confirmed_by_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_crm_opportunity_files_version_unique` (`opportunity_id`,`category`,`version_no`),
  UNIQUE KEY `erp_crm_opportunity_files_attachment_unique` (`attachment_id`),
  KEY `erp_crm_opportunity_files_status_index` (`opportunity_id`,`category`,`status`),
  CONSTRAINT `erp_crm_opportunity_files_opportunity_fk` FOREIGN KEY (`opportunity_id`) REFERENCES `erp_crm_opportunities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `erp_crm_opportunity_files_attachment_fk` FOREIGN KEY (`attachment_id`) REFERENCES `erp_crm_attachments` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
