<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_packaging_scheme_items';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void
    {
        $this->createEstablishedExtension();
    }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_packaging_scheme_items` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `packaging_scheme_id` bigint unsigned NOT NULL,
  `item_id` bigint unsigned NOT NULL,
  `qty_per_unit` decimal(18,8) NOT NULL,
  `sort_order` int NOT NULL DEFAULT '0',
  `remark` varchar(255) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `packaging_scheme_items_scheme_item_unique` (`packaging_scheme_id`,`item_id`),
  KEY `packaging_scheme_items_scheme_index` (`packaging_scheme_id`),
  KEY `packaging_scheme_items_item_index` (`item_id`),
  CONSTRAINT `packaging_scheme_items_scheme_fk` FOREIGN KEY (`packaging_scheme_id`) REFERENCES `erp_production_packaging_schemes` (`id`) ON DELETE CASCADE,
  CONSTRAINT `packaging_scheme_items_item_fk` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [
        'packaging_scheme_items_qty_positive' => '(`qty_per_unit` > 0)',
    ];
};
