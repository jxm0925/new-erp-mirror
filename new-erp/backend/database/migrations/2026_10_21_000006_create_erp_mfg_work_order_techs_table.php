<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 定制工单的技术资料：技术上传图纸、确认定制 BOM 后下发车间；下发后改图只提示已更新。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_work_order_techs';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_work_order_techs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `base_bom_id` bigint(20) unsigned DEFAULT NULL,
  `custom_bom` json DEFAULT NULL,
  `bom_confirmed` tinyint(1) NOT NULL DEFAULT '0',
  `tech_owner_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `tech_owner_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `released_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `released_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `drawings_updated_at` timestamp NULL DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_tech_wo_uq` (`work_order_id`),
  KEY `mfg_tech_status_idx` (`status`),
  CONSTRAINT `mfg_tech_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'mfg_tech_status_chk' => "(`status` IN ('pending', 'released'))",
    ];
};
