<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 售后附件：售后单问题图片/视频与索赔凭证的真实上传件，沿用订单附件的 storage_disk/storage_path 落盘方式；
// 新建售后单尚无 ID 时先记 draft_token，保存后归并到 case_id / claim_id。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_aftersales_attachments';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_aftersales_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `case_id` bigint(20) unsigned DEFAULT NULL,
  `claim_id` bigint(20) unsigned DEFAULT NULL,
  `draft_token` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `attachment_scope` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'case',
  `original_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `stored_name` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `storage_disk` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'public',
  `storage_path` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `url` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `mime_type` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `file_size` bigint(20) unsigned NOT NULL DEFAULT '0',
  `file_hash` varchar(128) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `uploaded_by` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `uploaded_at` timestamp NULL DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'active',
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_as_att_case_idx` (`case_id`),
  KEY `erp_as_att_claim_idx` (`claim_id`),
  KEY `erp_as_att_draft_idx` (`draft_token`,`status`),
  CONSTRAINT `erp_as_att_case_fk` FOREIGN KEY (`case_id`) REFERENCES `erp_aftersales_cases` (`id`) ON DELETE CASCADE,
  CONSTRAINT `erp_as_att_claim_fk` FOREIGN KEY (`claim_id`) REFERENCES `erp_aftersales_claims` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
