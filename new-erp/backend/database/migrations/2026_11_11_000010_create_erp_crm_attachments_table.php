<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_attachments';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_attachments` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subject_type` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `category` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'communication',
  `is_active` tinyint(1) NOT NULL DEFAULT '1',
  `removed_at` timestamp NULL DEFAULT NULL,
  `removed_by_legacy_id` bigint unsigned DEFAULT NULL,
  `name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `size` bigint unsigned NOT NULL,
  `storage_disk` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `uploaded_by_legacy_id` bigint unsigned NOT NULL,
  `uploaded_by_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_crm_attachments_subject_index` (`subject_type`,`subject_id`,`category`,`is_active`),
  KEY `erp_crm_attachments_uploader_index` (`uploaded_by_legacy_id`,`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
