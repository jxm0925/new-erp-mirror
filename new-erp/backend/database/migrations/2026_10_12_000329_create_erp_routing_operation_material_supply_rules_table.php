<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_routing_operation_material_supply_rules';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_routing_operation_material_supply_rules` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `routing_operation_id` bigint(20) unsigned NOT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `target_routing_operation_id` bigint(20) unsigned NOT NULL,
  `required_qty_ratio` decimal(10,6) NOT NULL DEFAULT '1.000000',
  `supply_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'dedicated_delivery',
  `requires_delivery` tinyint(1) NOT NULL DEFAULT '1',
  `participates_in_kitting` tinyint(1) NOT NULL DEFAULT '1',
  `allow_partial_delivery` tinyint(1) NOT NULL DEFAULT '0',
  `delivery_location_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'operation_station',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_route_supply_rule_uq` (`routing_operation_id`,`component_item_id`,`target_routing_operation_id`),
  KEY `erp_route_supply_item_fk` (`component_item_id`),
  KEY `erp_route_supply_target_op_fk` (`target_routing_operation_id`),
  CONSTRAINT `erp_route_supply_item_fk` FOREIGN KEY (`component_item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_route_supply_source_op_fk` FOREIGN KEY (`routing_operation_id`) REFERENCES `erp_production_routing_operations` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_route_supply_target_op_fk` FOREIGN KEY (`target_routing_operation_id`) REFERENCES `erp_production_routing_operations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
