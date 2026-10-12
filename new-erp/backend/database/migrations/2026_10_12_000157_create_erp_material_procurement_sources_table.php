<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_procurement_sources';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_procurement_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `request_id` bigint(20) unsigned NOT NULL,
  `request_item_id` bigint(20) unsigned DEFAULT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `sales_order_id` bigint(20) unsigned DEFAULT NULL,
  `work_order_id` bigint(20) unsigned DEFAULT NULL,
  `target_material_requirement_id` bigint(20) unsigned DEFAULT NULL,
  `requested_base_qty` decimal(18,8) NOT NULL,
  `source_snapshot` json DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `preparation_material_requirement_id` bigint(20) unsigned DEFAULT NULL,
  `preparation_version` int(10) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_material_procurement_sources_request_id_foreign` (`request_id`),
  KEY `erp_material_procurement_sources_request_item_id_foreign` (`request_item_id`),
  KEY `erp_material_procurement_sources_component_item_id_foreign` (`component_item_id`),
  KEY `erp_material_procurement_sources_sales_order_id_foreign` (`sales_order_id`),
  KEY `erp_material_procurement_sources_work_order_id_foreign` (`work_order_id`),
  KEY `erp_procurement_demand_fk` (`target_material_requirement_id`),
  KEY `erp_procurement_prep_fk` (`preparation_material_requirement_id`),
  CONSTRAINT `erp_material_procurement_sources_component_item_id_foreign` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_material_procurement_sources_request_id_foreign` FOREIGN KEY (`request_id`) REFERENCES `erp_purchase_requests` (`id`),
  CONSTRAINT `erp_material_procurement_sources_request_item_id_foreign` FOREIGN KEY (`request_item_id`) REFERENCES `erp_purchase_request_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_material_procurement_sources_sales_order_id_foreign` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`),
  CONSTRAINT `erp_material_procurement_sources_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`),
  CONSTRAINT `erp_procurement_demand_fk` FOREIGN KEY (`target_material_requirement_id`) REFERENCES `erp_production_target_material_requirements` (`id`),
  CONSTRAINT `erp_procurement_prep_fk` FOREIGN KEY (`preparation_material_requirement_id`) REFERENCES `erp_work_order_preparation_materials` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
