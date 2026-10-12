<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_physical_disposals';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_physical_disposals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `disposal_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `client_command_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `command_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `expected_version` int(10) unsigned DEFAULT NULL,
  `physical_material_id` bigint(20) unsigned NOT NULL,
  `source_holding_id` bigint(20) unsigned NOT NULL,
  `disposal_holding_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` decimal(18,8) NOT NULL,
  `total_cost` decimal(18,4) NOT NULL,
  `reason` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'POSTED',
  `disposed_by_legacy_id` bigint(20) unsigned NOT NULL,
  `disposed_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_material_physical_disposals_disposal_no_unique` (`disposal_no`),
  UNIQUE KEY `material_physical_disposal_uq` (`physical_material_id`),
  UNIQUE KEY `erp_material_physical_disposals_disposal_holding_id_unique` (`disposal_holding_id`),
  UNIQUE KEY `erp_material_physical_disposals_client_command_id_unique` (`client_command_id`),
  KEY `erp_material_physical_disposals_source_holding_id_foreign` (`source_holding_id`),
  KEY `material_physical_disposal_transaction_fk` (`inventory_transaction_id`),
  CONSTRAINT `erp_material_physical_disposals_disposal_holding_id_foreign` FOREIGN KEY (`disposal_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `erp_material_physical_disposals_physical_material_id_foreign` FOREIGN KEY (`physical_material_id`) REFERENCES `erp_material_physicals` (`id`),
  CONSTRAINT `erp_material_physical_disposals_source_holding_id_foreign` FOREIGN KEY (`source_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `material_physical_disposal_transaction_fk` FOREIGN KEY (`inventory_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
