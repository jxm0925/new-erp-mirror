<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_delivery_task_lines';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_delivery_task_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_task_id` bigint(20) unsigned NOT NULL,
  `material_delivery_id` bigint(20) unsigned NOT NULL,
  `material_delivery_line_id` bigint(20) unsigned NOT NULL,
  `allocated_qty` decimal(18,8) NOT NULL,
  `serial_snapshot` json DEFAULT NULL,
  `handling_unit_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_EXECUTION',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_dt_line_task_pdline_uq` (`delivery_task_id`,`material_delivery_line_id`),
  KEY `erp_dt_line_pd_status_idx` (`material_delivery_line_id`,`status`),
  KEY `erp_dt_line_pd_fk` (`material_delivery_id`),
  CONSTRAINT `erp_dt_line_pd_fk` FOREIGN KEY (`material_delivery_id`) REFERENCES `erp_material_deliveries` (`id`),
  CONSTRAINT `erp_dt_line_pdline_fk` FOREIGN KEY (`material_delivery_line_id`) REFERENCES `erp_material_delivery_lines` (`id`),
  CONSTRAINT `erp_dt_line_task_fk` FOREIGN KEY (`delivery_task_id`) REFERENCES `erp_material_delivery_tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
