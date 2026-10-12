<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_order_attachments';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_order_attachments` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `draft_token` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `line_uuid` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sales_order_id` bigint(20) unsigned DEFAULT NULL,
  `sales_order_line_id` bigint(20) unsigned DEFAULT NULL,
  `attachment_scope` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'order',
  `attachment_type` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'other',
  `version_no` int(10) unsigned NOT NULL DEFAULT '1',
  `replaced_attachment_id` bigint(20) unsigned DEFAULT NULL,
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
  `deleted_by` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `deleted_at` timestamp NULL DEFAULT NULL,
  `metadata` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_so_att_order_scope_idx` (`sales_order_id`,`attachment_scope`),
  KEY `erp_so_att_draft_line_idx` (`draft_token`,`line_uuid`,`status`),
  KEY `erp_sales_order_attachments_draft_token_index` (`draft_token`),
  KEY `erp_sales_order_attachments_line_uuid_index` (`line_uuid`),
  KEY `erp_sales_order_attachments_sales_order_id_index` (`sales_order_id`),
  KEY `erp_sales_order_attachments_sales_order_line_id_index` (`sales_order_line_id`),
  KEY `erp_sales_order_attachments_attachment_scope_index` (`attachment_scope`),
  KEY `erp_sales_order_attachments_attachment_type_index` (`attachment_type`),
  KEY `erp_sales_order_attachments_file_hash_index` (`file_hash`),
  KEY `erp_sales_order_attachments_uploaded_by_legacy_id_index` (`uploaded_by_legacy_id`),
  KEY `erp_sales_order_attachments_status_index` (`status`),
  KEY `erp_sales_order_attachments_replaced_attachment_id_index` (`replaced_attachment_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
