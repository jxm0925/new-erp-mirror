<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_followups';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_followups` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subject_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `lead_id` bigint unsigned DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `contact_name` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `method` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `contacted_at` timestamp NOT NULL,
  `content` longtext COLLATE utf8mb4_unicode_ci NOT NULL,
  `result` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `next_contact_at` timestamp NULL DEFAULT NULL,
  `next_plan` text COLLATE utf8mb4_unicode_ci,
  `operator_legacy_id` bigint unsigned NOT NULL,
  `operator_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department_legacy_id` bigint unsigned DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `legacy_id` bigint unsigned DEFAULT NULL,
  `legacy_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_crm_followups_legacy_unique` (`legacy_id`),
  KEY `erp_crm_followups_subject_index` (`subject_type`,`subject_id`,`contacted_at`),
  KEY `erp_crm_followups_customer_index` (`customer_id`,`contacted_at`),
  KEY `erp_crm_followups_lead_index` (`lead_id`,`contacted_at`),
  CONSTRAINT `erp_crm_followups_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `erp_sales_customers` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `erp_crm_followups_lead_fk` FOREIGN KEY (`lead_id`) REFERENCES `erp_crm_leads` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
