<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_cutting_inputs';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_cutting_inputs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` bigint(20) unsigned NOT NULL,
  `settlement_batch_id` bigint(20) unsigned NOT NULL,
  `production_input_holding_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(20,8) NOT NULL,
  `total_cost` decimal(20,4) NOT NULL,
  `requirement_snapshot` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `production_cutting_batch_unique` (`settlement_batch_id`),
  KEY `erp_production_cutting_inputs_operation_id_foreign` (`operation_id`),
  KEY `production_cutting_input_holding_fk` (`production_input_holding_id`),
  CONSTRAINT `erp_production_cutting_inputs_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `erp_production_cutting_operations` (`id`),
  CONSTRAINT `erp_production_cutting_inputs_settlement_batch_id_foreign` FOREIGN KEY (`settlement_batch_id`) REFERENCES `erp_cutting_settlement_batches` (`id`),
  CONSTRAINT `production_cutting_input_holding_fk` FOREIGN KEY (`production_input_holding_id`) REFERENCES `erp_production_input_holdings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
