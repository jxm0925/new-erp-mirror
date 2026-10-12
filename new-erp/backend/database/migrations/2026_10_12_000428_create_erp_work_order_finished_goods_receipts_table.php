<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_work_order_finished_goods_receipts';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_work_order_finished_goods_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `completion_id` bigint(20) unsigned NOT NULL,
  `completion_line_id` bigint(20) unsigned NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `output_record_id` bigint(20) unsigned NOT NULL,
  `output_warehouse_posting_id` bigint(20) unsigned DEFAULT NULL,
  `output_item_id` bigint(20) unsigned NOT NULL,
  `base_unit_id` bigint(20) unsigned DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `location_id` bigint(20) unsigned NOT NULL,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `posted_base_qty` decimal(18,8) NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'POSTING',
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `posted_by_legacy_id` bigint(20) unsigned NOT NULL,
  `posted_at` timestamp NOT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_work_order_finished_goods_receipts_receipt_no_unique` (`receipt_no`),
  UNIQUE KEY `erp_wo_fg_receipt_posting_uq` (`output_warehouse_posting_id`),
  UNIQUE KEY `erp_wo_fg_receipt_tx_uq` (`inventory_transaction_id`),
  KEY `erp_wo_fg_receipt_wo_time_idx` (`work_order_id`,`posted_at`),
  KEY `erp_wo_fg_receipt_completion_fk` (`completion_id`),
  KEY `erp_wo_fg_receipt_line_fk` (`completion_line_id`),
  KEY `erp_wo_fg_receipt_output_fk` (`output_record_id`),
  KEY `erp_wo_fg_receipt_item_fk` (`output_item_id`),
  KEY `erp_wo_fg_receipt_unit_fk` (`base_unit_id`),
  KEY `erp_wo_fg_receipt_wh_fk` (`warehouse_id`),
  KEY `erp_wo_fg_receipt_location_fk` (`location_id`),
  CONSTRAINT `erp_wo_fg_receipt_completion_fk` FOREIGN KEY (`completion_id`) REFERENCES `erp_work_order_completions` (`id`),
  CONSTRAINT `erp_wo_fg_receipt_item_fk` FOREIGN KEY (`output_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_wo_fg_receipt_line_fk` FOREIGN KEY (`completion_line_id`) REFERENCES `erp_work_order_completion_lines` (`id`),
  CONSTRAINT `erp_wo_fg_receipt_location_fk` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`),
  CONSTRAINT `erp_wo_fg_receipt_output_fk` FOREIGN KEY (`output_record_id`) REFERENCES `erp_production_output_records` (`id`),
  CONSTRAINT `erp_wo_fg_receipt_posting_fk` FOREIGN KEY (`output_warehouse_posting_id`) REFERENCES `erp_production_output_warehouse_postings` (`id`),
  CONSTRAINT `erp_wo_fg_receipt_tx_fk` FOREIGN KEY (`inventory_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`),
  CONSTRAINT `erp_wo_fg_receipt_unit_fk` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_wo_fg_receipt_wh_fk` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`),
  CONSTRAINT `erp_wo_fg_receipt_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
