<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_unit_conversions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_unit_conversions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `source_unit_id` bigint(20) unsigned NOT NULL,
  `target_unit_id` bigint(20) unsigned NOT NULL,
  `ratio` decimal(18,8) NOT NULL,
  `formula` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `decimal_places` tinyint(3) unsigned NOT NULL DEFAULT '4',
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_unit_conversions_source_unit_id_target_unit_id_unique` (`source_unit_id`,`target_unit_id`),
  KEY `erp_unit_conversions_target_unit_id_foreign` (`target_unit_id`),
  CONSTRAINT `erp_unit_conversions_source_unit_id_foreign` FOREIGN KEY (`source_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_unit_conversions_target_unit_id_foreign` FOREIGN KEY (`target_unit_id`) REFERENCES `erp_units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
