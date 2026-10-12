<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_assembly_inventory_reservations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_assembly_inventory_reservations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `component_demand_id` bigint(20) unsigned NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `inventory_balance_id` bigint(20) unsigned NOT NULL,
  `material_requirement_id` bigint(20) unsigned DEFAULT NULL,
  `finished_goods_receipt_id` bigint(20) unsigned DEFAULT NULL,
  `reserved_base_qty` decimal(28,8) NOT NULL,
  `consumed_base_qty` decimal(28,8) NOT NULL DEFAULT '0.00000000',
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `source_snapshot` json NOT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `reserved_at` timestamp NOT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `assembly_reservation_receipt_unique` (`finished_goods_receipt_id`),
  KEY `erp_assembly_inventory_reservations_component_demand_id_foreign` (`component_demand_id`),
  KEY `erp_assembly_inventory_reservations_work_order_id_foreign` (`work_order_id`),
  KEY `erp_assembly_inventory_reservations_inventory_balance_id_foreign` (`inventory_balance_id`),
  KEY `assembly_reservation_requirement_idx` (`material_requirement_id`),
  KEY `erp_assembly_inventory_reservations_status_index` (`status`),
  CONSTRAINT `erp_assembly_inventory_reservations_component_demand_id_foreign` FOREIGN KEY (`component_demand_id`) REFERENCES `erp_assembly_component_demands` (`id`),
  CONSTRAINT `erp_assembly_inventory_reservations_inventory_balance_id_foreign` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `erp_assembly_inventory_reservations_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
