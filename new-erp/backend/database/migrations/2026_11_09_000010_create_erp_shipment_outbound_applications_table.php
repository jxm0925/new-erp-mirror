<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_shipment_outbound_applications';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_shipment_outbound_applications` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shipment_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned NOT NULL,
  `shipment_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `operation_name` varchar(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `kind` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `package_ids` json NOT NULL,
  `package_nos` json NOT NULL,
  `warehouse_ids` json NOT NULL,
  `lines_snapshot` json NOT NULL,
  `delivery_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `carrier_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tracking_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `applicant_legacy_id` bigint unsigned NOT NULL,
  `applicant_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reviewer_legacy_id` bigint unsigned DEFAULT NULL,
  `reviewer_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `review_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `withdrawn_by_legacy_id` bigint unsigned DEFAULT NULL,
  `withdrawal_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `withdrawn_at` timestamp NULL DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `inventory_transaction_id` bigint unsigned DEFAULT NULL,
  `dispatch_id` bigint unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_shipment_outbound_status_idx` (`status`,`id`),
  KEY `erp_shipment_outbound_operation_idx` (`operation_id`,`kind`,`id`),
  KEY `erp_shipment_outbound_shipment_idx` (`shipment_id`,`kind`,`status`),
  KEY `erp_shipment_outbound_transaction_idx` (`inventory_transaction_id`),
  KEY `erp_shipment_outbound_dispatch_idx` (`dispatch_id`),
  CONSTRAINT `erp_shipment_outbound_shipment_fk` FOREIGN KEY (`shipment_id`) REFERENCES `erp_sales_shipments` (`id`),
  CONSTRAINT `erp_shipment_outbound_operation_fk` FOREIGN KEY (`operation_id`) REFERENCES `erp_shipment_packing_operations` (`id`),
  CONSTRAINT `erp_shipment_outbound_transaction_fk` FOREIGN KEY (`inventory_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`),
  CONSTRAINT `erp_shipment_outbound_dispatch_fk` FOREIGN KEY (`dispatch_id`) REFERENCES `erp_shipment_dispatches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
