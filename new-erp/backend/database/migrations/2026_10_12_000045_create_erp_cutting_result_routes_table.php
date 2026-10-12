<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_result_routes';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_result_routes` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `result_id` bigint(20) unsigned NOT NULL,
  `route_type` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_material_requirement_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` decimal(18,8) NOT NULL,
  `handed_over_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `total_cost` decimal(18,4) DEFAULT NULL,
  `handed_over_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `received_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `warehoused_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `received_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `warehoused_cost` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PLANNED',
  `holding_id` bigint(20) unsigned DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_cutting_result_routes_result_id_foreign` (`result_id`),
  KEY `erp_cutting_result_routes_holding_id_foreign` (`holding_id`),
  KEY `cut_route_target_fk` (`target_material_requirement_id`),
  CONSTRAINT `cut_route_target_fk` FOREIGN KEY (`target_material_requirement_id`) REFERENCES `erp_production_target_material_requirements` (`id`),
  CONSTRAINT `erp_cutting_result_routes_holding_id_foreign` FOREIGN KEY (`holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `erp_cutting_result_routes_result_id_foreign` FOREIGN KEY (`result_id`) REFERENCES `erp_cutting_results` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
