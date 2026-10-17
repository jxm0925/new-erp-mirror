<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// One complete definition for the new business object. Existing execution/routing
// snapshots are never altered or backfilled when a manager corrects a commission rate.
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_performance_rate_versions';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void
    {
        $this->createEstablishedExtension();
    }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_performance_rate_versions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `scope_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `scope_id` bigint(20) unsigned NOT NULL,
  `version_no` int(10) unsigned NOT NULL,
  `active_scope_key` varchar(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `performance_rate` decimal(12,8) NOT NULL,
  `original_performance_rate` decimal(12,8) DEFAULT NULL,
  `source_scope_version` int(10) unsigned NOT NULL,
  `source_snapshot` json NOT NULL,
  `reason` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `changed_by_legacy_id` bigint(20) unsigned NOT NULL,
  `changed_at` timestamp NOT NULL,
  `client_command_id` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `request_hash` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `response_snapshot` json NOT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `prod_perf_rate_scope_version_uq` (`scope_type`,`scope_id`,`version_no`),
  UNIQUE KEY `prod_perf_rate_active_scope_uq` (`active_scope_key`),
  UNIQUE KEY `prod_perf_rate_command_uq` (`client_command_id`),
  KEY `prod_perf_rate_actor_time_idx` (`changed_by_legacy_id`,`changed_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // Polymorphic scopes are resolved and locked through the three formal domain
    // entry points; there is no single operation table to which a fake FK could refer.
    // MySQL 8.0.12 ignores CHECK, so commands enforce these rules independently.
    public const DECLARED_CHECKS = [
        'prod_perf_rate_value_chk' => '(`performance_rate` >= 0 AND `performance_rate` <= 1)',
        'prod_perf_rate_scope_chk' => "(`scope_type` IN ('unit_operation', 'quantity_operation', 'shipment_packing_operation'))",
    ];
};
