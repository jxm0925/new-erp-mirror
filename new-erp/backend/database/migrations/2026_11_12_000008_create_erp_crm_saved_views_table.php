<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

return new class extends InitialTableMigration
{
    public const TABLE = 'erp_crm_saved_views';
    public const ESTABLISHED_EXTENSION = true;
    public function up(): void { $this->createEstablishedExtension(); }
    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_crm_saved_views` (
  `id` bigint unsigned NOT NULL AUTO_INCREMENT,
  `resource` varchar(30) COLLATE utf8mb4_unicode_ci NOT NULL,
  `name` varchar(80) COLLATE utf8mb4_unicode_ci NOT NULL,
  `owner_legacy_id` bigint unsigned NOT NULL,
  `owner_name` varchar(120) COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `scope` varchar(20) COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'personal',
  `scope_id` bigint unsigned DEFAULT NULL,
  `configuration` json NOT NULL,
  `version` int unsigned NOT NULL DEFAULT '1',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `crm_saved_views_owner_resource_name_unique` (`owner_legacy_id`,`resource`,`name`),
  KEY `crm_saved_views_share_index` (`resource`,`scope`,`scope_id`),
  KEY `crm_saved_views_scope_index` (`scope_id`),
  CONSTRAINT `crm_saved_views_scope_fk` FOREIGN KEY (`scope_id`) REFERENCES `erp_departments` (`legacy_id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;
    public const DECLARED_CHECKS = [
        'crm_saved_views_resource_check' => "(`resource` IN ('customers', 'leads', 'opportunities', 'dashboard'))",
        'crm_saved_views_scope_check' => "((`scope` = 'personal' AND `scope_id` IS NULL) OR (`scope` IN ('group', 'department') AND `scope_id` IS NOT NULL))",
        'crm_saved_views_version_check' => '(`version` > 0)',
    ];
};
