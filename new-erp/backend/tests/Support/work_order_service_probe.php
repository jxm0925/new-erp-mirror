<?php

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Services\Erp\WorkOrderApplicationService;
use App\Services\Erp\RbacBootstrapService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$permissions = [
    'production.demand.view', 'production.work_order.view', 'production.work_order.create',
    'production.work_order.edit', 'production.work_order.submit', 'production.work_order.cancel',
];
$mode = $argv[1] ?? '';

try {
    if ($mode === 'setup') {
        $ownerId = random_int(100000000, 199999999);
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $ownerId, 'username' => 'wo-probe-'.$ownerId, 'nickname' => 'WO Probe '.$ownerId,
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = SalesOrder::create([
            'sales_order_no' => 'WO-PROBE-SO-'.$ownerId, 'customer_name' => 'WO Probe', 'order_status' => 'confirmed',
            'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed', 'sales_user_legacy_id' => $ownerId,
            'created_by_legacy_id' => $ownerId, 'total_amount' => 0, 'final_receivable_amount' => 0,
        ]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_no' => 1, 'line_uuid' => 'WO-PROBE-LINE-'.$ownerId,
            'line_type' => 'physical', 'order_qty' => 10, 'unit_price' => 0, 'amount' => 0,
        ]);
        $demand = ProductionDemand::create([
            'requirement_no' => 'WO-PROBE-DEMAND-'.$ownerId, 'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id,
            'production_qty' => 10, 'allocated_qty' => 0, 'consumed_qty' => 0, 'remaining_qty' => 10, 'closed_qty' => 0,
            'requirement_status' => 'ready', 'bom_match_status' => 'matched', 'is_active' => true, 'requirement_version' => 1,
            'business_version' => 1, 'is_ready_for_work_order' => false,
        ]);
        app(RbacBootstrapService::class)->bootstrap(true);
        DB::table('erp_rbac_roles')->updateOrInsert(
            ['code' => 'work_order_probe_creator'],
            ['name' => '工单探针建单角色', 'data_scope' => 'all', 'enabled' => true, 'created_at' => now(), 'updated_at' => now()],
        );
        $creatorRoleId = DB::table('erp_rbac_roles')->where('code', 'work_order_probe_creator')->value('id');
        foreach (DB::table('erp_rbac_permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
            DB::table('erp_rbac_role_permissions')->insertOrIgnore(['role_id' => $creatorRoleId, 'permission_id' => $permissionId]);
        }
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $ownerId, 'role_id' => $creatorRoleId]);
        echo json_encode(['ok' => true, 'owner_id' => $ownerId, 'order_id' => $order->id, 'demand_id' => $demand->id]);
        exit(0);
    }

    if ($mode === 'cleanup') {
        $orderId = (int) ($argv[2] ?? 0);
        $demandId = (int) ($argv[3] ?? 0);
        $ownerId = (int) ($argv[4] ?? 0);
        $workOrderIds = DB::table('erp_work_orders')->where('production_demand_id', $demandId)->pluck('id');
        DB::table('erp_work_order_status_logs')->whereIn('work_order_id', $workOrderIds)->delete();
        DB::table('erp_work_order_command_ledgers')->whereIn('result_id', $workOrderIds)->delete();
        DB::table('erp_work_orders')->whereIn('id', $workOrderIds)->delete();
        DB::table('erp_sales_order_production_requirements')->where('id', $demandId)->delete();
        DB::table('erp_sales_order_lines')->where('sales_order_id', $orderId)->delete();
        DB::table('erp_sales_orders')->where('id', $orderId)->delete();
        DB::table('erp_rbac_user_roles')->where('user_legacy_id', $ownerId)->delete();
        DB::table('erp_legacy_admin_users')->where('legacy_id', $ownerId)->delete();
        echo json_encode(['ok' => true]);
        exit(0);
    }

    $demandId = (int) ($argv[2] ?? 0);
    $ownerId = (int) ($argv[3] ?? 0);
    $commandId = (string) ($argv[4] ?? '');
    $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $ownerId)->first();
    if (! $user) throw new RuntimeException('probe user not found');
    if ($mode === 'concurrent') putenv('WO02_TEST_DELAY_AFTER_CLAIM_MS=1500');
    if ($mode === 'crash') putenv('WO02_TEST_CRASH_AFTER_BUSINESS_COMMIT=1');
    if ($mode === 'recover') putenv('WO02_TEST_PROCESSING_TIMEOUT_SECONDS=0');

    $expectedDemandVersion = (int) DB::table('erp_sales_order_production_requirements')->where('id', $demandId)->value('business_version');
    if ($mode === 'recover') $expectedDemandVersion--;
    $workOrder = app(WorkOrderApplicationService::class)->createDraft([
        'client_command_id' => $commandId, 'production_demand_id' => $demandId,
        'expected_demand_version' => $expectedDemandVersion, 'target_qty' => 1,
    ], $user, $permissions);
    echo json_encode(['ok' => true, 'work_order_id' => $workOrder->id]);
    exit(0);
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'error_code' => $exception instanceof WorkOrderDomainException ? $exception->errorCode : get_class($exception),
        'status' => $exception instanceof WorkOrderDomainException ? $exception->status : 500,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}
