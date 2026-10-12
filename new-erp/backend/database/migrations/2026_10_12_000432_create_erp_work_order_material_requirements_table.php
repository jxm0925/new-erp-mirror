<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_work_order_material_requirements';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_work_order_material_requirements` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `line_no` int(10) unsigned NOT NULL,
  `bom_id` bigint(20) unsigned NOT NULL,
  `bom_item_id` bigint(20) unsigned DEFAULT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `component_item_code_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_item_name_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_spec_snapshot` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cut_length_mm_snapshot` decimal(12,2) DEFAULT NULL,
  `per_output_piece_qty` decimal(18,8) DEFAULT NULL,
  `required_piece_qty` decimal(18,8) DEFAULT NULL,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `unit_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `per_output_qty` decimal(18,8) NOT NULL,
  `loss_rate` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `fixed_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `required_qty` decimal(18,8) NOT NULL,
  `base_unit_id` bigint(20) unsigned DEFAULT NULL,
  `base_unit_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_required_qty` decimal(18,8) NOT NULL,
  `issued_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `returned_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `remaining_qty` decimal(18,8) NOT NULL,
  `picked_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `delivered_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `received_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'OPEN',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `cutting_requirement_snapshot` json DEFAULT NULL,
  `configuration_id` bigint(20) unsigned DEFAULT NULL,
  `configuration_snapshot` json DEFAULT NULL,
  `requirement_kind` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard',
  `remaining_supply_snapshot` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_wo_material_line_uq` (`work_order_id`,`line_no`),
  KEY `erp_wo_material_status_idx` (`work_order_id`,`status`),
  KEY `erp_wo_material_item_status_idx` (`component_item_id`,`status`),
  KEY `erp_wo_material_bom_fk` (`bom_id`),
  KEY `erp_wo_material_bom_item_fk` (`bom_item_id`),
  KEY `erp_wo_material_unit_fk` (`unit_id`),
  KEY `erp_wo_material_base_unit_fk` (`base_unit_id`),
  KEY `erp_work_order_material_requirements_configuration_id_index` (`configuration_id`),
  CONSTRAINT `erp_wo_material_base_unit_fk` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_wo_material_bom_fk` FOREIGN KEY (`bom_id`) REFERENCES `erp_boms` (`id`),
  CONSTRAINT `erp_wo_material_bom_item_fk` FOREIGN KEY (`bom_item_id`) REFERENCES `erp_bom_items` (`id`),
  CONSTRAINT `erp_wo_material_item_fk` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_wo_material_unit_fk` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_wo_material_work_order_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
