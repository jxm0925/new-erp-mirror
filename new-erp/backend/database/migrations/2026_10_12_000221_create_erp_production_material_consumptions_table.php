<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_material_consumptions';

    // 仅接纳历史09_17_230000已登记但遗漏的这一索引；不允许其他漂移或业务数据修复。
    public const LEGACY_UNIQUE_INDEX = [
        'name' => 'prod_material_consumption_source_uq',
        'columns' => ['output_record_id', 'source_holding_id'],
    ];

    public function up(): void
    {
        $this->completeLegacyUniqueIndex();
        parent::up();
    }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_material_consumptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `output_record_id` bigint(20) unsigned NOT NULL,
  `target_material_requirement_id` bigint(20) unsigned DEFAULT NULL,
  `source_holding_id` bigint(20) unsigned NOT NULL,
  `source_material_lot_id` bigint(20) unsigned NOT NULL,
  `quantity` decimal(18,8) NOT NULL,
  `total_cost` decimal(18,4) NOT NULL,
  `operator_legacy_id` bigint(20) unsigned NOT NULL,
  `occurred_at` timestamp NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_material_consumption_source_uq` (`output_record_id`,`source_holding_id`),
  KEY `erp_production_material_consumptions_output_record_id_foreign` (`output_record_id`),
  KEY `prod_mat_cons_requirement_fk` (`target_material_requirement_id`),
  KEY `prod_mat_cons_holding_fk` (`source_holding_id`),
  KEY `prod_mat_cons_lot_fk` (`source_material_lot_id`),
  CONSTRAINT `erp_production_material_consumptions_output_record_id_foreign` FOREIGN KEY (`output_record_id`) REFERENCES `erp_production_output_records` (`id`),
  CONSTRAINT `prod_mat_cons_holding_fk` FOREIGN KEY (`source_holding_id`) REFERENCES `erp_material_holdings` (`id`),
  CONSTRAINT `prod_mat_cons_lot_fk` FOREIGN KEY (`source_material_lot_id`) REFERENCES `erp_material_lots` (`id`),
  CONSTRAINT `prod_mat_cons_requirement_fk` FOREIGN KEY (`target_material_requirement_id`) REFERENCES `erp_production_target_material_requirements` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
