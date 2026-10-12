<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_material_supplement_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_material_supplement_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `supplement_request_id` bigint(20) unsigned NOT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `base_unit_id` bigint(20) unsigned DEFAULT NULL,
  `standard_base_qty_snapshot` decimal(18,8) NOT NULL,
  `additional_base_qty` decimal(18,8) NOT NULL,
  `generated_material_requirement_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_supplement_line_request_fk` (`supplement_request_id`),
  KEY `erp_supplement_line_item_fk` (`component_item_id`),
  KEY `erp_supplement_line_unit_fk` (`base_unit_id`),
  KEY `erp_supplement_line_requirement_fk` (`generated_material_requirement_id`),
  CONSTRAINT `erp_supplement_line_item_fk` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_supplement_line_request_fk` FOREIGN KEY (`supplement_request_id`) REFERENCES `erp_production_material_supplement_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_supplement_line_requirement_fk` FOREIGN KEY (`generated_material_requirement_id`) REFERENCES `erp_work_order_material_requirements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_supplement_line_unit_fk` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
