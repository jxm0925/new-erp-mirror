<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_delivery_task_events';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_delivery_task_events` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_task_id` bigint(20) unsigned NOT NULL,
  `action` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `before_version` int(10) unsigned NOT NULL,
  `after_version` int(10) unsigned NOT NULL,
  `snapshot` json DEFAULT NULL,
  `operator_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_dt_event_task_idx` (`delivery_task_id`,`id`),
  CONSTRAINT `erp_dt_event_task_fk` FOREIGN KEY (`delivery_task_id`) REFERENCES `erp_material_delivery_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
