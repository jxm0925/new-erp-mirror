<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_cutting_operations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_cutting_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `production_task_id` bigint(20) unsigned NOT NULL,
  `target_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `cutting_order_id` bigint(20) unsigned NOT NULL,
  `cutting_task_id` bigint(20) unsigned NOT NULL,
  `technical_snapshot` json NOT NULL,
  `created_by_legacy_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `production_cutting_target_unique` (`target_type`,`target_id`),
  UNIQUE KEY `production_cutting_order_unique` (`cutting_order_id`),
  UNIQUE KEY `production_cutting_task_unique` (`cutting_task_id`),
  KEY `erp_production_cutting_operations_work_order_id_foreign` (`work_order_id`),
  KEY `erp_production_cutting_operations_production_task_id_foreign` (`production_task_id`),
  CONSTRAINT `erp_production_cutting_operations_cutting_order_id_foreign` FOREIGN KEY (`cutting_order_id`) REFERENCES `erp_cutting_orders` (`id`),
  CONSTRAINT `erp_production_cutting_operations_cutting_task_id_foreign` FOREIGN KEY (`cutting_task_id`) REFERENCES `erp_cutting_tasks` (`id`),
  CONSTRAINT `erp_production_cutting_operations_production_task_id_foreign` FOREIGN KEY (`production_task_id`) REFERENCES `erp_production_tasks` (`id`),
  CONSTRAINT `erp_production_cutting_operations_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
