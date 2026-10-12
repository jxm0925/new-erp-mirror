<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_demand_revisions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_demand_revisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `demand_id` bigint(20) unsigned NOT NULL,
  `source_change_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_business_version_before` int(10) unsigned NOT NULL,
  `source_business_version_after` int(10) unsigned NOT NULL,
  `required_base_qty_before` decimal(18,8) NOT NULL,
  `required_base_qty_after` decimal(18,8) NOT NULL,
  `quantity_delta` decimal(18,8) NOT NULL,
  `cut_length_mm_before` decimal(18,2) DEFAULT NULL,
  `cut_length_mm_after` decimal(18,2) DEFAULT NULL,
  `required_piece_qty_before` decimal(18,8) DEFAULT NULL,
  `required_piece_qty_after` decimal(18,8) DEFAULT NULL,
  `demand_status_before` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `demand_status_after` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_snapshot_before` json NOT NULL,
  `source_snapshot_after` json NOT NULL,
  `created_by_legacy_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cut_demand_revision_source_uq` (`demand_id`,`source_change_id`),
  KEY `cut_demand_revision_history_idx` (`demand_id`,`id`),
  CONSTRAINT `erp_cutting_demand_revisions_demand_id_foreign` FOREIGN KEY (`demand_id`) REFERENCES `erp_cutting_demands` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
