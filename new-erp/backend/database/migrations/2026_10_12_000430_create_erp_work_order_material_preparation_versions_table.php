<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_work_order_material_preparation_versions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_work_order_material_preparation_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `preparation_id` bigint(20) unsigned NOT NULL,
  `version` int(10) unsigned NOT NULL,
  `input_hash` varchar(64) COLLATE utf8mb4_unicode_ci NOT NULL,
  `input_snapshot` json NOT NULL,
  `prepared_by_legacy_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_wompv_version_uq` (`preparation_id`,`version`),
  CONSTRAINT `erp_wompv_prep_fk` FOREIGN KEY (`preparation_id`) REFERENCES `erp_work_order_material_preparations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
