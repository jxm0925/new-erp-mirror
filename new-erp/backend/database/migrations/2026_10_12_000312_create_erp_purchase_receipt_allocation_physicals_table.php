<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_receipt_allocation_physicals';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_receipt_allocation_physicals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `allocation_id` bigint(20) unsigned NOT NULL,
  `sequence_no` int(10) unsigned NOT NULL,
  `dimensions` json NOT NULL,
  `physical_material_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_transaction_item_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `purchase_receipt_allocation_physical_sequence_uq` (`allocation_id`,`sequence_no`),
  UNIQUE KEY `purchase_receipt_physical_identity_uq` (`physical_material_id`),
  KEY `purchase_receipt_physical_tx_item_fk` (`inventory_transaction_item_id`),
  CONSTRAINT `purchase_receipt_physical_allocation_fk` FOREIGN KEY (`allocation_id`) REFERENCES `erp_purchase_receipt_item_allocations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `purchase_receipt_physical_identity_fk` FOREIGN KEY (`physical_material_id`) REFERENCES `erp_material_physicals` (`id`),
  CONSTRAINT `purchase_receipt_physical_tx_item_fk` FOREIGN KEY (`inventory_transaction_item_id`) REFERENCES `erp_inventory_transaction_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
