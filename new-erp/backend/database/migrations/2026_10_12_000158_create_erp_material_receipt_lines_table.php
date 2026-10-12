<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_receipt_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_receipt_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_id` bigint(20) unsigned NOT NULL,
  `delivery_line_id` bigint(20) unsigned DEFAULT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `delivered_qty_snapshot` decimal(18,8) NOT NULL,
  `accepted_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `rejected_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `reject_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `accepted_serial_snapshot` json DEFAULT NULL,
  `rejected_serial_snapshot` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `picking_task_line_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_material_receipt_line_doc_fk` (`receipt_id`),
  KEY `erp_material_receipt_line_delivery_fk` (`delivery_line_id`),
  KEY `erp_material_receipt_line_item_fk` (`component_item_id`),
  KEY `erp_material_receipt_line_unit_fk` (`unit_id`),
  KEY `erp_receipt_pick_line_fk` (`picking_task_line_id`),
  CONSTRAINT `erp_material_receipt_line_delivery_fk` FOREIGN KEY (`delivery_line_id`) REFERENCES `erp_material_delivery_lines` (`id`),
  CONSTRAINT `erp_material_receipt_line_doc_fk` FOREIGN KEY (`receipt_id`) REFERENCES `erp_material_receipts` (`id`),
  CONSTRAINT `erp_material_receipt_line_item_fk` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_material_receipt_line_unit_fk` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_receipt_pick_line_fk` FOREIGN KEY (`picking_task_line_id`) REFERENCES `erp_material_picking_task_lines` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
