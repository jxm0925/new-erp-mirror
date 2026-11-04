<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// BOM 每道路线节点的工时与提成：多款产品可共用一条路线，各自在 BOM 里维护工时和比例。
// 工时 NULL=未设、0=明确为 0；提成 inherit 沿用模板兜底 / override 指定比例 / zero 明确不计。
// 工单下达时解析并冻结到工单工序，之后改这里不影响已下达工单。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_bom_operation_standards';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_bom_operation_standards` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bom_id` bigint(20) unsigned NOT NULL,
  `routing_operation_id` bigint(20) unsigned NOT NULL,
  `setup_minutes` decimal(18,4) DEFAULT NULL,
  `unit_minutes` decimal(18,4) DEFAULT NULL,
  `performance_mode` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inherit',
  `performance_rate` decimal(10,6) DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bom_op_std_node_uq` (`bom_id`,`routing_operation_id`),
  KEY `bom_op_std_routing_op_fk` (`routing_operation_id`),
  CONSTRAINT `bom_op_std_bom_fk` FOREIGN KEY (`bom_id`) REFERENCES `erp_boms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bom_op_std_routing_op_fk` FOREIGN KEY (`routing_operation_id`) REFERENCES `erp_production_routing_operations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
