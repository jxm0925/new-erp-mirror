<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 库存盘点单：一张单盘一个仓库（可限定库位）。盘点中（counting）冻结范围内的在库数量变化，只允许原因为“盘点差异”的调整过账；完成时差异经手工调整单过账。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_stocktakes';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_stocktakes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `stocktake_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned DEFAULT NULL,
  `stocktake_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'counting',
  `stocktake_date` date DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `line_count` int(10) unsigned NOT NULL DEFAULT '0',
  `difference_count` int(10) unsigned NOT NULL DEFAULT '0',
  `gain_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `loss_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `difference_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `adjustment_id` bigint(20) unsigned DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `completed_by` bigint(20) unsigned DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_inventory_stocktakes_no_unique` (`stocktake_no`),
  KEY `erp_inventory_stocktakes_scope_index` (`warehouse_id`,`stocktake_status`,`location_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
