<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 总装工单缺半成品时给下料组开的半成品工单：记录“为哪张总装工单、补哪个半成品、开了多少”。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_work_order_links';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_work_order_links` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `parent_work_order_id` bigint(20) unsigned NOT NULL,
  `child_work_order_id` bigint(20) unsigned NOT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_by_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_wo_link_child_uq` (`child_work_order_id`),
  KEY `mfg_wo_link_parent_idx` (`parent_work_order_id`,`component_item_id`),
  CONSTRAINT `mfg_wo_link_child_fk` FOREIGN KEY (`child_work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`),
  CONSTRAINT `mfg_wo_link_parent_fk` FOREIGN KEY (`parent_work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
