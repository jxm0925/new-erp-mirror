<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\ApprovalTask;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryBatch;
use App\Models\Erp\InventoryReservation;
use App\Models\Erp\Item;
use App\Models\Erp\Location;
use App\Models\Erp\Product;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderChange;
use App\Models\Erp\SalesOrderChangeCandidate;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\SalesOrderVersion;
use App\Models\Erp\Sku;
use App\Models\Erp\SkuItemRelation;
use App\Models\Erp\Unit;
use App\Models\Erp\Warehouse;
use App\Services\Erp\ApprovalIntegrations\SalesOrderChangeApprovalIntegration;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\SalesOrderEditImpactService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Mockery\MockInterface;
use Tests\TestCase;

class SalesOrderEditClosureV1Test extends TestCase
{
    use DatabaseTransactions;

    public function test_global_sku_search_supports_compact_cross_field_alias_and_returns_paged_availability(): void
    {
        $fixture = $this->fixture(false, '压力罐', '55L 厚2.0', '稳压罐');
        $this->mockPermissions(['sales_order.view']);

        $response = $this->getJson('/api/v1/erp/sales/orders/skus/search?keyword='.urlencode('压力罐55L厚2.0').'&per_page=10');

        $response->assertOk()->assertJsonPath('per_page', 10)->assertJsonCount(1, 'data');
        $this->assertSame($fixture['sku']->id, $response->json('data.0.id'));
        $this->assertSame('台', $response->json('data.0.sales_unit.unit_name'));
        $this->assertSame(12, (int) $response->json('data.0.available_stock'));

        $this->getJson('/api/v1/erp/sales/orders/skus/search?keyword='.urlencode('稳压罐').'&per_page=1')
            ->assertOk()->assertJsonPath('per_page', 1);
    }

    public function test_payment_account_only_change_is_immediate_and_persists_structured_diff(): void
    {
        $fixture = $this->fixture();
        $service = app(SalesOrderEditImpactService::class);
        $payload = $this->payload($fixture, ['pay_type' => '企业微信']);

        $preview = $service->preview($fixture['order']->fresh(['lines', 'shipments', 'salesReturns']), $payload);
        $this->assertFalse($preview['requires_approval']);
        $this->assertSame(['INFO'], $preview['diffs'][0]['impact_types']);

        $result = $service->submit($fixture['order']->id, $payload, '销售闭环验收员');
        $this->assertSame('immediate', $result['mode']);
        $this->assertSame('企业微信', $fixture['order']->fresh()->pay_type);
        $change = SalesOrderChange::where('sales_order_id', $fixture['order']->id)->latest('id')->firstOrFail();
        $this->assertTrue($change->immediate_effect);
        $this->assertSame('pay_type', $change->structured_diffs[0]['semantic_key']);
        $this->assertSame('销售闭环验收员', $change->structured_diffs[0]['edited_by']);
    }

    public function test_price_change_creates_candidate_and_does_not_touch_official_order(): void
    {
        $fixture = $this->fixture();
        $this->mockApprovalSubmit();
        $payload = $this->payload($fixture, [], ['unit_price' => 125]);

        $result = app(SalesOrderEditImpactService::class)->submit($fixture['order']->id, $payload + ['change_reason' => '客户议价后调整单价'], '销售闭环验收员');

        $this->assertSame('candidate', $result['mode']);
        $this->assertSame(100.0, (float) $fixture['line']->fresh()->unit_price);
        $candidate = SalesOrderChangeCandidate::findOrFail($result['candidate']->id);
        $this->assertSame('PENDING_APPROVAL', $candidate->candidate_status);
        $this->assertSame(['business'], $candidate->approval_requirements);
        $this->assertSame('unit_price', $candidate->structured_diffs[0]['semantic_key']);
        $this->assertFalse($candidate->structured_diffs[0]['immediate_effect']);
    }

