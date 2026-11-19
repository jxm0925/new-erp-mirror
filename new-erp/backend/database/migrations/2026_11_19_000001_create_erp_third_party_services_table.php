<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_third_party_services';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void
    {
        $this->createEstablishedExtension();
    }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_third_party_services` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `service_code` varchar(60) COLLATE utf8mb4_unicode_ci NOT NULL,
  `category` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `provider_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT '0',
  `config_json` json DEFAULT NULL,
  `encrypted_credentials` longtext COLLATE utf8mb4_unicode_ci,
  `credential_mask` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_check_status` varchar(20) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_check_message` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `last_checked_at` timestamp NULL DEFAULT NULL,
  `business_version` int unsigned NOT NULL DEFAULT '1',
  `updated_by_legacy_id` bigint unsigned DEFAULT NULL,
  `updated_by_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_third_party_services_service_code_unique` (`service_code`),
  KEY `erp_third_party_services_category_enabled_index` (`category`,`enabled`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'erp_third_party_services_last_check_status_check' => "(`last_check_status` IS NULL OR `last_check_status` IN ('ok', 'failed'))",
    ];
};
