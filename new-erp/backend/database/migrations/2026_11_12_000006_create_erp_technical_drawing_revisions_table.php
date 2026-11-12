<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_technical_drawing_revisions';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_technical_drawing_revisions` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `drawing_id` bigint unsigned NOT NULL,
  `version_no` int unsigned NOT NULL,
  `previous_revision_id` bigint unsigned DEFAULT NULL,
  `action` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `designer_legacy_id` bigint unsigned NOT NULL,
  `designer_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `department_legacy_id` bigint unsigned DEFAULT NULL,
  `department_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_date` date NOT NULL,
  `source_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint unsigned NOT NULL,
  `source_no` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_disk` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint unsigned NOT NULL,
  `file_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `remark` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `confirmed_by_legacy_id` bigint unsigned DEFAULT NULL,
  `confirmed_by_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmation_remark` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_legacy_id` bigint unsigned NOT NULL,
  `created_by_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `technical_revision_version_unique` (`drawing_id`,`version_no`),
  UNIQUE KEY `technical_revision_content_unique` (`file_hash`),
  UNIQUE KEY `technical_revision_drawing_id_unique` (`drawing_id`,`id`),
  KEY `technical_revision_completed_index` (`completed_date`,`designer_legacy_id`),
  KEY `technical_revision_source_index` (`source_type`,`source_id`),
  KEY `technical_revision_previous_index` (`previous_revision_id`),
  KEY `technical_revision_previous_fk` (`drawing_id`,`previous_revision_id`),
  CONSTRAINT `technical_revision_drawing_fk` FOREIGN KEY (`drawing_id`) REFERENCES `erp_technical_drawings` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `technical_revision_previous_fk` FOREIGN KEY (`drawing_id`,`previous_revision_id`) REFERENCES `erp_technical_drawing_revisions` (`drawing_id`,`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [
        'technical_revision_action_check' => "((`version_no` = 1 AND `action` = 'new' AND `previous_revision_id` IS NULL) OR (`version_no` > 1 AND `action` = 'modify' AND `previous_revision_id` IS NOT NULL))",
        'technical_revision_source_check' => "(`source_type` IN ('opportunity', 'bom', 'work_order'))",
    ];
};
