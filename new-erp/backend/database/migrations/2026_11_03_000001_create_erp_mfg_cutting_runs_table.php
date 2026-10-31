<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

/**
 * 集中开料记录：一次只开一个 BOM（一种设备／半成品）的料，按实际计时（erp_mfg_cutting_run_sessions），结束时登记每种料实际切了多少件。
 * lines：BOM 里每种要切的料（line_key 按物料 + 尺寸匹配工单物料行），per_set 每套件数、actual_pieces 实际切的件数、allocated_pieces 已被工单领走的件数。
 * 工单下料点「已备料」时按件数从这里领，带走的工时 = 这批总工时 × 领走件数 ÷ 这批总件数（明细见 erp_mfg_cutting_run_allocations）。
 */
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_cutting_runs';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_cutting_runs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `run_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `bom_id` bigint(20) unsigned NOT NULL,
  `bom_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bom_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `output_item_id` bigint(20) unsigned NOT NULL,
  `output_item_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'running',
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `set_qty` int(10) unsigned NOT NULL DEFAULT '0',
  `lines` json NOT NULL,
  `total_pieces` int(10) unsigned NOT NULL DEFAULT '0',
  `allocated_pieces` int(10) unsigned NOT NULL DEFAULT '0',
  `labor_minutes` decimal(12,2) NOT NULL DEFAULT '0.00',
  `allocated_minutes` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `started_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `started_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `finished_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `finished_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `finished_at` timestamp NULL DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_cut_run_no_uq` (`run_no`),
  KEY `mfg_cut_run_item_idx` (`output_item_id`,`status`),
  KEY `mfg_cut_run_bom_idx` (`bom_id`),
  CONSTRAINT `mfg_cut_run_bom_fk` FOREIGN KEY (`bom_id`) REFERENCES `erp_boms` (`id`),
  CONSTRAINT `mfg_cut_run_item_fk` FOREIGN KEY (`output_item_id`) REFERENCES `erp_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
