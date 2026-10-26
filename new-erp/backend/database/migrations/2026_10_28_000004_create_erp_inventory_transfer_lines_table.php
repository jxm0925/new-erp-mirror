<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 调拨明细：来源为具体库存批次行，目标为库位；过账后回写结转成本。serial_ids 为随行迁移的库存序列号。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_transfer_lines';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_transfer_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_id` bigint(20) unsigned NOT NULL,
  `inventory_balance_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `source_location_id` bigint(20) unsigned NOT NULL,
  `target_location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` decimal(18,4) NOT NULL,
  `serial_ids` json DEFAULT NULL,
  `cost_amount` decimal(18,4) DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_inventory_transfer_lines_transfer_index` (`transfer_id`),
  KEY `erp_inventory_transfer_lines_balance_index` (`inventory_balance_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
