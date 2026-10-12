<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_work_order_technical_versions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_work_order_technical_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `version_no` int(10) unsigned NOT NULL,
  `snapshot` json NOT NULL,
  `reason` varchar(500) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `confirmed_by_legacy_id` bigint(20) unsigned NOT NULL,
  `confirmed_at` timestamp NOT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `wo_technical_version_unique` (`work_order_id`,`version_no`),
  CONSTRAINT `erp_work_order_technical_versions_work_order_id_foreign` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
