<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$order = DB::table('erp_sales_orders')
    ->where('sales_order_no', 'SO-QA-UNIT-SPLIT-20260805')
    ->first(['id', 'sales_order_no', 'production_confirm_status', 'fulfillment_status']);

if (!$order) {
    fwrite(STDERR, "QA order not found.\n");
    exit(1);
}

$evidence = [
    'order' => $order,
    'fulfillment_plan_rows' => DB::table('erp_sales_order_fulfillments')
        ->where('sales_order_id', $order->id)
        ->selectRaw('fulfillment_type, SUM(sales_qty) sales_qty, SUM(item_base_qty) item_base_qty')
        ->groupBy('fulfillment_type')
        ->orderBy('fulfillment_type')
        ->get(),
    'production_requirements' => DB::table('erp_sales_order_production_requirements')
        ->where('sales_order_id', $order->id)
        ->get(['production_qty', 'item_base_required_qty', 'is_ready_for_work_order']),
    'forbidden_downstream_footprint' => collect(Schema::getTableListing())
        ->filter(fn (string $table) => preg_match('/(work.?order|process.?task|production.?schedule)/i', $table))
        ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()]),
];

echo json_encode($evidence, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
