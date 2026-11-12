<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_technical_drawing_usages';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_technical_drawing_usages` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `drawing_id` bigint unsigned NOT NULL,
  `revision_id` bigint unsigned NOT NULL,
  `source_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint unsigned NOT NULL,
  `source_file_id` bigint unsigned NOT NULL DEFAULT '0',
  `source_file_key` varchar(100) CHARACTER SET ascii COLLATE ascii_bin DEFAULT NULL,
  `position_uid` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT '',
  `source_no` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `active` tinyint(1) NOT NULL DEFAULT '1',
  `request_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_by_legacy_id` bigint unsigned NOT NULL,
  `created_by_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `technical_usage_request_unique` (`request_key`),
  UNIQUE KEY `technical_usage_file_unique` (`source_file_key`),
  UNIQUE KEY `technical_usage_position_unique` (`source_type`,`source_id`,`position_uid`,`revision_id`),
  KEY `technical_usage_revision_index` (`revision_id`,`active`),
  KEY `technical_usage_drawing_revision_index` (`drawing_id`,`revision_id`),
  CONSTRAINT `technical_usage_revision_fk` FOREIGN KEY (`drawing_id`,`revision_id`) REFERENCES `erp_technical_drawing_revisions` (`drawing_id`,`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [
        'technical_usage_source_check' => "(`source_type` IN ('opportunity', 'bom', 'work_order'))",
    ];
};
