<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 工序收料：车间按台数收本道要用的料（半成品、外购件、原料），收料时才从库存扣减。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_mfg_material_issues';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_mfg_material_issues` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `issue_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `work_order_id` bigint(20) unsigned NOT NULL,
  `work_order_operation_id` bigint(20) unsigned NOT NULL,
  `unit_qty` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `issue_lines` json DEFAULT NULL,
  `cost_amount` decimal(18,4) NOT NULL DEFAULT '0.0000',
  `inventory_transaction_id` bigint(20) unsigned DEFAULT NULL,
  `operator_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `operator_name` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `mfg_issue_no_uq` (`issue_no`),
  KEY `mfg_issue_wo_idx` (`work_order_id`,`work_order_operation_id`),
  CONSTRAINT `mfg_issue_wo_fk` FOREIGN KEY (`work_order_id`) REFERENCES `erp_mfg_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
