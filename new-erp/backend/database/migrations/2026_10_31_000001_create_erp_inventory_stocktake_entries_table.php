<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 盘点逐件快照：序列号物料逐个编号、实物物料逐张记录开盘时的在账件（book_present），录入时标记在/不在并可补录账外新件（is_new）。
// is_locked 为开盘时已被业务占用（发货预留、待发、质检、返修、实物预留）的件，只读显示且按账面计，不参与差异。
// 完成盘点时据此生成“盘点差异”调整明细（减少行带编号/实物，增加行带新编号/新实物尺寸成本）。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_stocktake_entries';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_stocktake_entries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stocktake_id` bigint(20) unsigned NOT NULL,
  `line_id` bigint(20) unsigned NOT NULL,
  `entry_type` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `inventory_serial_id` bigint(20) unsigned DEFAULT NULL,
  `serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `material_physical_id` bigint(20) unsigned DEFAULT NULL,
  `physical_label` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `book_present` tinyint(1) NOT NULL DEFAULT '0',
  `counted_present` tinyint(1) DEFAULT NULL,
  `is_new` tinyint(1) NOT NULL DEFAULT '0',
  `is_locked` tinyint(1) NOT NULL DEFAULT '0',
  `book_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dimensions` json DEFAULT NULL,
  `total_cost` decimal(18,4) DEFAULT NULL,
  `counted_by` bigint(20) unsigned DEFAULT NULL,
  `counted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_stocktake_entries_line_serial_unique` (`line_id`,`inventory_serial_id`),
  UNIQUE KEY `erp_stocktake_entries_line_physical_unique` (`line_id`,`material_physical_id`),
  UNIQUE KEY `erp_stocktake_entries_line_serial_no_unique` (`line_id`,`serial_no`),
  KEY `erp_stocktake_entries_stocktake_line_index` (`stocktake_id`,`line_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
