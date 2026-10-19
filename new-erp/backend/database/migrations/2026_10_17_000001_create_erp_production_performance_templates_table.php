<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 提成模板：一套按工序设定的比例，可批量套用到多条工艺路线；已下达工单的比例快照不受影响。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_performance_templates';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_performance_templates` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `template_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `last_applied_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_perf_template_name_uq` (`template_name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'prod_perf_template_status_chk' => "(`status` IN ('enabled', 'disabled'))",
    ];
};
