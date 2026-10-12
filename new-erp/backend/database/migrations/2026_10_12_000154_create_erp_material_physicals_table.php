<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_physicals';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_physicals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `physical_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `source_transaction_item_id` bigint(20) unsigned DEFAULT NULL,
  `material_lot_id` bigint(20) unsigned NOT NULL,
  `current_holding_id` bigint(20) unsigned NOT NULL,
  `root_physical_id` bigint(20) unsigned DEFAULT NULL,
  `parent_physical_id` bigint(20) unsigned DEFAULT NULL,
  `material_form` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `shape` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `dimensions` json NOT NULL,
  `total_cost` decimal(18,4) NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'AVAILABLE',
  `first_cut_at` timestamp NULL DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_material_physicals_physical_no_unique` (`physical_no`),
  KEY `erp_material_physicals_item_id_foreign` (`item_id`),
  KEY `erp_material_physicals_source_transaction_item_id_foreign` (`source_transaction_item_id`),
  KEY `erp_material_physicals_material_lot_id_foreign` (`material_lot_id`),
  KEY `erp_material_physicals_current_holding_id_foreign` (`current_holding_id`),
  KEY `erp_material_physicals_root_physical_id_foreign` (`root_physical_id`),
  KEY `erp_material_physicals_parent_physical_id_foreign` (`parent_physical_id`),
  CONSTRAINT `erp_material_physicals_current_holding_id_foreign` FOREIGN KEY (`current_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `erp_material_physicals_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_material_physicals_material_lot_id_foreign` FOREIGN KEY (`material_lot_id`) REFERENCES `erp_material_lots` (`id`),
  CONSTRAINT `erp_material_physicals_parent_physical_id_foreign` FOREIGN KEY (`parent_physical_id`) REFERENCES `erp_material_physicals` (`id`),
  CONSTRAINT `erp_material_physicals_root_physical_id_foreign` FOREIGN KEY (`root_physical_id`) REFERENCES `erp_material_physicals` (`id`),
  CONSTRAINT `erp_material_physicals_source_transaction_item_id_foreign` FOREIGN KEY (`source_transaction_item_id`) REFERENCES `erp_inventory_transaction_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
