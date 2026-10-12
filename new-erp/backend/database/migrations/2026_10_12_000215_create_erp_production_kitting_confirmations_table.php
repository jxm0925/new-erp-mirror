<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_kitting_confirmations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_kitting_confirmations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `confirmation_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `task_id` bigint(20) unsigned NOT NULL,
  `target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `target_routing_operation_id_snapshot` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CONFIRMED',
  `required_materials_snapshot` json NOT NULL,
  `received_materials_snapshot` json NOT NULL,
  `shortage_materials_snapshot` json NOT NULL,
  `confirmed_by_legacy_id` bigint(20) unsigned NOT NULL,
  `confirmed_at` timestamp NOT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_production_kitting_confirmations_confirmation_no_unique` (`confirmation_no`),
  KEY `erp_kitting_target_idx` (`target_type`,`target_id`,`confirmed_at`),
  KEY `erp_kitting_wo_fk` (`work_order_id`),
  KEY `erp_kitting_task_fk` (`task_id`),
  CONSTRAINT `erp_kitting_task_fk` FOREIGN KEY (`task_id`) REFERENCES `erp_production_tasks` (`id`),
  CONSTRAINT `erp_kitting_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
