<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_shipment_packing_materials';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_shipment_packing_materials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` bigint(20) unsigned NOT NULL,
  `inventory_balance_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `base_qty` decimal(18,8) NOT NULL,
  `serial_snapshot` json DEFAULT NULL,
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_transaction_item_id` bigint(20) unsigned DEFAULT NULL,
  `cost_amount_snapshot` decimal(18,4) DEFAULT NULL,
  `posted_by_legacy_id` bigint(20) unsigned NOT NULL,
  `posted_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_shipment_packing_materials_operation_id_foreign` (`operation_id`),
  KEY `erp_shipment_packing_materials_inventory_balance_id_foreign` (`inventory_balance_id`),
  KEY `erp_shipment_packing_materials_item_id_foreign` (`item_id`),
  CONSTRAINT `erp_shipment_packing_materials_inventory_balance_id_foreign` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `erp_shipment_packing_materials_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_shipment_packing_materials_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `erp_shipment_packing_operations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
