<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_item_material_policies';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_item_material_policies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint(20) unsigned NOT NULL,
  `version_no` int(10) unsigned NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `template_code` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_stock_managed` tinyint(1) NOT NULL DEFAULT '1',
  `inventory_management_mode` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'standard',
  `requires_custodian` tinyint(1) NOT NULL DEFAULT '0',
  `is_returnable` tinyint(1) NOT NULL DEFAULT '0',
  `requires_capitalization` tinyint(1) NOT NULL DEFAULT '0',
  `serial_tracking_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `production_execution_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'unit',
  `serial_generation_stage` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'before_finished_goods_posting',
  `serial_generation_routing_operation_id` bigint(20) unsigned DEFAULT NULL,
  `post_purchase_action` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inventory_receipt',
  `consumption_confirmation_mode` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'none',
  `future_route` varchar(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inventory',
  `future_bearer_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'company',
  `parameter_snapshot` json NOT NULL,
  `change_reason` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `enabled_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `effective_at` timestamp NULL DEFAULT NULL,
  `expired_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_item_material_policy_version_unique` (`item_id`,`version_no`),
  KEY `erp_item_material_policy_effective_index` (`item_id`,`status`,`effective_at`),
  KEY `erp_item_material_policies_status_index` (`status`),
  KEY `erp_item_material_policies_created_by_legacy_id_index` (`created_by_legacy_id`),
  KEY `erp_item_material_policies_enabled_by_legacy_id_index` (`enabled_by_legacy_id`),
  KEY `erp_item_material_policies_effective_at_index` (`effective_at`),
  KEY `erp_item_material_policies_expired_at_index` (`expired_at`),
  KEY `erp_item_policy_exec_mode_idx` (`production_execution_mode`,`status`),
  KEY `erp_item_policy_serial_route_op_fk` (`serial_generation_routing_operation_id`),
  CONSTRAINT `erp_item_material_policies_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_item_policy_serial_route_op_fk` FOREIGN KEY (`serial_generation_routing_operation_id`) REFERENCES `erp_production_routing_operations` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
