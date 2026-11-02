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
  `work_order_id` bigint(20) unsigned NOT NULL,
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
  UNIQUE KEY `cancellation_production_order_wo_uq` (`sales_order_id`,`work_order_id`),
  KEY `cancellation_production_pending_idx` (`status`,`due_at`,`id`),
  KEY `cancellation_production_wo_fk` (`work_order_id`),
  CONSTRAINT `cancellation_production_order_fk` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`),
  CONSTRAINT `cancellation_production_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
};
