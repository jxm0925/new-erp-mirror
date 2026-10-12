<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_preparation_order_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_preparation_order_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `preparation_order_id` bigint(20) unsigned NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `material_requirement_id` bigint(20) unsigned NOT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `required_base_qty` decimal(18,8) NOT NULL,
  `prepared_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `delivered_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `received_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `planned_start_at` timestamp NULL DEFAULT NULL,
  `delivery_lead_minutes` int(10) unsigned DEFAULT NULL,
  `delivery_trigger_status` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_CONFIGURATION',
  `delivery_released_at` timestamp NULL DEFAULT NULL,
  `delivery_release_source` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `delivery_release_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `delivery_released_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_PREPARE',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_pb_line_req_uq` (`material_requirement_id`),
  KEY `erp_pb_line_order_status_idx` (`preparation_order_id`,`status`),
  KEY `erp_pb_line_wo_status_idx` (`work_order_id`,`status`),
  KEY `erp_pb_line_item_fk` (`component_item_id`),
  KEY `erp_pb_line_delivery_trigger_idx` (`delivery_trigger_status`,`planned_start_at`),
  CONSTRAINT `erp_pb_line_item_fk` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_pb_line_order_fk` FOREIGN KEY (`preparation_order_id`) REFERENCES `erp_production_preparation_orders` (`id`),
  CONSTRAINT `erp_pb_line_req_fk` FOREIGN KEY (`material_requirement_id`) REFERENCES `erp_work_order_material_requirements` (`id`),
  CONSTRAINT `erp_pb_line_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
