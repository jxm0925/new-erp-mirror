<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ErpDocumentNumberRuleSeeder extends Seeder
{
    public function run(): void
    {
        if (!Schema::hasTable('erp_document_number_rules')) {
            return;
        }

        $rules = [
            ['sales_order', '销售订单', 'SO', 'Ymd', 5, 'daily'],
            ['sales_quotation', '销售报价', 'SQ', 'Ymd', 5, 'daily'],
            ['shipment', '发货单', 'SH', 'Ymd', 5, 'daily'],
            ['sales_return', '销售退货单', 'SR', 'Ymd', 5, 'daily'],
            ['sales_return_receipt', '销售退货到货单', 'SRR', 'Ymd', 5, 'daily'],
            ['aftersales_case', '售后单', 'AS', 'Ymd', 5, 'daily'],
            ['sales_order_change', '订单变更单', 'SOC', 'Ymd', 5, 'daily'],

            ['purchase_request', '采购需求', 'PRQ', 'Ymd', 5, 'daily'],
            ['purchase_plan', '采购计划', 'PPL', 'Ymd', 5, 'daily'],
            ['purchase_order', '采购订单', 'POD', 'Ymd', 5, 'daily'],
            ['purchase_inquiry', '采购询价', 'PIQ', 'Ymd', 5, 'daily'],
            ['supplier_quotation', '供应商报价记录', 'SQR', 'Ymd', 5, 'daily'],
            ['purchase_receipt', '采购到货单', 'PRC', 'Ymd', 5, 'daily'],
            ['purchase_stock_in', '采购入库单', 'PSI', 'Ymd', 5, 'daily'],
            ['purchase_return', '采购退货单', 'PRT', 'Ymd', 5, 'daily'],
            ['purchase_exchange_order', '采购换货单', 'PEX', 'Ymd', 5, 'daily'],

            ['inventory_in', '入库单', 'IN', 'Ymd', 5, 'daily'],
            ['inventory_out', '出库单', 'OUT', 'Ymd', 5, 'daily'],
            ['inventory_transfer', '调拨单', 'TR', 'Ymd', 5, 'daily'],
            ['inventory_count', '盘点单', 'IC', 'Ymd', 5, 'daily'],
            ['inventory_adjustment', '库存调整单', 'IA', 'Ymd', 5, 'daily'],
            ['inventory_reservation', '库存占用单', 'IR', 'Ymd', 5, 'daily'],

            ['production_requirement', '生产需求', 'PD', 'Ymd', 5, 'daily'],
            ['production_plan', '生产计划', 'PP', 'Ymd', 5, 'daily'],
            ['trial_order', '试制单', 'TO', 'Ymd', 5, 'daily'],
            ['work_order', '生产工单', 'WO', 'Ymd', 5, 'daily'],
            ['stock_prebuild', '备货来源号', 'SPB', 'Ymd', 5, 'daily'],
            ['operation_task', '工序任务', 'OT', 'Ymd', 5, 'daily'],
            ['material_issue', '领料单', 'MI', 'Ymd', 5, 'daily'],
            ['material_return', '退料单', 'MR', 'Ymd', 5, 'daily'],
            ['material_replenishment', '补料单', 'RP', 'Ymd', 5, 'daily'],
            ['work_report', '报工单', 'WR', 'Ymd', 5, 'daily'],
            ['production_stock_in', '完工入库单', 'PS', 'Ymd', 5, 'daily'],

            ['finance_account', '资金账户', 'FAC', 'Ymd', 5, 'daily'],
            ['finance_receipt', '收款单', 'FR', 'Ymd', 5, 'daily'],
            ['finance_payment', '付款单', 'FP', 'Ymd', 5, 'daily'],
            ['finance_invoice', '发票记录', 'FI', 'Ymd', 5, 'daily'],
            ['finance_transfer', '资金转账/换汇单', 'FT', 'Ymd', 5, 'daily'],

            ['customer', '客户编码', 'CUST', '', 6, 'none'],
            ['supplier', '供应商编码', 'SUP', '', 6, 'none'],
            ['product', 'Product编码', 'PROD', '', 6, 'none'],
            ['sku', 'SKU编码', 'SKU', '', 8, 'none'],
            ['item', 'Item编码', 'ITEM', '', 8, 'none'],
            ['item_category', 'Item类目编号规则', 'IC', '', 6, 'none'],
            ['bom', 'BOM编号', 'BOM', 'Ymd', 5, 'daily'],
            ['routing', '工艺路线编号', 'RT', 'Ymd', 5, 'daily'],
            ['work_center', '工作中心编码', 'WC', '', 6, 'none'],
            ['operation', '工序编码', 'OP', 'Ymd', 5, 'daily'],
            ['warehouse', '仓库编码', 'WH', '', 5, 'none'],
            ['location', '库位编码', 'LOC', '', 6, 'none'],
        ];

        foreach ($rules as [$type, $name, $prefix, $dateFormat, $length, $resetCycle]) {
            $this->upsert('erp_document_number_rules', ['document_type' => $type], [
                'name' => $name,
                'prefix' => $prefix,
                'date_format' => $dateFormat,
                'sequence_length' => $length,
                'reset_cycle' => $resetCycle,
                'allow_manual_edit' => false,
                'enabled' => true,
            ]);
        }
    }

    private function upsert(string $table, array $key, array $values): void
    {
        $exists = DB::table($table)->where($key)->exists();
        $payload = $values + ['updated_at' => now()];
        if (!$exists) {
            $payload['created_at'] = now();
        }
        DB::table($table)->updateOrInsert($key, $payload);
    }
}
