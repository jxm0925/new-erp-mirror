<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_fifo_layers';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_fifo_layers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `inventory_balance_id` bigint(20) unsigned NOT NULL,
  `inbound_transaction_item_id` bigint(20) unsigned DEFAULT NULL,
  `origin_layer_id` bigint(20) unsigned DEFAULT NULL,
  `received_at` timestamp NOT NULL,
  `fifo_at` timestamp NOT NULL,
  `received_qty` decimal(20,8) NOT NULL,
  `remaining_qty` decimal(20,8) NOT NULL,
  `legacy_opening` tinyint(1) NOT NULL DEFAULT '0',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `inventory_fifo_queue` (`inventory_balance_id`,`fifo_at`,`id`),
  KEY `inventory_fifo_receipt` (`inbound_transaction_item_id`),
  CONSTRAINT `erp_inventory_fifo_layers_inbound_transaction_item_id_foreign` FOREIGN KEY (`inbound_transaction_item_id`) REFERENCES `erp_inventory_transaction_items` (`id`),
  CONSTRAINT `erp_inventory_fifo_layers_inventory_balance_id_foreign` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
