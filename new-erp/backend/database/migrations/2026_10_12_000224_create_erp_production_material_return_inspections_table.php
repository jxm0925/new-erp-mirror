<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_material_return_inspections';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_material_return_inspections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `inspection_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `return_id` bigint(20) unsigned NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `result` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `inspector_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `inspected_at` timestamp NULL DEFAULT NULL,
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_production_material_return_inspections_inspection_no_unique` (`inspection_no`),
  KEY `erp_prod_return_quality_return_fk` (`return_id`),
  KEY `erp_prod_return_quality_tx_fk` (`inventory_transaction_id`),
  CONSTRAINT `erp_prod_return_quality_return_fk` FOREIGN KEY (`return_id`) REFERENCES `erp_production_material_returns` (`id`),
  CONSTRAINT `erp_prod_return_quality_tx_fk` FOREIGN KEY (`inventory_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
