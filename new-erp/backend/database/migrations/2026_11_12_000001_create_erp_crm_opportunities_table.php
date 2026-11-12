<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_opportunities';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_opportunities` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `opportunity_no` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_key` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `customer_name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_lead_id` bigint unsigned DEFAULT NULL,
  `source_lead_no` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_snapshot` json DEFAULT NULL,
  `name` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `contact_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_line` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_legacy_id` bigint unsigned NOT NULL,
  `owner_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department_legacy_id` bigint unsigned NOT NULL,
  `stage` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'requirement',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `stage_entered_at` timestamp NULL DEFAULT NULL,
  `expected_amount` decimal(18,2) DEFAULT NULL,
  `currency` varchar(3) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `expected_close_date` date DEFAULT NULL,
  `demand_summary` text COLLATE utf8mb4_unicode_ci,
  `first_contact_at` timestamp NULL DEFAULT NULL,
  `last_contact_at` timestamp NULL DEFAULT NULL,
  `next_contact_at` timestamp NULL DEFAULT NULL,
  `next_plan` text COLLATE utf8mb4_unicode_ci,
  `won_at` timestamp NULL DEFAULT NULL,
  `lost_at` timestamp NULL DEFAULT NULL,
  `lost_reason` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lost_destination` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `lost_note` text COLLATE utf8mb4_unicode_ci,
  `created_by_legacy_id` bigint unsigned NOT NULL,
  `created_by_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_crm_opportunities_no_unique` (`opportunity_no`),
  UNIQUE KEY `erp_crm_opportunities_request_unique` (`request_key`),
  UNIQUE KEY `erp_crm_opportunities_lead_unique` (`source_lead_id`),
  KEY `erp_crm_opportunities_customer_index` (`customer_id`,`status`),
  KEY `erp_crm_opportunities_owner_index` (`owner_legacy_id`,`status`,`stage`),
  KEY `erp_crm_opportunities_department_index` (`department_legacy_id`,`status`),
  KEY `erp_crm_opportunities_close_index` (`expected_close_date`,`status`),
  KEY `erp_crm_opportunities_next_index` (`next_contact_at`),
  KEY `erp_crm_opportunities_contact_index` (`contact_id`),
  CONSTRAINT `erp_crm_opportunities_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `erp_sales_customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `erp_crm_opportunities_lead_fk` FOREIGN KEY (`source_lead_id`) REFERENCES `erp_crm_leads` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `erp_crm_opportunities_contact_fk` FOREIGN KEY (`contact_id`) REFERENCES `erp_sales_customer_contacts` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