    public function test_rejected_candidate_keeps_official_order_unchanged(): void
    {
        $fixture = $this->fixture();
        $candidate = $this->candidate($fixture, ['unit_price' => 125]);

        $result = app(SalesOrderEditImpactService::class)->decide($candidate->id, 'business', false, '业务审核员', '客户未确认议价');

        $this->assertSame('REJECTED', $result->candidate_status);
        $this->assertSame(100.0, (float) $fixture['line']->fresh()->unit_price);
        $this->assertDatabaseMissing('erp_sales_order_changes', ['sales_order_id' => $fixture['order']->id, 'candidate_id' => $candidate->id]);
    }

    public function test_approved_candidate_activates_once_and_writes_versioned_diff(): void
    {
        $fixture = $this->fixture();
        $candidate = $this->candidate($fixture, ['unit_price' => 125]);

        $result = app(SalesOrderEditImpactService::class)->decide($candidate->id, 'business', true, '业务审核员', '价格调整通过');

        $this->assertSame('APPROVED', $result->candidate_status);
        $this->assertSame(125.0, (float) $fixture['line']->fresh()->unit_price);
        $this->assertDatabaseHas('erp_sales_order_changes', ['sales_order_id' => $fixture['order']->id, 'candidate_id' => $candidate->id, 'immediate_effect' => false]);
        $this->assertDatabaseHas('erp_sales_order_versions', ['sales_order_id' => $fixture['order']->id, 'candidate_id' => $candidate->id, 'version_no' => $candidate->candidate_version]);
    }

    public function test_candidate_conflicts_when_official_version_advanced(): void
    {
        $fixture = $this->fixture();
        $candidate = $this->candidate($fixture, ['unit_price' => 125]);
        SalesOrderVersion::create([
            'sales_order_id' => $fixture['order']->id, 'version_no' => 2,
            'change_type' => 'concurrent_change', 'operator' => '其他用户',
        ]);

        $result = app(SalesOrderEditImpactService::class)->decide($candidate->id, 'business', true, '业务审核员', '通过');

        $this->assertSame('CONFLICTED', $result->candidate_status);
        $this->assertNotEmpty($result->conflict_reason);
        $this->assertSame(100.0, (float) $fixture['line']->fresh()->unit_price);
    }

    public function test_quantity_change_with_active_fulfillment_waits_for_approval_then_releases_old_reservation(): void
    {
        $fixture = $this->fixture(true);
        $this->mockApprovalSubmit();
        $payload = $this->payload($fixture, [], ['order_qty' => 8]) + ['change_reason' => '客户调整采购数量'];
        $candidate = app(SalesOrderEditImpactService::class)->submit($fixture['order']->id, $payload, '销售闭环验收员')['candidate'];

        $this->assertSame('active', $fixture['reservation']->fresh()->reservation_status);
        $this->assertSame(10.0, (float) $fixture['line']->fresh()->order_qty);
        $this->assertContains('fulfillment', $candidate->approval_requirements);

        $approved = app(SalesOrderEditImpactService::class)->decide($candidate->id, 'fulfillment', true, '履约审核员', '库存与交付可调整');
        $this->assertSame('APPROVED', $approved->candidate_status);
        $this->assertSame(8.0, (float) $fixture['line']->fresh()->order_qty);
        $this->assertSame('released', $fixture['reservation']->fresh()->reservation_status);
        $this->assertSame('superseded', $fixture['fulfillment']->fresh()->demand_status);
    }

    public function test_funding_rule_change_requires_finance_and_fulfillment_while_official_snapshot_stays_frozen(): void
    {
        $fixture = $this->fixture();
        $this->mockApprovalSubmit();
        $newTerms = ['policy_type' => 'installment_contract', 'production_threshold_type' => 'ratio', 'production_threshold_value' => '0.3'];
        $payload = $this->payload($fixture, ['payment_terms_snapshot' => $newTerms]) + ['change_reason' => '调整为定金加尾款'];

        $candidate = app(SalesOrderEditImpactService::class)->submit($fixture['order']->id, $payload, '销售闭环验收员')['candidate'];

        $this->assertEqualsCanonicalizing(['finance', 'fulfillment'], $candidate->approval_requirements);
        $this->assertSame('full_prepay', data_get($fixture['order']->fresh()->payment_terms_snapshot, 'policy_type'));
    }

