<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_business_lines';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void
    {
        $this->createEstablishedExtension();
    }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_business_lines` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `code` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled',
  `remark` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by` bigint unsigned DEFAULT NULL,
  `updated_by` bigint unsigned DEFAULT NULL,
  `create_request_id` char(36) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `create_fingerprint` char(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp(6) NULL DEFAULT NULL,
  `updated_at` timestamp(6) NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_business_lines_code_unique` (`code`),
  UNIQUE KEY `erp_business_lines_name_unique` (`name`),
  UNIQUE KEY `erp_business_lines_create_request_id_unique` (`create_request_id`),
  KEY `erp_business_lines_status_sort_order_index` (`status`,`sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'erp_business_lines_status_check' => "(`status` IN ('enabled', 'disabled'))",
    ];
};
