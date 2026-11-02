<?php

use Database\Schema\InitialTableMigration;
require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_cancellations';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_cancellations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sales_order_id` bigint(20) unsigned NOT NULL,
  `method` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'refund',
  `requested_refund` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `requested_retained` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `retained_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `finance_confirmed_at` timestamp NULL DEFAULT NULL,
  `finance_confirmed_by` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accounting_note` varchar(1000) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `history` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `sales_cancellation_order_uq` (`sales_order_id`),
  CONSTRAINT `sales_cancellation_order_fk` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
};