    public function test_funding_candidate_stays_frozen_until_all_required_approvals_then_activates(): void
    {
        $fixture = $this->fixture();
        $this->mockApprovalSubmit();
        $newTerms = [
            'policy_type' => 'installment_contract',
            'production_threshold_type' => 'ratio',
            'production_threshold_value' => '0.3',
        ];
        $payload = $this->payload($fixture, [
            'payment_terms_snapshot' => $newTerms,
            'funding_policy_snapshot' => $newTerms,
        ]) + ['change_reason' => 'Change funding policy'];

        $candidate = app(SalesOrderEditImpactService::class)->submit(
            $fixture['order']->id,
            $payload,
            'sales_editor'
        )['candidate'];

        $this->assertEqualsCanonicalizing(['finance', 'fulfillment'], $candidate->approval_requirements);
        $this->assertSame('full_prepay', data_get($fixture['order']->fresh()->payment_terms_snapshot, 'policy_type'));

        $afterFinance = app(SalesOrderEditImpactService::class)->decide(
            $candidate->id,
            'finance',
            true,
            'finance_approver',
            'Financial terms accepted'
        );
        $this->assertSame('PENDING_APPROVAL', $afterFinance->candidate_status);
        $this->assertSame('full_prepay', data_get($fixture['order']->fresh()->payment_terms_snapshot, 'policy_type'));

        $afterFulfillment = app(SalesOrderEditImpactService::class)->decide(
            $candidate->id,
            'fulfillment',
            true,
            'fulfillment_approver',
            'Fulfillment gate accepted'
        );
        $this->assertSame('APPROVED', $afterFulfillment->candidate_status);
        $this->assertSame('installment_contract', data_get($fixture['order']->fresh()->payment_terms_snapshot, 'policy_type'));
        $this->assertSame('installment_contract', data_get($fixture['order']->fresh()->funding_policy_snapshot, 'policy_type'));
    }

    public function test_sku_replacement_is_a_candidate_and_rejection_keeps_the_official_sku(): void
    {
        $fixture = $this->fixture();
        $replacement = $this->fixture(false, 'Replacement product', 'Replacement spec', 'replacement alias');
        $this->mockApprovalSubmit();

        $payload = $this->payload($fixture, ['remark' => 'Information change travels with the candidate']);
        $payload['lines'][0]['product_id'] = $replacement['product']->id;
        $payload['lines'][0]['product_name'] = $replacement['product']->product_name;
        $payload['lines'][0]['sku_id'] = $replacement['sku']->id;
        $payload['lines'][0]['sku_name'] = $replacement['sku']->sku_name;
        $payload['lines'][0]['unit_price'] = 150;
        $payload['change_reason'] = 'Replace SKU after customer confirmation';

        $candidate = app(SalesOrderEditImpactService::class)->submit(
            $fixture['order']->id,
            $payload,
            'sales_editor'
        )['candidate']->fresh('approvals');

        $this->assertSame('PENDING_APPROVAL', $candidate->candidate_status);
        $this->assertEqualsCanonicalizing(['business', 'fulfillment'], $candidate->approval_requirements);
        $this->assertEqualsCanonicalizing(
            ['remark', 'product_id', 'sku_id', 'unit_price'],
            collect($candidate->structured_diffs)->pluck('semantic_key')->all()
        );
        $this->assertSame($fixture['sku']->id, $fixture['line']->fresh()->sku_id);
        $this->assertNotSame('Information change travels with the candidate', $fixture['order']->fresh()->remark);

        $rejected = app(SalesOrderEditImpactService::class)->decide(
            $candidate->id,
            'business',
            false,
            'business_approver',
            'Customer evidence missing'
        );

        $this->assertSame('REJECTED', $rejected->candidate_status);
        $this->assertSame($fixture['sku']->id, $fixture['line']->fresh()->sku_id);
        $this->assertSame(100.0, (float) $fixture['line']->fresh()->unit_price);
        $this->assertNotSame('Information change travels with the candidate', $fixture['order']->fresh()->remark);
    }

