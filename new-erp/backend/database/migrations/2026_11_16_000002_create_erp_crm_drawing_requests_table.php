<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_drawing_requests';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_drawing_requests` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `request_no` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `request_hash` char(64) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `lead_id` bigint unsigned DEFAULT NULL,
  `opportunity_id` bigint unsigned DEFAULT NULL,
  `title` varchar(200) COLLATE utf8mb4_unicode_ci NOT NULL,
  `requirement` text COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_attachment_ids` json NOT NULL,
  `technician_legacy_id` bigint unsigned NOT NULL,
  `technician_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `version` int unsigned NOT NULL DEFAULT '1',
  `created_by_legacy_id` bigint unsigned NOT NULL,
  `created_by_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `updated_by_legacy_id` bigint unsigned NOT NULL,
  `updated_by_name` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `crm_drawing_request_no_unique` (`request_no`),
  UNIQUE KEY `crm_drawing_request_key_unique` (`request_key`),
  KEY `crm_drawing_request_technician_index` (`technician_legacy_id`,`status`),
  KEY `crm_drawing_request_lead_index` (`lead_id`),
  KEY `crm_drawing_request_opportunity_index` (`opportunity_id`),
  KEY `crm_drawing_request_customer_index` (`customer_id`),
  CONSTRAINT `crm_drawing_request_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `erp_sales_customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `crm_drawing_request_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `erp_crm_leads` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `crm_drawing_request_opportunity_fk` FOREIGN KEY (`opportunity_id`) REFERENCES `erp_crm_opportunities` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
