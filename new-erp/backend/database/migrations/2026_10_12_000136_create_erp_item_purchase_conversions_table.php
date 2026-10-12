<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_item_purchase_conversions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_item_purchase_conversions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `item_id` bigint(20) unsigned NOT NULL,
  `purchase_unit_id` bigint(20) unsigned NOT NULL,
  `base_unit_id` bigint(20) unsigned NOT NULL,
  `factor` decimal(18,8) NOT NULL,
  `is_default` tinyint(1) NOT NULL DEFAULT '0',
  `allow_actual_conversion` tinyint(1) NOT NULL DEFAULT '0',
  `effective_from` timestamp NULL DEFAULT NULL,
  `effective_to` timestamp NULL DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `change_reason` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `operator_id` bigint(20) unsigned DEFAULT NULL,
  `operator_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `active_default_item_id` bigint(20) unsigned GENERATED ALWAYS AS ((case when ((`status` = _utf8mb4'active') and (`is_default` = 1)) then `item_id` else NULL end)) VIRTUAL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_item_purchase_one_active_default` (`active_default_item_id`),
  KEY `erp_item_purchase_conversions_purchase_unit_id_foreign` (`purchase_unit_id`),
  KEY `erp_item_purchase_conversions_base_unit_id_foreign` (`base_unit_id`),
  KEY `erp_item_purchase_conversion_item_status` (`item_id`,`status`),
  KEY `erp_item_purchase_conversion_period` (`item_id`,`purchase_unit_id`,`effective_from`),
  CONSTRAINT `erp_item_purchase_conversions_base_unit_id_foreign` FOREIGN KEY (`base_unit_id`) REFERENCES `erp_units` (`id`),
  CONSTRAINT `erp_item_purchase_conversions_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_item_purchase_conversions_purchase_unit_id_foreign` FOREIGN KEY (`purchase_unit_id`) REFERENCES `erp_units` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
