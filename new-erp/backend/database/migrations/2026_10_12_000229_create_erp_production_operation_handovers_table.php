<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_operation_handovers';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_operation_handovers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `handover_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `source_target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_target_id` bigint(20) unsigned NOT NULL,
  `target_target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_target_id` bigint(20) unsigned NOT NULL,
  `target_material_requirement_id` bigint(20) unsigned DEFAULT NULL,
  `output_record_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'WAIT_RECEIVE',
  `handed_over_by_legacy_id` bigint(20) unsigned NOT NULL,
  `handed_over_at` timestamp NOT NULL,
  `expected_receiver_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `received_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `received_at` timestamp NULL DEFAULT NULL,
  `identity_snapshot` json NOT NULL,
  `completeness_snapshot` json DEFAULT NULL,
  `accepted_base_qty` decimal(18,8) DEFAULT NULL,
  `reject_reason` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_production_operation_handovers_handover_no_unique` (`handover_no`),
  UNIQUE KEY `erp_prod_handover_output_uq` (`output_record_id`),
  KEY `erp_handover_target_status_idx` (`target_target_type`,`target_target_id`,`status`),
  KEY `erp_handover_wo_fk` (`work_order_id`),
  KEY `erp_handover_target_material_fk` (`target_material_requirement_id`),
  CONSTRAINT `erp_handover_target_material_fk` FOREIGN KEY (`target_material_requirement_id`) REFERENCES `erp_production_target_material_requirements` (`id`) ON DELETE SET NULL,
  CONSTRAINT `erp_handover_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
