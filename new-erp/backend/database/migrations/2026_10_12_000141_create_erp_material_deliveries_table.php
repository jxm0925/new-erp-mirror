<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_deliveries';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `picking_task_id` bigint(20) unsigned NOT NULL,
  `delivery_wave_id` bigint(20) unsigned DEFAULT NULL,
  `assignment_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `zone_pool_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_routing_operation_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `target_operation_code_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_operation_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `production_target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `production_target_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'READY',
  `delivery_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard',
  `source_delivery_id` bigint(20) unsigned DEFAULT NULL,
  `delivery_user_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `assigned_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `assigned_at` timestamp NULL DEFAULT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `expected_receiver_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `from_warehouse_id` bigint(20) unsigned NOT NULL,
  `organization_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_production_location_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `departed_at` timestamp NULL DEFAULT NULL,
  `delivered_at` timestamp NULL DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_material_deliveries_delivery_no_unique` (`delivery_no`),
  KEY `erp_material_delivery_status_user_idx` (`status`,`delivery_user_legacy_id`),
  KEY `erp_material_delivery_wo_idx` (`work_order_id`,`status`),
  KEY `erp_material_delivery_task_fk` (`picking_task_id`),
  KEY `erp_material_delivery_wh_fk` (`from_warehouse_id`),
  KEY `erp_delivery_prod_target_idx` (`production_target_type`,`production_target_id`,`status`),
  KEY `erp_delivery_source_fk` (`source_delivery_id`),
  KEY `erp_dt_wave_status_idx` (`delivery_wave_id`,`status`),
  KEY `erp_dt_pool_status_idx` (`assignment_mode`,`zone_pool_code`,`status`),
  CONSTRAINT `erp_delivery_source_fk` FOREIGN KEY (`source_delivery_id`) REFERENCES `erp_material_deliveries` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_dt_wave_fk` FOREIGN KEY (`delivery_wave_id`) REFERENCES `erp_material_delivery_waves` (`id`),
  CONSTRAINT `erp_material_delivery_task_fk` FOREIGN KEY (`picking_task_id`) REFERENCES `erp_material_picking_tasks` (`id`),
  CONSTRAINT `erp_material_delivery_wh_fk` FOREIGN KEY (`from_warehouse_id`) REFERENCES `erp_warehouses` (`id`),
  CONSTRAINT `erp_material_delivery_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
