<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_opportunity_orders';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_opportunity_orders` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `opportunity_id` bigint unsigned NOT NULL,
  `order_id` bigint unsigned NOT NULL,
  `customer_id` bigint unsigned NOT NULL,
  `created_by_legacy_id` bigint unsigned NOT NULL,
  `created_by_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `first_effective_at` timestamp NULL DEFAULT NULL,
  `first_effective_by_legacy_id` bigint unsigned DEFAULT NULL,
  `first_effective_by_name` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_crm_opportunity_orders_order_unique` (`order_id`),
  KEY `erp_crm_opportunity_orders_opportunity_index` (`opportunity_id`,`first_effective_at`),
  KEY `erp_crm_opportunity_orders_customer_index` (`customer_id`),
  CONSTRAINT `erp_crm_opportunity_orders_opportunity_fk` FOREIGN KEY (`opportunity_id`) REFERENCES `erp_crm_opportunities` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `erp_crm_opportunity_orders_order_fk` FOREIGN KEY (`order_id`) REFERENCES `erp_sales_orders` (`id`) ON DELETE RESTRICT,
  CONSTRAINT `erp_crm_opportunity_orders_customer_fk` FOREIGN KEY (`customer_id`) REFERENCES `erp_sales_customers` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
