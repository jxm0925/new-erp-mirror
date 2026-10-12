<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_units';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_units` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `unit_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `sequence_no` int(10) unsigned NOT NULL,
  `output_item_id` bigint(20) unsigned NOT NULL,
  `device_serial_id` bigint(20) unsigned DEFAULT NULL,
  `device_no_snapshot` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAITING',
  `routing_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `routing_version_snapshot` int(10) unsigned DEFAULT NULL,
  `routing_snapshot` json NOT NULL,
  `current_routing_operation_id` bigint(20) unsigned DEFAULT NULL,
  `current_operation_code_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `current_operation_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `production_location_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `organization_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_prod_unit_wo_seq_uq` (`work_order_id`,`sequence_no`),
  UNIQUE KEY `erp_production_units_unit_no_unique` (`unit_no`),
  KEY `erp_prod_unit_wo_status_idx` (`work_order_id`,`status`),
  KEY `erp_prod_unit_route_status_idx` (`current_routing_operation_id`,`status`),
  KEY `erp_prod_unit_item_fk` (`output_item_id`),
  KEY `erp_prod_unit_serial_fk` (`device_serial_id`),
  KEY `erp_prod_unit_route_fk` (`routing_id_snapshot`),
  CONSTRAINT `erp_prod_unit_current_op_fk` FOREIGN KEY (`current_routing_operation_id`) REFERENCES `erp_production_routing_operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_unit_item_fk` FOREIGN KEY (`output_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_prod_unit_route_fk` FOREIGN KEY (`routing_id_snapshot`) REFERENCES `erp_production_routings` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_unit_serial_fk` FOREIGN KEY (`device_serial_id`) REFERENCES `erp_production_serials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_unit_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
