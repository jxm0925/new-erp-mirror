<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_purchase_return_item_serials';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_purchase_return_item_serials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_return_item_id` bigint(20) unsigned NOT NULL,
  `inventory_serial_id` bigint(20) unsigned NOT NULL,
  `serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_pris_item_serial_uq` (`purchase_return_item_id`,`inventory_serial_id`),
  KEY `erp_pris_serial_no_idx` (`inventory_serial_id`,`serial_no`),
  CONSTRAINT `erp_purchase_return_item_serials_inventory_serial_id_foreign` FOREIGN KEY (`inventory_serial_id`) REFERENCES `erp_inventory_serials` (`id`),
  CONSTRAINT `erp_purchase_return_item_serials_purchase_return_item_id_foreign` FOREIGN KEY (`purchase_return_item_id`) REFERENCES `erp_purchase_return_items` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
