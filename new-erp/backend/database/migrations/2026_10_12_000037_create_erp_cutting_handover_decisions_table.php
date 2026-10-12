<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_handover_decisions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_handover_decisions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `handover_id` bigint(20) unsigned NOT NULL,
  `action` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(18,8) NOT NULL,
  `total_cost` decimal(18,4) NOT NULL,
  `target_holding_id` bigint(20) unsigned DEFAULT NULL,
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `operator_legacy_id` bigint(20) unsigned NOT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_cutting_handover_decisions_target_holding_id_foreign` (`target_holding_id`),
  KEY `cut_handover_decision_history_idx` (`handover_id`,`action`,`occurred_at`),
  CONSTRAINT `erp_cutting_handover_decisions_handover_id_foreign` FOREIGN KEY (`handover_id`) REFERENCES `erp_cutting_handovers` (`id`),
  CONSTRAINT `erp_cutting_handover_decisions_target_holding_id_foreign` FOREIGN KEY (`target_holding_id`) REFERENCES `erp_material_holdings` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
