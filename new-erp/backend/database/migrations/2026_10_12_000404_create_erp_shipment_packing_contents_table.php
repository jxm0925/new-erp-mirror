<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_shipment_packing_contents';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_shipment_packing_contents` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `shipment_id` bigint(20) unsigned NOT NULL,
  `package_id` bigint(20) unsigned NOT NULL,
  `shipment_line_id` bigint(20) unsigned NOT NULL,
  `sales_order_line_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `packaging_scheme_id` bigint(20) unsigned DEFAULT NULL,
  `packaging_scheme_name_snapshot` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `base_qty` decimal(18,8) NOT NULL,
  `serial_snapshot` json DEFAULT NULL,
  `source_snapshot` json DEFAULT NULL,
  `routing_snapshot` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_shipment_packing_contents_package_id_foreign` (`package_id`),
  KEY `erp_shipment_packing_contents_shipment_line_id_foreign` (`shipment_line_id`),
  KEY `erp_shipment_packing_contents_sales_order_line_id_foreign` (`sales_order_line_id`),
  KEY `erp_shipment_packing_contents_item_id_foreign` (`item_id`),
  KEY `erp_packing_content_line_idx` (`shipment_id`,`shipment_line_id`),
  CONSTRAINT `erp_shipment_packing_contents_item_id_foreign` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_shipment_packing_contents_package_id_foreign` FOREIGN KEY (`package_id`) REFERENCES `erp_sales_shipment_packages` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_shipment_packing_contents_sales_order_line_id_foreign` FOREIGN KEY (`sales_order_line_id`) REFERENCES `erp_sales_order_lines` (`id`),
  CONSTRAINT `erp_shipment_packing_contents_shipment_id_foreign` FOREIGN KEY (`shipment_id`) REFERENCES `erp_sales_shipments` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_shipment_packing_contents_shipment_line_id_foreign` FOREIGN KEY (`shipment_line_id`) REFERENCES `erp_sales_shipment_lines` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
