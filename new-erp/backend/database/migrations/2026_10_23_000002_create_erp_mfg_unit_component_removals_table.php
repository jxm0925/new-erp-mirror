<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 装配谱系的“拆下”记录：装配记录（erp_mfg_unit_components）只追加不修改，
// 互换、换号、退料、报废都先在这里登记拆下，再按需新增装上记录；没有拆下记录的才是当前装在台上的部件。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_unit_component_removals';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_unit_component_removals` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `component_id` bigint(20) unsigned NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `reason` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `document_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `document_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `removed_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `removed_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `removed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_unit_comp_removal_comp_uq` (`component_id`),
  KEY `mfg_unit_comp_removal_order_idx` (`work_order_id`),
  KEY `mfg_unit_comp_removal_unit_idx` (`unit_id`),
  CONSTRAINT `mfg_unit_comp_removal_comp_fk` FOREIGN KEY (`component_id`) REFERENCES `erp_mfg_unit_components` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
