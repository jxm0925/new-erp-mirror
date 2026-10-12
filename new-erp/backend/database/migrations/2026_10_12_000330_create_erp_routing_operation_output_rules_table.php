<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_routing_operation_output_rules';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_routing_operation_output_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `routing_id` bigint(20) unsigned NOT NULL,
  `routing_operation_id` bigint(20) unsigned NOT NULL,
  `output_rule_key` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `line_no` int(10) unsigned NOT NULL,
  `output_role` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `base_unit_id` bigint(20) unsigned NOT NULL,
  `item_code_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_name_snapshot` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `spec_snapshot` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_unit_name_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_unit_decimal_places_snapshot` smallint(5) unsigned NOT NULL,
  `reference_item_id` bigint(20) unsigned NOT NULL,
  `reference_base_unit_id` bigint(20) unsigned NOT NULL,
  `reference_item_code_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_item_name_snapshot` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_base_unit_name_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `reference_base_unit_decimal_places_snapshot` smallint(5) unsigned NOT NULL,
  `base_qty_per_reference_unit` decimal(28,8) NOT NULL,
  `quality_mode` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `output_mode` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `allow_continue_without_warehouse` tinyint(1) NOT NULL,
  `remark` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `routing_output_rule_key_uq` (`routing_id`,`output_rule_key`),
  UNIQUE KEY `routing_output_rule_item_uq` (`routing_operation_id`,`item_id`),
  KEY `route_output_item_fk` (`item_id`),
  KEY `route_output_unit_fk` (`base_unit_id`),
  KEY `route_output_ref_item_fk` (`reference_item_id`),
  KEY `route_output_ref_unit_fk` (`reference_base_unit_id`),
  KEY `routing_output_rule_lookup_idx` (`routing_operation_id`,`line_no`),
  CONSTRAINT `route_output_item_fk` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `route_output_node_fk` FOREIGN KEY (`routing_operation_id`) REFERENCES `erp_production_routing_operations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `route_output_ref_item_fk` FOREIGN KEY (`reference_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `route_output_ref_unit_fk` FOREIGN KEY (`reference_base_unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `route_output_route_fk` FOREIGN KEY (`routing_id`) REFERENCES `erp_production_routings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `route_output_unit_fk` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
