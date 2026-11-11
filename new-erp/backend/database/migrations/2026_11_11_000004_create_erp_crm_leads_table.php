<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_leads';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_leads` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `lead_no` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_id` bigint unsigned DEFAULT NULL,
  `customer_kind` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'individual',
  `customer_name` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_id` bigint unsigned DEFAULT NULL,
  `contact_name` varchar(100) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_role` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mobile` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_value2` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `wechat` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `email` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `credit_code` varchar(40) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `region_id` bigint unsigned DEFAULT NULL,
  `province` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `district` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `detail_address` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `full_address` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `trade_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `department_legacy_id` bigint unsigned DEFAULT NULL,
  `source_channel` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `entry_method` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'operations',
  `lead_type` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_tags` json DEFAULT NULL,
  `custom_tags` json DEFAULT NULL,
  `is_new_inquiry` tinyint(1) NOT NULL DEFAULT '1',
  `business_lines` json DEFAULT NULL,
  `customer_pool` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expected_amount` decimal(18,2) DEFAULT NULL,
  `currency` varchar(10) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CNY',
  `content` longtext COLLATE utf8mb4_unicode_ci,
  `demand_summary` text COLLATE utf8mb4_unicode_ci,
  `platform_legacy_id` bigint unsigned DEFAULT NULL,
  `platform_buyer_id` varchar(160) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_input_snapshot` json DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `owner_legacy_id` bigint unsigned DEFAULT NULL,
  `owner_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `pending_user_legacy_id` bigint unsigned DEFAULT NULL,
  `pending_user_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `previous_owner_legacy_id` bigint unsigned DEFAULT NULL,
  `previous_owner_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assigned_by_legacy_id` bigint unsigned DEFAULT NULL,
  `assigned_by_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `assignment_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accepted_at` timestamp NULL DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `recycled_at` timestamp NULL DEFAULT NULL,
  `recycled_by_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `recycle_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `invalid_reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_contact_at` timestamp NULL DEFAULT NULL,
  `last_contact_at` timestamp NULL DEFAULT NULL,
  `next_contact_at` timestamp NULL DEFAULT NULL,
  `next_plan` text COLLATE utf8mb4_unicode_ci,
  `created_by_legacy_id` bigint unsigned NOT NULL,
  `created_by_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `legacy_id` bigint unsigned DEFAULT NULL,
  `legacy_payload` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_crm_leads_no_unique` (`lead_no`),
  UNIQUE KEY `erp_crm_leads_legacy_unique` (`legacy_id`),
  KEY `erp_crm_leads_customer_index` (`customer_id`,`status`),
  KEY `erp_crm_leads_owner_index` (`owner_legacy_id`,`status`),
  KEY `erp_crm_leads_pending_index` (`pending_user_legacy_id`,`status`),
  KEY `erp_crm_leads_scope_index` (`department_legacy_id`,`source_channel`,`status`),
  KEY `erp_crm_leads_creator_index` (`created_by_legacy_id`,`status`),
  KEY `erp_crm_leads_next_index` (`next_contact_at`),
  CONSTRAINT `erp_crm_leads_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `erp_sales_customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
