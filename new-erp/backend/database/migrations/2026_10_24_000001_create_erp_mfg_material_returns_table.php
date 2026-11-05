<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 生产退料单：车间把领多、领错、不良或报废台拆下的料退回仓库；工人申请、库管审核通过时才入库。
// return_type：surplus 余料/错料、defective 不良退回、scrapped 报废台拆回、cancellation 停产拆解退料；仅scrapped不冲减已领。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_material_returns';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_material_returns` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `return_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `work_order_operation_id` bigint(20) unsigned NOT NULL,
  `disposition_id` bigint(20) unsigned DEFAULT NULL,
  `return_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `target_warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `target_location_id` bigint(20) unsigned DEFAULT NULL,
  `cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `requested_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `requested_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `requested_at` timestamp NULL DEFAULT NULL,
  `reviewed_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `reviewed_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reviewed_at` timestamp NULL DEFAULT NULL,
  `review_remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `disposition_plan` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_return_no_uq` (`return_no`),
  KEY `mfg_return_wo_idx` (`work_order_id`,`work_order_operation_id`),
  KEY `mfg_return_status_idx` (`status`,`id`),
  KEY `mfg_return_disposition_idx` (`disposition_id`),
  CONSTRAINT `mfg_return_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
