<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_allowed_outputs';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_allowed_outputs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `cutting_order_id` bigint(20) unsigned NOT NULL,
  `plan_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `configuration_id` bigint(20) unsigned DEFAULT NULL,
  `stage_id` bigint(20) unsigned DEFAULT NULL,
  `quality_mode` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `output_mode` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_mode` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `rule_snapshot` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_cutting_allowed_outputs_cutting_order_id_foreign` (`cutting_order_id`),
  KEY `erp_cutting_allowed_outputs_plan_id_foreign` (`plan_id`),
  KEY `erp_cutting_allowed_outputs_item_id_foreign` (`item_id`),
  KEY `erp_cutting_allowed_outputs_configuration_id_foreign` (`configuration_id`),
  KEY `erp_cutting_allowed_outputs_stage_id_foreign` (`stage_id`),
  CONSTRAINT `erp_cutting_allowed_outputs_configuration_id_foreign` FOREIGN KEY (`configuration_id`) REFERENCES `erp_custom_configurations` (`id`),
  CONSTRAINT `erp_cutting_allowed_outputs_cutting_order_id_foreign` FOREIGN KEY (`cutting_order_id`) REFERENCES `erp_cutting_orders` (`id`),
  CONSTRAINT `erp_cutting_allowed_outputs_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_cutting_allowed_outputs_plan_id_foreign` FOREIGN KEY (`plan_id`) REFERENCES `erp_cutting_plan_allocations` (`id`),
  CONSTRAINT `erp_cutting_allowed_outputs_stage_id_foreign` FOREIGN KEY (`stage_id`) REFERENCES `erp_production_routing_operations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
