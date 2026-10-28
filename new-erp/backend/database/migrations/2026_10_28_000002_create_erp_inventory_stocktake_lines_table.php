<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 盘点明细：差异数量始终按开盘账面计算，完成金额取关联调整流水的实际金额。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_stocktake_lines';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_stocktake_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stocktake_id` bigint(20) unsigned NOT NULL,
  `inventory_balance_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `count_mode` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'quantity',
  `book_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `unnumbered_book_qty` decimal(18,4) DEFAULT NULL,
  `unnumbered_counted_qty` decimal(18,4) DEFAULT NULL,
  `counted_qty` decimal(18,4) DEFAULT NULL,
  `difference_qty` decimal(18,4) DEFAULT NULL,
  `unit_cost` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `difference_amount` decimal(18,4) DEFAULT NULL,
  `counted_by` bigint(20) unsigned DEFAULT NULL,
  `counted_at` timestamp NULL DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_inventory_stocktake_lines_balance_unique` (`stocktake_id`,`inventory_balance_id`),
  KEY `erp_inventory_stocktake_lines_item_index` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
