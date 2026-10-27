<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// BOM 行的用料工序：按工序主档（而非某条路线的节点）指定，共用工艺路线时由工单按工序匹配到路线节点。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_bom_item_operations';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_bom_item_operations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `bom_item_id` bigint(20) unsigned NOT NULL,
  `operation_id` bigint(20) unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_bom_item_operations_item_uq` (`bom_item_id`),
  KEY `erp_bom_item_operations_operation_idx` (`operation_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
