<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_alert_policies';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_alert_policies` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `scope_key` varchar(48) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'draft',
  `is_enabled` tinyint(1) NOT NULL DEFAULT '0',
  `min_stock` decimal(18,6) DEFAULT NULL,
  `safety_stock` decimal(18,6) DEFAULT NULL,
  `max_stock` decimal(18,6) DEFAULT NULL,
  `suggested_replenishment_qty` decimal(18,6) DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `enabled_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `effective_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_inv_alert_policy_item_scope_uq` (`item_id`,`scope_key`),
  KEY `erp_inventory_alert_policies_item_id_status_index` (`item_id`,`status`),
  KEY `erp_inventory_alert_policies_warehouse_id_status_index` (`warehouse_id`,`status`),
  CONSTRAINT `erp_inventory_alert_policies_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_inventory_alert_policies_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
