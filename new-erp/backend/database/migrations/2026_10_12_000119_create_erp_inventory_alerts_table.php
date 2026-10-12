<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_alerts';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_alerts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint(20) unsigned NOT NULL,
  `warehouse_id` bigint(20) unsigned NOT NULL,
  `policy_id` bigint(20) unsigned DEFAULT NULL,
  `alert_status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `severity` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'normal',
  `is_active` tinyint(1) NOT NULL DEFAULT '0',
  `quantity_on_hand` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `quantity_locked` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `quantity_reserved` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `quantity_defective` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `available_qty` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `min_stock_snapshot` decimal(18,6) DEFAULT NULL,
  `safety_stock_snapshot` decimal(18,6) DEFAULT NULL,
  `max_stock_snapshot` decimal(18,6) DEFAULT NULL,
  `suggested_replenishment_qty_snapshot` decimal(18,6) DEFAULT NULL,
  `first_triggered_at` timestamp NULL DEFAULT NULL,
  `last_changed_at` timestamp NULL DEFAULT NULL,
  `resolved_at` timestamp NULL DEFAULT NULL,
  `is_read` tinyint(1) NOT NULL DEFAULT '0',
  `read_at` timestamp NULL DEFAULT NULL,
  `purchase_request_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_inventory_alert_item_warehouse_uq` (`item_id`,`warehouse_id`),
  KEY `erp_inventory_alerts_policy_id_foreign` (`policy_id`),
  KEY `erp_inventory_alerts_is_active_alert_status_severity_index` (`is_active`,`alert_status`,`severity`),
  KEY `erp_inventory_alerts_warehouse_id_is_read_index` (`warehouse_id`,`is_read`),
  CONSTRAINT `erp_inventory_alerts_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_inventory_alerts_policy_id_foreign` FOREIGN KEY (`policy_id`) REFERENCES `erp_inventory_alert_policies` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_inventory_alerts_warehouse_id_foreign` FOREIGN KEY (`warehouse_id`) REFERENCES `erp_warehouses` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
