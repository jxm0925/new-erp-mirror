<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 业务员档案：登录账号的“业务身份”（销售员/采购员/仓管员/质检员），与角色权限分离。
// 能否被选为订单业务员、按业务员归属订单与提成，以本表启用且在生效期内的记录为准；
// erp_legacy_admin_users.is_sales 只作为兼容投影，由 BusinessStaffService 维护。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_business_staff';
    public const ESTABLISHED_EXTENSION = true;

    public function up(): void { $this->createEstablishedExtension(); }

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_business_staff` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `user_legacy_id` bigint(20) unsigned NOT NULL,
  `staff_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `department_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `status` varchar(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'enabled',
  `effective_from` date DEFAULT NULL,
  `effective_to` date DEFAULT NULL,
  `source` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'manual',
  `remark` varchar(500) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci DEFAULT NULL,
  `business_version` int(10) unsigned NOT NULL DEFAULT '1',
  `created_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `updated_by_legacy_id` bigint(20) unsigned DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_business_staff_user_type_unique` (`user_legacy_id`,`staff_type`),
  KEY `erp_business_staff_type_status_index` (`staff_type`,`status`),
  KEY `erp_business_staff_department_index` (`department_legacy_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    public const DECLARED_CHECKS = [];
};
