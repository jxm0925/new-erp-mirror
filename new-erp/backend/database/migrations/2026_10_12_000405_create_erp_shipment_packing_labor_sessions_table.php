<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_shipment_packing_labor_sessions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_shipment_packing_labor_sessions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `operation_id` bigint(20) unsigned NOT NULL,
  `employee_legacy_id` bigint(20) unsigned NOT NULL,
  `status` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `started_at` timestamp NOT NULL,
  `ended_at` timestamp NULL DEFAULT NULL,
  `actual_labor_minutes` decimal(12,2) NOT NULL DEFAULT '0.00',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `active_employee_legacy_id` bigint(20) unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'ACTIVE') then `employee_legacy_id` else NULL end)) STORED,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_packing_labor_one_active_employee_uq` (`active_employee_legacy_id`),
  KEY `erp_packing_labor_actor_idx` (`operation_id`,`employee_legacy_id`,`status`),
  KEY `erp_packing_labor_employee_status_idx` (`employee_legacy_id`,`status`,`started_at`),
  CONSTRAINT `erp_shipment_packing_labor_sessions_operation_id_foreign` FOREIGN KEY (`operation_id`) REFERENCES `erp_shipment_packing_operations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
