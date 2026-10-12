<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sso_ticket_consumptions';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sso_ticket_consumptions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `issuer` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `nonce` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `user_legacy_id` bigint(20) unsigned NOT NULL,
  `ticket_issued_at` timestamp NOT NULL,
  `ticket_expires_at` timestamp NOT NULL,
  `consumed_at` timestamp NOT NULL,
  `request_ip` varchar(45) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_sso_ticket_issuer_nonce_unique` (`issuer`,`nonce`),
  KEY `erp_sso_ticket_consumptions_user_legacy_id_index` (`user_legacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
