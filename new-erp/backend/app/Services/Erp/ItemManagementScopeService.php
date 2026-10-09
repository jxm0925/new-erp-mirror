<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, ItemCategory, Warehouse};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

/** Business management scope, independent of stock, expense and asset policy. */
final class ItemManagementScopeService
{
    public function requestScope(Request $request): ?string
    {
        $data = Validator::make($request->query(), ['management_scope' => 'sometimes|required|in:factory,office'])->validate();
        return $data['management_scope'] ?? null;
    }

    public function validateScope(?string $scope): ?string
    {
        if ($scope !== null && ! in_array($scope, ['factory', 'office'], true)) {
            throw ValidationException::withMessages(['management_scope' => '管理范围只能选择工厂物料或办公用品。']);
        }
        return $scope;
    }

    public function applyScope(Builder $query, ?string $scope): Builder
    {
        if ($this->validateScope($scope) !== null) $query->where($query->getModel()->qualifyColumn('management_scope'), $scope);
        return $query;
    }

    public function assertContext(Item|ItemCategory $record, ?string $scope, string $field = 'management_scope'): void
    {
        if ($this->validateScope($scope) !== null && $record->managementScope() !== $scope) {
            throw ValidationException::withMessages([$field => '该记录不属于当前管理范围，请从对应的物料入口打开。']);
        }
    }

    public function assertProductionAllowed(Item $item, string $field = 'item_id'): void
    {
        if ($item->managementScope() !== 'factory' || $item->item_type === 'office_consumable') {
            throw ValidationException::withMessages([$field => '办公用品不能用于工厂商品、BOM、工艺或生产工单。']);
        }
    }

    public function assertFactory(Item $item, string $field = 'item_id'): void
    {
        $this->assertProductionAllowed($item, $field);
    }

    public function prepareItem(array $data, ?Item $existing = null, ?string $creationScope = null): array
    {
        $type = $data['item_type'] ?? $existing?->item_type;
        if (array_key_exists('management_scope', $data)) {
            $scope = $this->requiredScope($data['management_scope']);
        } else {
            $scope = $existing?->managementScope() ?? $this->validateScope($creationScope)
                ?? ($type === 'office_consumable' ? 'office' : 'factory');
        }
        if (! $existing && $creationScope !== null && $scope !== $creationScope) {
            throw ValidationException::withMessages(['management_scope' => '新建物料的管理范围与当前入口不一致。']);
        }
        if ($existing && $scope !== $existing->managementScope()) $this->assertScopeChangeAllowed($existing);
        $allowed = $scope === 'office' ? ['office_consumable', 'service'] : ['finished_product', 'semi_finished', 'raw_material', 'packaging', 'service'];
        if (! in_array($type, $allowed, true)) {
            throw ValidationException::withMessages(['item_type' => $scope === 'office'
                ? '办公用品范围请选择办公用品或服务类型。' : '办公用品类型必须在办公用品范围维护。']);
        }
        if ($scope === 'office') {
            $mode = $data['cutting_mode'] ?? $existing?->cuttingMode()
                ?? ((bool) ($data['is_length_cut_material'] ?? false) ? 'length' : 'none');
            if ((bool) ($data['is_production_item'] ?? $existing?->is_production_item)
                || $mode !== 'none' || (bool) ($data['is_length_cut_material'] ?? $existing?->is_length_cut_material)
                || ($data['manufacturing_strategy'] ?? $existing?->manufacturing_strategy ?? 'unspecified') === 'make') {
                throw ValidationException::withMessages(['management_scope' => '办公用品不能启用生产、自制或下料；采购、库存、责任人和资产策略可以独立维护。']);
            }
        }
        $data['management_scope'] = $scope;
        $this->assertCategory($data['category_id'] ?? $existing?->category_id, $scope, $existing);
        $warehouseId = array_key_exists('default_warehouse_id', $data) ? $data['default_warehouse_id'] : $existing?->default_warehouse_id;
        if ($warehouseId) {
            $warehouse = Warehouse::whereKey($warehouseId)->lockForUpdate()->first();
            if (!$warehouse || $warehouse->managementScope() !== $scope) {
                throw ValidationException::withMessages(['default_warehouse_id' => '默认仓库必须与物料管理范围一致。']);
            }
        }
        return $data;
    }

    public function prepareCategory(array $data, ?ItemCategory $existing = null): array
    {
        $parentId = array_key_exists('parent_id', $data) ? $data['parent_id'] : $existing?->parent_id;
        $parent = $parentId ? ItemCategory::whereKey($parentId)->lockForUpdate()->first() : null;
        $scope = array_key_exists('management_scope', $data) ? $this->requiredScope($data['management_scope'])
            : ($existing?->managementScope() ?? $parent?->managementScope() ?? 'factory');
        if ($existing && $scope !== $existing->managementScope()) {
            throw ValidationException::withMessages(['management_scope' => '类目管理范围创建后不可更改，请在新范围建立类目后人工迁移物料。']);
        }
        if ($parent && $parent->managementScope() !== $scope) {
            throw ValidationException::withMessages(['parent_id' => '父子类目必须属于同一管理范围。']);
        }
        $data['management_scope'] = $scope;
        return $data;
    }

