<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 逐台报工记录：合格交下道、不合格退回某道、报废（主管确认）。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_unit_reports';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_unit_reports` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `work_order_operation_id` bigint(20) unsigned NOT NULL,
  `unit_id` bigint(20) unsigned NOT NULL,
  `serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `result` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `is_rework` tinyint(1) NOT NULL DEFAULT '0',
  `return_operation_id` bigint(20) unsigned DEFAULT NULL,
  `return_operation_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reason_tags` json DEFAULT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `photos` json DEFAULT NULL,
  `participants_snapshot` json DEFAULT NULL,
  `reporter_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `reporter_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `review_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mfg_report_unit_idx` (`unit_id`,`id`),
  KEY `mfg_report_op_idx` (`work_order_operation_id`),
  KEY `mfg_report_wo_idx` (`work_order_id`,`id`),
  KEY `mfg_report_review_idx` (`review_status`),
  KEY `mfg_report_created_idx` (`created_at`),
  CONSTRAINT `mfg_report_unit_fk` FOREIGN KEY (`unit_id`) REFERENCES `erp_mfg_work_order_units` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'mfg_report_result_chk' => "(`result` IN ('passed', 'failed', 'scrap'))",
    ];
};
