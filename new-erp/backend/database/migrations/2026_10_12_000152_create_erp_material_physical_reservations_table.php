<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_material_physical_reservations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_material_physical_reservations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `physical_material_id` bigint(20) unsigned NOT NULL,
  `cutting_order_id` bigint(20) unsigned NOT NULL,
  `status` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `active_physical_id` bigint(20) unsigned GENERATED ALWAYS AS ((case when (`status` = _utf8mb4'ACTIVE') then `physical_material_id` else NULL end)) STORED,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `cut_one_active_physical_uq` (`active_physical_id`),
  KEY `erp_material_physical_reservations_physical_material_id_foreign` (`physical_material_id`),
  KEY `erp_material_physical_reservations_cutting_order_id_foreign` (`cutting_order_id`),
  CONSTRAINT `erp_material_physical_reservations_cutting_order_id_foreign` FOREIGN KEY (`cutting_order_id`) REFERENCES `erp_cutting_orders` (`id`),
  CONSTRAINT `erp_material_physical_reservations_physical_material_id_foreign` FOREIGN KEY (`physical_material_id`) REFERENCES `erp_material_physicals` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
