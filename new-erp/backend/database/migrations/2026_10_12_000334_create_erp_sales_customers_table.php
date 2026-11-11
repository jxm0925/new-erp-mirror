<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_sales_customers';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_sales_customers` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `legacy_customer_id` bigint(20) unsigned DEFAULT NULL,
  `customer_code` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `customer_short_name` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `contact_phone` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `province` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `city` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `district` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `full_address` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled',
  `legacy_snapshot` json DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  `customer_type` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `customer_kind` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'individual',
  `source_platform` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform_buyer_id` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `platform_identity_key` varchar(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dedupe_name_key` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `dedupe_address_key` varchar(191) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `owner_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `owner_name` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `invoice_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '默认发票类型 vat_normal普票 vat_special专票',
  `invoice_title` varchar(200) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '发票抬头',
  `invoice_tax_no` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '纳税人识别号',
  `invoice_address_phone` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '开票地址电话',
  `invoice_bank_account` varchar(300) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '开户行及账号',
  `invoice_email` varchar(160) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '收票邮箱',
  `company_background` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci COMMENT 'CRM 公司背景',
  `customer_grade` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'CRM 客户等级，空为未填写',
  `industry` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'CRM 行业原值',
  `purchasing_power` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT 'CRM 年采购力 P0-P4',
  `business_lines` json DEFAULT NULL COMMENT 'CRM 业务线多选',
  `credit_code` varchar(40) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '统一社会信用代码',
  `region_id` bigint(20) unsigned DEFAULT NULL COMMENT '客户所在地区',
  `owner_department_legacy_id` bigint(20) unsigned DEFAULT NULL COMMENT '负责销售所属部门快照',
  `first_source` varchar(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL COMMENT '首次来源，只读汇总',
  `first_contact_at` timestamp NULL DEFAULT NULL COMMENT '初次有效联系，由跟进记录产生',
  `last_contact_at` timestamp NULL DEFAULT NULL COMMENT '最近有效联系，由跟进记录产生',
  `next_contact_at` timestamp NULL DEFAULT NULL COMMENT '下次计划联系，由跟进记录产生',
  `crm_version` int(10) unsigned NOT NULL DEFAULT '1' COMMENT 'CRM 编辑与转交乐观锁',
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_sales_customers_customer_code_unique` (`customer_code`),
  UNIQUE KEY `erp_sales_customers_platform_identity_key_unique` (`platform_identity_key`),
  UNIQUE KEY `erp_sales_customers_credit_code_unique` (`credit_code`),
  KEY `erp_sales_customers_legacy_customer_id_index` (`legacy_customer_id`),
  KEY `erp_sales_customers_owner_legacy_id_index` (`owner_legacy_id`),
  KEY `erp_sales_customers_customer_kind_index` (`customer_kind`),
  KEY `erp_sales_customers_dedupe_name_key_index` (`dedupe_name_key`),
  KEY `erp_sales_customers_dedupe_address_key_index` (`dedupe_address_key`),
  KEY `erp_sales_customers_owner_department_index` (`owner_department_legacy_id`),
  KEY `erp_sales_customers_next_contact_at_index` (`next_contact_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
