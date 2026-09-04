<?php

use App\Models\Erp\SalesOrder;
use App\Services\Erp\InventoryReservationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__).'/vendor/autoload.php';

$app = require dirname(__DIR__).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$action = $argv[1] ?? 'prepare';
$baseOrderId = 4;
$itemId = 274;
$warehouseId = 1;
$locationId = 1;
$unitId = 7;
$originalBatchId = 4;
$qaBatch = 'QA-AUTO-20260808';
$orderNumbers = [
    'zero' => 'SO-QA-AUTO-ZERO-20260808',
    'partial' => 'SO-QA-AUTO-PARTIAL-20260808',
    'after_reserved' => 'SO-QA-AUTO-AFTER-20260808',
];

$cleanupOrders = function () use ($orderNumbers): void {
    foreach (SalesOrder::whereIn('sales_order_no', array_values($orderNumbers))->get() as $order) {
        app(InventoryReservationService::class)->releaseForSalesOrder($order, 'auto fulfillment browser QA cleanup');
        DB::table('erp_inventory_reservations')->where('source_order_id', $order->id)->delete();
        DB::table('erp_sales_order_production_requirements')->where('sales_order_id', $order->id)->delete();
        DB::table('erp_sales_order_fulfillments')->where('sales_order_id', $order->id)->delete();
        DB::table('erp_sales_order_logs')->where('sales_order_id', $order->id)->delete();
        DB::table('erp_sales_order_lines')->where('sales_order_id', $order->id)->delete();
        DB::table('erp_sales_orders')->where('id', $order->id)->delete();
    }
};

if ($action === 'prepare') {
    DB::transaction(function () use ($cleanupOrders, $baseOrderId, $itemId, $warehouseId, $locationId, $unitId, $originalBatchId, $qaBatch, $orderNumbers): void {
        $cleanupOrders();
        DB::table('erp_inventory_balances')->where('item_id', $itemId)->where('batch_no', $qaBatch)->delete();
        DB::table('erp_inventory_batches')->where('item_id', $itemId)->where('batch_no', $qaBatch)->delete();
        DB::table('erp_inventory_batches')->where('id', $originalBatchId)->update(['status' => 'disabled', 'updated_at' => now()]);
        DB::table('erp_inventory_batches')->insert([
            'item_id' => $itemId,
            'batch_no' => $qaBatch,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'source_type' => 'qa_auto_fulfillment',
            'source_id' => $baseOrderId,
            'status' => 'enabled',
            'remark' => 'auto fulfillment browser QA batch',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        DB::table('erp_inventory_balances')->insert([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'batch_no' => $qaBatch,
            'unit_id' => $unitId,
            'quantity_on_hand' => 0,
            'quantity_available' => 0,
            'quantity_locked' => 0,
            'quantity_defective' => 0,
            'quantity_pending' => 0,
            'remark' => 'auto fulfillment browser QA inventory',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $baseOrder = (array) DB::table('erp_sales_orders')->where('id', $baseOrderId)->first();
        $baseLine = (array) DB::table('erp_sales_order_lines')->where('sales_order_id', $baseOrderId)->first();
        if (!$baseOrder || !$baseLine) {
            throw new RuntimeException('Real base sales order is missing.');
        }

        foreach ($orderNumbers as $key => $number) {
            $order = $baseOrder;
            unset($order['id']);
            $order['sales_order_no'] = $number;
            $order['origin_order_no'] = 'QA-AUTO-'.strtoupper($key);
            $order['order_status'] = 'confirmed';
            $order['confirm_status'] = 'confirmed';
            $order['production_confirm_status'] = 'pending';
            $order['fulfillment_status'] = 'pending';
            $order['total_qty'] = 10;
            $order['confirmed_at'] = now();
            $order['created_at'] = now();
            $order['updated_at'] = now();
            $orderId = DB::table('erp_sales_orders')->insertGetId($order);

            $line = $baseLine;
            unset($line['id']);
            $line['sales_order_id'] = $orderId;
            $line['line_uuid'] = 'qa-auto-'.$key.'-'.$orderId;
            $line['order_qty'] = 10;
            $line['item_base_required_qty'] = 10;
            $line['fulfillment_type'] = 'undetermined';
            $line['line_status'] = 'confirmed_pending_fulfillment';
            $line['inventory_fulfilled_qty'] = 0;
            $line['production_required_qty'] = 0;
            $line['service_fulfilled_qty'] = 0;
            $line['no_delivery_qty'] = 0;
            $line['undetermined_qty'] = 10;
            $line['created_at'] = now();
            $line['updated_at'] = now();
            DB::table('erp_sales_order_lines')->insert($line);
        }
    });
} elseif ($action === 'stock4') {
    DB::transaction(function () use ($itemId, $warehouseId, $locationId, $qaBatch): void {
        DB::table('erp_inventory_balances')->where([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
            'batch_no' => $qaBatch,
        ])->update([
            'quantity_on_hand' => 4,
            'quantity_available' => 4,
            'quantity_locked' => 0,
            'quantity_defective' => 0,
            'quantity_pending' => 0,
            'updated_at' => now(),
        ]);
        DB::table('erp_inventory_location_balances')->where([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
        ])->update([
            'quantity_on_hand' => 104,
            'quantity_available' => 104,
            'quantity_locked' => 0,
            'updated_at' => now(),
        ]);
    });
} elseif ($action === 'cleanup') {
    DB::transaction(function () use ($cleanupOrders, $itemId, $warehouseId, $locationId, $originalBatchId, $qaBatch): void {
        $cleanupOrders();
        DB::table('erp_inventory_balances')->where('item_id', $itemId)->where('batch_no', $qaBatch)->delete();
        DB::table('erp_inventory_batches')->where('item_id', $itemId)->where('batch_no', $qaBatch)->delete();
        DB::table('erp_inventory_batches')->where('id', $originalBatchId)->update(['status' => 'enabled', 'updated_at' => now()]);
        DB::table('erp_inventory_location_balances')->where([
            'item_id' => $itemId,
            'warehouse_id' => $warehouseId,
            'location_id' => $locationId,
        ])->update([
            'quantity_on_hand' => 100,
            'quantity_available' => 100,
            'quantity_locked' => 0,
            'quantity_defective' => 0,
            'quantity_pending' => 0,
            'updated_at' => now(),
        ]);
    });
} elseif ($action !== 'evidence') {
    fwrite(STDERR, "Unknown action: {$action}\n");
    exit(1);
}

$orders = DB::table('erp_sales_orders')
    ->whereIn('sales_order_no', array_values($orderNumbers))
    ->get(['id', 'sales_order_no', 'production_confirm_status', 'fulfillment_status'])
    ->keyBy('sales_order_no');

echo json_encode([
    'orders' => $orders,
    'qa_balance' => DB::table('erp_inventory_balances')->where('item_id', $itemId)->where('batch_no', $qaBatch)->first(),
    'fulfillments' => DB::table('erp_sales_order_fulfillments')->whereIn('sales_order_id', $orders->pluck('id'))->get(),
    'reservations' => DB::table('erp_inventory_reservations')->whereIn('source_order_id', $orders->pluck('id'))->get(),
    'production_requirements' => DB::table('erp_sales_order_production_requirements')->whereIn('sales_order_id', $orders->pluck('id'))->get(),
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
