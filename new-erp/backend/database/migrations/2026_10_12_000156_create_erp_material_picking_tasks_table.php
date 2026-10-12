<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_picking_tasks';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_picking_tasks` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `task_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_PICK',
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `organization_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `production_location_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `responsible_user_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `assigned_picker_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `planned_delivery_at` timestamp NULL DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `public_preparation_task_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_material_picking_tasks_task_no_unique` (`task_no`),
  KEY `erp_material_pick_status_wh_idx` (`status`,`warehouse_id`),
  KEY `erp_material_pick_wo_status_idx` (`work_order_id`,`status`),
  KEY `erp_material_pick_wh_fk` (`warehouse_id`),
  KEY `erp_material_pick_tx_fk` (`inventory_transaction_id`),
  KEY `erp_mpt_public_task_fk` (`public_preparation_task_id`),
  CONSTRAINT `erp_material_pick_tx_fk` FOREIGN KEY (`inventory_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_material_pick_wh_fk` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`),
  CONSTRAINT `erp_material_pick_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`),
  CONSTRAINT `erp_mpt_public_task_fk` FOREIGN KEY (`public_preparation_task_id`) REFERENCES `erp_public_material_preparation_tasks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
