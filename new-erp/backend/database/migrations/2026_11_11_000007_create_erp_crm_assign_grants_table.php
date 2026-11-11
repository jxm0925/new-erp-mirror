<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_assign_grants';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_assign_grants` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `user_legacy_id` bigint unsigned NOT NULL,
  `department_ids` json NOT NULL,
  `channels` json NOT NULL,
  `actions` json NOT NULL,
  `assignee_mode` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `assignee_ids` json NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by_legacy_id` bigint unsigned NOT NULL,
  `updated_by_legacy_id` bigint unsigned NOT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_crm_assign_grants_user_unique` (`user_legacy_id`),
  KEY `erp_crm_assign_grants_effective_index` (`status`,`effective_from`,`effective_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
