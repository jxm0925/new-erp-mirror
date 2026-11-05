<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 售后单涉及商品：数量单位 = 销售单位（与 erp_sales_order_lines.order_qty 一致）；
// 有序列号的行逐台登记（inventory_serial_id + 编号快照），同一台设备不能同时落在两张进行中的售后单里。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_aftersales_case_items';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_aftersales_case_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `case_id` bigint(20) unsigned NOT NULL,
  `sales_order_line_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `product_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sku_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `item_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `qty` decimal(20,8) NOT NULL DEFAULT '0.00000000',
  `inventory_serial_id` bigint(20) unsigned DEFAULT NULL,
  `serial_no` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_aftersales_case_items_case_index` (`case_id`),
  KEY `erp_aftersales_case_items_line_index` (`sales_order_line_id`),
  KEY `erp_aftersales_case_items_serial_index` (`inventory_serial_id`),
  KEY `erp_as_case_items_item_fk` (`item_id`),
  CONSTRAINT `erp_as_case_items_case_fk` FOREIGN KEY (`case_id`) REFERENCES `erp_aftersales_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_as_case_items_item_fk` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_as_case_items_line_fk` FOREIGN KEY (`sales_order_line_id`) REFERENCES `erp_sales_order_lines` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
