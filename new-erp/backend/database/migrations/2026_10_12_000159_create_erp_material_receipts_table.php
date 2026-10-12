<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_receipts';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_receipts` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `receipt_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `delivery_id` bigint(20) unsigned DEFAULT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'CONFIRMED',
  `received_by_legacy_id` bigint(20) unsigned NOT NULL,
  `received_at` timestamp NOT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `collection_type` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'delivery',
  `picking_task_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_material_receipts_receipt_no_unique` (`receipt_no`),
  KEY `erp_material_receipt_wo_time_idx` (`work_order_id`,`received_at`),
  KEY `erp_material_receipt_delivery_fk` (`delivery_id`),
  KEY `erp_receipt_pick_fk` (`picking_task_id`),
  CONSTRAINT `erp_material_receipt_delivery_fk` FOREIGN KEY (`delivery_id`) REFERENCES `erp_material_deliveries` (`id`),
  CONSTRAINT `erp_material_receipt_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`),
  CONSTRAINT `erp_receipt_pick_fk` FOREIGN KEY (`picking_task_id`) REFERENCES `erp_material_picking_tasks` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