    public function test_info_only_change_returns_three_neutral_approval_descriptions(): void
    {
        $fixture = $this->fixture();
        $impact = app(SalesOrderEditImpactService::class)->preview(
            $fixture['order']->fresh(['lines', 'shipments', 'salesReturns']),
            $this->payload($fixture, ['remark' => '仅更新内部备注'])
        );

        $this->assertFalse($impact['requires_approval']);
        $this->assertSame([], $impact['required_approval_types']);
        $this->assertSame('本次修改未触发业务审核条件。', $impact['approval_reasons']['business']['description']);
        $this->assertSame('本次修改未触发财务审核条件。', $impact['approval_reasons']['finance']['description']);
        $this->assertSame('本次修改未触发库存/交付履约复核条件。', $impact['approval_reasons']['fulfillment']['description']);
    }

    public function test_price_change_returns_only_real_business_approval_reason(): void
    {
        $fixture = $this->fixture();
        $impact = app(SalesOrderEditImpactService::class)->preview(
            $fixture['order']->fresh(['lines', 'shipments', 'salesReturns']),
            $this->payload($fixture, [], ['unit_price' => 125])
        );

        $this->assertSame(['business'], $impact['required_approval_types']);
        $this->assertStringContainsString('销售单价发生调整', $impact['approval_reasons']['business']['description']);
        $this->assertSame('本次修改未触发财务审核条件。', $impact['approval_reasons']['finance']['description']);
        $this->assertSame('本次修改未触发库存/交付履约复核条件。', $impact['approval_reasons']['fulfillment']['description']);
    }

    public function test_payment_rule_change_returns_finance_and_fulfillment_reasons(): void
    {
        $fixture = $this->fixture();
        $impact = app(SalesOrderEditImpactService::class)->preview(
            $fixture['order']->fresh(['lines', 'shipments', 'salesReturns']),
            $this->payload($fixture, ['payment_terms_snapshot' => ['policy_type' => 'installment_contract']])
        );

        $this->assertEqualsCanonicalizing(['finance', 'fulfillment'], $impact['required_approval_types']);
        $this->assertStringContainsString('资金门禁和应收口径', $impact['approval_reasons']['finance']['description']);
        $this->assertStringContainsString('生产或发货资金门槛', $impact['approval_reasons']['fulfillment']['description']);
        $this->assertSame('本次修改未触发业务审核条件。', $impact['approval_reasons']['business']['description']);
    }

    public function test_delivery_change_without_operational_facts_stays_info_only(): void
    {
        $fixture = $this->fixture(false);
        $impact = app(SalesOrderEditImpactService::class)->preview(
            $fixture['order']->fresh(['lines', 'shipments', 'salesReturns']),
            $this->payload($fixture, ['required_delivery_date' => '2026-09-20'])
        );

        $this->assertFalse($impact['requires_approval']);
        $this->assertSame(['INFO'], $impact['diffs'][0]['impact_types']);
        $this->assertSame('本次修改未触发库存/交付履约复核条件。', $impact['approval_reasons']['fulfillment']['description']);
    }

    public function test_delivery_change_with_operational_facts_returns_fulfillment_reason(): void
    {
        $fixture = $this->fixture(true);
        $impact = app(SalesOrderEditImpactService::class)->preview(
            $fixture['order']->fresh(['lines', 'shipments', 'salesReturns']),
            $this->payload($fixture, ['required_delivery_date' => '2026-09-20'])
        );

        $this->assertSame(['fulfillment'], $impact['required_approval_types']);
        $this->assertStringContainsString('现有库存或交付履约计划', $impact['approval_reasons']['fulfillment']['description']);
    }

