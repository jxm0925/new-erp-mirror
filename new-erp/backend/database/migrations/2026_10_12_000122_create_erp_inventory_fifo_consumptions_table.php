<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_fifo_consumptions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_fifo_consumptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `transaction_item_id` bigint(20) unsigned NOT NULL,
  `layer_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(20,8) NOT NULL,
  `created_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `inventory_fifo_consumption_unique` (`transaction_item_id`,`layer_id`),
  KEY `erp_inventory_fifo_consumptions_layer_id_foreign` (`layer_id`),
  CONSTRAINT `erp_inventory_fifo_consumptions_layer_id_foreign` FOREIGN KEY (`layer_id`) REFERENCES `erp_inventory_fifo_layers` (`id`),
  CONSTRAINT `erp_inventory_fifo_consumptions_transaction_item_id_foreign` FOREIGN KEY (`transaction_item_id`) REFERENCES `erp_inventory_transaction_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
