<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_delivery_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_delivery_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_id` bigint(20) unsigned NOT NULL,
  `material_requirement_id` bigint(20) unsigned NOT NULL,
  `picking_task_line_id` bigint(20) unsigned NOT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `delivery_qty` decimal(18,8) NOT NULL,
  `received_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `rejected_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `unit_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `serial_snapshot` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_material_delivery_pick_line_uq` (`delivery_id`,`picking_task_line_id`),
  KEY `erp_material_delivery_line_req_fk` (`material_requirement_id`),
  KEY `erp_material_delivery_line_pick_fk` (`picking_task_line_id`),
  KEY `erp_material_delivery_line_item_fk` (`component_item_id`),
  KEY `erp_material_delivery_line_unit_fk` (`unit_id`),
  CONSTRAINT `erp_material_delivery_line_doc_fk` FOREIGN KEY (`delivery_id`) REFERENCES `erp_material_deliveries` (`id`),
  CONSTRAINT `erp_material_delivery_line_item_fk` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_material_delivery_line_pick_fk` FOREIGN KEY (`picking_task_line_id`) REFERENCES `erp_material_picking_task_lines` (`id`),
  CONSTRAINT `erp_material_delivery_line_req_fk` FOREIGN KEY (`material_requirement_id`) REFERENCES `erp_work_order_material_requirements` (`id`),
  CONSTRAINT `erp_material_delivery_line_unit_fk` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
