<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_rbac_user_role_sources';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_rbac_user_role_sources` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_legacy_id` bigint(20) unsigned NOT NULL,
  `role_id` bigint(20) unsigned NOT NULL,
  `assignment_source` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_user_role_source_unique` (`user_legacy_id`,`role_id`,`assignment_source`),
  KEY `erp_rbac_user_role_sources_user_legacy_id_index` (`user_legacy_id`),
  KEY `erp_rbac_user_role_sources_role_id_index` (`role_id`),
  CONSTRAINT `erp_rbac_user_role_sources_role_id_foreign` FOREIGN KEY (`role_id`) REFERENCES `erp_rbac_roles` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
