<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_output_warehouse_postings';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_output_warehouse_postings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `posting_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `output_record_id` bigint(20) unsigned NOT NULL,
  `quality_inspection_id` bigint(20) unsigned DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posted_base_qty` decimal(18,8) NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'POSTED',
  `inventory_transaction_id` bigint(20) unsigned NOT NULL,
  `sales_order_reservation_id` bigint(20) unsigned DEFAULT NULL,
  `posted_by_legacy_id` bigint(20) unsigned NOT NULL,
  `posted_at` timestamp NOT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_prod_output_post_tx_uq` (`output_record_id`,`inventory_transaction_id`),
  UNIQUE KEY `erp_production_output_warehouse_postings_posting_no_unique` (`posting_no`),
  KEY `erp_prod_output_post_quality_fk` (`quality_inspection_id`),
  KEY `erp_prod_output_post_wh_fk` (`warehouse_id`),
  KEY `erp_prod_output_post_location_fk` (`location_id`),
  KEY `erp_prod_output_post_tx_fk` (`inventory_transaction_id`),
  KEY `erp_prod_output_post_sales_res_fk` (`sales_order_reservation_id`),
  CONSTRAINT `erp_prod_output_post_location_fk` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_prod_output_post_output_fk` FOREIGN KEY (`output_record_id`) REFERENCES `erp_production_output_records` (`id`),
  CONSTRAINT `erp_prod_output_post_quality_fk` FOREIGN KEY (`quality_inspection_id`) REFERENCES `erp_production_quality_inspections` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_output_post_sales_res_fk` FOREIGN KEY (`sales_order_reservation_id`) REFERENCES `erp_inventory_reservations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_output_post_tx_fk` FOREIGN KEY (`inventory_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`),
  CONSTRAINT `erp_prod_output_post_wh_fk` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
