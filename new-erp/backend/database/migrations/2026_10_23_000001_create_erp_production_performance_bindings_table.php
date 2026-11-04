<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 提成模板适用范围：binding_key='default' 为全局默认模板，'category:{id}' 为商品分类（erp_item_categories）。
// BOM 节点未指定比例时按 商品分类（逐级上级）→ 默认模板 兜底；建工单时解析并快照，模板后改不追溯。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_performance_bindings';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_performance_bindings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `binding_key` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `category_id` bigint(20) unsigned DEFAULT NULL,
  `template_id` bigint(20) unsigned NOT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_perf_binding_key_uq` (`binding_key`),
  KEY `prod_perf_binding_category_fk` (`category_id`),
  KEY `prod_perf_binding_template_fk` (`template_id`),
  CONSTRAINT `prod_perf_binding_category_fk` FOREIGN KEY (`category_id`) REFERENCES `erp_item_categories` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prod_perf_binding_template_fk` FOREIGN KEY (`template_id`) REFERENCES `erp_production_performance_templates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
