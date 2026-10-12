<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_output_lineage_links';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_output_lineage_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_output_record_id` bigint(20) unsigned NOT NULL,
  `child_output_record_id` bigint(20) unsigned NOT NULL,
  `relation_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `parent_inventory_serial_id` bigint(20) unsigned DEFAULT NULL,
  `child_inventory_serial_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `parent_base_qty` decimal(18,8) DEFAULT NULL,
  `child_base_qty` decimal(18,8) DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_prod_output_lineage_uq` (`parent_output_record_id`,`child_output_record_id`),
  KEY `erp_prod_output_lineage_child_idx` (`child_output_record_id`,`relation_type`),
  KEY `erp_prod_lineage_parent_serial_fk` (`parent_inventory_serial_id`),
  KEY `erp_prod_lineage_child_serial_fk` (`child_inventory_serial_id`),
  CONSTRAINT `erp_prod_lineage_child_output_fk` FOREIGN KEY (`child_output_record_id`) REFERENCES `erp_production_output_records` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_prod_lineage_child_serial_fk` FOREIGN KEY (`child_inventory_serial_id`) REFERENCES `erp_inventory_serials` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_prod_lineage_parent_output_fk` FOREIGN KEY (`parent_output_record_id`) REFERENCES `erp_production_output_records` (`id`),
  CONSTRAINT `erp_prod_lineage_parent_serial_fk` FOREIGN KEY (`parent_inventory_serial_id`) REFERENCES `erp_inventory_serials` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
