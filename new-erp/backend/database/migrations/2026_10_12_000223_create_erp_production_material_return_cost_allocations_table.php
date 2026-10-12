<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_material_return_cost_allocations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_material_return_cost_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_line_id` bigint(20) unsigned NOT NULL,
  `source_input_holding_id` bigint(20) unsigned NOT NULL,
  `inventory_transaction_item_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` decimal(18,8) NOT NULL,
  `total_cost` decimal(18,4) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `physical_material_ids` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_return_cost_line_holding_uq` (`return_line_id`,`source_input_holding_id`),
  KEY `prod_return_cost_holding_fk` (`source_input_holding_id`),
  KEY `prod_return_cost_tx_item_fk` (`inventory_transaction_item_id`),
  CONSTRAINT `prod_return_cost_holding_fk` FOREIGN KEY (`source_input_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `prod_return_cost_line_fk` FOREIGN KEY (`return_line_id`) REFERENCES `erp_production_material_return_lines` (`id`),
  CONSTRAINT `prod_return_cost_tx_item_fk` FOREIGN KEY (`inventory_transaction_item_id`) REFERENCES `erp_inventory_transaction_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
