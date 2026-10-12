<?php

declare(strict_types=1);

use Database\Schema\InitialTableMigration;

require_once __DIR__.'/../schema/InitialTableMigration.php';

// 本表唯一完整初始定义；历史变更已合并。修改前先核对实际库，禁止另加 ALTER 迁移。
// MySQL 精确类型、生成列、索引、外键及删除策略保留在同一处；不含数据回填。
return new class extends InitialTableMigration
{
    public const TABLE = 'erp_production_inventory_reservations';

    public const CREATE_SQL = <<<'SQL'
CREATE TABLE `erp_production_inventory_reservations` (
  `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
  `reservation_no` varchar(80) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `source_work_order_id` bigint(20) unsigned NOT NULL,
  `source_output_record_id` bigint(20) unsigned NOT NULL,
  `finished_goods_receipt_id` bigint(20) unsigned NOT NULL,
  `inventory_balance_id` bigint(20) unsigned NOT NULL,
  `target_work_order_id` bigint(20) unsigned NOT NULL,
  `target_production_unit_id` bigint(20) unsigned DEFAULT NULL,
  `target_routing_operation_id` bigint(20) unsigned NOT NULL,
  `target_type` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
  `target_id` bigint(20) unsigned NOT NULL,
  `target_material_requirement_id` bigint(20) unsigned NOT NULL,
  `item_id` bigint(20) unsigned NOT NULL,
  `reserved_base_qty` decimal(18,8) NOT NULL,
  `issued_base_qty` decimal(18,8) NOT NULL DEFAULT '0.00000000',
  `status` varchar(30) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'ACTIVE',
  `created_by_legacy_id` bigint(20) unsigned NOT NULL,
  `reserved_at` timestamp NOT NULL,
  `consumed_at` timestamp NULL DEFAULT NULL,
  `created_at` timestamp NULL DEFAULT NULL,
  `updated_at` timestamp NULL DEFAULT NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `erp_prod_inv_res_receipt_uq` (`finished_goods_receipt_id`),
  UNIQUE KEY `erp_production_inventory_reservations_reservation_no_unique` (`reservation_no`),
  KEY `erp_prod_inv_res_target_idx` (`target_work_order_id`,`target_type`,`target_id`,`status`),
  KEY `erp_prod_inv_res_source_wo_fk` (`source_work_order_id`),
  KEY `erp_prod_inv_res_output_fk` (`source_output_record_id`),
  KEY `erp_prod_inv_res_balance_fk` (`inventory_balance_id`),
  KEY `erp_prod_inv_res_target_unit_fk` (`target_production_unit_id`),
  KEY `erp_prod_inv_res_target_route_fk` (`target_routing_operation_id`),
  KEY `erp_prod_inv_res_target_req_fk` (`target_material_requirement_id`),
  KEY `erp_prod_inv_res_item_fk` (`item_id`),
  CONSTRAINT `erp_prod_inv_res_balance_fk` FOREIGN KEY (`inventory_balance_id`) REFERENCES `erp_inventory_balances` (`id`),
  CONSTRAINT `erp_prod_inv_res_item_fk` FOREIGN KEY (`item_id`) REFERENCES `erp_items` (`id`),
  CONSTRAINT `erp_prod_inv_res_output_fk` FOREIGN KEY (`source_output_record_id`) REFERENCES `erp_production_output_records` (`id`),
  CONSTRAINT `erp_prod_inv_res_receipt_fk` FOREIGN KEY (`finished_goods_receipt_id`) REFERENCES `erp_work_order_finished_goods_receipts` (`id`),
  CONSTRAINT `erp_prod_inv_res_source_wo_fk` FOREIGN KEY (`source_work_order_id`) REFERENCES `erp_work_orders` (`id`),
  CONSTRAINT `erp_prod_inv_res_target_req_fk` FOREIGN KEY (`target_material_requirement_id`) REFERENCES `erp_production_target_material_requirements` (`id`),
  CONSTRAINT `erp_prod_inv_res_target_route_fk` FOREIGN KEY (`target_routing_operation_id`) REFERENCES `erp_production_routing_operations` (`id`),
  CONSTRAINT `erp_prod_inv_res_target_unit_fk` FOREIGN KEY (`target_production_unit_id`) REFERENCES `erp_production_units` (`id`),
  CONSTRAINT `erp_prod_inv_res_target_wo_fk` FOREIGN KEY (`target_work_order_id`) REFERENCES `erp_work_orders` (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL;

    // 8.0.12 忽略 CHECK；原约束意图仍集中保留，支持约束的新库由 helper 纳入 CREATE。
    public const DECLARED_CHECKS = [
    ];
};
