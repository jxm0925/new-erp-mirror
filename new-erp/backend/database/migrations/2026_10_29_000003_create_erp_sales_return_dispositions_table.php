<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 退货待处理数量的后续处置记录：restock 转入库（生成处置到货行走原成本入库）/ scrap 报废 / rejected 退回客户。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_return_dispositions';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_return_dispositions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sales_return_id` bigint(20) unsigned NOT NULL,
  `sales_return_item_id` bigint(20) unsigned NOT NULL,
  `source_receipt_item_id` bigint(20) unsigned NOT NULL,
  `target_receipt_item_id` bigint(20) unsigned DEFAULT NULL,
  `disposition` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `base_qty` decimal(18,8) NOT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `operator_id` bigint(20) unsigned DEFAULT NULL,
  `operator_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_sales_return_dispositions_return_index` (`sales_return_id`),
  KEY `erp_sales_return_dispositions_source_index` (`source_receipt_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
