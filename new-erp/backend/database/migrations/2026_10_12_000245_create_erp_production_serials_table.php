<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_serials';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_serials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `serial_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `generation_stage` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'generated',
  `source_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_serial_id` bigint(20) unsigned DEFAULT NULL,
  `generated_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_production_serials_serial_no_unique` (`serial_no`),
  KEY `erp_prod_serial_source_idx` (`source_type`,`source_id`),
  KEY `erp_prod_serial_item_fk` (`item_id`),
  KEY `erp_prod_serial_inventory_fk` (`inventory_serial_id`),
  CONSTRAINT `erp_prod_serial_inventory_fk` FOREIGN KEY (`inventory_serial_id`) REFERENCES `erp_inventory_serials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_serial_item_fk` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
