<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_output_records';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_output_records` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `output_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `source_target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_target_id` bigint(20) unsigned NOT NULL,
  `production_unit_id` bigint(20) unsigned DEFAULT NULL,
  `output_item_id` bigint(20) unsigned DEFAULT NULL,
  `output_base_qty` decimal(18,8) NOT NULL,
  `output_mode_snapshot` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quality_mode_snapshot` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CREATED',
  `serial_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_serial_id` bigint(20) unsigned DEFAULT NULL,
  `serial_no_snapshot` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disposition` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned NOT NULL,
  `produced_at` timestamp NOT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `material_lot_id` bigint(20) unsigned DEFAULT NULL,
  `material_holding_id` bigint(20) unsigned DEFAULT NULL,
  `material_total_cost` decimal(18,4) DEFAULT NULL,
  `material_loss_cost` decimal(18,4) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_production_output_records_output_no_unique` (`output_no`),
  UNIQUE KEY `erp_prod_output_source_uq` (`source_target_type`,`source_target_id`),
  KEY `erp_prod_output_source_idx` (`source_target_type`,`source_target_id`),
  KEY `erp_prod_output_wo_status_idx` (`work_order_id`,`status`),
  KEY `erp_prod_output_unit_fk` (`production_unit_id`),
  KEY `erp_prod_output_item_fk` (`output_item_id`),
  KEY `erp_prod_output_serial_fk` (`serial_id`),
  KEY `erp_prod_output_inventory_serial_fk` (`inventory_serial_id`),
  KEY `erp_production_output_records_material_lot_id_foreign` (`material_lot_id`),
  KEY `erp_production_output_records_material_holding_id_foreign` (`material_holding_id`),
  CONSTRAINT `erp_prod_output_inventory_serial_fk` FOREIGN KEY (`inventory_serial_id`) REFERENCES `erp_inventory_serials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_output_item_fk` FOREIGN KEY (`output_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_prod_output_serial_fk` FOREIGN KEY (`serial_id`) REFERENCES `erp_production_serials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_output_unit_fk` FOREIGN KEY (`production_unit_id`) REFERENCES `erp_production_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_output_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`),
  CONSTRAINT `erp_production_output_records_material_holding_id_foreign` FOREIGN KEY (`material_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `erp_production_output_records_material_lot_id_foreign` FOREIGN KEY (`material_lot_id`) REFERENCES `erp_material_lots` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
