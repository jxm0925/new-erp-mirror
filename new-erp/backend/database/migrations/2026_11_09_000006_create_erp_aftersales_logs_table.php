<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 售后操作日志：售后单/处理/索赔的每次变更都落一条，含前后状态与操作人快照。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_aftersales_logs';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_aftersales_logs` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `case_id` bigint(20) unsigned NOT NULL,
  `action_id` bigint(20) unsigned DEFAULT NULL,
  `claim_id` bigint(20) unsigned DEFAULT NULL,
  `event` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `from_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `to_status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `operator_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `operator_name` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_aftersales_logs_case_index` (`case_id`),
  CONSTRAINT `erp_as_logs_case_fk` FOREIGN KEY (`case_id`) REFERENCES `erp_aftersales_cases` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
