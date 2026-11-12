<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_technical_drawings';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_technical_drawings` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `drawing_no` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `drawing_name` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `drawing_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `current_revision_id` bigint unsigned DEFAULT NULL,
  `first_confirmed_revision_id` bigint unsigned DEFAULT NULL,
  `first_confirmed_at` timestamp NULL DEFAULT NULL,
  `first_confirmed_by_legacy_id` bigint unsigned DEFAULT NULL,
  `first_confirmed_by_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_legacy_id` bigint unsigned NOT NULL,
  `created_by_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `technical_drawing_no_unique` (`drawing_no`),
  KEY `technical_drawing_first_confirmation_index` (`first_confirmed_at`,`first_confirmed_revision_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    // 版本由下一份完整建表定义建立，跨表指针归属由锁定图纸身份的同一用例校验。
    public const DECLARED_CHECKS = [
        'technical_drawing_type_check' => "(`drawing_type` IN ('structure', 'part', 'assembly', 'other'))",
    ];
};
