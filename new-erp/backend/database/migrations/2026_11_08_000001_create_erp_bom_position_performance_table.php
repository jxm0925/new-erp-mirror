<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 半成品工序提成（整机整套）：在整机 BOM 上按半成品位置 + 该位置子 BOM 路线的工序节点配置提成。
// 位置以 position_uid 串从根 BOM 行向下拼接（'/'分隔），节点以 (operation_id, 第几次出现) 标识。
// 半成品自己的 BOM 节点提成只在它单独销售时使用；随整机使用时按这里的配置计。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_bom_position_performance';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_bom_position_performance` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bom_id` bigint(20) unsigned NOT NULL,
  `position_path` varchar(512) CHARACTER SET ascii COLLATE ascii_bin NOT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `occurrence` int(10) unsigned NOT NULL DEFAULT '1',
  `performance_mode` varchar(16) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'inherit',
  `performance_rate` decimal(10,6) DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `bom_pos_perf_uq` (`bom_id`,`position_path`,`operation_id`,`occurrence`),
  KEY `bom_pos_perf_op_fk` (`operation_id`),
  CONSTRAINT `bom_pos_perf_bom_fk` FOREIGN KEY (`bom_id`) REFERENCES `erp_boms` (`id`) ON DELETE CASCADE,
  CONSTRAINT `bom_pos_perf_op_fk` FOREIGN KEY (`operation_id`) REFERENCES `erp_production_operations` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
