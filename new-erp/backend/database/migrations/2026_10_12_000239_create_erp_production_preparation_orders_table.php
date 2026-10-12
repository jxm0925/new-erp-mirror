<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_preparation_orders';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_preparation_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `preparation_order_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `production_master_order_id` bigint(20) unsigned NOT NULL,
  `active_production_master_order_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_PREPARE',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `organization_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_production_preparation_orders_preparation_order_no_unique` (`preparation_order_no`),
  UNIQUE KEY `erp_pb_active_master_uq` (`active_production_master_order_id`),
  KEY `erp_pb_status_time_idx` (`status`,`updated_at`),
  KEY `erp_pb_master_fk` (`production_master_order_id`),
  CONSTRAINT `erp_pb_active_master_fk` FOREIGN KEY (`active_production_master_order_id`) REFERENCES `erp_production_master_orders` (`id`),
  CONSTRAINT `erp_pb_master_fk` FOREIGN KEY (`production_master_order_id`) REFERENCES `erp_production_master_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
