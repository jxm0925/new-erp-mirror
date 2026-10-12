<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_shipment_packing_operations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_shipment_packing_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shipment_id` bigint(20) unsigned NOT NULL,
  `package_id` bigint(20) unsigned NOT NULL,
  `routing_id` bigint(20) unsigned DEFAULT NULL,
  `routing_version_snapshot` int(10) unsigned DEFAULT NULL,
  `routing_operation_id` bigint(20) unsigned DEFAULT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `operation_code_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operation_name_snapshot` varchar(120) COLLATE utf8mb4_unicode_ci NOT NULL,
  `production_stage_id` bigint(20) unsigned DEFAULT NULL,
  `stage_code_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stage_name_snapshot` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `performance_rate_snapshot` decimal(12,8) DEFAULT NULL,
  `sequence` int(10) unsigned NOT NULL,
  `chain_key` varchar(100) COLLATE utf8mb4_unicode_ci NOT NULL,
  `packing_content_ids` json NOT NULL,
  `packaging_materials_snapshot` json DEFAULT NULL,
  `planned_base_qty` decimal(18,8) NOT NULL,
  `completed_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `setup_standard_minutes_snapshot` decimal(12,2) DEFAULT NULL,
  `unit_standard_minutes_snapshot` decimal(12,2) DEFAULT NULL,
  `quality_mode_snapshot` varchar(40) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `quality_result` varchar(30) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inspected_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `inspected_at` timestamp NULL DEFAULT NULL,
  `inspection_remark` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAITING',
  `owner_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `claimed_at` timestamp NULL DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `is_public_snapshot` tinyint(1) NOT NULL DEFAULT '0',
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_packing_operation_chain_unique` (`package_id`,`chain_key`,`sequence`),
  KEY `erp_shipment_packing_operations_shipment_id_foreign` (`shipment_id`),
  KEY `erp_shipment_packing_operations_status_index` (`status`),
  CONSTRAINT `erp_shipment_packing_operations_package_id_foreign` FOREIGN KEY (`package_id`) REFERENCES `erp_sales_shipment_packages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_shipment_packing_operations_shipment_id_foreign` FOREIGN KEY (`shipment_id`) REFERENCES `erp_sales_shipments` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
