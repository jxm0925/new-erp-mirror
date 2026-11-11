<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// CRM 客户平台身份：一个客户可有多个平台身份，同一平台身份只能属于一个客户。
// identity_key 只在已确认身份上填写并唯一；待核实的旧值保留 raw_value，不参与唯一约束，
// 避免把来源不明的旧平台ID当成某个平台的有效身份。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_customer_identities';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_customer_identities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `platform_legacy_id` bigint unsigned DEFAULT NULL,
  `platform_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform_group` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scope` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `buyer_id` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `identity_key` char(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `raw_value` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `note` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bound_by_legacy_id` bigint unsigned DEFAULT NULL,
  `bound_by_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bound_at` timestamp NULL DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_crm_customer_identities_key_unique` (`identity_key`),
  KEY `erp_crm_customer_identities_customer_status_index` (`customer_id`,`status`),
  KEY `erp_crm_customer_identities_buyer_index` (`buyer_id`),
  CONSTRAINT `erp_crm_customer_identities_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `erp_sales_customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
