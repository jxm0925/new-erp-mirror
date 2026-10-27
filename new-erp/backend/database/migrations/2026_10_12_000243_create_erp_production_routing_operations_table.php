<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// 旧 output_item_id 及其约束仅保留历史事实；工序的新产出快照取工单物料，不读取或写入此列。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_routing_operations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_routing_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `routing_id` bigint(20) unsigned NOT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `sequence` int(10) unsigned NOT NULL,
  `parameters` json DEFAULT NULL,
  `is_key_operation` tinyint(1) NOT NULL DEFAULT '0',
  `standard_minutes` decimal(12,2) DEFAULT NULL,
  `setup_standard_minutes` decimal(12,2) NOT NULL DEFAULT '0.00',
  `unit_standard_minutes` decimal(12,2) DEFAULT NULL,
  `output_item_id` bigint(20) unsigned DEFAULT NULL,
  `output_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'flow_only',
  `quality_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `work_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `allow_continue_without_warehouse` tinyint(1) NOT NULL DEFAULT '1',
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `production_stage_id` bigint(20) unsigned DEFAULT NULL,
  `execution_context` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'production',
  `shipment_operation_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `packaging_scheme_id` bigint(20) unsigned DEFAULT NULL,
  `performance_rate` decimal(10,6) DEFAULT NULL,
  `packaging_materials` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_prod_route_operation_sequence_uq` (`routing_id`,`sequence`),
  KEY `erp_prod_route_operation_lookup_idx` (`routing_id`,`operation_id`),
  KEY `erp_prod_route_operation_operation_fk` (`operation_id`),
  KEY `erp_routing_op_output_item_fk` (`output_item_id`),
  KEY `erp_production_routing_operations_production_stage_id_foreign` (`production_stage_id`),
  KEY `erp_production_routing_operations_packaging_scheme_id_foreign` (`packaging_scheme_id`),
  CONSTRAINT `erp_prod_route_operation_operation_fk` FOREIGN KEY (`operation_id`) REFERENCES `erp_production_operations` (`id`),
  CONSTRAINT `erp_prod_route_operation_route_fk` FOREIGN KEY (`routing_id`) REFERENCES `erp_production_routings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_production_routing_operations_packaging_scheme_id_foreign` FOREIGN KEY (`packaging_scheme_id`) REFERENCES `erp_production_packaging_schemes` (`id`),
  CONSTRAINT `erp_production_routing_operations_production_stage_id_foreign` FOREIGN KEY (`production_stage_id`) REFERENCES `erp_production_stages` (`id`),
  CONSTRAINT `erp_routing_op_output_item_fk` FOREIGN KEY (`output_item_id`) REFERENCES `erp_items` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
