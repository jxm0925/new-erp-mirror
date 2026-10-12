<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_work_order_completion_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_work_order_completion_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `completion_id` bigint(20) unsigned NOT NULL,
  `output_record_id` bigint(20) unsigned NOT NULL,
  `source_target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_target_id` bigint(20) unsigned NOT NULL,
  `output_item_id` bigint(20) unsigned NOT NULL,
  `base_unit_id` bigint(20) unsigned DEFAULT NULL,
  `submitted_base_qty` decimal(18,8) NOT NULL,
  `qualified_base_qty` decimal(18,8) NOT NULL,
  `unqualified_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `scrapped_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_wo_completion_line_output_uq` (`completion_id`,`output_record_id`),
  KEY `erp_wo_completion_output_idx` (`output_record_id`,`completion_id`),
  KEY `erp_wo_completion_line_item_fk` (`output_item_id`),
  KEY `erp_wo_completion_line_unit_fk` (`base_unit_id`),
  CONSTRAINT `erp_wo_completion_line_item_fk` FOREIGN KEY (`output_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_wo_completion_line_output_fk` FOREIGN KEY (`output_record_id`) REFERENCES `erp_production_output_records` (`id`),
  CONSTRAINT `erp_wo_completion_line_parent_fk` FOREIGN KEY (`completion_id`) REFERENCES `erp_work_order_completions` (`id`),
  CONSTRAINT `erp_wo_completion_line_unit_fk` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
