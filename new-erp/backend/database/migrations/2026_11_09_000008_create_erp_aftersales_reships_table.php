<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 售后补发单：换货的新件或独立补发的出库申请；出库事实落在 erp_inventory_transactions，
// 与销售发运完全隔离（不动订单 shipped_qty），序列号状态仍记 customer 侧 shipped。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_aftersales_reships';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_aftersales_reships` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reship_no` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `case_id` bigint(20) unsigned NOT NULL,
  `action_id` bigint(20) unsigned NOT NULL,
  `sales_order_id` bigint(20) unsigned NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `carrier_id` bigint(20) unsigned DEFAULT NULL,
  `carrier_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `tracking_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receiver_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receiver_phone` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `receiver_address` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `shipped_at` datetime DEFAULT NULL,
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `package_count` int(11) DEFAULT NULL,
  `package_method` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cancel_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `shipped_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_as_reships_no_uq` (`reship_no`),
  KEY `erp_as_reships_case_idx` (`case_id`),
  KEY `erp_as_reships_action_idx` (`action_id`),
  KEY `erp_as_reships_order_idx` (`sales_order_id`),
  KEY `erp_as_reships_status_idx` (`status`),
  CONSTRAINT `erp_as_reships_case_fk` FOREIGN KEY (`case_id`) REFERENCES `erp_aftersales_cases` (`id`),
  CONSTRAINT `erp_as_reships_action_fk` FOREIGN KEY (`action_id`) REFERENCES `erp_aftersales_actions` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
