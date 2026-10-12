<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_assembly_production_plans';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_assembly_production_plans` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `root_work_order_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL,
  `plan_version` int(10) unsigned NOT NULL DEFAULT '1',
  `work_order_version` int(10) unsigned NOT NULL,
  `input_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `command_id` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `request_hash` varchar(64) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `plan_snapshot` json NOT NULL,
  `prepared_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `organization_code` varchar(80) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prepared_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_assembly_production_plans_root_work_order_id_unique` (`root_work_order_id`),
  UNIQUE KEY `erp_assembly_production_plans_command_id_unique` (`command_id`),
  CONSTRAINT `erp_assembly_production_plans_root_work_order_id_foreign` FOREIGN KEY (`root_work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
