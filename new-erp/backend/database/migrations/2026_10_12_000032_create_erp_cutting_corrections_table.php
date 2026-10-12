<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_corrections';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_corrections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `correction_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `original_settlement_batch_id` bigint(20) unsigned NOT NULL,
  `correction_settlement_batch_id` bigint(20) unsigned NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'OPEN',
  `reason` varchar(1000) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_by_legacy_id` bigint(20) unsigned NOT NULL,
  `reversed_at` timestamp NOT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_cutting_corrections_correction_no_unique` (`correction_no`),
  UNIQUE KEY `cut_correction_original_uq` (`original_settlement_batch_id`),
  UNIQUE KEY `cut_correction_replacement_uq` (`correction_settlement_batch_id`),
  CONSTRAINT `erp_cutting_corrections_correction_settlement_batch_id_foreign` FOREIGN KEY (`correction_settlement_batch_id`) REFERENCES `erp_cutting_settlement_batches` (`id`),
  CONSTRAINT `erp_cutting_corrections_original_settlement_batch_id_foreign` FOREIGN KEY (`original_settlement_batch_id`) REFERENCES `erp_cutting_settlement_batches` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
