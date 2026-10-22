<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 生产缺料转采购需求：一行采购需求明细可覆盖多张工单的缺口，每张工单分到的数量一行。
// 采购需求编辑会重建明细，明细 id 置空后仍按需求单 + 物料追溯。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_purchase_demand_links';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_purchase_demand_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `purchase_request_id` bigint(20) unsigned NOT NULL,
  `purchase_request_item_id` bigint(20) unsigned DEFAULT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(14,4) NOT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mfg_pdl_wo_idx` (`work_order_id`,`item_id`),
  KEY `mfg_pdl_request_idx` (`purchase_request_id`),
  KEY `mfg_pdl_request_item_idx` (`purchase_request_item_id`),
  CONSTRAINT `mfg_pdl_request_fk` FOREIGN KEY (`purchase_request_id`) REFERENCES `erp_purchase_requests` (`id`) ON DELETE CASCADE,
  CONSTRAINT `mfg_pdl_request_item_fk` FOREIGN KEY (`purchase_request_item_id`) REFERENCES `erp_purchase_request_items` (`id`) ON DELETE SET NULL,
  CONSTRAINT `mfg_pdl_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
