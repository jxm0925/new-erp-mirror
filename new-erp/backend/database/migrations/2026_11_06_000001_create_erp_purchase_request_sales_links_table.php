<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_request_sales_links';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_request_sales_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_request_id` bigint(20) unsigned NOT NULL,
  `purchase_request_item_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_id` bigint(20) unsigned NOT NULL,
  `sales_order_item_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(18,8) NOT NULL,
  `source_snapshot` json NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_by` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by_legacy_id` bigint(20) unsigned NOT NULL,
  `reversed_by` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reversed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `pr_sales_line_uq` (`purchase_request_item_id`,`sales_order_item_id`),
  KEY `pr_sales_request_status_idx` (`purchase_request_id`,`status`),
  KEY `pr_sales_order_status_idx` (`sales_order_id`,`status`),
  KEY `pr_sales_order_line_idx` (`sales_order_item_id`),
  CONSTRAINT `pr_sales_request_fk` FOREIGN KEY (`purchase_request_id`) REFERENCES `erp_purchase_requests` (`id`),
  CONSTRAINT `pr_sales_request_line_fk` FOREIGN KEY (`purchase_request_item_id`) REFERENCES `erp_purchase_request_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `pr_sales_order_fk` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`),
  CONSTRAINT `pr_sales_order_line_fk` FOREIGN KEY (`sales_order_item_id`) REFERENCES `erp_sales_order_lines` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
