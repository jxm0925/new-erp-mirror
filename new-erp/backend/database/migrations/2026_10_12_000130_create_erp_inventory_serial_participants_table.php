<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_inventory_serial_participants';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_inventory_serial_participants` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `inventory_serial_id` bigint(20) unsigned NOT NULL,
  `relation_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_id` bigint(20) unsigned DEFAULT NULL,
  `user_name_snapshot` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_document_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `source_document_id` bigint(20) unsigned DEFAULT NULL,
  `effective_at` timestamp NULL DEFAULT NULL,
  `expired_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_inv_serial_participant_type_idx` (`inventory_serial_id`,`relation_type`),
  KEY `erp_inv_serial_participant_user_idx` (`user_id`,`relation_type`),
  CONSTRAINT `erp_inventory_serial_participants_inventory_serial_id_foreign` FOREIGN KEY (`inventory_serial_id`) REFERENCES `erp_inventory_serials` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
