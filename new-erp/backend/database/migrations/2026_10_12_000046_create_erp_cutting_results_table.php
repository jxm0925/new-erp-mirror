<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_cutting_results';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_cutting_results` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `settlement_batch_id` bigint(20) unsigned NOT NULL,
  `client_row_id` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `result_type` varchar(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `allowed_output_id` bigint(20) unsigned DEFAULT NULL,
  `item_id` bigint(20) unsigned DEFAULT NULL,
  `configuration_id` bigint(20) unsigned DEFAULT NULL,
  `stage_id` bigint(20) unsigned DEFAULT NULL,
  `actual_qty` decimal(18,8) DEFAULT NULL,
  `piece_qty` decimal(18,8) DEFAULT NULL,
  `cut_length_mm` decimal(18,2) DEFAULT NULL,
  `measurements` json DEFAULT NULL,
  `measurement_status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'NOT_RECORDED',
  `quality_status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'NOT_REQUIRED',
  `reported_quality` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `total_cost` decimal(18,4) DEFAULT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'DRAFT',
  `material_lot_id` bigint(20) unsigned DEFAULT NULL,
  `physical_material_id` bigint(20) unsigned DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `active_client_row_id` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci GENERATED ALWAYS AS ((case when (`status` in (_utf8mb4'VOIDED',_utf8mb4'SUPERSEDED')) then NULL else `client_row_id` end)) STORED,
  `supersedes_result_id` bigint(20) unsigned DEFAULT NULL,
  `voided_at` timestamp NULL DEFAULT NULL,
  `voided_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `cutting_requirement_snapshot` json DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cut_result_active_row_uq` (`settlement_batch_id`,`active_client_row_id`),
  KEY `erp_cutting_results_allowed_output_id_foreign` (`allowed_output_id`),
  KEY `erp_cutting_results_item_id_foreign` (`item_id`),
  KEY `erp_cutting_results_configuration_id_foreign` (`configuration_id`),
  KEY `erp_cutting_results_stage_id_foreign` (`stage_id`),
  KEY `erp_cutting_results_material_lot_id_foreign` (`material_lot_id`),
  KEY `erp_cutting_results_physical_material_id_foreign` (`physical_material_id`),
  KEY `erp_cutting_results_supersedes_result_id_foreign` (`supersedes_result_id`),
  CONSTRAINT `erp_cutting_results_allowed_output_id_foreign` FOREIGN KEY (`allowed_output_id`) REFERENCES `erp_cutting_allowed_outputs` (`id`),
  CONSTRAINT `erp_cutting_results_configuration_id_foreign` FOREIGN KEY (`configuration_id`) REFERENCES `erp_custom_configurations` (`id`),
  CONSTRAINT `erp_cutting_results_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_cutting_results_material_lot_id_foreign` FOREIGN KEY (`material_lot_id`) REFERENCES `erp_material_lots` (`id`),
  CONSTRAINT `erp_cutting_results_physical_material_id_foreign` FOREIGN KEY (`physical_material_id`) REFERENCES `erp_material_physicals` (`id`),
  CONSTRAINT `erp_cutting_results_settlement_batch_id_foreign` FOREIGN KEY (`settlement_batch_id`) REFERENCES `erp_cutting_settlement_batches` (`id`),
  CONSTRAINT `erp_cutting_results_stage_id_foreign` FOREIGN KEY (`stage_id`) REFERENCES `erp_production_routing_operations` (`id`),
  CONSTRAINT `erp_cutting_results_supersedes_result_id_foreign` FOREIGN KEY (`supersedes_result_id`) REFERENCES `erp_cutting_results` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
