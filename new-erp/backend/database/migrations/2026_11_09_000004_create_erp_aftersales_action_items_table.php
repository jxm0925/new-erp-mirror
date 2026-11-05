<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 售后处理明细：每个处理行引用售后商品行；本期只有退回数量，补发字段预留给第 2 步。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_aftersales_action_items';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_aftersales_action_items` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `action_id` bigint(20) unsigned NOT NULL,
  `case_item_id` bigint(20) unsigned NOT NULL,
  `return_qty` decimal(20,8) NOT NULL DEFAULT '0.00000000',
  `reship_qty` decimal(20,8) NOT NULL DEFAULT '0.00000000',
  `reship_sku_id` bigint(20) unsigned DEFAULT NULL,
  `reship_item_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_as_action_items_action_index` (`action_id`),
  KEY `erp_as_action_items_case_item_index` (`case_item_id`),
  CONSTRAINT `erp_as_action_items_action_fk` FOREIGN KEY (`action_id`) REFERENCES `erp_aftersales_actions` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_as_action_items_case_item_fk` FOREIGN KEY (`case_item_id`) REFERENCES `erp_aftersales_case_items` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
