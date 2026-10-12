<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_unit_operations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_unit_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `production_unit_id` bigint(20) unsigned NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `routing_operation_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `operation_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `operation_code_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `operation_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sequence_no_snapshot` int(10) unsigned NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_PREVIOUS',
  `responsible_user_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `kitting_confirmed_at` timestamp NULL DEFAULT NULL,
  `kitting_confirmed_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `paused_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `standard_minutes_snapshot` decimal(12,2) DEFAULT NULL,
  `setup_standard_minutes_snapshot` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit_standard_minutes_snapshot` decimal(12,2) DEFAULT NULL,
  `standard_quantity_snapshot` decimal(18,8) NOT NULL DEFAULT '1.00000000',
  `standard_time_formula_snapshot` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unit_standard',
  `actual_labor_minutes` decimal(14,2) NOT NULL DEFAULT '0.00',
  `kitting_required` tinyint(1) NOT NULL DEFAULT '0',
  `output_item_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `output_mode_snapshot` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flow_only',
  `quality_mode_snapshot` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `work_mode_snapshot` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `allow_continue_without_warehouse_snapshot` tinyint(1) NOT NULL DEFAULT '1',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `production_stage_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `stage_code_snapshot` varchar(60) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stage_name_snapshot` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `performance_rate_snapshot` decimal(10,6) DEFAULT NULL,
  `is_public_snapshot` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_prod_unit_op_seq_uq` (`production_unit_id`,`sequence_no_snapshot`),
  KEY `erp_prod_unit_op_wo_status_idx` (`work_order_id`,`status`),
  KEY `erp_prod_unit_op_route_status_idx` (`routing_operation_id_snapshot`,`status`),
  KEY `erp_prod_unit_op_operation_fk` (`operation_id_snapshot`),
  KEY `erp_prod_unit_op_output_item_fk` (`output_item_id_snapshot`),
  CONSTRAINT `erp_prod_unit_op_operation_fk` FOREIGN KEY (`operation_id_snapshot`) REFERENCES `erp_production_operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_unit_op_output_item_fk` FOREIGN KEY (`output_item_id_snapshot`) REFERENCES `erp_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_unit_op_route_op_fk` FOREIGN KEY (`routing_operation_id_snapshot`) REFERENCES `erp_production_routing_operations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_unit_op_unit_fk` FOREIGN KEY (`production_unit_id`) REFERENCES `erp_production_units` (`id`),
  CONSTRAINT `erp_prod_unit_op_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
