<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// CRM 客户协作：协作只扩大指定人员对该客户的查看/跟进范围，不改变负责销售和历史订单归属。
// active_key 仅在生效记录上为“客户ID:人员ID”，结束后置空，借唯一索引保证同一人同一客户只有一条生效协作。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_customer_collaborators';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_customer_collaborators` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `user_legacy_id` bigint unsigned NOT NULL,
  `user_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `department_legacy_id` bigint unsigned DEFAULT NULL,
  `scope` json NOT NULL,
  `actions` json NOT NULL,
  `effective_from` date NOT NULL,
  `effective_to` date DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `active_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by_legacy_id` bigint unsigned NOT NULL,
  `created_by_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ended_by_legacy_id` bigint unsigned DEFAULT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `end_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_crm_customer_collaborators_active_unique` (`active_key`),
  KEY `erp_crm_customer_collaborators_customer_index` (`customer_id`,`status`),
  KEY `erp_crm_customer_collaborators_user_index` (`user_legacy_id`,`status`),
  CONSTRAINT `erp_crm_customer_collaborators_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `erp_sales_customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
