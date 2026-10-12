<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_handovers';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_handovers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `handover_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `route_id` bigint(20) unsigned NOT NULL,
  `cutting_order_id` bigint(20) unsigned NOT NULL,
  `result_id` bigint(20) unsigned NOT NULL,
  `target_material_requirement_id` bigint(20) unsigned NOT NULL,
  `target_task_id` bigint(20) unsigned NOT NULL,
  `target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `source_holding_id` bigint(20) unsigned NOT NULL,
  `transit_holding_id` bigint(20) unsigned NOT NULL,
  `dispatched_qty` decimal(18,8) NOT NULL,
  `dispatched_cost` decimal(18,4) NOT NULL,
  `accepted_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `accepted_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `rejected_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `rejected_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'IN_TRANSIT',
  `expected_receiver_legacy_id` bigint(20) unsigned NOT NULL,
  `dispatched_by_legacy_id` bigint(20) unsigned NOT NULL,
  `dispatched_at` timestamp NOT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_cutting_handovers_handover_no_unique` (`handover_no`),
  KEY `erp_cutting_handovers_cutting_order_id_foreign` (`cutting_order_id`),
  KEY `erp_cutting_handovers_result_id_foreign` (`result_id`),
  KEY `erp_cutting_handovers_source_holding_id_foreign` (`source_holding_id`),
  KEY `erp_cutting_handovers_transit_holding_id_foreign` (`transit_holding_id`),
  KEY `cut_handover_target_task_status_idx` (`target_task_id`,`status`),
  KEY `cut_handover_route_status_idx` (`route_id`,`status`),
  KEY `cut_handover_target_requirement_fk` (`target_material_requirement_id`),
  CONSTRAINT `cut_handover_target_requirement_fk` FOREIGN KEY (`target_material_requirement_id`) REFERENCES `erp_production_target_material_requirements` (`id`),
  CONSTRAINT `cut_handover_target_task_fk` FOREIGN KEY (`target_task_id`) REFERENCES `erp_production_tasks` (`id`),
  CONSTRAINT `erp_cutting_handovers_cutting_order_id_foreign` FOREIGN KEY (`cutting_order_id`) REFERENCES `erp_cutting_orders` (`id`),
  CONSTRAINT `erp_cutting_handovers_result_id_foreign` FOREIGN KEY (`result_id`) REFERENCES `erp_cutting_results` (`id`),
  CONSTRAINT `erp_cutting_handovers_route_id_foreign` FOREIGN KEY (`route_id`) REFERENCES `erp_cutting_result_routes` (`id`),
  CONSTRAINT `erp_cutting_handovers_source_holding_id_foreign` FOREIGN KEY (`source_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `erp_cutting_handovers_transit_holding_id_foreign` FOREIGN KEY (`transit_holding_id`) REFERENCES `erp_material_holdings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
