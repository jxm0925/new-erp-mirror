<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 模板明细：每道标准工序一个比例，与工艺路线工序的 performance_rate 同精度。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_performance_template_lines';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_performance_template_lines` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `template_id` bigint(20) unsigned NOT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `performance_rate` decimal(10,6) NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_perf_template_line_uq` (`template_id`,`operation_id`),
  KEY `prod_perf_template_line_operation_fk` (`operation_id`),
  CONSTRAINT `prod_perf_template_line_template_fk` FOREIGN KEY (`template_id`) REFERENCES `erp_production_performance_templates` (`id`) ON DELETE CASCADE,
  CONSTRAINT `prod_perf_template_line_operation_fk` FOREIGN KEY (`operation_id`) REFERENCES `erp_production_operations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'prod_perf_template_line_rate_chk' => '(`performance_rate` >= 0 AND `performance_rate` <= 1)',
    ];
};
