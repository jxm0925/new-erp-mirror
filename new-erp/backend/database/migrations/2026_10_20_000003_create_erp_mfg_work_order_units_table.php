<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 工单按台明细：下达时一台一个序列号，后续报工、返工、入库都挂在这台上。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_work_order_units';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_work_order_units` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `unit_no` int(10) unsigned NOT NULL DEFAULT '0',
  `serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'waiting',
  `current_operation_id` bigint(20) unsigned DEFAULT NULL,
  `rework_count` int(10) unsigned NOT NULL DEFAULT '0',
  `inventory_serial_id` bigint(20) unsigned DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `stored_at` timestamp NULL DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_wo_unit_serial_uq` (`serial_no`),
  KEY `mfg_wo_unit_order_idx` (`work_order_id`,`unit_no`),
  CONSTRAINT `mfg_wo_unit_order_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'mfg_wo_unit_status_chk' => "(`status` IN ('waiting', 'in_progress', 'rework', 'completed', 'stored', 'scrapped', 'cancelled'))",
    ];
};
