<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_input_holdings';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_input_holdings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `target_material_requirement_id` bigint(20) unsigned DEFAULT NULL,
  `source_output_record_id` bigint(20) unsigned DEFAULT NULL,
  `inventory_transaction_item_id` bigint(20) unsigned DEFAULT NULL,
  `source_holding_id` bigint(20) unsigned NOT NULL,
  `input_holding_id` bigint(20) unsigned DEFAULT NULL,
  `operation_handover_id` bigint(20) unsigned DEFAULT NULL,
  `internal_issue_line_id` bigint(20) unsigned DEFAULT NULL,
  `material_receipt_line_id` bigint(20) unsigned DEFAULT NULL,
  `quantity` decimal(18,8) NOT NULL,
  `total_cost` decimal(18,4) NOT NULL,
  `status` varchar(24) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_input_holding_uq` (`input_holding_id`),
  UNIQUE KEY `prod_input_handover_uq` (`operation_handover_id`),
  UNIQUE KEY `prod_input_issue_line_uq` (`internal_issue_line_id`),
  UNIQUE KEY `prod_input_receipt_line_uq` (`material_receipt_line_id`),
  KEY `prod_input_target_status_idx` (`target_type`,`target_id`,`status`),
  KEY `prod_input_requirement_fk` (`target_material_requirement_id`),
  KEY `prod_input_output_fk` (`source_output_record_id`),
  KEY `prod_input_source_holding_fk` (`source_holding_id`),
  KEY `prod_input_inventory_item_fk` (`inventory_transaction_item_id`),
  CONSTRAINT `prod_input_handover_fk` FOREIGN KEY (`operation_handover_id`) REFERENCES `erp_production_operation_handovers` (`id`),
  CONSTRAINT `prod_input_holding_fk` FOREIGN KEY (`input_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `prod_input_inventory_item_fk` FOREIGN KEY (`inventory_transaction_item_id`) REFERENCES `erp_inventory_transaction_items` (`id`),
  CONSTRAINT `prod_input_issue_line_fk` FOREIGN KEY (`internal_issue_line_id`) REFERENCES `erp_production_internal_issue_lines` (`id`),
  CONSTRAINT `prod_input_output_fk` FOREIGN KEY (`source_output_record_id`) REFERENCES `erp_production_output_records` (`id`),
  CONSTRAINT `prod_input_receipt_line_fk` FOREIGN KEY (`material_receipt_line_id`) REFERENCES `erp_material_receipt_lines` (`id`),
  CONSTRAINT `prod_input_requirement_fk` FOREIGN KEY (`target_material_requirement_id`) REFERENCES `erp_production_target_material_requirements` (`id`),
  CONSTRAINT `prod_input_source_holding_fk` FOREIGN KEY (`source_holding_id`) REFERENCES `erp_material_holdings` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
