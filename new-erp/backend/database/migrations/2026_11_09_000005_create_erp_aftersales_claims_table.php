<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 索赔登记与跟进：本期只做登记，不生成财务单据；售后单关闭后仍可继续跟进。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_aftersales_claims';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_aftersales_claims` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `case_id` bigint(20) unsigned NOT NULL,
  `target_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `carrier_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned DEFAULT NULL,
  `target_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `related_doc_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `related_doc_id` bigint(20) unsigned DEFAULT NULL,
  `related_doc_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `claim_amount` decimal(20,4) NOT NULL DEFAULT '0.0000',
  `claimed_on` date NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'submitted',
  `paid_amount` decimal(20,4) DEFAULT NULL,
  `paid_on` date DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_aftersales_claims_case_index` (`case_id`),
  KEY `erp_aftersales_claims_status_index` (`status`),
  CONSTRAINT `erp_as_claims_case_fk` FOREIGN KEY (`case_id`) REFERENCES `erp_aftersales_cases` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
