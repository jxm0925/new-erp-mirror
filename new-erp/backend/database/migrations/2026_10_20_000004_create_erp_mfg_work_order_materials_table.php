<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 工单用料：BOM 按工艺路线分到各工序，用来算齐套；配料、签收、扣库存在第 3 阶段接入。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_work_order_materials';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_work_order_materials` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `work_order_operation_id` bigint(20) unsigned DEFAULT NULL,
  `bom_item_id` bigint(20) unsigned DEFAULT NULL,
  `component_item_id` bigint(20) unsigned NOT NULL,
  `component_item_code` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `component_item_name` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_name` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `unit_qty` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `loss_rate` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `fixed_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `qty_ratio` decimal(18,6) NOT NULL DEFAULT '0.000000',
  `required_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `issued_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `supply_mode` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `participates_in_kitting` tinyint(1) NOT NULL DEFAULT '1',
  `cutting_mode` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `mfg_wo_mat_order_idx` (`work_order_id`,`work_order_operation_id`),
  KEY `mfg_wo_mat_item_idx` (`component_item_id`),
  CONSTRAINT `mfg_wo_mat_order_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
