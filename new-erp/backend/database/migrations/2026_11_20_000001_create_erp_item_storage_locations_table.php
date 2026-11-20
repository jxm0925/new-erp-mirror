<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_item_storage_locations';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void
    {
        $this->createEstablishedExtension();
    }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_item_storage_locations` (
  `item_id` bigint unsigned NOT NULL,
  `location_id` bigint unsigned NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`item_id`,`location_id`),
  KEY `erp_item_storage_locations_location_index` (`location_id`),
  CONSTRAINT `erp_item_storage_locations_item_fk` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_item_storage_locations_location_fk` FOREIGN KEY (`location_id`) REFERENCES `erp_locations` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
};
