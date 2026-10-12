<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_inventory_reservations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_inventory_reservations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `receipt_allocation_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned DEFAULT NULL,
  `configuration_id` bigint(20) unsigned DEFAULT NULL,
  `target_material_requirement_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_balance_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `reservation_scope` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reserved_qty` decimal(18,8) NOT NULL,
  `issued_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `created_by_legacy_id` bigint(20) unsigned NOT NULL,
  `reserved_at` timestamp NOT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `issued_total_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `released_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `released_total_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `released_target_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_cutting_inventory_reservations_reservation_no_unique` (`reservation_no`),
  UNIQUE KEY `cut_inv_res_receipt_allocation_uq` (`receipt_allocation_id`),
  KEY `erp_cutting_inventory_reservations_plan_id_foreign` (`plan_id`),
  KEY `erp_cutting_inventory_reservations_inventory_balance_id_foreign` (`inventory_balance_id`),
  KEY `erp_cutting_inventory_reservations_item_id_foreign` (`item_id`),
  KEY `cut_inv_res_target_status_idx` (`target_material_requirement_id`,`status`),
  KEY `cut_inv_res_config_status_idx` (`configuration_id`,`status`),
  CONSTRAINT `cut_inv_res_receipt_allocation_fk` FOREIGN KEY (`receipt_allocation_id`) REFERENCES `erp_cutting_warehouse_receipt_allocations` (`id`),
  CONSTRAINT `cut_inv_res_target_requirement_fk` FOREIGN KEY (`target_material_requirement_id`) REFERENCES `erp_production_target_material_requirements` (`id`),
  CONSTRAINT `erp_cutting_inventory_reservations_configuration_id_foreign` FOREIGN KEY (`configuration_id`) REFERENCES `erp_custom_configurations` (`id`),
  CONSTRAINT `erp_cutting_inventory_reservations_inventory_balance_id_foreign` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `erp_cutting_inventory_reservations_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_cutting_inventory_reservations_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `erp_cutting_plan_allocations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
