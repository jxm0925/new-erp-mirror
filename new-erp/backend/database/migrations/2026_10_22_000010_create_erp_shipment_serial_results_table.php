<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;
require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_shipment_serial_results';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_shipment_serial_results` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `shipment_id` bigint unsigned NOT NULL,
  `shipment_line_id` bigint unsigned NOT NULL,
  `operation_id` bigint unsigned NOT NULL,
  `inventory_serial_id` bigint unsigned NOT NULL,
  `round` int unsigned NOT NULL DEFAULT '1',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'waiting',
  `quality_result` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `completed_by_legacy_id` bigint unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `inspected_by_legacy_id` bigint unsigned DEFAULT NULL,
  `inspected_at` timestamp NULL DEFAULT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipment_serial_result_round_uq` (`operation_id`,`shipment_line_id`,`round`),
  KEY `shipment_serial_result_shipment_idx` (`shipment_id`,`inventory_serial_id`),
  KEY `shipment_serial_result_line_fk` (`shipment_line_id`),
  KEY `shipment_serial_result_serial_fk` (`inventory_serial_id`),
  CONSTRAINT `shipment_serial_result_shipment_fk` FOREIGN KEY (`shipment_id`) REFERENCES `erp_sales_shipments` (`id`),
  CONSTRAINT `shipment_serial_result_line_fk` FOREIGN KEY (`shipment_line_id`) REFERENCES `erp_sales_shipment_lines` (`id`),
  CONSTRAINT `shipment_serial_result_operation_fk` FOREIGN KEY (`operation_id`) REFERENCES `erp_shipment_packing_operations` (`id`),
  CONSTRAINT `shipment_serial_result_serial_fk` FOREIGN KEY (`inventory_serial_id`) REFERENCES `erp_inventory_serials` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
