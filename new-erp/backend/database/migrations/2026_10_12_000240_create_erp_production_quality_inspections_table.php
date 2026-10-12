<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_quality_inspections';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_quality_inspections` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `inspection_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `output_record_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'PENDING',
  `result` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `inspected_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `qualified_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `unqualified_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `inspection_snapshot` json DEFAULT NULL,
  `inspector_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `inspected_at` timestamp NULL DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `cutting_result_id` bigint(20) unsigned DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_production_quality_inspections_inspection_no_unique` (`inspection_no`),
  KEY `erp_prod_quality_output_status_idx` (`output_record_id`,`status`),
  KEY `erp_production_quality_inspections_cutting_result_id_foreign` (`cutting_result_id`),
  CONSTRAINT `erp_prod_quality_output_fk` FOREIGN KEY (`output_record_id`) REFERENCES `erp_production_output_records` (`id`),
  CONSTRAINT `erp_production_quality_inspections_cutting_result_id_foreign` FOREIGN KEY (`cutting_result_id`) REFERENCES `erp_cutting_results` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
        'cut_quality_subject_ck' => '(output_record_id IS NOT NULL AND cutting_result_id IS NULL) OR (output_record_id IS NULL AND cutting_result_id IS NOT NULL)',
    ];
};
