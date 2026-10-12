<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_exchange_serial_links';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_exchange_serial_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `exchange_order_id` bigint(20) unsigned NOT NULL,
  `original_serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `original_return_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `replacement_serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `replacement_receipt_status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `linked_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_pesl_order_original_uq` (`exchange_order_id`,`original_serial_no`),
  UNIQUE KEY `erp_pesl_order_replacement_uq` (`exchange_order_id`,`replacement_serial_no`),
  CONSTRAINT `erp_purchase_exchange_serial_links_exchange_order_id_foreign` FOREIGN KEY (`exchange_order_id`) REFERENCES `erp_purchase_exchange_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
