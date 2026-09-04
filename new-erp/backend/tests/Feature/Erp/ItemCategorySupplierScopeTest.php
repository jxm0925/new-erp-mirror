<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\ItemCategory;
use App\Models\Erp\DocumentNumberReservation;
use App\Models\Erp\Supplier;
use App\Models\Erp\SupplierCategoryCapability;
use App\Models\Erp\Unit;
use App\Services\Erp\ItemCategoryApplicationService;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\MasterDataApplicationService;
use App\Services\Erp\SupplierCapabilityService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ItemCategorySupplierScopeTest extends TestCase
{
    use DatabaseTransactions;

    public function test_category_create_always_uses_item_type(): void
    {
        $row = $this->categories()->create($this->categoryData('A'));
        $this->assertSame('item', $row->category_type);
    }

    public function test_category_can_be_created_under_item_parent(): void
    {
        $parent = $this->category('P');
        $child = $this->categories()->create($this->categoryData('C', $parent->id));
        $this->assertSame($parent->id, (int) $child->parent_id);
    }

    public function test_non_item_parent_is_rejected(): void
    {
        $parent = ItemCategory::create($this->categoryData('PRODUCT') + ['category_type' => 'product']);
        $this->expectException(HttpException::class);
        $this->categories()->create($this->categoryData('C', $parent->id));
    }

    public function test_category_cannot_be_its_own_parent(): void
    {
        $row = $this->category('SELF');
        $this->expectException(HttpException::class);
        $this->categories()->update($row, $this->categoryData('SELF', $row->id));
    }

    public function test_category_cannot_move_under_descendant(): void
    {
        $parent = $this->category('P');
        $child = $this->category('C', $parent->id);
        $this->expectException(HttpException::class);
        $this->categories()->update($parent, $this->categoryData('P', $child->id));
    }

    public function test_parent_with_enabled_child_cannot_be_disabled(): void
    {
        $parent = $this->category('P');
        $this->category('C', $parent->id);
        $this->expectException(HttpException::class);
        $this->categories()->disable($parent);
    }

    public function test_leaf_can_be_disabled_without_deleting_history(): void
    {
        $leaf = $this->category('LEAF');
        $disabled = $this->categories()->disable($leaf);
        $this->assertSame('disabled', $disabled->status);
        $this->assertDatabaseHas('erp_item_categories', ['id' => $leaf->id]);
    }

    public function test_child_cannot_be_enabled_when_parent_is_disabled(): void
    {
        $parent = $this->category('P', null, 'disabled');
        $child = $this->category('C', $parent->id, 'disabled');
        $this->expectException(HttpException::class);
        $this->categories()->enable($child);
    }

    public function test_child_can_be_enabled_when_all_ancestors_are_enabled(): void
    {
        $parent = $this->category('P');
        $child = $this->category('C', $parent->id, 'disabled');
        $this->assertSame('enabled', $this->categories()->enable($child)->status);
    }

    public function test_supplier_can_select_enabled_item_leaf(): void
    {
        $leaf = $this->category('LEAF');
        $supplier = $this->supplier();
        $this->capabilities()->syncCategories($supplier->id, [$leaf->id], 1);
        $this->assertDatabaseHas('erp_supplier_category_capabilities', ['supplier_id' => $supplier->id, 'item_category_id' => $leaf->id, 'status' => 'active']);
    }

    public function test_supplier_cannot_select_parent_category(): void
    {
        $parent = $this->category('P');
        $this->category('C', $parent->id);
        $this->expectException(HttpException::class);
        $this->capabilities()->syncCategories($this->supplier()->id, [$parent->id], 1);
    }

    public function test_supplier_cannot_select_disabled_leaf(): void
    {
        $leaf = $this->category('LEAF', null, 'disabled');
        $this->expectException(HttpException::class);
        $this->capabilities()->syncCategories($this->supplier()->id, [$leaf->id], 1);
    }

    public function test_supplier_category_ids_are_deduplicated(): void
    {
        $leaf = $this->category('LEAF');
        $supplier = $this->supplier();
        $this->capabilities()->syncCategories($supplier->id, [$leaf->id, $leaf->id], 1);
        $this->assertSame(1, SupplierCategoryCapability::where('supplier_id', $supplier->id)->count());
    }

    public function test_removed_supplier_category_is_inactivated_not_deleted(): void
    {
        $leaf = $this->category('LEAF');
        $supplier = $this->supplier();
        $this->capabilities()->syncCategories($supplier->id, [$leaf->id], 1);
        $this->capabilities()->syncCategories($supplier->id, [], 1);
        $this->assertDatabaseHas('erp_supplier_category_capabilities', ['supplier_id' => $supplier->id, 'item_category_id' => $leaf->id, 'status' => 'inactive']);
    }

    public function test_new_item_without_category_is_rejected_by_application_service(): void
    {
        $this->expectException(HttpException::class);
        app(MasterDataApplicationService::class)->create('items', Item::class, $this->itemData(null), 1);
    }

    public function test_new_item_cannot_select_parent_category(): void
    {
        $parent = $this->category('P');
        $this->category('C', $parent->id);
        $this->expectException(HttpException::class);
        app(MasterDataApplicationService::class)->create('items', Item::class, $this->itemData($parent->id), 1);
    }

    public function test_historical_unclassified_item_can_still_be_edited(): void
    {
        $item = Item::create($this->itemData(null));
        $updated = app(MasterDataApplicationService::class)->update('items', $item, ['item_name' => '历史Item已编辑'], 1);
        $this->assertSame('历史Item已编辑', $updated->item_name);
        $this->assertNull($updated->category_id);
    }

    public function test_new_item_can_select_enabled_leaf_category(): void
    {
        $leaf = $this->category('LEAF');
        $item = app(MasterDataApplicationService::class)->create('items', Item::class, $this->itemData($leaf->id), 1);
        $this->assertSame($leaf->id, (int) $item->category_id);
    }

    public function test_item_category_number_business_type_is_registered(): void
    {
        $this->assertSame('Item类目', config('erp_document_numbers.business_types.item_category'));
        $this->assertDatabaseHas('erp_document_number_rules', [
            'document_type' => 'item_category',
            'name' => 'Item类目编号规则',
            'prefix' => 'IC',
            'enabled' => true,
        ]);
    }

    public function test_root_category_create_page_can_reserve_visible_number(): void
    {
        $reservation = $this->reserveCategoryNumber();
        $this->assertMatchesRegularExpression('/^IC\d{6}$/', $reservation->document_no);
        $this->assertSame('item_category', $reservation->document_type);
        $this->assertSame('reserved', $reservation->status);
    }

    public function test_child_category_create_page_uses_an_independent_number(): void
    {
        $rootNumber = $this->reserveCategoryNumber();
        $childNumber = $this->reserveCategoryNumber();
        $this->assertNotSame($rootNumber->document_no, $childNumber->document_no);
    }

    public function test_category_is_created_from_reservation_without_manual_code(): void
    {
        $reservation = $this->reserveCategoryNumber();
        $data = $this->categoryData('GENERATED');
        unset($data['category_code']);
        $data['reservation_token'] = $reservation->reservation_token;
        $data['creation_session_id'] = $reservation->creation_session_id;

        $category = $this->categories()->create($data, 1);

        $this->assertSame($reservation->document_no, $category->category_code);
        $this->assertDatabaseHas('erp_item_categories', [
            'id' => $category->id,
            'category_code' => $reservation->document_no,
        ]);
        $this->assertDatabaseHas('erp_document_number_reservations', [
            'reservation_token' => $reservation->reservation_token,
            'status' => 'used',
            'business_type' => 'item_category',
            'business_id' => $category->id,
        ]);
    }

    public function test_displayed_number_must_match_saved_number(): void
    {
        $reservation = $this->reserveCategoryNumber();
        $this->expectException(ValidationException::class);
        $this->categories()->create(array_merge($this->categoryData('MISMATCH'), [
            'category_code' => 'IC999999',
            'reservation_token' => $reservation->reservation_token,
            'creation_session_id' => $reservation->creation_session_id,
        ]), 1);
    }

    public function test_category_number_cannot_be_empty_without_reservation(): void
    {
        $data = $this->categoryData('EMPTY');
        unset($data['category_code']);
        $this->expectException(ValidationException::class);
        $this->categories()->create($data, 1);
    }

    public function test_duplicate_category_number_is_rejected_by_backend(): void
    {
        $reservation = $this->reserveCategoryNumber();
        ItemCategory::create(array_merge($this->categoryData('EXISTING'), [
            'category_code' => $reservation->document_no,
            'category_type' => 'item',
        ]));
        $data = $this->categoryData('DUPLICATE');
        unset($data['category_code']);
        $data['reservation_token'] = $reservation->reservation_token;
        $data['creation_session_id'] = $reservation->creation_session_id;

        $this->expectException(ValidationException::class);
        $this->categories()->create($data, 1);
    }

    public function test_editing_category_does_not_change_number(): void
    {
        $category = $this->category('IMMUTABLE');
        $updated = $this->categories()->update($category, [
            'category_code' => $category->category_code,
            'category_name' => '修改后的类目名称',
            'parent_id' => null,
            'sort_order' => 9,
            'status' => 'enabled',
            'remark' => '修改备注',
        ]);
        $this->assertSame('CAT-IMMUTABLE', $updated->category_code);

        $this->expectException(ValidationException::class);
        $this->categories()->update($updated, [
            'category_code' => 'IC999999',
            'category_name' => $updated->category_name,
            'parent_id' => null,
            'sort_order' => 9,
            'status' => 'enabled',
            'remark' => '尝试改号',
        ]);
    }

    public function test_concurrent_create_sessions_receive_unique_numbers(): void
    {
        $numbers = collect(range(1, 8))->map(fn () => $this->reserveCategoryNumber()->document_no);
        $this->assertSame(8, $numbers->unique()->count());
    }

    public function test_wrong_business_type_reservation_cannot_create_item_category(): void
    {
        $reservation = app(DocumentNumberService::class)->reserve('item', (string) Str::uuid(), 1, '/master/categories#create');
        $data = $this->categoryData('WRONG-TYPE');
        unset($data['category_code']);
        $data['reservation_token'] = $reservation->reservation_token;
        $data['creation_session_id'] = $reservation->creation_session_id;

        $this->expectException(ValidationException::class);
        $this->categories()->create($data, 1);
    }

    public function test_frontend_uses_readonly_item_category_number_flow(): void
    {
        $source = file_get_contents(base_path('../frontend/src/views/erp/master/ItemCategoryList.vue'));
        $this->assertStringContainsString("reserveForCreatePage('item_category'", $source);
        $this->assertStringContainsString('disabled placeholder="正在预生成"', $source);
        $this->assertStringContainsString('reservation_token', $source);
    }

    public function test_supplier_page_uses_unified_item_category_title(): void
    {
        $supplier = file_get_contents(base_path('../frontend/src/views/erp/master/SupplierList.vue'));
        $purchase = file_get_contents(base_path('../frontend/src/views/erp/purchase/PurchaseDocumentForm.vue'));
        $this->assertStringContainsString('可供Item类目', $supplier);
        $this->assertStringNotContainsString('供应品类', $supplier);
        $this->assertStringNotContainsString('供应品类', $purchase);
    }

    private function categories(): ItemCategoryApplicationService { return app(ItemCategoryApplicationService::class); }
    private function capabilities(): SupplierCapabilityService { return app(SupplierCapabilityService::class); }

    private function reserveCategoryNumber(): DocumentNumberReservation
    {
        return app(DocumentNumberService::class)->reserve(
            'item_category',
            (string) Str::uuid(),
            1,
            '/master/categories#create'
        );
    }

    private function category(string $suffix, ?int $parentId = null, string $status = 'enabled'): ItemCategory
    {
        return ItemCategory::create($this->categoryData($suffix, $parentId, $status) + ['category_type' => 'item']);
    }

    private function categoryData(string $suffix, ?int $parentId = null, string $status = 'enabled'): array
    {
        return ['category_code' => 'CAT-'.$suffix, 'category_name' => '类目'.$suffix, 'parent_id' => $parentId, 'sort_order' => 0, 'status' => $status, 'remark' => null];
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['supplier_code' => 'SUP-001', 'supplier_name' => '测试供应商', 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
    }

    private function itemData(?int $categoryId): array
    {
        $unit = Unit::firstOrCreate(['unit_code' => 'EA'], ['unit_name' => '件', 'unit_type' => 'quantity', 'status' => 'enabled']);
        return ['item_code' => 'ITEM-001', 'item_name' => '测试Item', 'item_type' => 'raw_material', 'category_id' => $categoryId, 'unit_id' => $unit->id, 'cost_method' => 'weighted_average', 'status' => 'enabled'];
    }
}
