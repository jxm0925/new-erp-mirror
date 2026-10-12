<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_holdings';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_holdings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `material_lot_id` bigint(20) unsigned NOT NULL,
  `position_type` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `position_id` bigint(20) unsigned NOT NULL,
  `inventory_balance_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` decimal(18,8) DEFAULT NULL,
  `total_cost` decimal(18,4) DEFAULT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cut_holding_balance_uq` (`inventory_balance_id`),
  KEY `erp_material_holdings_material_lot_id_foreign` (`material_lot_id`),
  CONSTRAINT `erp_material_holdings_inventory_balance_id_foreign` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `erp_material_holdings_material_lot_id_foreign` FOREIGN KEY (`material_lot_id`) REFERENCES `erp_material_lots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
        'cut_holding_authority_ck' => '(position_type = \'WAREHOUSE\' AND inventory_balance_id IS NOT NULL AND quantity IS NULL AND total_cost IS NULL) OR (position_type <> \'WAREHOUSE\' AND inventory_balance_id IS NULL AND quantity IS NOT NULL AND total_cost IS NOT NULL AND quantity >= 0 AND total_cost >= 0)',
    ];
};