    public function test_sku_change_returns_business_and_fulfillment_reasons_without_fake_work_order_claim(): void
    {
        $fixture = $this->fixture();
        $replacement = $this->fixture(false, '替换产品', '升级规格', '替换别名');
        $payload = $this->payload($fixture);
        $payload['lines'][0]['product_id'] = $replacement['product']->id;
        $payload['lines'][0]['product_name'] = $replacement['product']->product_name;
        $payload['lines'][0]['sku_id'] = $replacement['sku']->id;
        $payload['lines'][0]['sku_name'] = $replacement['sku']->sku_name;

        $impact = app(SalesOrderEditImpactService::class)->preview(
            $fixture['order']->fresh(['lines', 'shipments', 'salesReturns']),
            $payload
        );

        $this->assertEqualsCanonicalizing(['business', 'fulfillment'], $impact['required_approval_types']);
        $this->assertStringContainsString('SKU 身份发生变化', $impact['approval_reasons']['business']['description']);
        $this->assertStringContainsString('库存和交付履约', $impact['approval_reasons']['fulfillment']['description']);
        $this->assertStringNotContainsString('工单', json_encode($impact['approval_reasons'], JSON_UNESCAPED_UNICODE));
    }

    public function test_multiple_changes_keep_deduplicated_reasons_in_stable_order(): void
    {
        $fixture = $this->fixture(true);
        $impact = app(SalesOrderEditImpactService::class)->preview(
            $fixture['order']->fresh(['lines', 'shipments', 'salesReturns']),
            $this->payload(
                $fixture,
                ['payment_terms_snapshot' => ['policy_type' => 'installment_contract'], 'required_delivery_date' => '2026-09-20'],
                ['unit_price' => 125, 'order_qty' => 8]
            )
        );

        $this->assertEqualsCanonicalizing(['business', 'finance', 'fulfillment'], $impact['required_approval_types']);
        foreach ($impact['approval_reasons'] as $reasonGroup) {
            $this->assertSame(array_values(array_unique($reasonGroup['reasons'])), $reasonGroup['reasons']);
        }
        $this->assertStringContainsString('销售单价发生调整', $impact['approval_reasons']['business']['reasons'][0]);
        $this->assertStringContainsString('付款规则发生变化', $impact['approval_reasons']['finance']['reasons'][0]);
    }

    public function test_submit_recomputes_and_freezes_approval_reasons_instead_of_trusting_frontend(): void
    {
        $fixture = $this->fixture();
        $this->mockApprovalSubmit();
        $payload = $this->payload($fixture, [], ['unit_price' => 125]) + [
            'change_reason' => '销售价格调整',
            'required_approval_types' => ['finance'],
            'approval_reasons' => ['finance' => ['description' => '前端伪造原因']],
        ];

        $candidate = app(SalesOrderEditImpactService::class)->submit(
            $fixture['order']->id,
            $payload,
            '销售闭环验收员'
        )['candidate']->fresh();

        $this->assertSame(['business'], $candidate->approval_requirements);
        $this->assertStringContainsString('销售单价发生调整', $candidate->approval_reasons['business']['description']);
        $this->assertStringNotContainsString('前端伪造原因', json_encode($candidate->approval_reasons, JSON_UNESCAPED_UNICODE));
        $this->assertSame($candidate->approval_reasons, data_get($candidate->impact_summary, 'approval_reasons'));
    }

    private function candidate(array $fixture, array $lineOverrides): SalesOrderChangeCandidate
    {
        $this->mockApprovalSubmit();
        return app(SalesOrderEditImpactService::class)->submit(
            $fixture['order']->id,
            $this->payload($fixture, [], $lineOverrides) + ['change_reason' => '候选版本验收'],
            '销售闭环验收员'
        )['candidate']->fresh('approvals');
    }

