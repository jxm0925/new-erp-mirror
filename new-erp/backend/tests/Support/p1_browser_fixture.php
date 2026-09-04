<?php

use App\Models\Erp\ProductionDemand;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Services\Erp\RbacBootstrapService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? 'setup';
if ($mode === 'cleanup') {
    $adminId = (int) ($argv[2] ?? 0);
    $operatorId = (int) ($argv[3] ?? 0);
    $orderId = (int) ($argv[4] ?? 0);
    $demandId = (int) ($argv[5] ?? 0);
    $workOrderIds = DB::table('erp_work_orders')->where('production_demand_id', $demandId)->pluck('id');
    DB::table('erp_operation_logs')->where('module', 'work_order')->whereIn('target_id', $workOrderIds)->delete();
    DB::table('erp_work_order_status_logs')->whereIn('work_order_id', $workOrderIds)->delete();
    DB::table('erp_work_order_command_ledgers')->where(function ($query) use ($workOrderIds): void {
        $query->whereIn('result_id', $workOrderIds)->orWhereIn('aggregate_id', $workOrderIds);
    })->delete();
    DB::table('erp_work_orders')->whereIn('id', $workOrderIds)->delete();
    DB::table('erp_sales_order_production_requirements')->where('id', $demandId)->delete();
    DB::table('erp_sales_order_lines')->where('sales_order_id', $orderId)->delete();
    DB::table('erp_sales_orders')->where('id', $orderId)->delete();
    DB::table('erp_auth_tokens')->whereIn('user_legacy_id', [$adminId, $operatorId])->delete();
    DB::table('erp_rbac_user_roles')->whereIn('user_legacy_id', [$adminId, $operatorId])->delete();
    DB::table('erp_legacy_admin_users')->whereIn('legacy_id', [$adminId, $operatorId])->delete();
    echo json_encode(['ok' => true]);
    exit(0);
}

app(RbacBootstrapService::class)->bootstrap(true);
$suffix = date('YmdHis');
$adminId = random_int(700000001, 799999998);
$operatorId = $adminId + 1;
$username = 'codex_p1_'.$suffix;
DB::table('erp_legacy_admin_users')->insert([
    [
        'legacy_id' => $adminId, 'username' => $username,
        'password_hash' => Hash::make('P1Browser2026'), 'nickname' => 'P1验收管理员',
        'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
    ],
    [
        'legacy_id' => $operatorId, 'username' => 'p1_operator_'.$suffix, 'nickname' => '一线生产员',
        'password_hash' => null,
        'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
    ],
]);
$adminRole = DB::table('erp_rbac_roles')->where('code', 'admin')->value('id');
$operatorRole = DB::table('erp_rbac_roles')->where('code', 'production_operator')->value('id');
DB::table('erp_rbac_user_roles')->insert([
    ['user_legacy_id' => $adminId, 'role_id' => $adminRole],
    ['user_legacy_id' => $operatorId, 'role_id' => $operatorRole],
]);
$order = SalesOrder::create([
    'sales_order_no' => 'BROWSER-P1-SO-'.$suffix, 'customer_name' => 'P1浏览器客户',
    'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed',
    'sales_user_legacy_id' => $adminId, 'created_by_legacy_id' => $adminId,
    'total_amount' => 0, 'final_receivable_amount' => 0, 'required_delivery_date' => '2026-09-30',
]);
$line = SalesOrderLine::create([
    'sales_order_id' => $order->id, 'line_no' => 1, 'line_uuid' => 'BROWSER-P1-LINE-'.$suffix,
    'line_type' => 'physical', 'order_qty' => 10, 'unit_price' => 0, 'amount' => 0,
    'product_name' => '浏览器验收产品', 'sku_name' => 'P1-SKU',
    'item_snapshot' => ['name' => '验收物料', 'spec' => 'P1-SPEC'],
]);
$demand = ProductionDemand::create([
    'requirement_no' => 'BROWSER-P1-DEMAND-'.$suffix,
    'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id,
    'production_qty' => 10, 'allocated_qty' => 0, 'consumed_qty' => 0, 'remaining_qty' => 10, 'closed_qty' => 0,
    'requirement_status' => 'ready', 'bom_match_status' => 'matched', 'is_active' => true,
    'requirement_version' => 1, 'business_version' => 1, 'is_ready_for_work_order' => false,
    'required_delivery_date' => '2026-09-30',
]);
echo json_encode([
    'username' => $username, 'password' => 'P1Browser2026', 'admin_id' => $adminId,
    'operator_id' => $operatorId, 'order_id' => $order->id, 'demand_id' => $demand->id,
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
