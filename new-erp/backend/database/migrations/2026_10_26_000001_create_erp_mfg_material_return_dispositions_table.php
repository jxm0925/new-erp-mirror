<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 不良退料处置：不良退回入库的料先冻结（计入不良数量，编号质量冻结），库管逐行处置：
// release 判定可用释放回可用库存；scrap 报废出库（按退料单价）；supplier_return 转库存质量事件退供应商。
// 一行退料可分几次处置，未处置数量 = 退料数量 − 各次处置数量。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_material_return_dispositions';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_material_return_dispositions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_id` bigint(20) unsigned NOT NULL,
  `return_line_id` bigint(20) unsigned NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `action` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `inventory_serial_id` bigint(20) unsigned DEFAULT NULL,
  `cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `document_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `document_id` bigint(20) unsigned DEFAULT NULL,
  `document_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mfg_return_disp_return_idx` (`return_id`),
  KEY `mfg_return_disp_line_idx` (`return_line_id`),
  KEY `mfg_return_disp_wo_idx` (`work_order_id`),
  CONSTRAINT `mfg_return_disp_line_fk` FOREIGN KEY (`return_line_id`) REFERENCES `erp_mfg_material_return_lines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
