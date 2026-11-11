<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_staff_profiles';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_staff_profiles` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_legacy_id` bigint unsigned NOT NULL,
  `region` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tags` json NOT NULL,
  `tag_source` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `confirmed_by_legacy_id` bigint unsigned NOT NULL,
  `confirmed_by_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmed_at` timestamp NOT NULL,
  `accepting` tinyint(1) NOT NULL DEFAULT '1',
  `remark` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_crm_staff_profiles_user_unique` (`user_legacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
