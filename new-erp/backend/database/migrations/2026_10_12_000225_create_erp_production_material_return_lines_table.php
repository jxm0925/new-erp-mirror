<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_material_return_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_material_return_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_id` bigint(20) unsigned NOT NULL,
  `material_requirement_id` bigint(20) unsigned NOT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `serial_snapshot` json DEFAULT NULL,
  `return_base_qty` decimal(18,8) NOT NULL,
  `quality_disposition` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'not_required',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_prod_return_line_return_fk` (`return_id`),
  KEY `erp_prod_return_line_requirement_fk` (`material_requirement_id`),
  KEY `erp_prod_return_line_item_fk` (`component_item_id`),
  KEY `erp_prod_return_line_wh_fk` (`warehouse_id`),
  KEY `erp_prod_return_line_location_fk` (`location_id`),
  CONSTRAINT `erp_prod_return_line_item_fk` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_prod_return_line_location_fk` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_prod_return_line_requirement_fk` FOREIGN KEY (`material_requirement_id`) REFERENCES `erp_work_order_material_requirements` (`id`),
  CONSTRAINT `erp_prod_return_line_return_fk` FOREIGN KEY (`return_id`) REFERENCES `erp_production_material_returns` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_prod_return_line_wh_fk` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