    public function categoryScopeMismatch(Item $item): bool
    {
        $category = $item->category;
        return $category && $category->managementScope() !== $item->managementScope();
    }

    public function exposeCategoryScope(Item $item): Item
    {
        return $item->setAttribute('category_scope_mismatch', $this->categoryScopeMismatch($item));
    }

    public function importScope(array $row, ?string $batchScope = null): string
    {
        $batchScope = $this->validateScope($batchScope);
        $values = [];
        foreach (['management_scope', '管理范围', '物料管理范围'] as $key) {
            if (array_key_exists($key, $row) && $row[$key] !== null && trim((string) $row[$key]) !== '') {
                $label = trim((string) $row[$key]);
                $values[] = $this->requiredScope(['工厂物料' => 'factory', '工厂' => 'factory', '办公用品' => 'office', '办公' => 'office'][$label] ?? $label);
            }
        }
        if (count(array_unique($values)) > 1) throw ValidationException::withMessages(['management_scope' => '同一导入行的管理范围列相互冲突。']);
        $explicit = $values[0] ?? null;
        if ($explicit !== null && $batchScope !== null && $explicit !== $batchScope) {
            throw ValidationException::withMessages(['management_scope' => '导入行管理范围与上传入口的批次范围不一致。']);
        }
        $type = null;
        foreach (['item_type', '物料类型', '物料类型（中文选择）'] as $key) if (!empty($row[$key])) { $type = trim((string) $row[$key]); break; }
        $officeType = in_array($type, ['office_consumable', '办公耗材', '办公用品'], true);
        $scope = $explicit ?? $batchScope ?? ($officeType ? 'office' : 'factory');
        if ($officeType && $scope !== 'office') throw ValidationException::withMessages(['management_scope' => '明确的办公用品类型不能导入工厂物料范围。']);
        return $scope;
    }

    public function assertScopeChangeAllowed(Item $item): void
    {
        // Preserve identifiers and stock on a manual correction, but do not
        // reclassify Items that already define factory production or sales.
        $references = [
            ['erp_purchase_request_items', 'item_id'], ['erp_purchase_plan_items', 'item_id'],
            ['erp_purchase_order_items', 'item_id'], ['erp_purchase_receipt_items', 'item_id'],
            ['erp_purchase_return_items', 'item_id'], ['erp_purchase_exchange_orders', 'item_id'],
            ['erp_boms', 'output_item_id'], ['erp_bom_items', 'component_item_id'],
            ['erp_production_routings', 'output_item_id'], ['erp_production_routing_operations', 'output_item_id'],
            ['erp_routing_operation_output_rules', 'item_id'], ['erp_routing_operation_output_rules', 'reference_item_id'],
            ['erp_routing_operation_material_supply_rules', 'component_item_id'], ['erp_work_orders', 'output_item_id'],
            ['erp_work_orders', 'effective_output_item_id_snapshot'], ['erp_work_order_planned_outputs', 'item_id'],
            ['erp_work_order_material_requirements', 'component_item_id'], ['erp_production_target_material_requirements', 'component_item_id'],
            ['erp_sales_order_production_requirements', 'item_id'], ['erp_sku_item_relations', 'item_id'],
        ];
        foreach ($references as [$table, $column]) if (Schema::hasTable($table) && Schema::hasColumn($table, $column)
            && DB::table($table)->where($column, $item->id)->exists()) {
            throw ValidationException::withMessages(['management_scope' => '该物料已有采购、商品、BOM、工艺或生产引用，不能直接更改管理范围。']);
        }
        // Reclassifying live stock would place office Items in a factory warehouse
        // without a real inventory move. Keep identities and stock facts unchanged.
        foreach (['erp_inventory_balances', 'erp_inventory_location_balances'] as $table) {
            if (DB::table($table)->where('item_id', $item->id)
                ->where(fn ($q) => $q->where('quantity_on_hand', '!=', 0)->orWhere('quantity_locked', '!=', 0)->orWhere('quantity_pending', '!=', 0))
                ->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['management_scope' => '该物料已有库存或库存占用，不能直接改换工厂、办公范围。']);
            }
        }
    }

    private function requiredScope(mixed $value): string
    {
        if (! is_string($value) || ! in_array($value, ['factory', 'office'], true)) {
            throw ValidationException::withMessages(['management_scope' => '管理范围只能选择工厂物料或办公用品，显式值不能为空。']);
        }
        return $value;
    }

    private function assertCategory(?int $categoryId, string $scope, ?Item $existing): void
    {
        if (! $categoryId) return;
        $category = ItemCategory::whereKey($categoryId)->lockForUpdate()->first();
        if (! $category || $category->managementScope() === $scope) return;
        // Legacy office-consumable rows retain their approved factory category
        // as a read-only bridge. Only the exact unchanged historical reference
        // survives ordinary edits; every replacement must belong to office.
        if ($existing && $existing->managementScope() === 'office' && $scope === 'office'
            && $existing->item_type === 'office_consumable' && (int) $existing->category_id === $categoryId) return;
        throw ValidationException::withMessages(['category_id' => '物料与所选类目必须属于同一管理范围。']);
    }
}
