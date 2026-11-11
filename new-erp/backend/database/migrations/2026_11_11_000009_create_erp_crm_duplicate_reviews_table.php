<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_duplicate_reviews';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_duplicate_reviews` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `subject_type` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `subject_id` bigint unsigned DEFAULT NULL,
  `input_snapshot` json NOT NULL,
  `matches` json NOT NULL,
  `review_department_legacy_id` bigint unsigned DEFAULT NULL,
  `source_channel` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `applicant_legacy_id` bigint unsigned NOT NULL,
  `applicant_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `application_note` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `pending_key` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolved_customer_id` bigint unsigned DEFAULT NULL,
  `resolver_legacy_id` bigint unsigned DEFAULT NULL,
  `resolver_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `note` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_crm_duplicate_reviews_pending_unique` (`pending_key`),
  KEY `erp_crm_duplicate_reviews_scope_index` (`status`,`review_department_legacy_id`),
  KEY `erp_crm_duplicate_reviews_subject_index` (`subject_type`,`subject_id`),
  KEY `erp_crm_duplicate_reviews_applicant_index` (`applicant_legacy_id`,`status`),
  KEY `erp_crm_duplicate_reviews_customer_fk` (`resolved_customer_id`),
  CONSTRAINT `erp_crm_duplicate_reviews_customer_fk` FOREIGN KEY (`resolved_customer_id`) REFERENCES `erp_sales_customers` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
