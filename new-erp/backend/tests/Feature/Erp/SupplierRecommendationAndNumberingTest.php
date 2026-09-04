<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\DocumentNumberReservation;
use App\Models\Erp\Item;
use App\Models\Erp\ItemCategory;
use App\Models\Erp\ItemSupplierPrice;
use App\Models\Erp\Supplier;
use App\Models\Erp\SupplierCategoryCapability;
use App\Models\Erp\SupplierItemStat;
use App\Models\Erp\Unit;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\PurchaseSupplierRecommendationService;
use App\Services\Erp\SupplierCapabilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class SupplierRecommendationAndNumberingTest extends TestCase
{
    use DatabaseTransactions;

    public function test_number_reservation_is_idempotent_unique_and_consumable(): void
    {
        $service = app(DocumentNumberService::class);
        $session = (string) Str::uuid();
        $first = $service->reserve('supplier', $session, 1, '/master/suppliers/create');
        $same = $service->reserve('supplier', $session, 1, '/master/suppliers/create');

        $this->assertSame($first->document_no, $same->document_no);
        $this->assertSame($first->reservation_token, $same->reservation_token);

        $numbers = collect(range(1, 100))->map(fn () => $service
            ->reserve('purchase_order', (string) Str::uuid(), 1, '/purchase/orders/create')
            ->document_no);
        $this->assertCount(100, $numbers->unique());

        $used = $service->consume($first->reservation_token, 'supplier', $first->document_no, 1, 'suppliers', 999);
        $this->assertSame('used', $used->status);
        $this->assertSame(999, (int) $used->business_id);
        $this->assertSame('used', $service->consume($first->reservation_token, 'supplier', $first->document_no, 1, 'suppliers', 999)->status);
    }

    public function test_expired_number_is_voided_and_never_reused(): void
    {
        $service = app(DocumentNumberService::class);
        $reservation = $service->reserve('purchase_plan', (string) Str::uuid(), 1);
        $reservation->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, $service->expire());
        $this->assertSame('expired', $reservation->fresh()->status);

        $next = $service->reserve('purchase_plan', (string) Str::uuid(), 1);
        $this->assertNotSame($reservation->document_no, $next->document_no);
    }

    public function test_valid_quote_wins_and_ineligible_supplier_is_excluded(): void
    {
        [$item, $unit, $category] = $this->itemFixture();
        $best = $this->supplier('SUP-BEST', '有效报价供应商');
        $history = $this->supplier('SUP-HISTORY', '历史采购供应商');
        $blocked = $this->supplier('SUP-BLOCKED', '黑名单供应商', ['is_blacklisted' => true]);

        $capabilities = app(SupplierCapabilityService::class);
        foreach ([$best, $history, $blocked] as $supplier) {
            $capabilities->saveItemRelation($supplier->id, [
                'item_id' => $item->id,
                'capability_source' => 'manual_confirmed',
                'relation_status' => 'active',
                'change_reason' => '测试能力',
            ], 1);
        }

        foreach ([[$best, 8], [$blocked, 1]] as [$supplier, $price]) {
            ItemSupplierPrice::create([
                'item_id' => $item->id,
                'supplier_id' => $supplier->id,
                'unit_id' => $unit->id,
                'price' => $price,
                'currency' => 'CNY',
                'tax_mode' => 'tax_included',
                'tax_rate' => 13,
                'lead_time_days' => 2,
                'min_order_qty' => 1,
                'valid_from' => now()->subDay(),
                'valid_until' => now()->addMonth(),
                'quote_status' => 'approved',
                'status' => 'enabled',
            ]);
        }

        $result = app(PurchaseSupplierRecommendationService::class)
            ->recommend($item, 10, $unit->id, now()->addWeek()->toDateString(), 'CNY', 'tax_included');

        $this->assertSame($best->id, $result['recommended_supplier_id']);
        $this->assertSame('VALID_QUOTE_BEST_PRICE', collect($result['candidates'])->firstWhere('supplier_id', $best->id)['recommendation_basis']);
        $this->assertNotContains($blocked->id, collect($result['candidates'])->pluck('supplier_id'));
    }

    public function test_category_candidate_is_visible_but_never_auto_selected(): void
    {
        [$item, $unit, $category] = $this->itemFixture();
        $supplier = $this->supplier('SUP-CATEGORY', '品类候选供应商');
        SupplierCategoryCapability::create([
            'supplier_id' => $supplier->id,
            'item_category_id' => $category->id,
            'status' => 'active',
            'effective_at' => now(),
        ]);

        $result = app(PurchaseSupplierRecommendationService::class)
            ->recommend($item, 10, $unit->id, null, 'CNY', 'tax_included');
        $candidate = $result['candidates'][0];

        $this->assertSame('CATEGORY_CANDIDATE', $candidate['recommendation_basis']);
        $this->assertFalse($candidate['auto_selectable']);
        $this->assertFalse($candidate['recommended']);
        $this->assertNull($result['recommended_supplier_id']);
    }

    public function test_weighted_score_can_prefer_stable_supplier_over_slightly_cheaper_supplier(): void
    {
        [$item, $unit] = $this->itemFixture();
        $cheap = $this->supplier('SUP-CHEAP-RISK', '低价高风险供应商');
        $stable = $this->supplier('SUP-STABLE', '稳定供应商');
        $capabilities = app(SupplierCapabilityService::class);

        foreach ([$cheap, $stable] as $supplier) {
            $capabilities->saveItemRelation($supplier->id, [
                'item_id' => $item->id,
                'capability_source' => 'manual_confirmed',
                'relation_status' => 'active',
                'change_reason' => '评分算法测试',
            ], 1);
        }
        foreach ([[$cheap, 9.5], [$stable, 10]] as [$supplier, $price]) {
            ItemSupplierPrice::create([
                'item_id' => $item->id,
                'supplier_id' => $supplier->id,
                'unit_id' => $unit->id,
                'price' => $price,
                'currency' => 'CNY',
                'tax_mode' => 'tax_included',
                'tax_rate' => 13,
                'lead_time_days' => 2,
                'min_order_qty' => 1,
                'valid_from' => now()->subDay(),
                'valid_until' => now()->addMonth(),
                'quote_status' => 'approved',
                'status' => 'enabled',
            ]);
        }
        SupplierItemStat::create(['supplier_id' => $cheap->id, 'item_id' => $item->id, 'qualified_rate' => .5, 'on_time_rate' => .5, 'return_rate' => .3]);
        SupplierItemStat::create(['supplier_id' => $stable->id, 'item_id' => $item->id, 'qualified_rate' => .99, 'on_time_rate' => 1, 'return_rate' => 0]);

        $result = app(PurchaseSupplierRecommendationService::class)
            ->recommend($item, 10, $unit->id, now()->addWeek()->toDateString(), 'CNY', 'tax_included');
        $stableRow = collect($result['candidates'])->firstWhere('supplier_id', $stable->id);

        $this->assertSame($stable->id, $result['recommended_supplier_id']);
        $this->assertGreaterThan(collect($result['candidates'])->firstWhere('supplier_id', $cheap->id)['recommendation_score'], $stableRow['recommendation_score']);
        $this->assertArrayHasKey('score_details', $stableRow);
        $this->assertStringContainsString('综合评分', $stableRow['recommendation_explanation']);
    }

    public function test_specific_item_capability_keeps_history_when_default_changes(): void
    {
        [$item] = $this->itemFixture();
        $first = $this->supplier('SUP-ONE', '供应商一');
        $second = $this->supplier('SUP-TWO', '供应商二');
        $service = app(SupplierCapabilityService::class);

        $old = $service->saveItemRelation($first->id, [
            'item_id' => $item->id,
            'capability_source' => 'manual_confirmed',
            'is_default' => true,
            'change_reason' => '首次设置',
        ], 1);
        $current = $service->saveItemRelation($second->id, [
            'item_id' => $item->id,
            'capability_source' => 'manual_confirmed',
            'is_default' => true,
            'change_reason' => '供应能力优化',
        ], 1);

        $this->assertSame('inactive', $old->fresh()->relation_status);
        $this->assertFalse($old->fresh()->is_default);
        $this->assertTrue($current->fresh()->is_default);
        $this->assertSame($second->id, (int) $item->fresh()->default_supplier_id);
        $this->assertDatabaseHas('erp_supplier_item_relation_logs', ['relation_id' => $old->id, 'action' => 'replace_default']);
    }

    private function itemFixture(): array
    {
        $unit = Unit::create(['unit_code' => 'EA-SUP', 'unit_name' => '件', 'unit_type' => 'quantity', 'status' => 'enabled']);
        $category = ItemCategory::create(['category_code' => 'CAT-SUP', 'category_name' => '采购物料', 'category_type' => 'item', 'status' => 'enabled']);
        $item = Item::create([
            'item_code' => 'ITEM-SUP-001',
            'item_name' => '供应商推荐测试物料',
            'item_type' => 'raw_material',
            'unit_id' => $unit->id,
            'category_id' => $category->id,
            'is_purchase_item' => true,
            'status' => 'enabled',
        ]);

        return [$item, $unit, $category];
    }

    private function supplier(string $code, string $name, array $overrides = []): Supplier
    {
        return Supplier::create(array_merge([
            'supplier_code' => $code,
            'supplier_name' => $name,
            'supplier_type' => 'manufacturer',
            'status' => 'enabled',
            'approval_status' => 'approved',
            'is_blacklisted' => false,
            'cooperation_status' => 'normal',
            'purchase_restricted' => false,
            'quality_status' => 'normal',
        ], $overrides));
    }
}
