<?php

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Bom;
use App\Models\Erp\BomItem;
use App\Models\Erp\Item;
use App\Models\Erp\Product;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Sku;
use App\Models\Erp\Unit;
use App\Services\Erp\RbacBootstrapService;
use App\Services\Erp\WorkOrderApplicationService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$permissions = [
    'production.demand.view', 'production.work_order.view', 'production.work_order.create',
    'production.work_order.edit', 'production.work_order.submit', 'production.work_order.cancel',
    'production.work_order.gate.view', 'production.work_order.publish', 'production.material.view',
];
$mode = $argv[1] ?? '';

try {
    if ($mode === 'setup') {
        $ownerId = random_int(200000000, 299999999);
        $prefix = 'WO04-PROBE-'.$ownerId;
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $ownerId, 'username' => strtolower($prefix), 'nickname' => 'WO04 Publish Probe',
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        app(RbacBootstrapService::class)->bootstrap(true);
        $roleId = DB::table('erp_rbac_roles')->insertGetId([
            'code' => strtolower(str_replace('-', '_', $prefix)), 'name' => 'WO04 发布并发探针角色',
            'data_scope' => 'all', 'enabled' => true, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('erp_rbac_permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
            DB::table('erp_rbac_role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $ownerId, 'role_id' => $roleId]);
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $ownerId)->first();

        $unit = Unit::create(['unit_code' => $prefix.'-U', 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 4, 'is_base' => true, 'status' => 'enabled']);
        $product = Product::create(['product_code' => $prefix.'-P', 'product_name' => 'WO04 并发产品', 'product_type' => 'standard', 'status' => 'enabled']);
        $sku = Sku::create(['product_id' => $product->id, 'sales_unit_id' => $unit->id, 'sku_code' => $prefix.'-S', 'sku_name' => 'WO04 并发规格', 'order_line_type' => 'physical', 'fulfillment_type' => 'physical', 'status' => 'enabled']);
        $output = Item::create(['item_code' => $prefix.'-FG', 'item_name' => 'WO04 并发成品', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        $component = Item::create(['item_code' => $prefix.'-RM', 'item_name' => 'WO04 并发原料', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        $operationId = DB::table('erp_production_operations')->insertGetId(['operation_no' => $prefix.'-OP', 'operation_name' => '并发组装', 'status' => 'enabled', 'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $routingId = DB::table('erp_production_routings')->insertGetId(['routing_no' => $prefix.'-RT', 'routing_name' => '并发默认路线', 'output_item_id' => $output->id, 'version' => 1, 'status' => 'active', 'is_default' => true, 'default_scope_key' => $output->id, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('erp_production_routing_operations')->insert(['routing_id' => $routingId, 'operation_id' => $operationId, 'sequence' => 10, 'is_key_operation' => true, 'created_at' => now(), 'updated_at' => now()]);
        $order = SalesOrder::create(['sales_order_no' => $prefix.'-SO', 'customer_name' => 'WO04 并发客户', 'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed', 'sales_user_legacy_id' => $ownerId, 'created_by_legacy_id' => $ownerId, 'total_amount' => 0, 'final_receivable_amount' => 0, 'required_delivery_date' => '2026-09-20']);
        $line = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'line_uuid' => $prefix.'-L', 'line_type' => 'physical', 'product_id' => $product->id, 'product_name' => $product->product_name, 'sku_id' => $sku->id, 'sku_name' => $sku->sku_name, 'item_id' => $output->id, 'item_name' => $output->item_name, 'order_qty' => 10, 'unit_id' => $unit->id, 'unit_name_snapshot' => $unit->unit_name, 'unit_price' => 0, 'amount' => 0, 'item_base_unit_id' => $unit->id, 'item_base_required_qty' => 10, 'is_special_customized' => false]);
        $demand = ProductionDemand::create(['requirement_no' => $prefix.'-D', 'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id, 'product_id' => $product->id, 'sku_id' => $sku->id, 'item_id' => $output->id, 'production_qty' => 10, 'base_unit_id' => $unit->id, 'base_unit_name_snapshot' => $unit->unit_name, 'allocated_qty' => 0, 'consumed_qty' => 0, 'remaining_qty' => 10, 'closed_qty' => 0, 'requirement_status' => 'ready', 'bom_match_status' => 'matched', 'is_active' => true, 'requirement_version' => 1, 'business_version' => 1, 'is_ready_for_work_order' => true, 'required_delivery_date' => '2026-09-20']);
        $bom = Bom::create(['bom_no' => $prefix.'-BOM', 'bom_name' => 'WO04 并发 BOM', 'product_id' => $product->id, 'sku_id' => $sku->id, 'output_item_id' => $output->id, 'bom_type' => 'standard', 'version' => 'V1.0', 'is_default' => true, 'status' => 'active', 'audit_status' => 'approved', 'effective_date' => '2026-09-01']);
        BomItem::create(['bom_id' => $bom->id, 'line_no' => 10, 'component_item_id' => $component->id, 'component_item_code' => $component->item_code, 'component_item_name' => $component->item_name, 'qty' => 2, 'unit_id' => $unit->id, 'loss_rate' => 0, 'fixed_qty' => 0, 'replaceable' => false]);

        $service = app(WorkOrderApplicationService::class);
        $draft = $service->createDraft(['client_command_id' => $prefix.'-CREATE', 'production_demand_id' => $demand->id, 'expected_demand_version' => 1, 'target_qty' => 5, 'planned_date' => '2026-09-15', 'responsible_user_legacy_id' => $ownerId, 'production_location_name' => '并发探针车间'], $user, $permissions);
        $waiting = $service->submit($draft->id, ['client_command_id' => $prefix.'-SUBMIT', 'expected_version' => 1, 'reason' => '进入并发发布'], $user, $permissions);
        echo json_encode(['ok' => true, 'owner_id' => $ownerId, 'work_order_id' => $waiting->id]);
        exit(0);
    }

    if ($mode === 'cleanup') {
        $ownerId = (int) ($argv[2] ?? 0);
        $prefix = 'WO04-PROBE-'.$ownerId;
        $workOrderIds = DB::table('erp_work_orders')->where('created_by_legacy_id', $ownerId)->pluck('id');
        $demandIds = DB::table('erp_work_orders')->whereIn('id', $workOrderIds)->pluck('production_demand_id');
        $orderIds = DB::table('erp_sales_order_production_requirements')->whereIn('id', $demandIds)->pluck('sales_order_id');
        $bomIds = DB::table('erp_boms')->where('bom_no', $prefix.'-BOM')->pluck('id');
        $routingIds = DB::table('erp_production_routings')->where('routing_no', $prefix.'-RT')->pluck('id');
        $operationIds = DB::table('erp_production_operations')->where('operation_no', $prefix.'-OP')->pluck('id');
        DB::table('erp_work_order_material_requirements')->whereIn('work_order_id', $workOrderIds)->delete();
        DB::table('erp_work_order_release_gate_checks')->whereIn('work_order_id', $workOrderIds)->delete();
        DB::table('erp_work_order_status_logs')->whereIn('work_order_id', $workOrderIds)->delete();
        DB::table('erp_work_order_command_ledgers')->where('initiated_by_legacy_id', $ownerId)->delete();
        DB::table('erp_work_orders')->whereIn('id', $workOrderIds)->delete();
        DB::table('erp_production_routing_operations')->whereIn('routing_id', $routingIds)->delete();
        DB::table('erp_production_routings')->whereIn('id', $routingIds)->delete();
        DB::table('erp_production_operations')->whereIn('id', $operationIds)->delete();
        DB::table('erp_sales_order_production_requirements')->whereIn('id', $demandIds)->delete();
        DB::table('erp_bom_items')->whereIn('bom_id', $bomIds)->delete();
        DB::table('erp_boms')->whereIn('id', $bomIds)->delete();
        DB::table('erp_sales_order_lines')->whereIn('sales_order_id', $orderIds)->delete();
        DB::table('erp_sales_orders')->whereIn('id', $orderIds)->delete();
        DB::table('erp_skus')->where('sku_code', $prefix.'-S')->delete();
        DB::table('erp_products')->where('product_code', $prefix.'-P')->delete();
        DB::table('erp_items')->whereIn('item_code', [$prefix.'-FG', $prefix.'-RM'])->delete();
        DB::table('erp_units')->where('unit_code', $prefix.'-U')->delete();
        $roleIds = DB::table('erp_rbac_roles')->where('code', strtolower(str_replace('-', '_', $prefix)))->pluck('id');
        DB::table('erp_rbac_user_roles')->where('user_legacy_id', $ownerId)->delete();
        DB::table('erp_rbac_role_permissions')->whereIn('role_id', $roleIds)->delete();
        DB::table('erp_rbac_roles')->whereIn('id', $roleIds)->delete();
        DB::table('erp_legacy_admin_users')->where('legacy_id', $ownerId)->delete();
        echo json_encode(['ok' => true]);
        exit(0);
    }

    if ($mode === 'publish') {
        $workOrderId = (int) ($argv[2] ?? 0);
        $ownerId = (int) ($argv[3] ?? 0);
        $commandId = (string) ($argv[4] ?? '');
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $ownerId)->first();
        if (! $user) throw new RuntimeException('probe user not found');
        putenv('WO02_TEST_DELAY_AFTER_CLAIM_MS=750');
        $workOrder = app(WorkOrderApplicationService::class)->publish($workOrderId, [
            'client_command_id' => $commandId,
            'expected_version' => 2,
            'reason' => '并发发布探针',
        ], $user, $permissions);
        echo json_encode(['ok' => true, 'work_order_id' => $workOrder->id, 'status' => $workOrder->status]);
        exit(0);
    }

    throw new RuntimeException('unknown probe mode');
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'error_code' => $exception instanceof WorkOrderDomainException ? $exception->errorCode : get_class($exception),
        'status' => $exception instanceof WorkOrderDomainException ? $exception->status : 500,
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}
