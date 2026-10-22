<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;
require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_shipment_dispatches';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_shipment_dispatches` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `dispatch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shipment_id` bigint unsigned NOT NULL,
  `delivery_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `package_ids` json NOT NULL,
  `shipment_line_ids` json NOT NULL,
  `inventory_transaction_id` bigint unsigned DEFAULT NULL,
  `operator_legacy_id` bigint unsigned DEFAULT NULL,
  `operator_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dispatched_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipment_dispatch_no_uq` (`dispatch_no`),
  KEY `shipment_dispatch_shipment_idx` (`shipment_id`,`dispatched_at`),
  KEY `shipment_dispatch_transaction_fk` (`inventory_transaction_id`),
  CONSTRAINT `shipment_dispatch_shipment_fk` FOREIGN KEY (`shipment_id`) REFERENCES `erp_sales_shipments` (`id`),
  CONSTRAINT `shipment_dispatch_transaction_fk` FOREIGN KEY (`inventory_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
