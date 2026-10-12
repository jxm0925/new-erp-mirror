<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_delivery_waves';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_delivery_waves` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `wave_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `production_preparation_order_id` bigint(20) unsigned NOT NULL,
  `production_master_order_id` bigint(20) unsigned NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_EXECUTION',
  `client_command_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `organization_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_material_delivery_waves_wave_no_unique` (`wave_no`),
  UNIQUE KEY `erp_material_delivery_waves_client_command_id_unique` (`client_command_id`),
  KEY `erp_dw_pb_status_idx` (`production_preparation_order_id`,`status`),
  KEY `erp_dw_mwo_status_idx` (`production_master_order_id`,`status`),
  CONSTRAINT `erp_dw_mwo_fk` FOREIGN KEY (`production_master_order_id`) REFERENCES `erp_production_master_orders` (`id`),
  CONSTRAINT `erp_dw_pb_fk` FOREIGN KEY (`production_preparation_order_id`) REFERENCES `erp_production_preparation_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
