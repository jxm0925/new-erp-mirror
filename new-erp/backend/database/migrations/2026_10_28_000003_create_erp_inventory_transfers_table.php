<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 库存调拨：同仓一步；跨仓调出冻结数量/成本，仓库整单接收后入目标库，历史一步流水保留。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_transfers';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_warehouse_id` bigint(20) unsigned NOT NULL,
  `target_warehouse_id` bigint(20) unsigned NOT NULL,
  `transfer_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `transfer_date` date DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `outbound_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `inbound_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_by` bigint(20) unsigned DEFAULT NULL,
  `dispatched_by` bigint(20) unsigned DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `confirmed_by` bigint(20) unsigned DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `receipt_exception` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `exception_by` bigint(20) unsigned DEFAULT NULL,
  `exception_at` timestamp NULL DEFAULT NULL,
  `posted_by` bigint(20) unsigned DEFAULT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `cancelled_by` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_inventory_transfers_no_unique` (`transfer_no`),
  KEY `erp_inventory_transfers_status_index` (`transfer_status`,`source_warehouse_id`,`target_warehouse_id`),
  KEY `inventory_transfer_inbound_idx` (`target_warehouse_id`,`transfer_status`,`dispatched_at`,`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