    private function payload(array $fixture, array $header = [], array $line = []): array
    {
        return $header + ['lines' => [[
            'id' => $fixture['line']->id,
            'product_id' => $fixture['product']->id,
            'product_name' => $fixture['product']->product_name,
            'sku_id' => $fixture['sku']->id,
            'sku_name' => $fixture['sku']->sku_name,
            'order_qty' => $line['order_qty'] ?? (float) $fixture['line']->order_qty,
            'unit_price' => $line['unit_price'] ?? (float) $fixture['line']->unit_price,
            'discount_rate' => $line['discount_rate'] ?? (float) $fixture['line']->discount_rate,
            'tax_rate' => $line['tax_rate'] ?? (float) $fixture['line']->tax_rate,
            'price_tax_mode' => $line['price_tax_mode'] ?? $fixture['line']->price_tax_mode,
            'fulfillment_method' => $line['fulfillment_method'] ?? 'auto',
            'electric' => $line['electric'] ?? null,
            'need_pump' => $line['need_pump'] ?? null,
            'is_customized' => $line['is_customized'] ?? false,
            'is_special_customized' => $line['is_special_customized'] ?? false,
            'configuration_snapshot' => $line['configuration_snapshot'] ?? [],
            'remark' => $line['remark'] ?? null,
        ]], 'deleted_line_ids' => []];
    }

