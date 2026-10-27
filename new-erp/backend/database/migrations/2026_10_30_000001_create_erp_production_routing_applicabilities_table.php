<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 共用工艺路线的适用范围：一条路线除自身产出物料外，还可适用指定物料（item）或物料类目（category，含下级类目）。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_routing_applicabilities';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_routing_applicabilities` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `routing_id` bigint(20) unsigned NOT NULL,
  `scope_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `item_id` bigint(20) unsigned DEFAULT NULL,
  `item_category_id` bigint(20) unsigned DEFAULT NULL,
  `scope_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_prod_routing_applicability_scope_uq` (`routing_id`,`scope_key`),
  KEY `erp_prod_routing_applicability_item_idx` (`item_id`),
  KEY `erp_prod_routing_applicability_category_idx` (`item_category_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
