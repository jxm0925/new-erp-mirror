<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_supplier_item_relation_logs';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_supplier_item_relation_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `relation_id` bigint(20) unsigned DEFAULT NULL,
  `supplier_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `action` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `old_snapshot` json DEFAULT NULL,
  `new_snapshot` json DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `operator_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `erp_supplier_item_relation_logs_relation_id_foreign` (`relation_id`),
  KEY `erp_supplier_item_relation_logs_item_id_foreign` (`item_id`),
  KEY `erp_supplier_item_relation_log_lookup` (`supplier_id`,`item_id`,`created_at`),
  CONSTRAINT `erp_supplier_item_relation_logs_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_supplier_item_relation_logs_relation_id_foreign` FOREIGN KEY (`relation_id`) REFERENCES `erp_supplier_item_relations` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_supplier_item_relation_logs_supplier_id_foreign` FOREIGN KEY (`supplier_id`) REFERENCES `erp_suppliers` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
