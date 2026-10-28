<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_bom_items';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_bom_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bom_id` bigint(20) unsigned NOT NULL,
  `line_no` int(10) unsigned NOT NULL DEFAULT '10',
  `component_item_id` bigint(20) unsigned NOT NULL,
  `component_item_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `component_item_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cut_length_mm` decimal(12,2) DEFAULT NULL,
  `piece_qty` int(10) unsigned DEFAULT NULL,
  `qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `unit_id` bigint(20) unsigned DEFAULT NULL,
  `loss_rate` decimal(8,4) NOT NULL DEFAULT '0.0000',
  `fixed_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `replaceable` tinyint(1) NOT NULL DEFAULT '0',
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `cut_width_mm` decimal(12,2) DEFAULT NULL,
  `cut_thickness_mm` decimal(12,2) DEFAULT NULL,
  `allow_cut_rotation` tinyint(1) NOT NULL DEFAULT '0',
  `cutting_quantity_snapshot` json DEFAULT NULL,
  `entry_unit_id` bigint(20) unsigned DEFAULT NULL,
  `entry_qty` decimal(14,4) DEFAULT NULL,
  `entry_factor` decimal(18,8) DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_bom_items_deleted_at_index` (`deleted_at`),
  KEY `erp_bom_items_unit_id_foreign` (`unit_id`),
  KEY `erp_bom_items_bom_id_line_no_index` (`bom_id`,`line_no`),
  KEY `erp_bom_items_component_item_id_index` (`component_item_id`),
  KEY `erp_bom_item_cut_idx` (`bom_id`,`component_item_id`,`cut_length_mm`),
  CONSTRAINT `erp_bom_items_bom_id_foreign` FOREIGN KEY (`bom_id`) REFERENCES `erp_boms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_bom_items_component_item_id_foreign` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_bom_items_unit_id_foreign` FOREIGN KEY (`unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
