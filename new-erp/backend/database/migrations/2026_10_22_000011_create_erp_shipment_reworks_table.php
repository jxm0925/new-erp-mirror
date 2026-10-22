<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;
require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_shipment_reworks';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_shipment_reworks` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `rework_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shipment_id` bigint unsigned NOT NULL,
  `shipment_line_id` bigint unsigned NOT NULL,
  `inventory_serial_id` bigint unsigned NOT NULL,
  `work_order_id` bigint unsigned DEFAULT NULL,
  `unit_id` bigint unsigned DEFAULT NULL,
  `return_operation_id` bigint unsigned DEFAULT NULL,
  `round` int unsigned NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_progress',
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by_legacy_id` bigint unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `returned_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `active_unit_id` bigint unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'in_progress') then `unit_id` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `shipment_rework_no_uq` (`rework_no`),
  UNIQUE KEY `shipment_rework_active_unit_uq` (`active_unit_id`),
  KEY `shipment_rework_line_idx` (`shipment_line_id`,`round`),
  KEY `shipment_rework_shipment_fk` (`shipment_id`),
  KEY `shipment_rework_serial_fk` (`inventory_serial_id`),
  KEY `shipment_rework_unit_fk` (`unit_id`),
  KEY `shipment_rework_wo_fk` (`work_order_id`),
  KEY `shipment_rework_operation_fk` (`return_operation_id`),
  CONSTRAINT `shipment_rework_shipment_fk` FOREIGN KEY (`shipment_id`) REFERENCES `erp_sales_shipments` (`id`),
  CONSTRAINT `shipment_rework_line_fk` FOREIGN KEY (`shipment_line_id`) REFERENCES `erp_sales_shipment_lines` (`id`),
  CONSTRAINT `shipment_rework_serial_fk` FOREIGN KEY (`inventory_serial_id`) REFERENCES `erp_inventory_serials` (`id`),
  CONSTRAINT `shipment_rework_unit_fk` FOREIGN KEY (`unit_id`) REFERENCES `erp_mfg_work_order_units` (`id`),
  CONSTRAINT `shipment_rework_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`),
  CONSTRAINT `shipment_rework_operation_fk` FOREIGN KEY (`return_operation_id`) REFERENCES `erp_mfg_work_order_operations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [];
};
