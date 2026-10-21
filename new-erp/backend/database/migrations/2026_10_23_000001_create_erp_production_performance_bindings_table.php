<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 工艺路线使用哪套提成模板：binding_key='default' 为全局默认模板，'routing:{id}' 为指定路线。
// 路线本身不再保存比例，建工单/发货包装时按此绑定取模板比例并快照。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_performance_bindings';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_performance_bindings` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `binding_key` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `routing_id` bigint(20) unsigned DEFAULT NULL,
  `template_id` bigint(20) unsigned NOT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_perf_binding_key_uq` (`binding_key`),
  KEY `prod_perf_binding_routing_fk` (`routing_id`),
  KEY `prod_perf_binding_template_fk` (`template_id`),
  CONSTRAINT `prod_perf_binding_routing_fk` FOREIGN KEY (`routing_id`) REFERENCES `erp_production_routings` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prod_perf_binding_template_fk` FOREIGN KEY (`template_id`) REFERENCES `erp_production_performance_templates` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
