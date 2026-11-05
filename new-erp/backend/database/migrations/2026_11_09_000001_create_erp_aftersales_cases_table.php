<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 售后单：客户反馈问题的总单，挂在原销售订单下；一单可涉及多行商品/多台设备，
// 状态 待受理→处理中→待关闭（全部处理完成）→已关闭；待受理/处理中/待关闭且无库存资金事实时可取消。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_aftersales_cases';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_aftersales_cases` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `case_no` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `sales_order_id` bigint(20) unsigned NOT NULL,
  `customer_id` bigint(20) unsigned DEFAULT NULL,
  `customer_snapshot` json DEFAULT NULL,
  `owner_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `owner_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `issue_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `warranty_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'in_warranty',
  `responsibility` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `reported_at` datetime NOT NULL,
  `description` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'pending',
  `result` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_confirmed` tinyint(4) DEFAULT NULL,
  `close_remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `cancel_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `accepted_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `accepted_at` datetime DEFAULT NULL,
  `closed_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `closed_at` datetime DEFAULT NULL,
  `cancelled_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `lock_version` int(11) NOT NULL DEFAULT '0',
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_aftersales_cases_case_no_unique` (`case_no`),
  KEY `erp_aftersales_cases_sales_order_index` (`sales_order_id`),
  KEY `erp_aftersales_cases_status_index` (`status`),
  KEY `erp_aftersales_cases_created_at_index` (`created_at`),
  CONSTRAINT `erp_aftersales_cases_sales_order_id_foreign` FOREIGN KEY (`sales_order_id`) REFERENCES `erp_sales_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
