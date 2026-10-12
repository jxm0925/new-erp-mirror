<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_work_order_planned_outputs';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_work_order_planned_outputs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `line_uuid` char(36) COLLATE utf8mb4_unicode_ci NOT NULL,
  `line_no` int(10) unsigned NOT NULL,
  `is_reference` tinyint(1) NOT NULL DEFAULT '0',
  `active_reference_work_order_id` bigint(20) unsigned DEFAULT NULL,
  `output_role` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `base_unit_id` bigint(20) unsigned NOT NULL,
  `item_code_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_name_snapshot` varchar(160) COLLATE utf8mb4_unicode_ci NOT NULL,
  `spec_snapshot` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_unit_name_snapshot` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_unit_decimal_places_snapshot` smallint(5) unsigned NOT NULL,
  `planned_base_qty` decimal(28,8) NOT NULL,
  `remark` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wo_planned_output_uuid_uq` (`line_uuid`),
  UNIQUE KEY `wo_planned_reference_uq` (`active_reference_work_order_id`),
  KEY `erp_work_order_planned_outputs_item_id_foreign` (`item_id`),
  KEY `erp_work_order_planned_outputs_base_unit_id_foreign` (`base_unit_id`),
  KEY `wo_planned_output_lookup_idx` (`work_order_id`,`status`,`line_no`),
  CONSTRAINT `erp_work_order_planned_outputs_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `erp_work_order_planned_outputs_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_work_order_planned_outputs_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
