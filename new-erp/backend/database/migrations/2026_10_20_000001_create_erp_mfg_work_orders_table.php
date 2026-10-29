<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 新生产工单：一张订单行一张工单（或手工备货工单），下达时生成按台序列号；工艺路线、BOM 均为下达时快照。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_work_orders';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_work_orders` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_no` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'sales_order',
  `sales_order_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sales_order_line_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_line_no` int(10) unsigned DEFAULT NULL,
  `sales_fulfillment_id` bigint(20) unsigned DEFAULT NULL,
  `customer_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `stock_reason` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `product_id` bigint(20) unsigned DEFAULT NULL,
  `product_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku_id` bigint(20) unsigned DEFAULT NULL,
  `sku_code` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `item_code` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_name` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `planned_qty` decimal(18,4) NOT NULL,
  `completed_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `stored_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `bom_id` bigint(20) unsigned DEFAULT NULL,
  `bom_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bom_version` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `bom_snapshot` json DEFAULT NULL,
  `routing_id` bigint(20) unsigned DEFAULT NULL,
  `routing_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `routing_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `routing_version` int(10) unsigned DEFAULT NULL,
  `routing_snapshot` json DEFAULT NULL,
  `configuration_snapshot` json DEFAULT NULL,
  `special_requirement` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'planned',
  `block_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `is_urgent` tinyint(1) NOT NULL DEFAULT '0',
  `owner_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `owner_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `planned_start_date` date DEFAULT NULL,
  `planned_finish_date` date DEFAULT NULL,
  `required_date` date DEFAULT NULL,
  `warehouse_id` bigint(20) unsigned DEFAULT NULL,
  `cost_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'open',
  `pause_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `cancel_reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `released_at` timestamp NULL DEFAULT NULL,
  `released_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `started_at` timestamp NULL DEFAULT NULL,
  `completed_at` timestamp NULL DEFAULT NULL,
  `closed_at` timestamp NULL DEFAULT NULL,
  `paused_at` timestamp NULL DEFAULT NULL,
  `cancelled_at` timestamp NULL DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_wo_no_uq` (`work_order_no`),
  KEY `mfg_wo_fulfillment_idx` (`sales_fulfillment_id`),
  KEY `mfg_wo_status_idx` (`status`,`planned_finish_date`),
  KEY `mfg_wo_sales_order_idx` (`sales_order_id`,`sales_order_line_id`),
  KEY `mfg_wo_item_idx` (`item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'mfg_wo_status_chk' => "(`status` IN ('planned', 'released', 'in_progress', 'paused', 'completed', 'closed', 'cancelled'))",
        'mfg_wo_source_chk' => "(`source_type` IN ('sales_order', 'stock', 'work_order'))",
    ];
};
