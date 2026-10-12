<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_warehouse_receipt_allocations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_warehouse_receipt_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_id` bigint(20) unsigned NOT NULL,
  `output_allocation_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(18,8) NOT NULL,
  `total_cost` decimal(18,4) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `cut_wh_receipt_output_allocation_idx` (`output_allocation_id`,`receipt_id`),
  KEY `cut_wh_receipt_allocation_receipt_fk` (`receipt_id`),
  CONSTRAINT `cut_wh_receipt_allocation_receipt_fk` FOREIGN KEY (`receipt_id`) REFERENCES `erp_cutting_warehouse_receipts` (`id`),
  CONSTRAINT `cut_wh_receipt_output_allocation_fk` FOREIGN KEY (`output_allocation_id`) REFERENCES `erp_cutting_output_allocations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
