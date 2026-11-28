<?php

declare(strict_types=1);
use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_customer_pools';

    public const ESTABLISHED_EXTENSION = true;

    public function up(): void
    {
        $this->createEstablishedExtension();
    }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_customer_pools` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `pool_code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pool_name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `group_code` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'lead',
  `applicable_scopes` json NOT NULL,
  `legacy_aliases` json DEFAULT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled',
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_crm_customer_pools_code_unique` (`pool_code`),
  UNIQUE KEY `erp_crm_customer_pools_name_unique` (`pool_name`),
  KEY `erp_crm_customer_pools_status_sort_index` (`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