    private function fixture(bool $operational = false, string $productName = '销售闭环产品', string $spec = '标准型', ?string $alias = null): array
    {
        $suffix = strtoupper(substr(uniqid(), -8));
        $unit = Unit::create(['unit_code' => 'SOE-U-'.$suffix, 'unit_name' => '台', 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $product = Product::create(['product_code' => 'SOE-P-'.$suffix, 'product_name' => $productName, 'product_type' => 'standard', 'model' => 'P55', 'status' => 'enabled']);
        $sku = Sku::create([
            'product_id' => $product->id, 'sales_unit_id' => $unit->id,
            'sku_code' => 'SOE-SKU-'.$suffix, 'sku_name' => $productName.' '.$spec,
            'spec_text' => $spec, 'search_aliases' => $alias, 'search_keywords' => '增压 稳压',
            'sale_price' => 100, 'default_tax_rate' => 0.13, 'default_price_tax_mode' => 'tax_inclusive',
            'order_line_type' => 'physical', 'fulfillment_type' => 'physical', 'production_policy' => 'stock',
            'is_sale_item' => true, 'is_need_production' => false, 'is_need_bom' => false, 'status' => 'enabled',
        ]);
        $item = Item::create(['item_code' => 'SOE-I-'.$suffix, 'item_name' => '销售闭环Item', 'item_type' => 'finished_good', 'unit_id' => $unit->id, 'is_stock_item' => true, 'status' => 'enabled']);
        SkuItemRelation::create(['sku_id' => $sku->id, 'item_id' => $item->id, 'relation_type' => 'primary', 'qty' => 1, 'unit_id' => $unit->id, 'is_primary' => true, 'status' => 'active', 'effective_at' => now()]);
        $order = SalesOrder::create([
            'sales_order_no' => 'SOE-O-'.$suffix, 'customer_name' => '销售闭环客户', 'pay_type' => '企业支付宝',
            'payment_terms_snapshot' => ['policy_type' => 'full_prepay'],
            'funding_policy_snapshot' => ['policy_type' => 'full_prepay', 'production_threshold_type' => 'ratio', 'production_threshold_value' => '1'],
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'production_confirm_status' => $operational ? 'confirmed' : 'pending',
            'shipment_status' => 'not_shipped', 'fulfillment_status' => $operational ? 'confirmed' : 'pending',
            'total_amount' => 1000, 'final_receivable_amount' => 1000,
        ]);
        $line = SalesOrderLine::create([
            'sales_order_id' => $order->id, 'line_no' => 1, 'product_id' => $product->id, 'product_name' => $product->product_name,
            'sku_id' => $sku->id, 'sku_name' => $sku->sku_name, 'item_id' => $item->id, 'item_name' => $item->item_name,
            'line_type' => 'physical', 'order_qty' => 10, 'unit_id' => $unit->id, 'unit_name_snapshot' => '台',
            'unit_price' => 100, 'amount' => 1000, 'price_tax_mode' => 'tax_inclusive', 'discount_rate' => 1, 'tax_rate' => 0,
            'amount_excl_tax' => 1000, 'tax_amount' => 0, 'amount_incl_tax' => 1000,
            'fulfillment_factor_snapshot' => 1, 'item_base_unit_id' => $unit->id, 'item_base_required_qty' => 10,
            'fulfillment_method' => 'auto', 'fulfillment_type' => $operational ? 'inventory' : 'pending', 'line_status' => 'open',
            'configuration_snapshot' => [],
        ]);
        SalesOrderVersion::create(['sales_order_id' => $order->id, 'version_no' => 1, 'change_type' => 'confirmed', 'operator' => '系统']);

        $result = compact('unit', 'product', 'sku', 'item', 'order', 'line');
        $warehouse = Warehouse::create(['warehouse_code' => 'SOE-W-'.$suffix, 'warehouse_name' => '销售闭环仓库', 'status' => 'enabled']);
        $location = Location::create(['warehouse_id' => $warehouse->id, 'location_code' => 'SOE-L-'.$suffix, 'location_name' => '销售闭环库位', 'status' => 'enabled']);
        $balance = InventoryBalance::create([
            'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
            'batch_no' => 'SOE-B-'.$suffix, 'unit_id' => $unit->id,
            'quantity_on_hand' => 12, 'quantity_locked' => $operational ? 10 : 0,
            'quantity_available' => $operational ? 2 : 12, 'quantity_defective' => 0, 'quantity_pending' => 0,
            'inventory_value' => 1200, 'average_unit_cost' => 100,
        ]);
        InventoryBatch::create(['item_id' => $item->id, 'batch_no' => $balance->batch_no, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'status' => 'enabled']);
        $result['balance'] = $balance;

        if ($operational) {
            $result['fulfillment'] = SalesOrderFulfillment::create([
                'sales_order_id' => $order->id, 'sales_order_line_id' => $line->id, 'fulfillment_type' => 'inventory', 'fulfillment_qty' => 10,
                'sales_qty' => 10, 'fulfillment_factor_snapshot' => 1, 'item_base_qty' => 10, 'base_unit_id' => $unit->id,
                'item_id' => $item->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id, 'batch_no' => $balance->batch_no,
                'inventory_balance_id' => $balance->id, 'reservation_status' => 'reserved', 'demand_status' => 'confirmed',
            ]);
            $result['reservation'] = InventoryReservation::create([
                'source_type' => InventoryReservation::SOURCE_SALES_ORDER, 'source_order_id' => $order->id, 'source_order_line_id' => $line->id,
                'item_id' => $item->id, 'inventory_balance_id' => $balance->id, 'warehouse_id' => $warehouse->id, 'location_id' => $location->id,
                'batch_no' => $balance->batch_no, 'reserved_qty' => 10, 'reservation_status' => 'active', 'reserved_at' => now(),
                'idempotency_key' => 'soe-'.$order->id,
            ]);
        }
        return $result;
    }

    private function mockApprovalSubmit(): void
    {
        $this->mock(SalesOrderChangeApprovalIntegration::class, function (MockInterface $mock): void {
            $mock->shouldReceive('submit')->once()->andReturn(new ApprovalTask());
        });
    }

    private function mockPermissions(array $permissions): void
    {
        $user = (object) ['legacy_id' => 999, 'username' => 'sales_edit_tester', 'nickname' => '销售闭环验收员'];
        $this->mock(AuthContextService::class, function (MockInterface $mock) use ($user, $permissions): void {
            $mock->shouldReceive('currentUser')->andReturn($user);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturn($permissions);
        });
    }
}
