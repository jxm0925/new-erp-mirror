<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_work_order_inventory_continuations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_work_order_inventory_continuations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `source_output_record_id` bigint(20) unsigned NOT NULL,
  `inventory_balance_id` bigint(20) unsigned NOT NULL,
  `inventory_serial_id` bigint(20) unsigned DEFAULT NULL,
  `production_unit_id` bigint(20) unsigned DEFAULT NULL,
  `start_routing_operation_id` bigint(20) unsigned NOT NULL,
  `start_sequence` int(10) unsigned NOT NULL,
  `base_qty` decimal(18,8) NOT NULL,
  `received_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'RESERVED',
  `source_snapshot` json NOT NULL,
  `internal_issue_task_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned NOT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_wo_continuation_target_idx` (`work_order_id`,`start_routing_operation_id`,`status`),
  KEY `erp_wo_continuation_source_idx` (`source_output_record_id`,`status`),
  KEY `erp_wo_cont_balance_fk` (`inventory_balance_id`),
  KEY `erp_wo_cont_serial_fk` (`inventory_serial_id`),
  KEY `erp_wo_cont_unit_fk` (`production_unit_id`),
  CONSTRAINT `erp_wo_cont_balance_fk` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `erp_wo_cont_output_fk` FOREIGN KEY (`source_output_record_id`) REFERENCES `erp_production_output_records` (`id`),
  CONSTRAINT `erp_wo_cont_serial_fk` FOREIGN KEY (`inventory_serial_id`) REFERENCES `erp_inventory_serials` (`id`),
  CONSTRAINT `erp_wo_cont_unit_fk` FOREIGN KEY (`production_unit_id`) REFERENCES `erp_production_units` (`id`),
  CONSTRAINT `erp_work_order_inventory_continuations_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
