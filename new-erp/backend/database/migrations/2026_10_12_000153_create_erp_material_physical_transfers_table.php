<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_physical_transfers';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_physical_transfers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transfer_no` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_command_id` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `command_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `expected_version` int(10) unsigned NOT NULL,
  `physical_material_id` bigint(20) unsigned NOT NULL,
  `source_holding_id` bigint(20) unsigned NOT NULL,
  `target_holding_id` bigint(20) unsigned DEFAULT NULL,
  `source_inventory_balance_id` bigint(20) unsigned NOT NULL,
  `target_inventory_balance_id` bigint(20) unsigned DEFAULT NULL,
  `target_warehouse_id` bigint(20) unsigned NOT NULL,
  `target_location_id` bigint(20) unsigned NOT NULL,
  `target_batch_no` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `total_cost` decimal(18,4) NOT NULL,
  `reason` varchar(1000) COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(24) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `moved_by_legacy_id` bigint(20) unsigned NOT NULL,
  `moved_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_material_physical_transfers_transfer_no_unique` (`transfer_no`),
  UNIQUE KEY `erp_material_physical_transfers_client_command_id_unique` (`client_command_id`),
  KEY `material_physical_transfer_status_idx` (`physical_material_id`,`status`),
  KEY `material_physical_transfer_source_holding_fk` (`source_holding_id`),
  KEY `material_physical_transfer_target_holding_fk` (`target_holding_id`),
  KEY `material_physical_transfer_source_balance_fk` (`source_inventory_balance_id`),
  KEY `material_physical_transfer_target_balance_fk` (`target_inventory_balance_id`),
  KEY `material_physical_transfer_warehouse_fk` (`target_warehouse_id`),
  KEY `material_physical_transfer_location_fk` (`target_location_id`),
  KEY `material_physical_transfer_transaction_fk` (`inventory_transaction_id`),
  CONSTRAINT `material_physical_transfer_identity_fk` FOREIGN KEY (`physical_material_id`) REFERENCES `erp_material_physicals` (`id`),
  CONSTRAINT `material_physical_transfer_location_fk` FOREIGN KEY (`target_location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `material_physical_transfer_source_balance_fk` FOREIGN KEY (`source_inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `material_physical_transfer_source_holding_fk` FOREIGN KEY (`source_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `material_physical_transfer_target_balance_fk` FOREIGN KEY (`target_inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `material_physical_transfer_target_holding_fk` FOREIGN KEY (`target_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `material_physical_transfer_transaction_fk` FOREIGN KEY (`inventory_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`),
  CONSTRAINT `material_physical_transfer_warehouse_fk` FOREIGN KEY (`target_warehouse_id`) REFERENCES `erp_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
