<?php

namespace App\Console\Commands;

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
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

final class SeedMwoInterfaceAcceptance extends Command
{
    private const MARKER = 'MWO-INTERFACE-V1';

    private const PERMISSIONS = [
        'production.demand.view', 'production.work_order.view', 'production.work_order.create',
        'production.work_order.edit', 'production.work_order.submit', 'production.work_order.cancel',
        'production.work_order.gate.view', 'production.work_order.publish', 'production.material.view',
        'production.material_requirement.view',
    ];

    protected $signature = 'erp:seed-mwo-interface-acceptance
        {--user=1 : Existing active local production-user legacy id used as salesperson and responsible person}';

    protected $description = 'Idempotently prepare a service-generated local MWO/WO/PU/PT interface acceptance fixture.';

    public function handle(WorkOrderApplicationService $workOrders, RbacBootstrapService $rbac): int
    {
        if (! app()->environment(['local', 'testing'])) {
            $this->error('该命令只允许在 local/testing 环境运行。');
            return self::FAILURE;
        }

        $userId = filter_var($this->option('user'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
        $user = $userId ? DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->first() : null;
        if (! $user || $user->status !== 'normal') {
            $this->error('--user 必须指向一个状态正常的本地 ERP 用户。');
            return self::INVALID;
        }

        try {
            $rbac->bootstrap();
            $result = DB::transaction(function () use ($user, $workOrders): array {
                $existing = SalesOrder::query()->where('sales_order_no', self::MARKER.'-SO')->first();
                if ($existing) return $this->existingResult($existing);

                $master = $this->createMasterData();
                $order = SalesOrder::create([
                    'sales_order_no' => self::MARKER.'-SO',
                    'order_source' => 'acceptance_fixture',
                    'customer_name' => '宁波星辉自动化设备有限公司',
                    'customer_snapshot' => ['customer_name' => '宁波星辉自动化设备有限公司'],
                    'contact_name' => '李工',
                    'contact_phone' => '13800000000',
                    'required_delivery_date' => now()->addDays(14)->toDateString(),
                    'total_qty' => 10,
                    'total_amount' => 100000,
                    'final_receivable_amount' => 100000,
                    'currency' => 'CNY',
                    'order_status' => 'confirmed',
                    'confirm_status' => 'confirmed',
                    'fulfillment_status' => 'partially_matched',
                    'production_confirm_status' => 'confirmed',
                    'shipment_status' => 'not_shipped',
                    'payment_status' => 'unpaid',
                    'production_funding_status' => 'passed',
                    'shipment_funding_status' => 'blocked',
                    'sales_user_legacy_id' => $user->legacy_id,
                    'created_by_legacy_id' => $user->legacy_id,
                    'business_version' => 1,
                    'funding_policy_snapshot' => [
                        'policy_type' => 'deposit_production',
                        'policy_name' => '零门槛启动生产，全额回款后发货',
                        'production_threshold_type' => 'ratio',
                        'production_threshold_value' => '0',
                        'shipment_requires_full_payment' => true,
                    ],
                    'remark' => '首批 4 台优先交付；出货前需完成全额回款确认。',
                    'confirmed_by' => $user->nickname ?: $user->username,
                    'confirmed_at' => now(),
                ]);

                $line = SalesOrderLine::create([
                    'sales_order_id' => $order->id,
                    'line_no' => 1,
                    'line_uuid' => self::MARKER.'-LINE-1',
                    'line_type' => 'physical',
                    'commercial_role' => 'sale',
                    'product_id' => $master['product']->id,
                    'product_name' => $master['product']->product_name,
                    'sku_id' => $master['sku']->id,
                    'sku_name' => $master['sku']->sku_name,
                    'item_id' => $master['output']->id,
                    'item_name' => $master['output']->item_name,
                    'order_qty' => 10,
                    'unit_id' => $master['unit']->id,
                    'unit_name_snapshot' => $master['unit']->unit_name,
                    'unit_price' => 10000,
                    'amount' => 100000,
                    'inventory_fulfilled_qty' => 4,
                    'production_required_qty' => 6,
                    'fulfillment_type' => 'physical',
                    'line_status' => 'open',
                    'item_base_unit_id' => $master['unit']->id,
                    'item_base_required_qty' => 10,
                    'remark' => '生产缺口 6 台，页面验收用数据。',
                ]);

                $demand = ProductionDemand::create([
                    'requirement_no' => self::MARKER.'-DEMAND',
                    'sales_order_id' => $order->id,
                    'sales_order_line_id' => $line->id,
                    'product_id' => $master['product']->id,
                    'sku_id' => $master['sku']->id,
                    'item_id' => $master['output']->id,
                    'production_qty' => 6,
                    'base_unit_id' => $master['unit']->id,
                    'base_unit_name_snapshot' => $master['unit']->unit_name,
                    'allocated_qty' => 0,
                    'consumed_qty' => 0,
                    'remaining_qty' => 6,
                    'closed_qty' => 0,
                    'requirement_status' => 'ready',
                    'bom_match_status' => 'matched',
                    'is_active' => true,
                    'requirement_version' => 1,
                    'business_version' => 1,
                    'is_ready_for_work_order' => true,
                    'required_delivery_date' => $order->required_delivery_date,
                    'confirmed_at' => now(),
                    'confirmed_by' => $user->nickname ?: $user->username,
                    'remark' => '仅用于本地 MWO 详情接口与小程序验收。',
                ]);

                $this->createAttachment($order, $user);
                DB::table('erp_sales_order_logs')->insert([
                    'sales_order_id' => $order->id,
                    'order_no_snapshot' => $order->sales_order_no,
                    'action' => 'order_remark_update',
                    'operator' => $user->nickname ?: $user->username,
                    'content' => '生产计划已确认，首批顺序按销售备注执行。',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                $draft = $workOrders->createDraft([
                    'client_command_id' => self::MARKER.'-CREATE',
                    'production_demand_id' => $demand->id,
                    'expected_demand_version' => 1,
                    'target_qty' => 6,
                    'planned_date' => now()->addDays(2)->toDateString(),
                    'production_batch' => self::MARKER.'-BATCH',
                    'responsible_user_legacy_id' => $user->legacy_id,
                    'production_location_name' => '一号智能装配车间',
                ], $user, self::PERMISSIONS, true);
                $waiting = $workOrders->submit($draft->id, [
                    'client_command_id' => self::MARKER.'-SUBMIT',
                    'expected_version' => 1,
                    'reason' => '本地界面验收数据',
                ], $user, self::PERMISSIONS, true);
                $released = $workOrders->publish($waiting->id, [
                    'client_command_id' => self::MARKER.'-PUBLISH',
                    'expected_version' => 2,
                    'reason' => '本地界面验收数据',
                ], $user, self::PERMISSIONS, true);

                return $this->result($order, $released->id, false);
            }, 5);

            $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
            return self::SUCCESS;
        } catch (Throwable $exception) {
            report($exception);
            $this->error($exception->getMessage());
            return self::FAILURE;
        }
    }

    private function createMasterData(): array
    {
        $unit = Unit::create([
            'unit_code' => self::MARKER.'-UNIT', 'unit_name' => '台', 'unit_type' => 'quantity',
            'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled',
        ]);
        $product = Product::create([
            'product_code' => self::MARKER.'-PRODUCT', 'product_name' => '智能装配工作站',
            'product_type' => 'standard', 'status' => 'enabled',
        ]);
        $sku = Sku::create([
            'product_id' => $product->id, 'sales_unit_id' => $unit->id,
            'sku_code' => self::MARKER.'-SKU', 'sku_name' => 'A200 / 220V / 标准版',
            'order_line_type' => 'physical', 'fulfillment_type' => 'physical', 'status' => 'enabled',
        ]);
        $output = Item::create([
            'item_code' => self::MARKER.'-FG', 'item_name' => '智能装配工作站',
            'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true,
            'is_production_item' => true, 'production_execution_mode' => 'unit',
            'serial_tracking_mode' => 'required', 'serial_number_prefix' => 'A200',
            'serial_generation_stage' => 'before_finished_goods_posting',
            'equipment_identity_requirement' => 'required', 'status' => 'enabled',
        ]);
        $component = Item::create([
            'item_code' => self::MARKER.'-RM', 'item_name' => '装配电控包',
            'item_type' => 'raw_material', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled',
        ]);

        $routingId = DB::table('erp_production_routings')->insertGetId([
            'routing_no' => self::MARKER.'-ROUTING', 'routing_name' => '智能装配三工序路线',
            'output_item_id' => $output->id, 'product_id' => $product->id, 'sku_id' => $sku->id,
            'version' => 1, 'status' => 'active', 'is_default' => true, 'default_scope_key' => $output->id,
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $routingOperations = [];
        foreach ([10 => ['装配', 35, 'flow_only'], 20 => ['调试', 25, 'flow_only'], 30 => ['终检与入库', 20, 'warehouse_required']] as $sequence => [$name, $minutes, $mode]) {
            $operationId = DB::table('erp_production_operations')->insertGetId([
                'operation_no' => self::MARKER.'-OP-'.$sequence, 'operation_name' => $name,
                'status' => 'enabled', 'sort' => $sequence, 'business_version' => 1,
                'created_at' => now(), 'updated_at' => now(),
            ]);
            $routingOperations[$sequence] = DB::table('erp_production_routing_operations')->insertGetId([
                'routing_id' => $routingId, 'operation_id' => $operationId, 'sequence' => $sequence,
                'standard_minutes' => $minutes, 'setup_standard_minutes' => 0,
                'unit_standard_minutes' => $minutes, 'is_key_operation' => $sequence === 30,
                'output_item_id' => $output->id, 'output_mode' => $mode, 'quality_mode' => 'none',
                'allow_continue_without_warehouse' => true, 'created_at' => now(), 'updated_at' => now(),
            ]);
        }

        $bom = Bom::create([
            'bom_no' => self::MARKER.'-BOM', 'bom_name' => '智能装配工作站验收 BOM',
            'product_id' => $product->id, 'sku_id' => $sku->id, 'output_item_id' => $output->id,
            'bom_type' => 'standard', 'version' => 'V1.0', 'is_default' => true,
            'status' => 'active', 'audit_status' => 'approved', 'effective_date' => now()->subDay()->toDateString(),
        ]);
        BomItem::create([
            'bom_id' => $bom->id, 'line_no' => 10, 'component_item_id' => $component->id,
            'component_item_code' => $component->item_code, 'component_item_name' => $component->item_name,
            'qty' => 1, 'unit_id' => $unit->id, 'loss_rate' => 0, 'fixed_qty' => 0, 'replaceable' => false,
        ]);
        DB::table('erp_routing_operation_material_supply_rules')->insert([
            'routing_operation_id' => $routingOperations[10], 'component_item_id' => $component->id,
            'target_routing_operation_id' => $routingOperations[10], 'required_qty_ratio' => 1,
            'supply_mode' => 'dedicated_delivery', 'requires_delivery' => true,
            'participates_in_kitting' => true, 'allow_partial_delivery' => false,
            'delivery_location_type' => 'operation_station', 'business_version' => 1,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return compact('unit', 'product', 'sku', 'output');
    }

    private function createAttachment(SalesOrder $order, object $user): void
    {
        $path = 'acceptance/'.self::MARKER.'/production-drawing.png';
        $contents = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mNk+A8AAQUBAScY42YAAAAASUVORK5CYII=', true);
        if ($contents === false || ! Storage::disk('local')->put($path, $contents)) {
            throw new \RuntimeException('本地验收附件写入失败。');
        }
        DB::table('erp_sales_order_attachments')->insert([
            'sales_order_id' => $order->id, 'attachment_scope' => 'order', 'attachment_type' => 'design_drawing',
            'version_no' => 1, 'original_name' => '生产验收图纸.png', 'stored_name' => 'production-drawing.png',
            'storage_disk' => 'local', 'storage_path' => $path, 'mime_type' => 'image/png',
            'file_size' => strlen($contents), 'file_hash' => hash('sha256', $contents),
            'uploaded_by_legacy_id' => $user->legacy_id, 'uploaded_by' => $user->nickname ?: $user->username,
            'uploaded_at' => now(), 'status' => 'active', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function existingResult(SalesOrder $order): array
    {
        $workOrderId = DB::table('erp_work_orders')->where('source_type', 'sales_order')
            ->where('source_id', $order->id)->orderBy('id')->value('id');
        if (! $workOrderId) {
            throw new \RuntimeException('已有验收销售订单但缺少工单，为避免覆盖局部数据，命令已停止。');
        }
        return $this->result($order, (int) $workOrderId, true);
    }

    private function result(SalesOrder $order, int $workOrderId, bool $reused): array
    {
        $workOrder = DB::table('erp_work_orders')->where('id', $workOrderId)->first();
        $unitIds = DB::table('erp_production_units')->where('work_order_id', $workOrderId)->orderBy('sequence_no')->pluck('id');
        return [
            'fixture' => self::MARKER,
            'reused' => $reused,
            'user_legacy_id' => (int) $order->sales_user_legacy_id,
            'sales_order_id' => (int) $order->id,
            'sales_order_no' => $order->sales_order_no,
            'production_master_order_id' => (int) $workOrder->production_master_order_id,
            'master_order_no' => DB::table('erp_production_master_orders')->where('id', $workOrder->production_master_order_id)->value('master_order_no'),
            'work_order_id' => (int) $workOrderId,
            'work_order_no' => $workOrder->work_order_no,
            'production_unit_ids' => $unitIds->map(fn ($id) => (int) $id)->all(),
            'production_unit_count' => $unitIds->count(),
            'production_task_count' => DB::table('erp_production_tasks')->where('work_order_id', $workOrderId)->count(),
            'detail_routes' => [
                'master' => '/pages/production/master-detail/index?id='.$workOrder->production_master_order_id,
                'work_order' => '/pages/production/work-order-detail/index?masterId='.$workOrder->production_master_order_id.'&workOrderId='.$workOrderId,
                'unit' => $unitIds->isEmpty() ? null : '/pages/production/unit-detail/index?unitId='.$unitIds->first(),
            ],
        ];
    }
}
