<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_technical_drawing_revision_files';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_technical_drawing_revision_files` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `revision_id` bigint unsigned NOT NULL,
  `sort` int unsigned NOT NULL,
  `file_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_disk` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_path` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_name` varchar(255) COLLATE utf8mb4_unicode_ci NOT NULL,
  `mime_type` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint unsigned NOT NULL,
  `file_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `technical_revision_file_sort_unique` (`revision_id`,`sort`),
  KEY `technical_revision_file_hash_index` (`file_hash`),
  CONSTRAINT `technical_revision_file_revision_fk` FOREIGN KEY (`revision_id`) REFERENCES `erp_technical_drawing_revisions` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [
        'technical_revision_file_sort_check' => '(`sort` > 0)',
        'technical_revision_file_type_check' => "(`file_type` IN ('technical', 'architecture', 'welding', 'cutting'))",
    ];
};
