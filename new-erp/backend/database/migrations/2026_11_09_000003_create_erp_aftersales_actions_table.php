<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 售后处理：每个处理单独推进，退货退款/换货退回走现有销售退货单（sales_return_id 关联），
// 仅记录（record）登记即完成；全部处理完成/取消后售后单自动转待关闭。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_aftersales_actions';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_aftersales_actions` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `case_id` bigint(20) unsigned NOT NULL,
  `action_type` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'processing',
  `reship_timing` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `ship_first_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `return_method` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `freight_payer` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `freight_amount` decimal(20,4) DEFAULT NULL,
  `planned_disposition` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `sales_return_id` bigint(20) unsigned DEFAULT NULL,
  `record_content` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `remark` text CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci,
  `completed_at` datetime DEFAULT NULL,
  `cancelled_at` datetime DEFAULT NULL,
  `cancel_reason` varchar(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compensation_amount` decimal(20,4) DEFAULT NULL,
  `compensation_method` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `compensated_at` datetime DEFAULT NULL,
  `compensation_payee` varchar(60) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  KEY `erp_aftersales_actions_case_index` (`case_id`),
  KEY `erp_aftersales_actions_return_index` (`sales_return_id`),
  CONSTRAINT `erp_as_actions_case_fk` FOREIGN KEY (`case_id`) REFERENCES `erp_aftersales_cases` (`id`),
  CONSTRAINT `erp_as_actions_return_fk` FOREIGN KEY (`sales_return_id`) REFERENCES `erp_sales_returns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
