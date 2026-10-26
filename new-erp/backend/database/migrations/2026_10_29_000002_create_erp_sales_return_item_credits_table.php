<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 退货行冲减应收：退货单价快照（销售单位，含税净价，可低于原单价做折价退货）、已认可销售数量与冲减金额。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_return_item_credits';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_return_item_credits` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `sales_return_item_id` bigint(20) unsigned NOT NULL,
  `unit_price_snapshot` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `credited_sales_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `credit_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_sales_return_item_credits_item_unique` (`sales_return_item_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
