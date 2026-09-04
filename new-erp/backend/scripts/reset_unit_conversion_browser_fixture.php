<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use App\Models\Erp\SalesOrder;
use App\Services\Erp\InventoryReservationService;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$order = DB::table('erp_sales_orders')
    ->where('sales_order_no', 'SO-QA-UNIT-SPLIT-20260805')
    ->first();

if (!$order) {
    fwrite(STDERR, "QA order not found. Run create_unit_conversion_browser_fixture.php first.\n");
    exit(1);
}

DB::transaction(function () use ($order): void {
    app(InventoryReservationService::class)->releaseForSalesOrder(
        SalesOrder::findOrFail($order->id),
        '自动拆分浏览器验收重置释放库存占用'
    );
    DB::table('erp_sales_order_production_requirements')->where('sales_order_id', $order->id)->delete();
    DB::table('erp_sales_order_fulfillments')->where('sales_order_id', $order->id)->delete();
    DB::table('erp_sales_order_logs')
        ->where('sales_order_id', $order->id)
        ->where('action', 'production_confirm')
        ->delete();

    DB::table('erp_sales_order_lines')->where('sales_order_id', $order->id)->update([
        'fulfillment_type' => 'undetermined',
        'line_status' => 'confirmed_pending_fulfillment',
        'inventory_fulfilled_qty' => 0,
        'production_required_qty' => 0,
        'service_fulfilled_qty' => 0,
        'no_delivery_qty' => 0,
        'undetermined_qty' => DB::raw('order_qty - cancelled_qty'),
        'updated_at' => now(),
    ]);

    DB::table('erp_sales_orders')->where('id', $order->id)->update([
        'fulfillment_status' => 'pending',
        'production_confirm_status' => 'pending',
        'updated_at' => now(),
    ]);
});

echo $order->id;
