<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 仓库配料配送单：库管按工序配料（prepared）→ 发出扣库存（dispatched，生成领料单 material_issue_id）→ 车间签收（signed）；
// 车间拒收（rejected）后库管重新送达或收回入库（recalled，生成退料单 material_return_id）；未发出的可取消（cancelled）。
// lines：[{material_id, component_item_id, component_item_name, unit_name, qty, picks:[{balance_id, qty, serial_ids, serial_nos, warehouse_name, location_code, batch_no}]}]
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_material_deliveries';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_material_deliveries` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `delivery_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `work_order_operation_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `unit_qty` decimal(14,4) NOT NULL DEFAULT '0.0000',
  `lines` json NOT NULL,
  `material_issue_id` bigint(20) unsigned DEFAULT NULL,
  `material_return_id` bigint(20) unsigned DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prepared_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `prepared_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `prepared_at` timestamp NULL DEFAULT NULL,
  `dispatched_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `dispatched_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dispatched_at` timestamp NULL DEFAULT NULL,
  `signed_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `signed_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `signed_at` timestamp NULL DEFAULT NULL,
  `reject_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rejected_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `rejected_at` timestamp NULL DEFAULT NULL,
  `closed_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `close_remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_delivery_no_uq` (`delivery_no`),
  KEY `mfg_delivery_op_idx` (`work_order_operation_id`,`status`),
  KEY `mfg_delivery_wo_idx` (`work_order_id`),
  KEY `mfg_delivery_status_idx` (`status`,`id`),
  CONSTRAINT `mfg_delivery_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
