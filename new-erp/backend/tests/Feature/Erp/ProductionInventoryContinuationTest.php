<?php

namespace Tests\Feature\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{Bom, BomItem, InventoryBalance, InventoryLocationBalance, InventoryReservation, InventoryTransaction, Item, Location, ProductionOutputRecord, ProductionQuantityOperation, ProductionRouting, SalesOrder, SalesOrderLine, SalesShipment, SalesShipmentLine, Unit, Warehouse, WorkOrder};
use App\Services\Erp\{InventoryService, ProductionInternalIssueService, ProductionInventoryContinuationService, ProductionMasterDataService, ProductionOutputTraceService, ProductionShipmentSourceResolver, WorkOrderApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class ProductionInventoryContinuationTest extends TestCase
{
    use DatabaseTransactions;
    private const PERMISSIONS = ['production.work_order.view', 'production.work_order.edit', 'production.work_order.publish', 'production.output.issue', 'production.output.receive'];
    private array $c;

    protected function setUp(): void
    {
        parent::setUp();
        $suffix = Str::upper(Str::random(10));
        $actor = random_int(2400000, 2499999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $actor, 'username' => 'ic-'.$suffix, 'nickname' => '库存续接验证人',
            'status' => 'normal', 'auth_group_names' => '[]', 'department_names' => '[]', 'created_at' => now(), 'updated_at' => now()]);
        $user = (object) ['legacy_id' => $actor];
        $unit = Unit::create(['unit_code' => 'IC-U-'.$suffix, 'unit_name' => '件', 'unit_type' => 'quantity', 'decimal_places' => 4, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => 'IC-I-'.$suffix, 'item_name' => '待水检总装产品', 'item_type' => 'finished_good', 'unit_id' => $unit->id,
            'is_stock_item' => true, 'is_production_item' => true, 'production_execution_mode' => 'quantity', 'serial_tracking_mode' => 'none', 'status' => 'enabled']);
        $raw = Item::create(['item_code' => 'IC-R-'.$suffix, 'item_name' => '前序焊接原料', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        $seal = Item::create(['item_code' => 'IC-S-'.$suffix, 'item_name' => '水检密封用料', 'item_type' => 'raw_material', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        $warehouse = Warehouse::create(['warehouse_code' => 'IC-WH-'.$suffix, 'warehouse_name' => '正式总装库存仓', 'management_scope' => 'factory', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => 'IC-LOC-'.$suffix, 'location_name' => '总装待水检库位', 'status' => 'enabled']);
        $routing = ProductionRouting::create(['routing_no' => 'IC-RT-'.$suffix, 'routing_name' => '焊接总装水检包装', 'output_item_id' => $item->id,
            'version' => 1, 'status' => 'active', 'is_default' => true, 'default_scope_key' => $item->id, 'business_version' => 1]);
        $nodes = []; $operations = [];
        foreach ([10 => '焊接', 20 => '总装', 30 => '水检', 40 => '发货包装'] as $sequence => $name) {
            $operation = DB::table('erp_production_operations')->insertGetId(['operation_no' => 'IC-OP-'.$sequence.'-'.$suffix,
                'operation_name' => $name, 'status' => 'enabled', 'sort' => $sequence, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
            $operations[$sequence] = $operation;
            $nodes[$sequence] = DB::table('erp_production_routing_operations')->insertGetId(['routing_id' => $routing->id, 'operation_id' => $operation,
                'sequence' => $sequence, 'execution_context' => $sequence === 40 ? 'shipment' : 'production', 'output_item_id' => $item->id,
                'output_mode' => $sequence === 20 ? 'warehouse_optional' : 'flow_only', 'quality_mode' => 'none', 'work_mode' => 'manual',
                'allow_continue_without_warehouse' => true, 'performance_rate' => .1, 'is_key_operation' => false, 'created_at' => now(), 'updated_at' => now()]);
        }
        $bom = Bom::create(['bom_no' => 'IC-BOM-'.$suffix, 'bom_name' => '库存续接验证BOM', 'output_item_id' => $item->id,
            'bom_type' => 'standard', 'version' => 'V1', 'status' => 'active', 'audit_status' => 'approved', 'is_default' => true]);
        foreach ([[$raw, 2, 10], [$seal, 1, 30]] as $index => [$component, $quantity, $sequence]) {
            BomItem::create(['bom_id' => $bom->id, 'line_no' => ($index + 1) * 10, 'component_item_id' => $component->id,
                'component_item_code' => $component->item_code, 'component_item_name' => $component->item_name, 'qty' => $quantity,
                'unit_id' => $unit->id, 'loss_rate' => 0, 'fixed_qty' => 0, 'replaceable' => false]);
            DB::table('erp_routing_operation_material_supply_rules')->insert(['routing_operation_id' => $nodes[$sequence],
                'component_item_id' => $component->id, 'target_routing_operation_id' => $nodes[$sequence], 'required_qty_ratio' => 1,
                'supply_mode' => 'warehouse_picking', 'requires_delivery' => false, 'participates_in_kitting' => true,
                'allow_partial_delivery' => false, 'delivery_location_type' => 'operation_station', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        }
        $snapshot = app(ProductionMasterDataService::class)->snapshot($routing);
        $this->c = compact('suffix', 'actor', 'user', 'unit', 'item', 'raw', 'seal', 'warehouse', 'location', 'routing', 'nodes', 'operations', 'bom', 'snapshot');
        [$sourceWork, $sourceTarget, $sourceOutput] = $this->productionOutput(10, 20);
        $lot = DB::table('erp_material_lots')->insertGetId(['lot_no' => 'IC-LOT-'.$suffix, 'item_id' => $item->id, 'material_form' => 'PRODUCT',
            'source_type' => 'production_output_record', 'source_id' => $sourceOutput->id, 'created_at' => now(), 'updated_at' => now()]);
        $balance = InventoryBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => 'IC-BATCH-'.$suffix, 'unit_id' => $unit->id, 'material_lot_id' => $lot, 'quantity_on_hand' => 10, 'quantity_locked' => 0,
            'quantity_available' => 10, 'quantity_defective' => 0, 'quantity_pending' => 0, 'average_unit_cost' => 100, 'inventory_value' => 1000]);
        $locationBalance = InventoryLocationBalance::create(['item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'unit_id' => $unit->id, 'quantity_on_hand' => 10, 'quantity_locked' => 0, 'quantity_available' => 10,
            'quantity_defective' => 0, 'quantity_pending' => 0]);
        $holding = DB::table('erp_material_holdings')->insertGetId(['material_lot_id' => $lot, 'position_type' => 'WAREHOUSE', 'position_id' => $warehouse->id,
            'inventory_balance_id' => $balance->id, 'quantity' => null, 'total_cost' => null,
            'status' => 'ACTIVE', 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
        $sourceOutput->update(['material_lot_id' => $lot, 'material_holding_id' => $holding, 'material_total_cost' => 1000, 'material_loss_cost' => 0]);
        $posting = $this->posting($sourceOutput, $balance);
        $this->c += compact('sourceWork', 'sourceTarget', 'sourceOutput', 'lot', 'balance', 'locationBalance', 'holding', 'posting');
    }

    public function test_trace_old_batch_ten_consumed_two_covers_the_entire_new_two_piece_slice(): void
    {
        [, $newTarget, $child] = $this->productionOutput(2, 30);
        $this->link($this->c['sourceOutput'], $child, 2, 2);
        $rows = collect(app(ProductionOutputTraceService::class)->contributions($child->id, '2'))->keyBy('target_id');
        $old = $rows[$this->c['sourceTarget']->id];
        $this->assertSame('complete', $old['trace_status']); $this->assertSame(0, bccomp('1', $old['basis_fraction'], 8));
        $this->assertSame(0, bccomp('2', $old['allocated_base_qty'], 8)); $this->assertSame(0, bccomp('10', $old['output_base_qty'], 8));
        $this->assertSame(0, bccomp('1', $rows[$newTarget->id]['basis_fraction'], 8));
    }

    public function test_trace_equivalent_phase_sources_split_current_slice_by_actual_consumed_quantity_across_versions(): void
    {
        $c = $this->c;
        $nextRoute = ProductionRouting::create(['routing_no' => $this->code('RT2'), 'routing_name' => '同工序新版本', 'output_item_id' => $c['item']->id,
            'version' => 2, 'status' => 'retired', 'is_default' => false, 'business_version' => 1]);
        $nextNode = DB::table('erp_production_routing_operations')->insertGetId(['routing_id' => $nextRoute->id, 'operation_id' => $c['operations'][20],
            'sequence' => 20, 'execution_context' => 'production', 'output_item_id' => $c['item']->id, 'quality_mode' => 'none', 'work_mode' => 'manual',
            'performance_rate' => .2, 'is_key_operation' => false, 'created_at' => now(), 'updated_at' => now()]);
        [, $otherTarget, $otherOutput] = $this->productionOutput(10, 20, ['routing_operation_id_snapshot' => $nextNode, 'performance_rate_snapshot' => .2]);
        [, $childTarget, $child] = $this->productionOutput(5, 30);
        $this->link($c['sourceOutput'], $child, 2, 5); $this->link($otherOutput, $child, 3, 5);
        $rows = collect(app(ProductionOutputTraceService::class)->contributions($child->id, '5'))->keyBy('target_id');
        $this->assertSame(0, bccomp('.4', $rows[$c['sourceTarget']->id]['basis_fraction'], 8));
        $this->assertSame(0, bccomp('.6', $rows[$otherTarget->id]['basis_fraction'], 8));
        $this->assertSame(0, bccomp('1', $rows[$childTarget->id]['basis_fraction'], 8));
        $this->assertSame(0, bccomp('2', $rows[$c['sourceTarget']->id]['allocated_base_qty'], 8));
        $this->assertSame(0, bccomp('3', $rows[$otherTarget->id]['allocated_base_qty'], 8));
    }

    public function test_trace_unknown_parent_quantities_cycles_and_rejected_outputs_remain_pending(): void
    {
        [, , $child] = $this->productionOutput(2, 30);
        $link = $this->link($this->c['sourceOutput'], $child, null, null);
        $trace = app(ProductionOutputTraceService::class);
        $this->assertContains('pending', array_column($trace->contributions($child->id, '2'), 'trace_status'));
        DB::table('erp_production_output_lineage_links')->where('id', $link)->update(['parent_base_qty' => 2, 'child_base_qty' => 2]);
        $this->link($child, $this->c['sourceOutput'], 2, 10);
        $this->assertContains('pending', array_column($trace->contributions($child->id, '2'), 'trace_status'));
        foreach (['REJECTED', 'QUALITY_FAILED', 'REWORK', 'HANDOVER_REJECTED', 'CANCELLED'] as $status) {
            $child->update(['status' => $status]);
            $rows = $trace->contributions($child->id, '2');
            $this->assertSame(['pending'], array_column($rows, 'trace_status')); $this->assertArrayNotHasKey('basis_fraction', $rows[0]);
        }
    }

    public function test_shipment_sources_require_actual_dispatch_and_formal_outbound_and_keep_reservation_lot_source(): void
    {
        [$order, $shipment, $line] = $this->shipment(2, 2);
        $resolver = app(ProductionShipmentSourceResolver::class);
        $this->assertSame([], $resolver->forOrder($order));
        $tx = $this->shipmentTransaction($shipment, 'sales_shipment_outbound');
        $shipment->update(['shipment_status' => 'outbound_posted', 'shipped_at' => null]);
        $this->assertSame([], $resolver->forOrder($order));
        $shipment->update(['shipment_status' => 'shipped', 'shipped_at' => now()]);
        $rows = $resolver->forOrder($order); $this->assertCount(1, $rows);
        $this->assertSame($this->c['sourceOutput']->id, $rows[0]['output_record_id']); $this->assertSame('complete', $rows[0]['trace_status']);
        $this->assertSame(0, bccomp('2', $rows[0]['base_qty'], 8));
        $tx->update(['transaction_type' => 'inventory_adjustment']);
        $this->assertSame([], $resolver->forOrder($order), '其他已过账交易不能充当销售发货正式出库事实');
        $tx->update(['transaction_type' => 'sales_shipment_outbound', 'posting_status' => 'reversed']);
        $this->assertSame([], $resolver->forOrder($order));
    }

    public function test_shipment_resolver_falls_back_to_unique_output_posting_and_preserves_serial_conversion(): void
    {
        [$order, $shipment, $line] = $this->shipment(1, 2); $this->shipmentTransaction($shipment, 'sales_shipment_outbound');
        $this->c['balance']->update(['material_lot_id' => null]);
        $resolver = app(ProductionShipmentSourceResolver::class);
        $this->assertSame($this->c['sourceOutput']->id, $resolver->forOrder($order)[0]['output_record_id']);
        $ids = [];
        for ($i = 0; $i < 2; $i++) $ids[] = $this->serial();
        $line->update(['serial_snapshot' => ['inventory_serial_ids' => $ids]]);
        $rows = $resolver->forOrder($order); $this->assertCount(2, $rows);
        $this->assertSame($ids, array_column($rows, 'inventory_serial_id'));
        foreach ($rows as $row) { $this->assertSame(0, bccomp('1', $row['base_qty'], 8)); $this->assertSame(0, bccomp('.5', $row['sales_qty'], 8)); }
        DB::table('erp_inventory_serials')->where('id', $ids[0])->update(['source_document_type' => 'purchase_receipt']);
        $this->assertSame('pending', $resolver->forOrder($order)[0]['trace_status']);
        $line->update(['serial_snapshot' => []]);
        [, , $other] = $this->productionOutput(10, 20); $this->posting($other, $this->c['balance']);
        $this->assertNull($resolver->outputIdForBalance($this->c['balance']->fresh()), '同一库存余额有多个产出来源时不能猜测来源');
    }

    public function test_shipment_sources_require_the_exact_formal_outbound_line_item_and_quantity(): void
    {
        [$order, $shipment, $line] = $this->shipment(2, 2); $tx = $this->shipmentTransaction($shipment, 'sales_shipment_outbound');
        $resolver = app(ProductionShipmentSourceResolver::class); $this->assertCount(1, $resolver->forOrder($order));
        $posted = DB::table('erp_inventory_transaction_items')->where('transaction_id', $tx->id)->first();
        foreach (['source_item_id' => $line->id + 1000000, 'item_id' => $this->c['seal']->id, 'change_qty' => -1,
            'batch_no' => '其他正式批次'] as $field => $invalid) {
            DB::table('erp_inventory_transaction_items')->where('id', $posted->id)->update([$field => $invalid]);
            $this->assertSame([], $resolver->forOrder($order), '仅有已过账交易头，不能替代当前发货行的实际出库事实：'.$field);
            DB::table('erp_inventory_transaction_items')->where('id', $posted->id)->update([$field => $posted->{$field}]);
        }
        $this->assertCount(1, $resolver->forOrder($order));
    }

    public function test_frozen_shipment_source_and_conversion_survive_the_same_serial_being_warehoused_again(): void
    {
        [$order, $shipment, $line] = $this->shipment(1, 2); $this->shipmentTransaction($shipment, 'sales_shipment_outbound');
        $serials = [$this->serial(), $this->serial()]; $line->update(['serial_snapshot' => ['inventory_serial_ids' => $serials]]);
        app(\App\Services\Erp\ShipmentPackingApplicationService::class)->freezeRequirements($shipment);
        $before = app(ProductionShipmentSourceResolver::class)->forOrder($order);
        [, , $newOutput] = $this->productionOutput(2, 30, ['performance_rate_snapshot' => .7]);
        DB::table('erp_inventory_serials')->whereIn('id', $serials)->update(['source_document_id' => $newOutput->id]);
        DB::table('erp_material_lots')->where('id', $this->c['lot'])->update(['source_id' => $newOutput->id]);
        $after = app(ProductionShipmentSourceResolver::class)->forOrder($order);
        $this->assertSame($before, $after, '同一 SN 后续再次生产入库，不能修改已经发运订单的来源');
        $this->assertSame([$this->c['sourceOutput']->id, $this->c['sourceOutput']->id], array_column($after, 'output_record_id'));
        foreach ($after as $source) {
            $this->assertSame(0, bccomp('1', $source['base_qty'], 8)); $this->assertSame(0, bccomp('.5', $source['sales_qty'], 8));
            $contribution = app(ProductionOutputTraceService::class)->contributions($source['output_record_id'], $source['base_qty']);
            $this->assertSame([$this->c['sourceTarget']->id], array_column($contribution, 'target_id'));
        }
        $line->fresh()->update(['packing_source_snapshot' => [['output_record_id' => $this->c['sourceOutput']->id, 'base_qty' => '1', 'sales_qty' => '.5']]]);
        $bad = app(ProductionShipmentSourceResolver::class)->contextForShipmentLine($line->fresh());
        $this->assertFalse($bad['production_ready'], '冻结数量与发货行数量不符时不能回退到实时 SN 来源');
    }

    public function test_ambiguous_known_production_stock_and_handover_rejection_cannot_be_treated_as_legacy_stock(): void
    {
        [, , $line] = $this->shipment(2, 2); $resolver = app(ProductionShipmentSourceResolver::class);
        $this->c['balance']->update(['material_lot_id' => null]);
        [, , $other] = $this->productionOutput(10, 20); $this->posting($other, $this->c['balance']);
        $context = $resolver->contextForShipmentLine($line);
        $this->assertFalse($context['production_ready']); $this->assertNull($context['source_rows'][0]['output_record_id']);
        $this->assertSame('production', $context['source_rows'][0]['source_origin']);
        $this->c['balance']->update(['material_lot_id' => $this->c['lot']]);
        $this->c['sourceTarget']->update(['routing_operation_id_snapshot' => $this->c['nodes'][30], 'sequence_no_snapshot' => 30]);
        $this->c['sourceOutput']->update(['status' => 'HANDOVER_REJECTED']);
        $rejected = $resolver->contextForShipmentLine($line);
        $this->assertFalse($rejected['production_ready']); $this->assertContains('来源生产工序质检尚未合格。', $rejected['blockers']);
        $this->c['sourceOutput']->update(['status' => 'COMPLETED']);
        $this->assertTrue($resolver->contextForShipmentLine($line)['production_ready']);
    }

    public function test_invalid_ancestor_outputs_do_not_supply_amount_basis_for_a_valid_later_output(): void
    {
        $source = $this->c['sourceOutput']; [, $target, $child] = $this->productionOutput(2, 30); $this->link($source, $child, 2, 2);
        foreach (['QUALITY_FAILED', 'HANDOVER_REJECTED', 'REWORK', 'REJECTED', 'CANCELLED'] as $status) {
            $source->update(['status' => $status]);
            $rows = collect(app(ProductionOutputTraceService::class)->contributions($child->id, '2'))->keyBy('output_record_id');
            $this->assertSame('pending', $rows[$source->id]['trace_status']); $this->assertArrayNotHasKey('basis_fraction', $rows[$source->id]);
            $this->assertSame('complete', $rows[$child->id]['trace_status']); $this->assertSame($target->id, $rows[$child->id]['target_id']);
        }
        $source->update(['status' => 'COMPLETED']);
        $rows = app(ProductionOutputTraceService::class)->contributions($source->id, '2');
        $this->assertSame('complete', $rows[0]['trace_status']); $this->assertSame(0, bccomp('10', $rows[0]['total_target_output_qty'], 8));
    }

    public function test_two_serial_sources_in_the_same_batch_can_be_selected_separately_without_guessing_a_balance_source(): void
    {
        $c = $this->c; $c['item']->update(['production_execution_mode' => 'unit', 'serial_tracking_mode' => 'required']);
        $c['sourceOutput']->update(['output_base_qty' => 1]); $c['sourceTarget']->update(['planned_base_qty' => 1, 'completed_base_qty' => 1]);
        DB::table('erp_production_output_warehouse_postings')->where('id', $c['posting'])->update(['posted_base_qty' => 1]);
        $c['balance']->update(['material_lot_id' => null, 'quantity_on_hand' => 2, 'quantity_available' => 2, 'inventory_value' => 200]);
        $c['locationBalance']->update(['quantity_on_hand' => 2, 'quantity_available' => 2]);
        [, , $other] = $this->productionOutput(1, 20); $this->posting($other, $c['balance']);
        $firstSerial = $this->serial(); $secondSerial = $this->serial();
        DB::table('erp_inventory_serials')->where('id', $secondSerial)->update(['source_document_id' => $other->id]);
        $wo = $this->newWorkOrder(2); $service = app(ProductionInventoryContinuationService::class);
        $this->assertNull(app(ProductionShipmentSourceResolver::class)->outputIdForBalance($c['balance']->fresh()));
        $candidates = $service->candidates($wo->id, ['keyword' => $c['balance']->batch_no], $c['user'], self::PERMISSIONS, true);
        $this->assertSame([$c['sourceOutput']->id, $other->id], array_column($candidates['data'], 'source_output_record_id'));
        foreach ([[$c['sourceOutput']->id, $firstSerial], [$other->id, $secondSerial]] as [$outputId, $serialId]) {
            $serialPage = $service->serials($wo->id, ['inventory_balance_id' => $c['balance']->id, 'source_output_record_id' => $outputId], $c['user'], self::PERMISSIONS, true);
            $this->assertSame([$serialId], array_map(fn ($row) => (int) $row->id, $serialPage['data']));
        }
        $configured = $service->configure($wo->id, ['client_command_id' => $this->code('MIX-SN'), 'expected_version' => 1, 'sources' => [
            ['inventory_balance_id' => $c['balance']->id, 'source_output_record_id' => $c['sourceOutput']->id, 'inventory_serial_id' => $firstSerial, 'base_qty' => 1],
            ['inventory_balance_id' => $c['balance']->id, 'source_output_record_id' => $other->id, 'inventory_serial_id' => $secondSerial, 'base_qty' => 1],
        ]], $c['user'], self::PERMISSIONS, true);
        $this->assertSame([$c['sourceOutput']->id, $other->id], array_column($configured['inventory_continuation_plan'], 'source_output_record_id'));
        $this->publish($wo);
        $continuations = DB::table('erp_work_order_inventory_continuations')->where('work_order_id', $wo->id)->orderBy('production_unit_id')->get();
        $this->assertSame([$firstSerial, $secondSerial], $continuations->pluck('inventory_serial_id')->all());
        $this->assertSame([$c['sourceOutput']->id, $other->id], $continuations->pluck('source_output_record_id')->all());
        $this->assertSame(2.0, (float) $c['balance']->fresh()->quantity_locked);
    }

    public function test_continuation_rejects_missing_or_reversed_formal_production_posting_without_touching_stock(): void
    {
        $c = $this->c; $wo = $this->newWorkOrder(2);
        $post = DB::table('erp_production_output_warehouse_postings')->where('id', $c['posting'])->first();
        DB::table('erp_inventory_transactions')->where('id', $post->inventory_transaction_id)->update(['posting_status' => 'reversed']);
        $this->domain('continuation_source_not_posted', fn () => $this->configure($wo, 2));
        $this->assertSame([], app(ProductionInventoryContinuationService::class)->candidates($wo->id, ['keyword' => $c['balance']->batch_no], $c['user'], self::PERMISSIONS, true)['data']);
        DB::table('erp_inventory_transactions')->where('id', $post->inventory_transaction_id)->update(['posting_status' => 'posted', 'transaction_type' => 'inventory_adjustment']);
        $this->domain('continuation_source_not_posted', fn () => $this->configure($wo, 2));
        DB::table('erp_production_output_warehouse_postings')->where('id', $post->id)->delete();
        $this->domain('continuation_source_not_posted', fn () => $this->configure($wo, 2));
        $this->assertSame(1, (int) $wo->fresh()->business_version); $this->assertSame([], $wo->fresh()->inventory_continuation_plan ?? []);
        $this->assertSame(10.0, (float) $c['balance']->fresh()->quantity_on_hand); $this->assertSame(0.0, (float) $c['balance']->fresh()->quantity_locked);
        $this->assertSame(0.0, (float) $c['locationBalance']->fresh()->quantity_locked);
    }

    public function test_full_stock_quantity_publish_starts_at_water_test_without_repeating_previous_raw_demand(): void
    {
        $wo = $this->newWorkOrder(2); $configured = $this->configure($wo, 2);
        $this->assertSame(30, $configured['inventory_continuation_plan'][0]['start_sequence']);
        $this->assertSame($this->c['nodes'][30], $configured['inventory_continuation_plan'][0]['start_routing_operation_id']);
        $this->assertSame(0.0, (float) $this->c['balance']->fresh()->quantity_locked, '选择来源时不提前占库');
        $released = $this->publish($wo);
        $this->assertSame('RELEASED', $released->status);
        $targets = DB::table('erp_production_quantity_operations')->where('work_order_id', $wo->id)->get();
        $this->assertSame([30], $targets->pluck('sequence_no_snapshot')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(2.0, (float) $targets[0]->planned_base_qty);
        $requirements = DB::table('erp_work_order_material_requirements')->where('work_order_id', $wo->id)->get()->keyBy('component_item_id');
        $this->assertFalse($requirements->has($this->c['raw']->id));
        $this->assertSame(2.0, (float) $requirements[$this->c['seal']->id]->base_required_qty);
        $this->assertSame('stock_continuation', $requirements[$this->c['item']->id]->requirement_kind);
        $this->assertSame(2.0, (float) $requirements[$this->c['item']->id]->base_required_qty);
        $this->assertSame(2.0, (float) $this->c['balance']->fresh()->quantity_locked);
        $this->assertSame(8.0, (float) $this->c['balance']->fresh()->quantity_available);
        $this->assertSame(2.0, (float) $this->c['locationBalance']->fresh()->quantity_locked);
        $continuation = DB::table('erp_work_order_inventory_continuations')->where('work_order_id', $wo->id)->first();
        $this->assertSame('RESERVED', $continuation->status); $this->assertSame(2.0, (float) $continuation->base_qty);
        $this->assertDatabaseHas('erp_production_internal_issue_tasks', ['id' => $continuation->internal_issue_task_id,
            'work_order_id' => $wo->id, 'target_id' => $targets[0]->id, 'target_type' => 'quantity_operation', 'source_type' => 'inventory_continuation', 'status' => 'WAIT_ISSUE']);
        $this->assertDatabaseHas('erp_production_internal_issue_lines', ['issue_task_id' => $continuation->internal_issue_task_id,
            'inventory_continuation_id' => $continuation->id, 'output_record_id' => $this->c['sourceOutput']->id, 'issue_base_qty' => 2]);
    }

    public function test_mixed_quantity_work_order_keeps_fresh_previous_operations_and_only_remaining_raw_quantities(): void
    {
        $wo = $this->newWorkOrder(3); $this->configure($wo, 2); $this->publish($wo);
        $targets = DB::table('erp_production_quantity_operations')->where('work_order_id', $wo->id)->orderBy('sequence_no_snapshot')->get();
        $this->assertSame([10, 20, 30], $targets->pluck('sequence_no_snapshot')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([1.0, 1.0, 3.0], $targets->pluck('planned_base_qty')->map(fn ($v) => (float) $v)->all());
        $requirements = DB::table('erp_work_order_material_requirements')->where('work_order_id', $wo->id)->get()->keyBy('component_item_id');
        $this->assertSame(2.0, (float) $requirements[$this->c['raw']->id]->base_required_qty);
        $this->assertSame(3.0, (float) $requirements[$this->c['seal']->id]->base_required_qty);
        $this->assertSame(2.0, (float) $requirements[$this->c['item']->id]->base_required_qty);
        $stockRequirement = DB::table('erp_production_target_material_requirements')->where('work_order_id', $wo->id)->where('requirement_kind', 'stock_continuation')->first();
        $this->assertSame($targets[2]->id, $stockRequirement->target_id); $this->assertSame(2.0, (float) $stockRequirement->required_base_qty);
    }

    public function test_mixed_unit_execution_creates_only_remaining_nodes_for_stock_units_and_one_stock_input_per_piece(): void
    {
        $this->c['item']->update(['production_execution_mode' => 'unit']);
        $wo = $this->newWorkOrder(3); $this->configure($wo, 2); $this->publish($wo);
        $units = DB::table('erp_production_units')->where('work_order_id', $wo->id)->orderBy('sequence_no')->get();
        $this->assertCount(3, $units);
        $targets = DB::table('erp_production_unit_operations')->where('work_order_id', $wo->id)->orderBy('production_unit_id')->orderBy('sequence_no_snapshot')->get();
        $this->assertSame([30, 30, 10, 20, 30], $targets->pluck('sequence_no_snapshot')->map(fn ($v) => (int) $v)->all());
        $this->assertSame(5, DB::table('erp_production_tasks')->where('work_order_id', $wo->id)->count());
        $this->assertSame([$this->c['nodes'][30], $this->c['nodes'][30], $this->c['nodes'][10]], $units->pluck('current_routing_operation_id')->map(fn ($v) => (int) $v)->all());
        $continuations = DB::table('erp_work_order_inventory_continuations')->where('work_order_id', $wo->id)->orderBy('production_unit_id')->get();
        $this->assertCount(2, $continuations); $this->assertSame([1.0, 1.0], $continuations->pluck('base_qty')->map(fn ($v) => (float) $v)->all());
        $this->assertSame([$units[0]->id, $units[1]->id], $continuations->pluck('production_unit_id')->all());
        $stockRequirements = DB::table('erp_production_target_material_requirements')->where('work_order_id', $wo->id)->where('requirement_kind', 'stock_continuation')->get();
        $this->assertCount(2, $stockRequirements); $this->assertSame([1.0, 1.0], $stockRequirements->pluck('required_base_qty')->map(fn ($v) => (float) $v)->all());
        $this->assertSame(2.0, (float) $this->c['balance']->fresh()->quantity_locked); $this->assertSame(2.0, (float) $this->c['locationBalance']->fresh()->quantity_locked);
    }

    public function test_fixed_material_quantities_go_to_the_first_unit_that_actually_executes_each_operation(): void
    {
        $this->c['item']->update(['production_execution_mode' => 'unit']);
        BomItem::where('bom_id', $this->c['bom']->id)->where('component_item_id', $this->c['raw']->id)->update(['fixed_qty' => 10]);
        BomItem::where('bom_id', $this->c['bom']->id)->where('component_item_id', $this->c['seal']->id)->update(['fixed_qty' => 5]);
        $wo = $this->newWorkOrder(3); $this->configure($wo, 2); $this->publish($wo);
        $units = DB::table('erp_production_units')->where('work_order_id', $wo->id)->orderBy('sequence_no')->get();
        $targets = DB::table('erp_production_unit_operations')->where('work_order_id', $wo->id)->get();
        $requirements = DB::table('erp_production_target_material_requirements')->where('work_order_id', $wo->id)->get();
        $weld = $targets->first(fn ($target) => (int) $target->production_unit_id === (int) $units[2]->id && (int) $target->sequence_no_snapshot === 10);
        $raw = $requirements->where('component_item_id', $this->c['raw']->id);
        $this->assertCount(1, $raw); $this->assertSame($weld->id, $raw->first()->target_id);
        $this->assertSame(12.0, (float) $raw->first()->required_base_qty, '两件库存跳过焊接后，固定前序用量必须由第三件新制件承担');
        foreach ($units as $index => $unit) {
            $water = $targets->first(fn ($target) => (int) $target->production_unit_id === (int) $unit->id && (int) $target->sequence_no_snapshot === 30);
            $seal = $requirements->first(fn ($row) => (int) $row->target_id === (int) $water->id && (int) $row->component_item_id === (int) $this->c['seal']->id);
            $this->assertSame($index === 0 ? 6.0 : 1.0, (float) $seal->required_base_qty);
        }
        $workRequirements = DB::table('erp_work_order_material_requirements')->where('work_order_id', $wo->id)->get()->keyBy('component_item_id');
        $this->assertSame(12.0, (float) $workRequirements[$this->c['raw']->id]->base_required_qty);
        $this->assertSame(8.0, (float) $workRequirements[$this->c['seal']->id]->base_required_qty);
    }

    public function test_invalid_quantity_routing_quality_and_serial_inputs_do_not_change_the_draft_or_stock(): void
    {
        $wo = $this->newWorkOrder(2); $service = app(ProductionInventoryContinuationService::class);
        $this->domain('continuation_quantity_exceeded', fn () => $service->configure($wo->id, $this->configurationPayload($wo, 3), $this->c['user'], self::PERMISSIONS, true));
        $routing = $this->c['snapshot']; $routing['operations'][0]['quality_mode'] = 'required'; $wo->update(['routing_snapshot' => $routing]);
        $this->domain('continuation_routing_mismatch', fn () => $this->configure($wo, 2));
        $wo->update(['routing_snapshot' => $this->c['snapshot']]); $this->c['sourceOutput']->update(['quality_mode_snapshot' => 'required']);
        $this->domain('continuation_quality_pending', fn () => $this->configure($wo, 2));
        $this->c['sourceOutput']->update(['quality_mode_snapshot' => 'none']); $this->c['item']->update(['serial_tracking_mode' => 'required']);
        $this->domain('continuation_serial_required', fn () => $this->configure($wo, 2));
        $serial = $this->serial(); $payload = $this->configurationPayload($wo, 1); $payload['sources'][0]['inventory_serial_id'] = $serial;
        $payload['sources'][] = $payload['sources'][0];
        $this->domain('continuation_serial_duplicate', fn () => $service->configure($wo->id, $payload, $this->c['user'], self::PERMISSIONS, true));
        DB::table('erp_inventory_serials')->where('id', $serial)->update(['source_document_type' => 'purchase_receipt']);
        array_pop($payload['sources']); $payload['client_command_id'] = $this->code('WRONG-SERIAL');
        $this->domain('continuation_serial_invalid', fn () => $service->configure($wo->id, $payload, $this->c['user'], self::PERMISSIONS, true));
        $this->assertSame(1, (int) $wo->fresh()->business_version); $this->assertSame([], $wo->fresh()->inventory_continuation_plan ?? []);
        $this->assertSame(0.0, (float) $this->c['balance']->fresh()->quantity_locked); $this->assertSame(0.0, (float) $this->c['locationBalance']->fresh()->quantity_locked);
    }

    public function test_publish_rechecks_current_inventory_and_rolls_back_expansion_if_selected_stock_is_gone(): void
    {
        $wo = $this->newWorkOrder(2); $this->configure($wo, 2);
        $this->c['balance']->update(['quantity_available' => 0, 'quantity_locked' => 10]);
        $this->c['locationBalance']->update(['quantity_available' => 0, 'quantity_locked' => 10]);
        $this->domain('continuation_inventory_insufficient', fn () => $this->publish($wo));
        $this->assertSame('WAIT_RELEASE', $wo->fresh()->status); $this->assertSame(2, (int) $wo->fresh()->business_version);
        $this->assertSame(0, DB::table('erp_production_quantity_operations')->where('work_order_id', $wo->id)->count());
        $this->assertSame(0, DB::table('erp_production_tasks')->where('work_order_id', $wo->id)->count());
        $this->assertSame(0, DB::table('erp_work_order_material_requirements')->where('work_order_id', $wo->id)->count());
        $this->assertSame(0, DB::table('erp_work_order_inventory_continuations')->where('work_order_id', $wo->id)->count());
        $this->assertSame(10.0, (float) $this->c['balance']->fresh()->quantity_locked); $this->assertSame(10.0, (float) $this->c['locationBalance']->fresh()->quantity_locked);
    }

    public function test_receiving_continuation_posts_real_outbound_and_releases_exact_locks_and_satisfies_bound_requirement(): void
    {
        [$wo, $issue] = $this->publishedIssue(); $service = app(ProductionInternalIssueService::class);
        $dispatched = $service->dispatch($issue->id, ['client_command_id' => $this->code('DISPATCH'), 'expected_version' => 1], $this->c['user'], self::PERMISSIONS, true);
        $payload = ['client_command_id' => $this->code('RECEIVE'), 'expected_version' => $dispatched['business_version']];
        $received = $service->receive($issue->id, $payload, $this->c['user'], self::PERMISSIONS, true);
        $this->assertEquals($received, $service->receive($issue->id, $payload, $this->c['user'], self::PERMISSIONS, true));
        $this->assertSame('RECEIVED', $received['status']);
        $this->assertSame(8.0, (float) $this->c['balance']->fresh()->quantity_on_hand); $this->assertSame(0.0, (float) $this->c['balance']->fresh()->quantity_locked);
        $this->assertSame(8.0, (float) $this->c['locationBalance']->fresh()->quantity_on_hand); $this->assertSame(0.0, (float) $this->c['locationBalance']->fresh()->quantity_locked);
        $this->assertDatabaseHas('erp_work_order_inventory_continuations', ['work_order_id' => $wo->id, 'internal_issue_task_id' => $issue->id, 'status' => 'RECEIVED', 'received_base_qty' => 2]);
        $this->assertDatabaseHas('erp_production_target_material_requirements', ['work_order_id' => $wo->id, 'target_id' => $issue->target_id,
            'requirement_kind' => 'stock_continuation', 'satisfied_base_qty' => 2, 'status' => 'SATISFIED']);
        $this->assertDatabaseHas('erp_inventory_transactions', ['id' => $received['inventory_transaction_id'], 'transaction_type' => 'production_internal_issue_outbound',
            'source_type' => 'production_internal_issue', 'source_id' => $issue->id, 'posting_status' => 'posted']);
        $this->assertDatabaseHas('erp_production_input_holdings', ['target_type' => 'quantity_operation', 'target_id' => $issue->target_id,
            'source_output_record_id' => $this->c['sourceOutput']->id, 'quantity' => 2, 'total_cost' => 200]);
    }

    public function test_serial_stock_continuation_keeps_the_existing_identity_through_execution_and_second_formal_warehouse_posting(): void
    {
        $c = $this->c;
        $c['item']->update(['production_execution_mode' => 'unit', 'serial_tracking_mode' => 'required']);
        $c['sourceWork']->update(['target_qty' => 1, 'target_base_qty' => 1]);
        $c['sourceTarget']->update(['planned_base_qty' => 1, 'completed_base_qty' => 1]);
        $c['sourceOutput']->update(['output_base_qty' => 1, 'material_total_cost' => 100]);
        $c['balance']->update(['quantity_on_hand' => 1, 'quantity_available' => 1, 'inventory_value' => 100]);
        $c['locationBalance']->update(['quantity_on_hand' => 1, 'quantity_available' => 1]);
        DB::table('erp_production_output_warehouse_postings')->where('id', $c['posting'])->update(['posted_base_qty' => 1]);
        BomItem::where('bom_id', $c['bom']->id)->where('component_item_id', $c['seal']->id)->delete();
        DB::table('erp_production_routing_operations')->where('id', $c['nodes'][30])->update(['output_mode' => 'warehouse_required']);
        $this->c['snapshot'] = app(ProductionMasterDataService::class)->snapshot($c['routing']->fresh());
        $serialId = $this->serial(); $serialNo = DB::table('erp_inventory_serials')->where('id', $serialId)->value('serial_no');
        $c['sourceOutput']->update(['inventory_serial_id' => $serialId, 'serial_no_snapshot' => $serialNo]);
        $wo = $this->newWorkOrder(1); $payload = $this->configurationPayload($wo, 1); $payload['sources'][0]['inventory_serial_id'] = $serialId;
        app(ProductionInventoryContinuationService::class)->configure($wo->id, $payload, $c['user'], self::PERMISSIONS, true);
        $this->publish($wo);
        $unit = DB::table('erp_production_units')->where('work_order_id', $wo->id)->first();
        $productionSerial = DB::table('erp_production_serials')->where('id', $unit->device_serial_id)->first();
        $this->assertSame($serialNo, $unit->device_no_snapshot); $this->assertSame($serialNo, $productionSerial->serial_no);
        $this->assertSame($serialId, $productionSerial->inventory_serial_id);
        $issue = DB::table('erp_production_internal_issue_tasks')->where('work_order_id', $wo->id)->first();
        DB::table('erp_production_tasks')->where('id', $issue->target_task_id)->update(['assignee_user_legacy_id' => $c['actor'], 'status' => 'CLAIMED']);
        DB::table('erp_production_unit_operations')->where('id', $issue->target_id)->update(['responsible_user_legacy_id' => $c['actor'], 'status' => 'CLAIMED']);
        $issueService = app(ProductionInternalIssueService::class);
        $issueService->dispatch($issue->id, ['client_command_id' => $this->code('SN-DISPATCH'), 'expected_version' => 1], $c['user'], self::PERMISSIONS, true);
        $issueService->receive($issue->id, ['client_command_id' => $this->code('SN-RECEIVE'), 'expected_version' => 2], $c['user'], self::PERMISSIONS, true);
        $this->assertDatabaseHas('erp_inventory_serials', ['id' => $serialId, 'serial_status' => 'production_consumed']);
        $target = \App\Models\Erp\ProductionUnitOperation::findOrFail($issue->target_id);
        $actions = app(\App\Services\Erp\ProductionExecutionActionService::class);
        if ($target->kitting_required) app(\App\Services\Erp\ProductionKittingService::class)->confirm($issue->target_task_id, 'unit_operation', $target->id,
            ['client_command_id' => $this->code('SN-KITTING'), 'expected_version' => $target->business_version], $c['user'], ['production.kitting.confirm']);
        else $actions->start($issue->target_task_id, 'unit_operation', $target->id,
            ['client_command_id' => $this->code('SN-START'), 'expected_version' => $target->business_version], $c['user'], ['production.task.start']);
        $this->travel(2)->minutes();
        $done = $actions->complete($issue->target_task_id, 'unit_operation', $target->id,
            ['client_command_id' => $this->code('SN-COMPLETE'), 'expected_version' => $target->fresh()->business_version, 'disposition' => 'warehouse'],
            $c['user'], ['production.task.complete']);
        $this->travelBack();
        $newOutput = ProductionOutputRecord::findOrFail($done['output_record_id']);
        $this->assertSame($productionSerial->id, $newOutput->serial_id); $this->assertSame($serialNo, $newOutput->serial_no_snapshot);
        $this->assertDatabaseHas('erp_production_output_lineage_links', ['parent_output_record_id' => $c['sourceOutput']->id,
            'child_output_record_id' => $newOutput->id, 'parent_base_qty' => 1, 'child_base_qty' => 1]);
        $completionService = app(\App\Services\Erp\WorkOrderCompletionService::class);
        $submitted = $completionService->submit($wo->id, ['client_command_id' => $this->code('SN-SUBMIT'), 'expected_version' => $wo->fresh()->business_version,
            'output_record_ids' => [$newOutput->id]], $c['user'], ['production.completion.create'], true);
        $completionService->review($submitted['completion_id'], ['client_command_id' => $this->code('SN-APPROVE'), 'expected_version' => 1, 'decision' => 'approve'],
            $c['user'], ['production.completion.review'], true);
        $batch = $this->code('SN-REPOST');
        $postingPayload = ['client_command_id' => $this->code('SN-WAREHOUSE'), 'expected_version' => $newOutput->fresh()->business_version,
            'warehouse_id' => $c['warehouse']->id, 'location_id' => $c['location']->id, 'batch_no' => $batch, 'posted_base_qty' => 1];
        $postingService = app(\App\Services\Erp\ProductionOutputService::class);
        $posted = $postingService->warehouse($newOutput->id, $postingPayload, $c['user'], ['production.output.warehouse']);
        $this->assertEquals($posted, $postingService->warehouse($newOutput->id, $postingPayload, $c['user'], ['production.output.warehouse']));
        $newBalance = InventoryBalance::where('item_id', $c['item']->id)->where('batch_no', $batch)->firstOrFail();
        $this->assertSame($serialId, $posted['inventory_serial_id']);
        $this->assertSame(1, DB::table('erp_inventory_serials')->where('serial_no', $serialNo)->count());
        $this->assertDatabaseHas('erp_inventory_serials', ['id' => $serialId, 'inventory_balance_id' => $newBalance->id,
            'source_document_type' => 'production_output', 'source_document_id' => $newOutput->id, 'serial_status' => 'available']);
        $this->assertDatabaseHas('erp_production_output_records', ['id' => $newOutput->id, 'inventory_serial_id' => $serialId, 'serial_no_snapshot' => $serialNo]);
        $this->assertSame(0.0, (float) $c['balance']->fresh()->quantity_on_hand); $this->assertSame(1.0, (float) $newBalance->quantity_on_hand);
        $this->assertSame('COMPLETED', $wo->fresh()->status);
        $this->assertDatabaseHas('erp_inventory_transactions', ['id' => $posted['inventory_transaction_id'], 'transaction_type' => 'finished_goods_receipt', 'posting_status' => 'posted']);
    }

    public function test_failed_internal_outbound_restores_reservation_and_both_inventory_locks(): void
    {
        [$wo, $issue] = $this->publishedIssue();
        app(ProductionInternalIssueService::class)->dispatch($issue->id, ['client_command_id' => $this->code('FAIL-DISPATCH'), 'expected_version' => 1], $this->c['user'], self::PERMISSIONS, true);
        $inventory = Mockery::mock(InventoryService::class); $inventory->shouldReceive('postProductionInternalIssue')->once()->andThrow(new RuntimeException('模拟正式出库失败'));
        app()->instance(InventoryService::class, $inventory); app()->forgetInstance(ProductionInternalIssueService::class);
        try {
            app(ProductionInternalIssueService::class)->receive($issue->id, ['client_command_id' => $this->code('FAIL-RECEIVE'), 'expected_version' => 2], $this->c['user'], self::PERMISSIONS, true);
            $this->fail('模拟出库失败必须回滚');
        } catch (RuntimeException $e) { $this->assertSame('模拟正式出库失败', $e->getMessage()); }
        $this->assertSame(10.0, (float) $this->c['balance']->fresh()->quantity_on_hand); $this->assertSame(2.0, (float) $this->c['balance']->fresh()->quantity_locked);
        $this->assertSame(10.0, (float) $this->c['locationBalance']->fresh()->quantity_on_hand); $this->assertSame(2.0, (float) $this->c['locationBalance']->fresh()->quantity_locked);
        $this->assertDatabaseHas('erp_work_order_inventory_continuations', ['work_order_id' => $wo->id, 'status' => 'RESERVED', 'received_base_qty' => 0]);
        $this->assertDatabaseHas('erp_production_internal_issue_tasks', ['id' => $issue->id, 'status' => 'ISSUED', 'business_version' => 2]);
        $this->assertDatabaseMissing('erp_inventory_transactions', ['source_type' => 'production_internal_issue', 'source_id' => $issue->id]);
        $this->assertDatabaseHas('erp_production_target_material_requirements', ['work_order_id' => $wo->id, 'requirement_kind' => 'stock_continuation', 'satisfied_base_qty' => 0]);
    }

    private function newWorkOrder(int $quantity): WorkOrder
    {
        $c = $this->c;
        return WorkOrder::create(['work_order_no' => $this->code('RESUME-WO'), 'source_type' => 'production_plan', 'output_item_id' => $c['item']->id,
            'target_qty' => $quantity, 'target_base_qty' => $quantity, 'target_unit_id' => $c['unit']->id, 'base_unit_id' => $c['unit']->id,
            'bom_id' => $c['bom']->id, 'production_routing_id' => $c['routing']->id, 'routing_version_snapshot' => 1, 'routing_snapshot' => $c['snapshot'],
            'status' => 'WAIT_RELEASE', 'responsible_user_legacy_id' => $c['actor'], 'planned_date' => '2026-10-07', 'business_version' => 1]);
    }
    private function configurationPayload(WorkOrder $wo, int $quantity): array
    {
        return ['client_command_id' => $this->code('CONFIGURE'), 'expected_version' => $wo->fresh()->business_version,
            'sources' => [['inventory_balance_id' => $this->c['balance']->id, 'base_qty' => $quantity]]];
    }
    private function configure(WorkOrder $wo, int $quantity): array
    {
        return app(ProductionInventoryContinuationService::class)->configure($wo->id, $this->configurationPayload($wo, $quantity), $this->c['user'], self::PERMISSIONS, true);
    }
    private function publish(WorkOrder $wo): WorkOrder
    {
        return app(WorkOrderApplicationService::class)->publish($wo->id, ['client_command_id' => $this->code('PUBLISH'),
            'expected_version' => $wo->fresh()->business_version, 'reason' => '按正式总装库存续接水检'], $this->c['user'], self::PERMISSIONS, true);
    }
    private function publishedIssue(): array
    {
        $wo = $this->newWorkOrder(2); $this->configure($wo, 2); $this->publish($wo);
        $issue = DB::table('erp_production_internal_issue_tasks')->where('work_order_id', $wo->id)->first();
        DB::table('erp_production_tasks')->where('id', $issue->target_task_id)->update(['assignee_user_legacy_id' => $this->c['actor'], 'status' => 'CLAIMED']);
        DB::table('erp_production_quantity_operations')->where('id', $issue->target_id)->update(['responsible_user_legacy_id' => $this->c['actor'], 'status' => 'WAIT_KITTING']);
        return [$wo, $issue];
    }

    private function productionOutput(int $quantity, int $sequence, array $targetOverrides = []): array
    {
        $c = $this->c;
        $wo = WorkOrder::create(['work_order_no' => $this->code('SOURCE-WO'), 'source_type' => 'stock_prebuild', 'output_item_id' => $c['item']->id,
            'target_qty' => $quantity, 'target_base_qty' => $quantity, 'target_unit_id' => $c['unit']->id, 'base_unit_id' => $c['unit']->id,
            'production_routing_id' => $c['routing']->id, 'routing_version_snapshot' => 1, 'routing_snapshot' => $c['snapshot'],
            'target_routing_operation_id' => $c['nodes'][$sequence], 'status' => 'COMPLETED', 'business_version' => 1]);
        $target = ProductionQuantityOperation::create(array_replace(['work_order_id' => $wo->id, 'routing_operation_id_snapshot' => $c['nodes'][$sequence],
            'operation_id_snapshot' => $c['operations'][$sequence], 'operation_code_snapshot' => 'IC-'.$sequence,
            'operation_name_snapshot' => '来源工序'.$sequence, 'sequence_no_snapshot' => $sequence, 'status' => 'COMPLETED',
            'planned_base_qty' => $quantity, 'completed_base_qty' => $quantity, 'remaining_base_qty' => 0, 'output_item_id_snapshot' => $c['item']->id,
            'output_mode_snapshot' => 'warehouse_optional', 'quality_mode_snapshot' => 'none', 'performance_rate_snapshot' => .1, 'business_version' => 1], $targetOverrides));
        $output = ProductionOutputRecord::create(['output_no' => $this->code('OUTPUT'), 'work_order_id' => $wo->id, 'source_target_type' => 'quantity_operation',
            'source_target_id' => $target->id, 'output_item_id' => $c['item']->id, 'output_base_qty' => $quantity, 'output_mode_snapshot' => 'warehouse_optional',
            'quality_mode_snapshot' => 'none', 'status' => 'COMPLETED', 'created_by_legacy_id' => $c['actor'], 'produced_at' => now(), 'business_version' => 1]);
        return [$wo, $target, $output];
    }
    private function link(ProductionOutputRecord $parent, ProductionOutputRecord $child, ?int $parentQty, ?int $childQty): int
    {
        return DB::table('erp_production_output_lineage_links')->insertGetId(['parent_output_record_id' => $parent->id, 'child_output_record_id' => $child->id,
            'relation_type' => 'inventory_continuation', 'parent_base_qty' => $parentQty, 'child_base_qty' => $childQty, 'created_at' => now(), 'updated_at' => now()]);
    }
    private function posting(ProductionOutputRecord $output, InventoryBalance $balance): int
    {
        $tx = InventoryTransaction::create(['transaction_no' => $this->code('POST-TX'), 'transaction_type' => 'production_output_receipt',
            'source_type' => 'production_output_record', 'source_id' => $output->id, 'posting_status' => 'posted', 'posted_at' => now()]);
        return DB::table('erp_production_output_warehouse_postings')->insertGetId(['posting_no' => $this->code('POST'), 'output_record_id' => $output->id,
            'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no, 'posted_base_qty' => $output->output_base_qty,
            'status' => 'POSTED', 'inventory_transaction_id' => $tx->id, 'posted_by_legacy_id' => $this->c['actor'], 'posted_at' => now(), 'business_version' => 1, 'created_at' => now(), 'updated_at' => now()]);
    }
    private function serial(): int
    {
        $c = $this->c;
        return DB::table('erp_inventory_serials')->insertGetId(['serial_no' => $this->code('SERIAL'), 'inventory_balance_id' => $c['balance']->id,
            'item_id' => $c['item']->id, 'warehouse_id' => $c['warehouse']->id, 'location_id' => $c['location']->id, 'batch_no' => $c['balance']->batch_no,
            'serial_status' => 'available', 'source_document_type' => 'production_output', 'source_document_id' => $c['sourceOutput']->id, 'created_at' => now(), 'updated_at' => now()]);
    }
    private function shipment(int $salesQty, int $baseQty): array
    {
        $c = $this->c;
        $order = SalesOrder::create(['sales_order_no' => $this->code('SO'), 'customer_name' => '来源验证客户', 'order_status' => 'confirmed', 'confirm_status' => 'confirmed']);
        $orderLine = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1, 'line_type' => 'physical', 'item_id' => $c['item']->id,
            'order_qty' => $salesQty, 'amount' => 1000, 'amount_incl_tax' => 1000]);
        $shipment = SalesShipment::create(['shipment_no' => $this->code('SHIP'), 'sales_order_id' => $order->id, 'shipment_status' => 'shipped', 'shipped_at' => now()]);
        $reservation = InventoryReservation::create(['source_type' => 'sales_shipment', 'source_order_id' => $shipment->id, 'source_order_line_id' => $orderLine->id,
            'inventory_balance_id' => $c['balance']->id, 'item_id' => $c['item']->id, 'warehouse_id' => $c['warehouse']->id, 'location_id' => $c['location']->id,
            'batch_no' => $c['balance']->batch_no, 'reserved_qty' => $baseQty, 'reservation_status' => 'active', 'reserved_at' => now(), 'idempotency_key' => $this->code('RESERVE')]);
        $line = SalesShipmentLine::create(['shipment_id' => $shipment->id, 'sales_order_line_id' => $orderLine->id, 'inventory_reservation_id' => $reservation->id,
            'item_id' => $c['item']->id, 'warehouse_id' => $c['warehouse']->id, 'location_id' => $c['location']->id, 'batch_no' => $c['balance']->batch_no,
            'unit_id' => $c['unit']->id, 'sales_qty' => $salesQty, 'base_qty' => $baseQty, 'line_status' => 'shipped']);
        return [$order, $shipment, $line];
    }
    private function shipmentTransaction(SalesShipment $shipment, string $type): InventoryTransaction
    {
        $transaction = InventoryTransaction::create(['transaction_no' => $this->code('SHIP-TX'), 'transaction_type' => $type, 'source_type' => 'sales_shipment',
            'source_id' => $shipment->id, 'posting_status' => 'posted', 'posted_at' => now()]);
        foreach ($shipment->lines()->with('orderLine.item')->get() as $line) DB::table('erp_inventory_transaction_items')->insert([
            'transaction_id' => $transaction->id, 'transaction_no' => $transaction->transaction_no, 'item_id' => $line->item_id,
            'item_code' => $line->orderLine->item->item_code, 'item_name' => $line->orderLine->item->item_name,
            'warehouse_id' => $line->warehouse_id, 'location_id' => $line->location_id, 'batch_no' => $line->batch_no,
            'unit_id' => $line->unit_id, 'change_qty' => bcsub('0', (string) $line->base_qty, 8), 'balance_after_qty' => 8,
            'source_type' => 'sales_shipment', 'source_id' => $shipment->id, 'source_item_id' => $line->id, 'created_at' => now(), 'updated_at' => now()]);
        return $transaction;
    }
    private function code(string $prefix): string { return 'IC-'.$prefix.'-'.Str::upper(Str::random(10)); }
    private function domain(string $code, callable $action): void
    {
        try { $action(); $this->fail('预期业务规则拒绝 '.$code); }
        catch (WorkOrderDomainException $e) { $this->assertSame($code, $e->errorCode); }
    }
}
