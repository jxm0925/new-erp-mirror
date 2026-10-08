<?php

namespace Tests\Feature\Erp;

use App\DTO\Erp\WorkOrderDto;
use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Bom;
use App\Models\Erp\BomItem;
use App\Models\Erp\Item;
use App\Models\Erp\ItemCategory;
use App\Models\Erp\Product;
use App\Models\Erp\ProductionDemand;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Sku;
use App\Models\Erp\Unit;
use App\Models\Erp\WorkOrder;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\RbacBootstrapService;
use App\Services\Erp\ReleaseGateApplicationService;
use App\Services\Erp\WorkOrderApplicationService;
use App\Services\Erp\WorkOrderPlannedOutputOptionService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WorkOrderPlannedOutputTest extends TestCase
{
    use DatabaseTransactions;

    private const LINES = 'erp_work_order_planned_outputs';
    private const VERSIONS = 'erp_work_order_planned_output_versions';
    private const PERMISSIONS = [
        'production.demand.view', 'production.work_order.view', 'production.work_order.create',
        'production.work_order.edit', 'production.work_order.submit', 'production.work_order.cancel',
        'production.work_order.gate.view', 'production.work_order.publish', 'production.material.view',
        'production.material_requirement.view',
    ];

    public function test_legacy_plan_is_a_read_only_projection_of_real_item_unit_and_base_quantity(): void
    {
        $fixture = $this->fixture();
        $workOrder = $fixture['work_order'];
        $before = $workOrder->fresh()->getRawOriginal();
        $lineCount = DB::table(self::LINES)->where('work_order_id', $workOrder->id)->count();
        $versionCount = $this->versions($workOrder->id)->count();
        $auditCount = $this->audits($workOrder->id)->count();

        foreach (range(1, 2) as $read) {
            $response = $this->readPlan($fixture);
            $response->assertOk()->assertJsonPath('data.work_order_id', $workOrder->id)
                ->assertJsonPath('data.business_version', 1)->assertJsonPath('data.editable', true)
                ->assertJsonPath('data.plan_source', 'legacy_projection')
                ->assertJsonPath('data.multi_output_execution_available', false)->assertJsonCount(1, 'data.outputs');
            $reference = $response->json('data.outputs.0');
            $this->assertNull($reference['line_uuid']);
            $this->assertNull($reference['business_version']);
            $this->assertTrue($reference['is_reference']);
            $this->assertSame('product', $reference['output_role']);
            $this->assertSame($fixture['output']->id, $reference['item_id']);
            $this->assertSame($fixture['output']->item_code, $reference['item_code']);
            $this->assertSame($fixture['output']->item_name, $reference['item_name']);
            $this->assertSame($fixture['output']->spec, $reference['spec']);
            $this->assertSame($fixture['unit']->id, $reference['base_unit_id']);
            $this->assertSame($fixture['unit']->unit_name, $reference['base_unit_name']);
            $this->assertSame(4, $reference['base_unit_decimal_places']);
            $this->assertQuantity((string) $workOrder->target_base_qty, $reference['planned_base_qty']);
            $this->assertNoPricesOrCosts($response->json('data'));
        }

        $this->assertSame($before, $workOrder->fresh()->getRawOriginal());
        $this->assertSame($lineCount, DB::table(self::LINES)->where('work_order_id', $workOrder->id)->count());
        $this->assertSame($versionCount, $this->versions($workOrder->id)->count());
        $this->assertSame($auditCount, $this->audits($workOrder->id)->count());
    }

    public function test_stock_prebuild_reference_uses_effective_node_output_and_its_actual_unit(): void
    {
        $fixture = $this->fixture();
        $effectiveUnit = $this->unit(2, '公斤');
        $effectiveItem = $this->item($effectiveUnit, ['item_name' => '实际预制半成品', 'item_type' => 'semi_finished']);
        DB::table('erp_production_routing_operations')->where('id', $fixture['routing_operation_id'])
            ->update(['output_item_id' => $effectiveItem->id, 'output_mode' => 'flow_only']);
        $session = (string) Str::uuid();
        $number = app(DocumentNumberService::class)->reserve('work_order', $session,
            $fixture['user']->legacy_id, '/production/work-orders/create');
        $workOrder = app(WorkOrderApplicationService::class)->createDraft([
            'client_command_id' => $this->command(), 'source_type' => 'stock_prebuild',
            'stocking_purpose' => 'common_inventory', 'creation_session_id' => $session,
            'reservation_token' => $number->reservation_token, 'output_item_id' => $fixture['output']->id,
            'production_routing_id' => $fixture['routing_id'],
            'target_routing_operation_id' => $fixture['routing_operation_id'], 'target_qty' => 12,
            'responsible_user_legacy_id' => $fixture['user']->legacy_id,
        ], $fixture['user'], self::PERMISSIONS);
        // Legacy converted quantities can differ from display quantities; the plan must not invent a ratio.
        $workOrder->update(['target_base_qty' => '7.25000000']);
        $fixture['work_order'] = $workOrder->fresh();
        $before = $fixture['work_order']->getRawOriginal();

        $response = $this->readPlan($fixture)->assertOk()->assertJsonCount(1, 'data.outputs');
        $reference = $response->json('data.outputs.0');
        $this->assertNotSame($workOrder->output_item_id, $reference['item_id']);
        $this->assertSame($effectiveItem->id, $reference['item_id']);
        $this->assertSame($effectiveUnit->id, $reference['base_unit_id']);
        $this->assertSame('公斤', $reference['base_unit_name']);
        $this->assertSame(2, $reference['base_unit_decimal_places']);
        $this->assertQuantity('7.25', $reference['planned_base_qty']);
        $this->assertSame($before, $workOrder->fresh()->getRawOriginal());
        $this->assertSame(0, DB::table(self::LINES)->where('work_order_id', $workOrder->id)->count());
    }

    public function test_three_planned_lines_keep_uuid_history_and_retry_once_without_rewriting_legacy_identity(): void
    {
        $fixture = $this->fixture();
        $workOrder = $fixture['work_order'];
        $product = $this->item($fixture['unit'], ['item_name' => '第二种计划管件']);
        $byProduct = $this->item($fixture['unit'], ['item_name' => '明确登记的可入库副产物',
            'item_type' => 'raw_material', 'is_production_item' => false]);
        $first = $this->line($product, '3', 'product', '按图切段');
        $second = $this->line($byProduct, '2', 'by_product', '独立记录数量');
        $payload = $this->payload($workOrder, [$first, $second]);
        $before = $this->legacyIdentity($workOrder);
        $auditCount = $this->audits($workOrder->id)->count();

        $saved = $this->savePlan($fixture, $payload)->assertOk()->assertJsonPath('data.plan_source', 'saved_plan')
            ->assertJsonPath('data.business_version', 2)->assertJsonCount(3, 'data.outputs');
        $reference = collect($saved->json('data.outputs'))->firstWhere('is_reference', true);
        $this->assertNotNull($reference);
        $this->assertTrue(Str::isUuid($reference['line_uuid']));
        $this->assertSame('by_product', collect($saved->json('data.outputs'))->firstWhere('line_uuid', $second['line_uuid'])['output_role']);
        $firstRowId = DB::table(self::LINES)->where('line_uuid', $first['line_uuid'])->value('id');
        $this->assertNotNull($firstRowId);
        $this->assertSame(1, $this->versions($workOrder->id)->count());
        $this->assertSame($auditCount + 1, $this->audits($workOrder->id)->count());
        $referenceTamper = $this->line($product, '999');
        $referenceTamper['line_uuid'] = $reference['line_uuid'];
        $this->savePlan($fixture, $this->payload($workOrder->fresh(), [$referenceTamper]))->assertStatus(422);

        $retry = $this->savePlan($fixture, $payload)->assertOk();
        $this->assertSame($saved->json('data'), $retry->json('data'));
        $this->assertSame(1, $this->versions($workOrder->id)->count());
        $this->assertSame($auditCount + 1, $this->audits($workOrder->id)->count());
        $conflict = $payload;
        $conflict['outputs'][0]['planned_base_qty'] = '4';
        $this->savePlan($fixture, $conflict)->assertStatus(409);

        $first['planned_base_qty'] = '4';
        $first['remark'] = '按修订图切段';
        $updated = $this->savePlan($fixture, $this->payload($workOrder->fresh(), [$first]))
            ->assertOk()->assertJsonPath('data.business_version', 3)->assertJsonCount(2, 'data.outputs');
        $current = collect($updated->json('data.outputs'))->firstWhere('line_uuid', $first['line_uuid']);
        $this->assertQuantity('4', $current['planned_base_qty']);
        $this->assertGreaterThan(1, $current['business_version']);
        $this->assertSame($firstRowId, DB::table(self::LINES)->where('line_uuid', $first['line_uuid'])->value('id'));
        $this->assertSame('REMOVED', DB::table(self::LINES)->where('line_uuid', $second['line_uuid'])->value('status'));
        $this->assertSame($reference['line_uuid'], collect($updated->json('data.outputs'))->firstWhere('is_reference', true)['line_uuid']);
        $this->assertSame($before, $this->legacyIdentity($workOrder->fresh()));
        $this->assertSame(2, $this->versions($workOrder->id)->count());
        $versions = $this->versions($workOrder->id)->orderBy('id')->get();
        $this->assertSame([1, 2], $versions->pluck('before_version')->map(fn ($v) => (int) $v)->all());
        $this->assertSame([2, 3], $versions->pluck('after_version')->map(fn ($v) => (int) $v)->all());
        $this->assertSame($payload['client_command_id'], $versions[0]->client_command_id);
        $this->assertSame((int) $fixture['user']->legacy_id, (int) $versions[1]->operator_legacy_id);
        $this->assertSame($fixture['user']->nickname, $versions[1]->operator_name);
        $this->assertNotNull($versions[1]->occurred_at);
        $this->assertIsArray(json_decode($versions[1]->before_snapshot, true, 512, JSON_THROW_ON_ERROR));
        $this->assertIsArray(json_decode($versions[1]->after_snapshot, true, 512, JSON_THROW_ON_ERROR));
        $this->assertStringContainsString($second['line_uuid'], $versions[1]->before_snapshot);
        $this->assertStringNotContainsString($second['line_uuid'], $versions[1]->after_snapshot);
        $this->assertSame($auditCount + 2, $this->audits($workOrder->id)->count());
        $fixture['output']->update(['item_name' => '主档已改名，不能覆盖计划历史']);
        $historical = collect($this->readPlan($fixture)->assertOk()->json('data.outputs'))->firstWhere('is_reference', true);
        $this->assertSame($reference['item_name'], $historical['item_name']);
        $this->assertSame($reference['line_uuid'], $historical['line_uuid']);
        $this->assertSame(2, $this->versions($workOrder->id)->count());
    }

    public function test_output_role_is_explicit_and_product_and_by_product_have_different_eligibility(): void
    {
        $fixture = $this->fixture();
        $stockMaterial = $this->item($fixture['unit'], ['is_production_item' => false, 'item_type' => 'raw_material']);
        $this->savePlan($fixture, $this->payload($fixture['work_order'], [$this->line($stockMaterial, '2', 'product')]))
            ->assertStatus(422);
        $product = $this->item($fixture['unit'], ['item_name' => '第三种正常计划管件']);
        $response = $this->savePlan($fixture, $this->payload($fixture['work_order'], [
            $this->line($stockMaterial, '2', 'by_product'), $this->line($product, '3', 'product'),
        ]))->assertOk();
        $rows = collect($response->json('data.outputs'));
        $this->assertSame('by_product', $rows->firstWhere('item_id', $stockMaterial->id)['output_role']);
        $this->assertSame('product', $rows->firstWhere('item_id', $product->id)['output_role']);

        foreach ([['item_type' => 'service'], ['is_stock_item' => false], ['status' => 'disabled']] as $invalid) {
            $item = $this->item($fixture['unit'], $invalid);
            $this->savePlan($fixture, $this->payload($fixture['work_order']->fresh(), [$this->line($item, '1', 'by_product')]))
                ->assertStatus(422);
        }
        $this->assertSame(2, (int) $fixture['work_order']->fresh()->business_version);
        $this->assertSame(1, $this->versions($fixture['work_order']->id)->count());
    }

    public function test_unknown_fields_uuid_and_quantity_validation_cannot_default_or_partially_save_a_plan(): void
    {
        $fixture = $this->fixture();
        $unit = $this->unit(2, '米');
        $item = $this->item($unit);
        $valid = $this->line($item, '1.25');
        $cases = [];
        $cases['unknown_header'] = $this->payload($fixture['work_order'], [$valid]) + ['target_qty' => '999'];
        foreach ([
            'unknown_row' => ['actual_qty' => '1'], 'fake_unit' => ['base_unit_id' => $fixture['unit']->id],
            'fake_reference' => ['is_reference' => true], 'cost_injection' => ['cost' => '12.00'],
            'invalid_uuid' => ['line_uuid' => 'not-a-uuid'], 'zero' => ['planned_base_qty' => '0'],
            'negative' => ['planned_base_qty' => '-1'], 'excess_precision' => ['planned_base_qty' => '1.251'],
            'scientific_quantity' => ['planned_base_qty' => '1e2'], 'float_quantity' => ['planned_base_qty' => 1.25],
        ] as $name => $change) {
            $cases[$name] = $this->payload($fixture['work_order'], [array_replace($valid, $change)]);
        }
        $missingQty = $valid;
        unset($missingQty['planned_base_qty']);
        $cases['missing_quantity'] = $this->payload($fixture['work_order'], [$missingQty]);
        $missingRole = $valid;
        unset($missingRole['output_role']);
        $cases['missing_role'] = $this->payload($fixture['work_order'], [$missingRole]);
        $cases['duplicate_uuid'] = $this->payload($fixture['work_order'], [$valid, $valid]);

        foreach ($cases as $name => $payload) {
            $payload['client_command_id'] = $this->command();
            $this->savePlan($fixture, $payload)->assertStatus(422);
            $this->assertSame(1, (int) $fixture['work_order']->fresh()->business_version, $name);
            $this->assertSame(0, DB::table(self::LINES)->where('work_order_id', $fixture['work_order']->id)->count(), $name);
            $this->assertSame(0, $this->versions($fixture['work_order']->id)->count(), $name);
        }
        $valid['planned_base_qty'] = '1.25000000';
        $accepted = $this->savePlan($fixture, $this->payload($fixture['work_order'], [$valid]))->assertOk();
        $this->assertQuantity('1.25', collect($accepted->json('data.outputs'))->firstWhere('line_uuid', $valid['line_uuid'])['planned_base_qty']);
    }

    public function test_same_material_cannot_be_split_into_duplicate_extra_or_reference_lines_without_configuration_identity(): void
    {
        $fixture = $this->fixture();
        $extraItem = $this->item($fixture['unit']);
        $before = $this->legacyIdentity($fixture['work_order']);
        $auditCount = $this->audits($fixture['work_order']->id)->count();
        $this->savePlan($fixture, $this->payload($fixture['work_order'], [
            $this->line($extraItem, '2', 'product'), $this->line($extraItem, '3', 'by_product'),
        ]))->assertStatus(422);
        $this->savePlan($fixture, $this->payload($fixture['work_order'], [
            $this->line($fixture['output'], '2', 'product'),
        ]))->assertStatus(422);
        $this->assertSame(1, (int) $fixture['work_order']->fresh()->business_version);
        $this->assertSame($before, $this->legacyIdentity($fixture['work_order']->fresh()));
        $this->assertSame(0, DB::table(self::LINES)->where('work_order_id', $fixture['work_order']->id)->count());
        $this->assertSame(0, $this->versions($fixture['work_order']->id)->count());
        $this->assertSame($auditCount, $this->audits($fixture['work_order']->id)->count());
    }

    public function test_missing_item_and_disabled_units_reject_the_whole_change_and_preserve_the_saved_plan(): void
    {
        $fixture = $this->fixture();
        $validItem = $this->item($fixture['unit']);
        $existing = $this->line($validItem, '2');
        $this->savePlan($fixture, $this->payload($fixture['work_order'], [$existing]))->assertOk();
        $before = $this->readPlan($fixture)->assertOk()->json('data');
        $disabledUnit = $this->unit(2, '公斤');
        $badUnitItem = $this->item($disabledUnit);
        $disabledUnit->update(['status' => 'disabled']);
        $missingItemId = (int) DB::table('erp_items')->max('id') + 1000;
        foreach ([$missingItemId, $badUnitItem->id] as $invalidItemId) {
            $invalid = $this->line($validItem, '1');
            $invalid['item_id'] = $invalidItemId;
            $this->savePlan($fixture, $this->payload($fixture['work_order']->fresh(), [$existing, $invalid]))->assertStatus(422);
            $this->assertSame($before, $this->readPlan($fixture)->assertOk()->json('data'));
            $this->assertSame(1, $this->versions($fixture['work_order']->id)->count());
        }

        // A corrupt legacy reference remains inspectable, but cannot be made into a guessed saved plan.
        $fixture['unit']->update(['status' => 'disabled']);
        $this->readPlan($fixture)->assertOk();
        $this->savePlan($fixture, $this->payload($fixture['work_order']->fresh(), []))->assertStatus(422);
        $this->assertSame(2, (int) $fixture['work_order']->fresh()->business_version);
    }

    public function test_saved_unit_names_and_precision_stay_frozen_while_official_quantity_edits_sync_reference_once(): void
    {
        $fixture = $this->fixture();
        $workOrder = $fixture['work_order'];
        $extra = $this->line($this->item($fixture['unit']), '1.2345');
        $saved = $this->savePlan($fixture, $this->payload($workOrder, [$extra]))->assertOk()->json('data');
        $reference = collect($saved['outputs'])->firstWhere('is_reference', true);
        $referenceId = DB::table(self::LINES)->where('line_uuid', $reference['line_uuid'])->value('id');

        $fixture['unit']->update(['unit_name' => '主档改名后的件', 'decimal_places' => 1]);
        $this->assertSame($saved, $this->readPlan($fixture)->assertOk()->json('data'));
        foreach ($saved['outputs'] as $row) {
            $this->assertSame('件', $row['base_unit_name']);
            $this->assertSame(4, $row['base_unit_decimal_places']);
        }

        $workOrders = app(WorkOrderApplicationService::class);
        $command = $this->command();
        $edit = ['client_command_id' => $command, 'expected_version' => 2, 'target_qty' => '6',
            'reason' => '正式调整工单计划数量'];
        $auditCount = $this->audits($workOrder->id)->count();
        $changed = $workOrders->updateDraft($workOrder->id, $edit, $fixture['user'], self::PERMISSIONS);
        $this->assertSame(3, (int) $changed->business_version);
        $this->assertQuantity('6', $changed->target_qty);
        $this->assertQuantity('6', $changed->target_base_qty);
        $after = $this->readPlan($fixture)->assertOk()->json('data');
        $updatedReference = collect($after['outputs'])->firstWhere('is_reference', true);
        $this->assertSame($reference['line_uuid'], $updatedReference['line_uuid']);
        $this->assertSame($referenceId, DB::table(self::LINES)->where('line_uuid', $reference['line_uuid'])->value('id'));
        $this->assertSame(2, $updatedReference['business_version']);
        $this->assertSame('件', $updatedReference['base_unit_name']);
        $this->assertSame(4, $updatedReference['base_unit_decimal_places']);
        $this->assertQuantity('6', $updatedReference['planned_base_qty']);
        $this->assertSame(collect($saved['outputs'])->firstWhere('line_uuid', $extra['line_uuid']),
            collect($after['outputs'])->firstWhere('line_uuid', $extra['line_uuid']));
        $this->assertSame(2, $this->versions($workOrder->id)->count());
        $syncAudit = $this->versions($workOrder->id)->where('client_command_id', $command)->first();
        $this->assertNotNull($syncAudit);
        $this->assertSame('edit_draft', $syncAudit->command_type);
        $this->assertSame(2, (int) $syncAudit->before_version);
        $this->assertSame(3, (int) $syncAudit->after_version);
        $this->assertQuantity('5', collect(json_decode($syncAudit->before_snapshot, true, 512, JSON_THROW_ON_ERROR))
            ->firstWhere('is_reference', true)['planned_base_qty']);
        $this->assertQuantity('6', collect(json_decode($syncAudit->after_snapshot, true, 512, JSON_THROW_ON_ERROR))
            ->firstWhere('is_reference', true)['planned_base_qty']);
        $workOrders->updateDraft($workOrder->id, $edit, $fixture['user'], self::PERMISSIONS);
        $this->assertSame($after, $this->readPlan($fixture)->assertOk()->json('data'));
        $this->assertSame(2, $this->versions($workOrder->id)->count());
        $this->assertSame($auditCount + 1, $this->audits($workOrder->id)->count());

        $dateOnly = $workOrders->updateDraft($workOrder->id, ['client_command_id' => $this->command(),
            'expected_version' => 3, 'planned_date' => now()->addDays(2)->toDateString(),
            'reason' => '只调整计划日期'], $fixture['user'], self::PERMISSIONS);
        $this->assertSame(4, (int) $dateOnly->business_version);
        $this->assertQuantity('6', $dateOnly->target_base_qty);
        $this->assertSame($after['outputs'], $this->readPlan($fixture)->assertOk()->json('data.outputs'));
        $this->assertSame(2, $this->versions($workOrder->id)->count());
        $this->assertSame($auditCount + 2, $this->audits($workOrder->id)->count());
    }

    public function test_changed_canonical_unit_identity_cannot_reinterpret_saved_reference_or_extra_quantities(): void
    {
        $fixture = $this->fixture();
        $workOrder = $fixture['work_order'];
        $extraItem = $this->item($fixture['unit']);
        $extra = $this->line($extraItem, '2.125');
        $saved = $this->savePlan($fixture, $this->payload($workOrder, [$extra]))->assertOk()->json('data');
        $before = $workOrder->fresh()->getRawOriginal();
        $lineSnapshot = DB::table(self::LINES)->where('work_order_id', $workOrder->id)->orderBy('id')->get()->toArray();
        $auditCount = $this->audits($workOrder->id)->count();
        $replacementUnit = $this->unit(2, '公斤');
        $fixture['output']->update(['unit_id' => $replacementUnit->id]);
        $this->assertSame($saved, $this->readPlan($fixture)->assertOk()->json('data'));
        $this->savePlan($fixture, $this->payload($workOrder->fresh(), []))
            ->assertStatus(422)->assertJsonPath('error_code', 'reference_output_unit_changed');
        try {
            app(WorkOrderApplicationService::class)->updateDraft($workOrder->id,
                ['client_command_id' => $this->command(), 'expected_version' => 2, 'target_qty' => '6',
                    'reason' => '不能用新单位解释原计划数量'], $fixture['user'], self::PERMISSIONS);
            $this->fail('已保存参考产出的单位身份变化后，正式数量编辑必须拒绝。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame('reference_output_unit_changed', $exception->errorCode);
        }
        $this->assertSame($before, $workOrder->fresh()->getRawOriginal());
        $this->assertSame($saved, $this->readPlan($fixture)->assertOk()->json('data'));
        $this->assertEquals($lineSnapshot, DB::table(self::LINES)->where('work_order_id', $workOrder->id)->orderBy('id')->get()->toArray());
        $this->assertSame(1, $this->versions($workOrder->id)->count());
        $this->assertSame($auditCount, $this->audits($workOrder->id)->count());

        $fixture['output']->update(['unit_id' => $fixture['unit']->id]);
        $extraItem->update(['unit_id' => $replacementUnit->id]);
        $this->savePlan($fixture, $this->payload($workOrder->fresh(), [$extra]))
            ->assertStatus(422)->assertJsonPath('error_code', 'planned_output_unit_changed');
        $this->assertSame($before, $workOrder->fresh()->getRawOriginal());
        $this->assertSame($saved, $this->readPlan($fixture)->assertOk()->json('data'));
        $this->assertEquals($lineSnapshot, DB::table(self::LINES)->where('work_order_id', $workOrder->id)->orderBy('id')->get()->toArray());
        $this->assertSame(1, $this->versions($workOrder->id)->count());
        $this->assertSame($auditCount, $this->audits($workOrder->id)->count());

        // A new standard mapping changes canonical identity even when both items still point to the old unit.
        $fixture['output']->update(['unit_id' => $fixture['unit']->id]);
        $extraItem->update(['unit_id' => $fixture['unit']->id]);
        $fixture['unit']->update(['is_legacy' => true, 'standard_unit_id' => $replacementUnit->id]);
        $this->assertSame($saved, $this->readPlan($fixture)->assertOk()->json('data'));
        $this->savePlan($fixture, $this->payload($workOrder->fresh(), []))
            ->assertStatus(422)->assertJsonPath('error_code', 'reference_output_unit_changed');
        try {
            app(WorkOrderApplicationService::class)->updateDraft($workOrder->id,
                ['client_command_id' => $this->command(), 'expected_version' => 2, 'target_qty' => '6',
                    'reason' => '标准单位映射变化不能重解释冻结数量'], $fixture['user'], self::PERMISSIONS);
            $this->fail('旧单位新增标准映射后，正式数量编辑必须保留冻结单位身份并拒绝。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame(422, $exception->status);
            $this->assertSame('reference_output_unit_changed', $exception->errorCode);
        }
        $this->assertSame($before, $workOrder->fresh()->getRawOriginal());
        $this->assertSame($saved, $this->readPlan($fixture)->assertOk()->json('data'));
        $this->assertEquals($lineSnapshot, DB::table(self::LINES)->where('work_order_id', $workOrder->id)->orderBy('id')->get()->toArray());
        $this->assertSame(1, $this->versions($workOrder->id)->count());
        $this->assertSame($auditCount, $this->audits($workOrder->id)->count());
    }

    public function test_view_edit_and_original_work_order_scope_apply_to_plan_and_selector_endpoints(): void
    {
        $fixture = $this->fixture();
        $url = $this->planUrl($fixture['work_order']->id);
        $options = $this->optionsUrl($fixture['work_order']->id, ['type' => 'items']);
        $this->flushHeaders();
        $this->getJson($url)->assertUnauthorized();
        $reader = $this->actor(['production.work_order.view']);
        $this->withToken($reader['token'])->getJson($url)->assertOk()->assertJsonPath('data.editable', false);
        $this->withToken($reader['token'])->getJson($options)->assertOk();
        $this->withToken($reader['token'])->putJson($url, $this->payload($fixture['work_order'], []))->assertForbidden();
        $withoutView = $this->actor([]);
        $this->withToken($withoutView['token'])->getJson($url)->assertForbidden();
        $this->withToken($withoutView['token'])->getJson($options)->assertForbidden();
        $outside = $this->actor(['production.work_order.view', 'production.work_order.edit'], 'self');
        $this->withToken($outside['token'])->getJson($url)->assertForbidden();
        $this->withToken($outside['token'])->getJson($options)->assertForbidden();
        $this->withToken($outside['token'])->putJson($url, $this->payload($fixture['work_order'], []))->assertForbidden();
        foreach ([['production.work_order.view'], ['production.work_order.edit']] as $explicitPermissions) {
            try {
                app(WorkOrderApplicationService::class)->savePlannedOutputs($fixture['work_order']->id,
                    $this->payload($fixture['work_order'], []), $fixture['user'], $explicitPermissions, true);
                $this->fail('superAdmin 不能替代计划产出的显式查看和编辑权限。');
            } catch (WorkOrderDomainException $exception) {
                $this->assertSame(403, $exception->status);
                $this->assertSame('permission_denied', $exception->errorCode);
            }
        }
        try {
            app(WorkOrderPlannedOutputOptionService::class)->options($fixture['work_order']->id,
                ['type' => 'items'], $fixture['user'], [], true);
            $this->fail('superAdmin 不能替代计划产出选择器的显式查看权限。');
        } catch (WorkOrderDomainException $exception) {
            $this->assertSame(403, $exception->status);
            $this->assertSame('permission_denied', $exception->errorCode);
        }
        foreach ([[], ['production.work_order.view']] as $explicitPermissions) {
            $dto = WorkOrderDto::fromModel($fixture['work_order']->fresh(), $explicitPermissions, true);
            $this->assertFalse($dto['planned_outputs']['editable']);
            $this->assertFalse($dto['actions']['edit']);
        }
        $this->assertSame(1, (int) $fixture['work_order']->fresh()->business_version);
        $this->assertSame(0, $this->versions($fixture['work_order']->id)->count());
    }

    public function test_successful_command_replay_rechecks_revoked_permission_and_changed_data_scope(): void
    {
        $fixture = $this->fixture();
        $item = $this->item($fixture['unit']);
        $payload = $this->payload($fixture['work_order'], [$this->line($item, '2')]);
        $this->savePlan($fixture, $payload)->assertOk();
        $versionCount = $this->versions($fixture['work_order']->id)->count();
        $permissionId = DB::table('erp_rbac_permissions')->where('code', 'production.work_order.edit')->value('id');
        DB::table('erp_rbac_role_permissions')->where('role_id', $fixture['role_id'])->where('permission_id', $permissionId)->delete();
        $this->savePlan($fixture, $payload)->assertForbidden();

        DB::table('erp_rbac_role_permissions')->insert(['role_id' => $fixture['role_id'], 'permission_id' => $permissionId]);
        DB::table('erp_rbac_roles')->where('id', $fixture['role_id'])->update(['data_scope' => 'self']);
        $newOwner = $this->actor(['production.work_order.view'], 'self');
        $fixture['work_order']->update(['responsible_user_legacy_id' => $newOwner['user']->legacy_id]);
        $this->savePlan($fixture, $payload)->assertForbidden();
        $this->assertSame(2, (int) $fixture['work_order']->fresh()->business_version);
        $this->assertSame($versionCount, $this->versions($fixture['work_order']->id)->count());
        $this->assertSame(2, DB::table(self::LINES)->where('work_order_id', $fixture['work_order']->id)->where('status', 'ACTIVE')->count());
    }

    public function test_stale_work_order_version_and_another_work_orders_uuid_cannot_overwrite_lines(): void
    {
        $fixture = $this->fixture();
        $item = $this->item($fixture['unit']);
        $line = $this->line($item, '2');
        $this->savePlan($fixture, $this->payload($fixture['work_order'], [$line]))->assertOk();
        $this->savePlan($fixture, ['client_command_id' => $this->command(), 'expected_version' => 1,
            'outputs' => [array_replace($line, ['planned_base_qty' => '999'])]])->assertStatus(409);
        $other = $this->newDraft($fixture);
        $otherFixture = array_replace($fixture, ['work_order' => $other]);
        $foreign = $this->savePlan($otherFixture, $this->payload($other, [$line]));
        $this->assertContains($foreign->status(), [409, 422]);
        $this->assertSame(1, (int) $other->fresh()->business_version);
        $this->assertSame(0, $this->versions($other->id)->count());
        $this->assertSame(0, DB::table(self::LINES)->where('work_order_id', $other->id)->count());
        $row = DB::table(self::LINES)->where('line_uuid', $line['line_uuid'])->first();
        $this->assertSame($fixture['work_order']->id, (int) $row->work_order_id);
        $this->assertQuantity('2', (string) $row->planned_base_qty);
    }

    public function test_waiting_work_order_is_immutable_even_for_a_previously_successful_command(): void
    {
        $fixture = $this->fixture();
        $item = $this->item($fixture['unit']);
        $payload = $this->payload($fixture['work_order'], [$this->line($item, '2')]);
        $this->savePlan($fixture, $payload)->assertOk();
        $waiting = app(WorkOrderApplicationService::class)->submit($fixture['work_order']->id,
            ['client_command_id' => $this->command(), 'expected_version' => 2], $fixture['user'], self::PERMISSIONS);
        $this->readPlan($fixture)->assertOk()->assertJsonPath('data.editable', false);
        $this->savePlan($fixture, $payload)->assertStatus(409);
        $this->savePlan($fixture, $this->payload($waiting, []))->assertStatus(409);
        $this->assertSame('WAIT_RELEASE', $waiting->fresh()->status);
        $this->assertSame(3, (int) $waiting->fresh()->business_version);
        $this->assertSame(1, $this->versions($waiting->id)->count());
    }

    public function test_real_release_gate_blocks_extra_outputs_and_clear_restores_single_output_publish(): void
    {
        $fixture = $this->fixture();
        $workOrders = app(WorkOrderApplicationService::class);
        $releaseGate = app(ReleaseGateApplicationService::class);
        $single = $workOrders->submit($fixture['work_order']->id,
            ['client_command_id' => $this->command(), 'expected_version' => 1], $fixture['user'], self::PERMISSIONS);
        $singleGate = $releaseGate->evaluate($single->id, $fixture['user'], self::PERMISSIONS);
        $this->assertTrue($singleGate['allowed']);
        $this->assertSame('passed', collect($singleGate['checks'])->firstWhere('key', 'planned_outputs')['status']);
        $published = $this->withToken($fixture['token'])->postJson('/api/v1/erp/production/work-orders/'.$single->id.'/publish',
            ['client_command_id' => $this->command(), 'expected_version' => $single->fresh()->business_version,
                'reason' => '验证计划产出发布门禁']);
        $published->assertOk()->assertJsonPath('data.status', 'RELEASED');
        $this->savePlan($fixture, $this->payload($single->fresh(), []))->assertStatus(409);

        $multi = $this->newDraft($fixture);
        $multiFixture = array_replace($fixture, ['work_order' => $multi]);
        $extra = $this->item($fixture['unit']);
        $this->savePlan($multiFixture, $this->payload($multi, [$this->line($extra, '3')]))->assertOk();
        $waiting = $workOrders->submit($multi->id, ['client_command_id' => $this->command(), 'expected_version' => 2],
            $fixture['user'], self::PERMISSIONS);
        $blocked = $releaseGate->evaluate($waiting->id, $fixture['user'], self::PERMISSIONS);
        $plannedCheck = collect($blocked['checks'])->firstWhere('key', 'planned_outputs');
        $this->assertFalse($blocked['allowed']);
        $this->assertSame('blocked', $plannedCheck['status']);
        $this->assertSame('multi_output_execution_not_available', $plannedCheck['reason_code']);
        $this->assertSame('工单包含多项计划产出，当前尚未开放多产出执行，不能发布。', $plannedCheck['message']);
        foreach ($blocked['checks'] as $check) {
            if ($check['key'] !== 'planned_outputs') $this->assertSame('passed', $check['status'], $check['key']);
        }
        $this->withToken($fixture['token'])->postJson('/api/v1/erp/production/work-orders/'.$waiting->id.'/publish',
            ['client_command_id' => $this->command(), 'expected_version' => $waiting->fresh()->business_version,
                'reason' => '验证计划产出发布门禁'])
            ->assertStatus(422)->assertJsonPath('error_code', 'release_gate_blocked');
        $this->assertSame('WAIT_RELEASE', $waiting->fresh()->status);
        $this->assertSame(0, DB::table('erp_production_tasks')->where('work_order_id', $waiting->id)->count());

        $draft = $workOrders->returnToDraft($waiting->id, ['client_command_id' => $this->command(),
            'expected_version' => $waiting->fresh()->business_version, 'reason' => '暂按单产出发布'], $fixture['user'], self::PERMISSIONS);
        $this->savePlan($multiFixture, $this->payload($draft, []))->assertOk()->assertJsonCount(1, 'data.outputs')
            ->assertJsonPath('data.plan_source', 'saved_plan')->assertJsonPath('data.outputs.0.is_reference', true);
        $ready = $workOrders->submit($draft->id, ['client_command_id' => $this->command(),
            'expected_version' => $draft->fresh()->business_version], $fixture['user'], self::PERMISSIONS);
        $restored = $releaseGate->evaluate($ready->id, $fixture['user'], self::PERMISSIONS);
        $this->assertTrue($restored['allowed']);
        $this->assertSame('passed', collect($restored['checks'])->firstWhere('key', 'planned_outputs')['status']);
        $this->withToken($fixture['token'])->postJson('/api/v1/erp/production/work-orders/'.$ready->id.'/publish',
            ['client_command_id' => $this->command(), 'expected_version' => $ready->fresh()->business_version,
                'reason' => '验证计划产出发布门禁'])
            ->assertOk()->assertJsonPath('data.status', 'RELEASED');
    }

    public function test_selector_uses_real_category_tree_keyword_server_pages_units_and_no_master_or_cost_permission(): void
    {
        $fixture = $this->fixture();
        $reader = $this->actor(['production.work_order.view']);
        $root = ItemCategory::create(['category_code' => $this->code('CAT'), 'category_name' => '计划产出根类目',
            'category_type' => 'item', 'status' => 'enabled']);
        $child = ItemCategory::create(['category_code' => $this->code('CAT'), 'category_name' => '管料子类目',
            'parent_id' => $root->id, 'category_type' => 'item', 'status' => 'enabled']);
        $other = ItemCategory::create(['category_code' => $this->code('CAT'), 'category_name' => '其它真实类目',
            'category_type' => 'item', 'status' => 'enabled']);
        foreach (range(1, 53) as $index) {
            $this->item($fixture['unit'], ['item_code' => $root->category_code.'-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT),
                'category_id' => $child->id, 'item_name' => '管料-'.$index, 'spec' => '25×25×2']);
        }
        $byProduct = $this->item($fixture['unit'], ['category_id' => $child->id, 'is_production_item' => false,
            'item_type' => 'raw_material', 'item_name' => '供明确选择用途的可回收料', 'spec' => '独立规格检索标识']);
        $outside = $this->item($fixture['unit'], ['category_id' => $other->id]);
        $excluded = collect([
            $this->item($fixture['unit'], ['category_id' => $child->id, 'item_type' => 'service']),
            $this->item($fixture['unit'], ['category_id' => $child->id, 'status' => 'disabled']),
            $this->item($fixture['unit'], ['category_id' => $child->id, 'is_stock_item' => false]),
        ])->pluck('id')->all();
        $first = $this->withToken($reader['token'])->getJson($this->optionsUrl($fixture['work_order']->id,
            ['type' => 'items', 'category_id' => $root->id, 'per_page' => 50, 'page' => 1]))->assertOk();
        $first->assertJsonPath('total', 54)->assertJsonPath('per_page', 50)->assertJsonPath('current_page', 1)->assertJsonCount(50, 'data');
        $second = $this->withToken($reader['token'])->getJson($this->optionsUrl($fixture['work_order']->id,
            ['type' => 'items', 'category_id' => $root->id, 'per_page' => 50, 'page' => 2]))->assertOk();
        $second->assertJsonPath('total', 54)->assertJsonPath('current_page', 2)->assertJsonCount(4, 'data');
        $oversized = $this->withToken($reader['token'])->getJson($this->optionsUrl($fixture['work_order']->id,
            ['type' => 'items', 'category_id' => $root->id, 'per_page' => 500]));
        $this->assertContains($oversized->status(), [200, 422]);
        if ($oversized->status() === 200) {
            $this->assertLessThanOrEqual(50, $oversized->json('per_page'));
            $this->assertLessThanOrEqual(50, count($oversized->json('data')));
        }
        $rows = collect($first->json('data'))->concat($second->json('data'));
        $this->assertCount(54, $rows->pluck('id')->unique());
        $this->assertNotContains($outside->id, $rows->pluck('id')->all());
        $this->assertSame([], array_intersect($excluded, $rows->pluck('id')->all()));
        $this->assertContains($byProduct->id, $rows->pluck('id')->all());
        foreach ($rows as $row) {
            $this->assertSame($fixture['unit']->id, $row['base_unit_id']);
            $this->assertSame($fixture['unit']->unit_name, $row['base_unit_name']);
            $this->assertSame(4, $row['base_unit_decimal_places']);
            $this->assertNoPricesOrCosts($row);
        }
        foreach ([$byProduct->item_code, $byProduct->item_name, $byProduct->spec] as $keyword) {
            $search = $this->withToken($reader['token'])->getJson($this->optionsUrl($fixture['work_order']->id,
                ['type' => 'items', 'category_id' => $root->id, 'keyword' => $keyword, 'per_page' => 20]))
                ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $byProduct->id);
            $this->assertNoPricesOrCosts($search->json());
        }
        $tree = $this->withToken($reader['token'])->getJson($this->optionsUrl($fixture['work_order']->id,
            ['type' => 'categories']))->assertOk();
        $rootNode = collect($tree->json('data'))->firstWhere('id', $root->id);
        $this->assertNotNull($rootNode);
        $this->assertSame('计划产出根类目', $rootNode['category_name']);
        $this->assertSame($child->id, $rootNode['children'][0]['id']);
        $this->assertSame($root->id, $rootNode['children'][0]['parent_id']);
        $this->withToken($reader['token'])->getJson($this->optionsUrl($fixture['work_order']->id,
            ['type' => 'items', 'category_id' => (int) DB::table('erp_item_categories')->max('id') + 1000]))->assertStatus(422);
    }

    private function fixture(): array
    {
        $actor = $this->actor(self::PERMISSIONS);
        $user = $actor['user'];
        $unit = $this->unit(4, '件');
        $output = $this->item($unit, ['item_name' => '工单原计划成品', 'item_type' => 'finished_good', 'spec' => 'WOPO-成品规格']);
        $component = $this->item($unit, ['item_name' => '工单原料', 'item_type' => 'raw_material', 'is_production_item' => false]);
        $product = Product::create(['product_code' => $this->code('P'), 'product_name' => '计划产出验证产品',
            'product_type' => 'standard', 'status' => 'enabled']);
        $sku = Sku::create(['product_id' => $product->id, 'sales_unit_id' => $unit->id,
            'sku_code' => $this->code('SKU'), 'sku_name' => '计划产出验证规格', 'order_line_type' => 'physical',
            'fulfillment_type' => 'physical', 'status' => 'enabled']);
        $operationId = DB::table('erp_production_operations')->insertGetId([
            'operation_no' => $this->code('OP'), 'operation_name' => '真实组装工序', 'status' => 'enabled',
            'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $routingId = DB::table('erp_production_routings')->insertGetId([
            'routing_no' => $this->code('RT'), 'routing_name' => '真实发布路线', 'output_item_id' => $output->id,
            'version' => 1, 'status' => 'active', 'is_default' => true, 'default_scope_key' => (string) $output->id,
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $routingOperationId = DB::table('erp_production_routing_operations')->insertGetId([
            'routing_id' => $routingId, 'operation_id' => $operationId, 'sequence' => 10,
            'output_item_id' => $output->id, 'output_mode' => 'flow_only', 'is_key_operation' => true,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $order = SalesOrder::create(['sales_order_no' => $this->code('SO'), 'customer_name' => '计划产出测试客户',
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => 'confirmed',
            'sales_user_legacy_id' => $user->legacy_id, 'created_by_legacy_id' => $user->legacy_id,
            'total_amount' => 0, 'final_receivable_amount' => 0,
            'funding_policy_snapshot' => ['policy_type' => 'full_prepay', 'shipment_requires_full_payment' => true],
            'required_delivery_date' => now()->addWeek()->toDateString()]);
        $salesLine = SalesOrderLine::create(['sales_order_id' => $order->id, 'line_no' => 1,
            'line_uuid' => (string) Str::uuid(), 'line_type' => 'physical', 'product_id' => $product->id,
            'product_name' => $product->product_name, 'sku_id' => $sku->id, 'sku_name' => $sku->sku_name,
            'item_id' => $output->id, 'item_name' => $output->item_name, 'order_qty' => 10,
            'unit_id' => $unit->id, 'unit_name_snapshot' => $unit->unit_name, 'unit_price' => 0, 'amount' => 0,
            'item_base_unit_id' => $unit->id, 'item_base_required_qty' => 10, 'is_special_customized' => false]);
        $demand = ProductionDemand::create(['requirement_no' => $this->code('D'), 'sales_order_id' => $order->id,
            'sales_order_line_id' => $salesLine->id, 'product_id' => $product->id, 'sku_id' => $sku->id,
            'item_id' => $output->id, 'production_qty' => 10, 'base_unit_id' => $unit->id,
            'base_unit_name_snapshot' => $unit->unit_name, 'allocated_qty' => 0, 'consumed_qty' => 0,
            'remaining_qty' => 10, 'closed_qty' => 0, 'requirement_status' => 'ready', 'bom_match_status' => 'matched',
            'is_active' => true, 'requirement_version' => 1, 'business_version' => 1,
            'is_ready_for_work_order' => true, 'required_delivery_date' => now()->addWeek()->toDateString()]);
        $bom = Bom::create(['bom_no' => $this->code('BOM'), 'bom_name' => '计划产出完整发布 BOM',
            'product_id' => $product->id, 'sku_id' => $sku->id, 'output_item_id' => $output->id,
            'bom_type' => 'standard', 'version' => 'V1.0', 'is_default' => true, 'status' => 'active',
            'audit_status' => 'approved', 'effective_date' => now()->subDay()->toDateString()]);
        BomItem::create(['bom_id' => $bom->id, 'line_no' => 10, 'component_item_id' => $component->id,
            'component_item_code' => $component->item_code, 'component_item_name' => $component->item_name,
            'qty' => 2, 'unit_id' => $unit->id, 'loss_rate' => 10, 'fixed_qty' => 1, 'replaceable' => false]);
        DB::table('erp_routing_operation_material_supply_rules')->insert([
            'routing_operation_id' => $routingOperationId, 'component_item_id' => $component->id,
            'target_routing_operation_id' => $routingOperationId, 'required_qty_ratio' => 1,
            'supply_mode' => 'dedicated_delivery', 'requires_delivery' => true, 'participates_in_kitting' => true,
            'allow_partial_delivery' => false, 'delivery_location_type' => 'operation_station',
            'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $fixture = $actor + ['unit' => $unit, 'output' => $output, 'component' => $component, 'demand' => $demand,
            'bom' => $bom, 'routing_id' => $routingId, 'routing_operation_id' => $routingOperationId];
        $fixture['work_order'] = $this->newDraft($fixture);
        return $fixture;
    }

    private function newDraft(array $fixture): WorkOrder
    {
        return app(WorkOrderApplicationService::class)->createDraft([
            'client_command_id' => $this->command(), 'production_demand_id' => $fixture['demand']->id,
            'expected_demand_version' => $fixture['demand']->fresh()->business_version, 'target_qty' => 5,
            'responsible_user_legacy_id' => $fixture['user']->legacy_id,
            'planned_date' => now()->addDay()->toDateString(),
        ], $fixture['user'], self::PERMISSIONS);
    }

    private function actor(array $permissions, string $scope = 'all'): array
    {
        app(RbacBootstrapService::class)->bootstrap();
        $legacyId = random_int(6100000, 6199999);
        DB::table('erp_legacy_admin_users')->insert(['legacy_id' => $legacyId, 'username' => $this->code('USER'),
            'nickname' => '工单计划产出测试人员', 'status' => 'normal', 'auth_group_names' => '[]',
            'created_at' => now(), 'updated_at' => now()]);
        $roleId = DB::table('erp_rbac_roles')->insertGetId(['code' => $this->code('ROLE'), 'name' => '计划产出测试角色',
            'data_scope' => $scope, 'enabled' => true, 'created_at' => now(), 'updated_at' => now()]);
        foreach (DB::table('erp_rbac_permissions')->whereIn('code', $permissions)->pluck('id') as $permissionId) {
            DB::table('erp_rbac_role_permissions')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        DB::table('erp_rbac_user_roles')->insert(['user_legacy_id' => $legacyId, 'role_id' => $roleId]);
        $token = $this->code('TOKEN');
        DB::table('erp_auth_tokens')->insert(['user_legacy_id' => $legacyId, 'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addHour(), 'created_at' => now(), 'updated_at' => now()]);
        return ['user' => DB::table('erp_legacy_admin_users')->where('legacy_id', $legacyId)->first(),
            'role_id' => $roleId, 'token' => $token];
    }

    private function unit(int $precision, string $name): Unit
    {
        return Unit::create(['unit_code' => $this->code('U'), 'unit_name' => $name, 'unit_type' => 'quantity',
            'decimal_places' => $precision, 'is_base' => true, 'status' => 'enabled']);
    }

    private function item(Unit $unit, array $attributes = []): Item
    {
        return Item::create(array_replace(['item_code' => $this->code('ITEM'), 'item_name' => '真实计划产出物料',
            'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true,
            'is_production_item' => true, 'production_execution_mode' => 'unit', 'status' => 'enabled',
            'standard_cost' => '17.50', 'last_purchase_price' => '16.25'], $attributes));
    }

    private function line(Item $item, string $quantity, string $role = 'product', ?string $remark = null): array
    {
        return ['line_uuid' => (string) Str::uuid(), 'item_id' => $item->id, 'planned_base_qty' => $quantity,
            'output_role' => $role, 'remark' => $remark];
    }

    private function payload(WorkOrder $workOrder, array $outputs): array
    {
        return ['client_command_id' => $this->command(), 'expected_version' => (int) $workOrder->business_version,
            'outputs' => $outputs];
    }

    private function readPlan(array $fixture): TestResponse
    {
        return $this->withToken($fixture['token'])->getJson($this->planUrl($fixture['work_order']->id));
    }

    private function savePlan(array $fixture, array $payload): TestResponse
    {
        return $this->withToken($fixture['token'])->putJson($this->planUrl($fixture['work_order']->id), $payload);
    }

    private function planUrl(int $id): string
    {
        return '/api/v1/erp/production/work-orders/'.$id.'/planned-outputs';
    }

    private function optionsUrl(int $id, array $filters): string
    {
        return '/api/v1/erp/production/work-orders/'.$id.'/planned-output-options?'.http_build_query($filters);
    }

    private function versions(int $id)
    {
        return DB::table(self::VERSIONS)->where('work_order_id', $id);
    }

    private function audits(int $id)
    {
        return DB::table('erp_work_order_status_logs')->where('work_order_id', $id);
    }

    private function legacyIdentity(WorkOrder $workOrder): array
    {
        return $workOrder->only(['output_item_id', 'effective_output_item_id_snapshot', 'target_qty', 'target_base_qty',
            'base_unit_id', 'target_unit_id', 'production_routing_id', 'routing_version_snapshot', 'routing_snapshot',
            'bom_id', 'bom_version_id', 'bom_version', 'bom_snapshot', 'production_demand_id', 'source_type', 'source_id']);
    }

    private function assertQuantity(string $expected, mixed $actual): void
    {
        $this->assertIsString($actual);
        $this->assertSame(0, bccomp($expected, $actual, 8));
    }

    private function assertNoPricesOrCosts(array $payload): void
    {
        foreach ($payload as $key => $value) {
            if (is_string($key)) $this->assertDoesNotMatchRegularExpression('/cost|price|amount|margin/i', $key);
            if (is_array($value)) $this->assertNoPricesOrCosts($value);
        }
    }

    private function code(string $prefix): string { return 'WOPO-'.$prefix.'-'.strtoupper(Str::random(12)); }
    private function command(): string { return 'wopo-'.Str::uuid(); }
}
