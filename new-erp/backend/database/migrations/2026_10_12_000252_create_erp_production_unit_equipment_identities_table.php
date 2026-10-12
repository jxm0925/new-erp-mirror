<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_unit_equipment_identities';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_unit_equipment_identities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `production_unit_id` bigint(20) unsigned NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'NOT_APPLICABLE',
  `equipment_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `bound_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `bound_at` timestamp NULL DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_pu_equipment_identity_unit_uq` (`production_unit_id`),
  UNIQUE KEY `erp_pu_equipment_identity_no_uq` (`equipment_no`),
  KEY `erp_pu_equipment_identity_status_no_idx` (`status`,`equipment_no`),
  CONSTRAINT `erp_pu_equipment_identity_unit_fk` FOREIGN KEY (`production_unit_id`) REFERENCES `erp_production_units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
