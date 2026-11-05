<?php

use Database\Schema\InitialTableMigration;
require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cancellation_production_dispositions';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cancellation_production_dispositions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sales_order_id` bigint(20) unsigned NOT NULL,
  `sales_order_line_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_change_id` bigint(20) unsigned DEFAULT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `work_order_unit_id` bigint(20) unsigned DEFAULT NULL,
  `parent_unit_id` bigint(20) unsigned DEFAULT NULL,
  `material_allocation_snapshot` json DEFAULT NULL,
  `reserved_material_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `loss_material_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `effective_at` datetime DEFAULT NULL,
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `due_at` timestamp NOT NULL,
  `deadline_at` timestamp NOT NULL,
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `history` json NOT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cancellation_production_order_unit_uq` (`sales_order_id`,`work_order_unit_id`),
  KEY `cancellation_production_pending_idx` (`status`,`due_at`,`id`),
  KEY `cancellation_production_wo_fk` (`work_order_id`),
  KEY `cancellation_production_change_fk` (`sales_order_change_id`),
  KEY `cancellation_production_line_idx` (`sales_order_id`,`sales_order_line_id`),
  KEY `cancellation_production_unit_fk` (`work_order_unit_id`),
  KEY `cancellation_production_parent_unit_fk` (`parent_unit_id`),
  CONSTRAINT `cancellation_production_unit_fk` FOREIGN KEY (`work_order_unit_id`) REFERENCES `erp_mfg_work_order_units` (`id`),
  CONSTRAINT `cancellation_production_parent_unit_fk` FOREIGN KEY (`parent_unit_id`) REFERENCES `erp_mfg_work_order_units` (`id`),
  CONSTRAINT `cancellation_production_change_fk` FOREIGN KEY (`sales_order_change_id`) REFERENCES `erp_sales_order_changes` (`id`),
  CONSTRAINT `cancellation_production_order_fk` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`),
  CONSTRAINT `cancellation_production_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
};
