<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__) . '/vendor/autoload.php';

$app = require dirname(__DIR__) . '/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$bomId = DB::table('erp_boms')->where('bom_no', 'BOM-QA-UNIT-SPLIT-20260805')->value('id');
if (!$bomId) {
    $bomId = DB::table('erp_boms')->insertGetId([
        'bom_no' => 'BOM-QA-UNIT-SPLIT-20260805',
        'bom_name' => '单位换算最终验收专用BOM',
        'product_id' => 476,
        'sku_id' => 7459,
        'output_item_id' => 274,
        'bom_type' => 'standard',
        'version' => 'V1.0',
        'is_default' => true,
        'status' => 'active',
        'audit_status' => 'approved',
        'effective_date' => now()->toDateString(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('erp_bom_items')->insert([
        'bom_id' => $bomId,
        'line_no' => 10,
        'component_item_id' => 264,
        'component_item_code' => '00001',
        'component_item_name' => '锰砂',
        'qty' => 1,
        'unit_id' => 6,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

$existing = DB::table('erp_sales_orders')
    ->where('sales_order_no', 'SO-QA-UNIT-SPLIT-20260805')
    ->first();

if ($existing) {
    echo $existing->id;
    exit(0);
}

DB::transaction(function (): void {
    $order = (array) DB::table('erp_sales_orders')->where('id', 2)->first();
    unset($order['id']);
    $order['sales_order_no'] = 'SO-QA-UNIT-SPLIT-20260805';
    $order['origin_order_no'] = 'QA-UNIT-10-4-6';
    $order['total_qty'] = 10;
    $order['total_amount'] = 2061.50;
    $order['fulfillment_status'] = 'pending';
    $order['production_confirm_status'] = 'pending';
    $order['confirmed_at'] = now();
    $order['created_at'] = now();
    $order['updated_at'] = now();
    $orderId = DB::table('erp_sales_orders')->insertGetId($order);

    $line = (array) DB::table('erp_sales_order_lines')->where('id', 2)->first();
    unset($line['id']);
    $line['sales_order_id'] = $orderId;
    $line['line_uuid'] = 'qa-unit-split-' . $orderId;
    $line['order_qty'] = 10;
    $line['amount'] = 2061.50;
    $line['item_base_required_qty'] = 10;
    $line['inventory_fulfilled_qty'] = 0;
    $line['production_required_qty'] = 0;
    $line['service_fulfilled_qty'] = 0;
    $line['no_delivery_qty'] = 0;
    $line['undetermined_qty'] = 10;
    $line['fulfillment_type'] = 'undetermined';
    $line['line_status'] = 'pending_confirmation';
    $line['created_at'] = now();
    $line['updated_at'] = now();
    DB::table('erp_sales_order_lines')->insert($line);

    echo $orderId;
});
