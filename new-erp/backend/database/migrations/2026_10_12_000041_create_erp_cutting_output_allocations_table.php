<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_output_allocations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_output_allocations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `result_id` bigint(20) unsigned NOT NULL,
  `route_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned DEFAULT NULL,
  `disposition` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `quantity` decimal(18,8) NOT NULL,
  `total_cost` decimal(18,4) NOT NULL,
  `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'EFFECTIVE',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cut_allocation_route_plan_uq` (`route_id`,`plan_id`),
  KEY `erp_cutting_output_allocations_result_id_foreign` (`result_id`),
  KEY `erp_cutting_output_allocations_plan_id_foreign` (`plan_id`),
  CONSTRAINT `erp_cutting_output_allocations_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `erp_cutting_plan_allocations` (`id`),
  CONSTRAINT `erp_cutting_output_allocations_result_id_foreign` FOREIGN KEY (`result_id`) REFERENCES `erp_cutting_results` (`id`),
  CONSTRAINT `erp_cutting_output_allocations_route_id_foreign` FOREIGN KEY (`route_id`) REFERENCES `erp_cutting_result_routes` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
