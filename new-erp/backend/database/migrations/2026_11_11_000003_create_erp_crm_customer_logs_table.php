<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// CRM 客户日志：建档、编辑、负责销售变化、身份绑定与核实、协作增减的只追加记录。
// 负责销售的历史只从这里追溯，订单上的销售归属不随客户转交改写。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_customer_logs';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_customer_logs` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `customer_id` bigint unsigned NOT NULL,
  `action` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `summary` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `before_snapshot` json DEFAULT NULL,
  `after_snapshot` json DEFAULT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator_legacy_id` bigint unsigned DEFAULT NULL,
  `operator_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_crm_customer_logs_customer_index` (`customer_id`,`id`),
  KEY `erp_crm_customer_logs_action_index` (`action`),
  CONSTRAINT `erp_crm_customer_logs_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `erp_sales_customers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
