<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_settlement_batches';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_settlement_batches` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `batch_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `cutting_order_id` bigint(20) unsigned NOT NULL,
  `cutting_task_id` bigint(20) unsigned NOT NULL,
  `input_item_id` bigint(20) unsigned NOT NULL,
  `physical_material_id` bigint(20) unsigned DEFAULT NULL,
  `source_holding_id` bigint(20) unsigned NOT NULL,
  `wip_holding_id` bigint(20) unsigned DEFAULT NULL,
  `issue_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `input_qty` decimal(18,8) NOT NULL,
  `original_total_cost` decimal(18,4) NOT NULL,
  `standard_stock_length_mm` decimal(18,2) DEFAULT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PROCESSING',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `first_cut_at` timestamp NULL DEFAULT NULL,
  `submitted_at` timestamp NULL DEFAULT NULL,
  `confirmed_at` timestamp NULL DEFAULT NULL,
  `confirmed_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `active_physical_id` bigint(20) unsigned GENERATED ALWAYS AS ((case when (`status` not in (_utf8mb4'RETURNED',_utf8mb4'CONFIRMED',_utf8mb4'REVERSED')) then `physical_material_id` else NULL end)) STORED,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `correction_of_batch_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_cutting_settlement_batches_batch_no_unique` (`batch_no`),
  UNIQUE KEY `cut_one_active_issue_uq` (`active_physical_id`),
  UNIQUE KEY `cut_settlement_correction_of_uq` (`correction_of_batch_id`),
  KEY `erp_cutting_settlement_batches_cutting_order_id_foreign` (`cutting_order_id`),
  KEY `erp_cutting_settlement_batches_cutting_task_id_foreign` (`cutting_task_id`),
  KEY `erp_cutting_settlement_batches_input_item_id_foreign` (`input_item_id`),
  KEY `erp_cutting_settlement_batches_physical_material_id_foreign` (`physical_material_id`),
  KEY `erp_cutting_settlement_batches_source_holding_id_foreign` (`source_holding_id`),
  KEY `erp_cutting_settlement_batches_wip_holding_id_foreign` (`wip_holding_id`),
  KEY `erp_cutting_settlement_batches_issue_transaction_id_foreign` (`issue_transaction_id`),
  CONSTRAINT `erp_cutting_settlement_batches_correction_of_batch_id_foreign` FOREIGN KEY (`correction_of_batch_id`) REFERENCES `erp_cutting_settlement_batches` (`id`),
  CONSTRAINT `erp_cutting_settlement_batches_cutting_order_id_foreign` FOREIGN KEY (`cutting_order_id`) REFERENCES `erp_cutting_orders` (`id`),
  CONSTRAINT `erp_cutting_settlement_batches_cutting_task_id_foreign` FOREIGN KEY (`cutting_task_id`) REFERENCES `erp_cutting_tasks` (`id`),
  CONSTRAINT `erp_cutting_settlement_batches_input_item_id_foreign` FOREIGN KEY (`input_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_cutting_settlement_batches_issue_transaction_id_foreign` FOREIGN KEY (`issue_transaction_id`) REFERENCES `erp_inventory_transactions` (`id`),
  CONSTRAINT `erp_cutting_settlement_batches_physical_material_id_foreign` FOREIGN KEY (`physical_material_id`) REFERENCES `erp_material_physicals` (`id`),
  CONSTRAINT `erp_cutting_settlement_batches_source_holding_id_foreign` FOREIGN KEY (`source_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `erp_cutting_settlement_batches_wip_holding_id_foreign` FOREIGN KEY (`wip_holding_id`) REFERENCES `erp_material_holdings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
