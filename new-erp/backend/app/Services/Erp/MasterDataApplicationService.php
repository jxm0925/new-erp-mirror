<?php

namespace App\Services\Erp;

use App\Models\Erp\{ItemCategory, Sku, Supplier};
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class MasterDataApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly SupplierCapabilityService $supplierCapabilities
    ) {
    }

    public function create(string $entity, string $modelClass, array $data, ?int $operatorLegacyId): Model
    {
        return DB::transaction(function () use ($entity, $modelClass, $data, $operatorLegacyId) {
            if ($entity === 'items') $this->assertItemCategory($data['category_id'] ?? null, false);
            $categoryIds = $data['category_ids'] ?? [];
            $reservationToken = $data['reservation_token'] ?? null;
            unset($data['category_ids'], $data['reservation_token'], $data['creation_session_id']);

            /** @var Model $record */
            $record = $modelClass::create($data);

            if ($entity === 'suppliers') {
                $this->supplierCapabilities->syncCategories(
                    (int) $record->getKey(),
                    $categoryIds,
                    $operatorLegacyId,
                    '供应商主数据维护'
                );
            }

            if ($reservationToken) {
                [$documentType, $codeField] = $this->numberConfig($entity);
                $this->numbers->consume(
                    $reservationToken,
                    $documentType,
                    (string) $record->{$codeField},
                    $operatorLegacyId,
                    $entity,
                    (int) $record->getKey()
                );
            }

            return $record->fresh();
        });
    }

    public function update(string $entity, Model $record, array $data, ?int $operatorLegacyId): Model
    {
        return DB::transaction(function () use ($entity, $record, $data, $operatorLegacyId) {
            if ($entity === 'items') $this->assertItemCategory($data['category_id'] ?? $record->category_id, true);
            if ($entity === 'items') $this->assertItemBaseUnitChangeAllowed($record, $data['unit_id'] ?? null);
            if ($entity === 'skus') $this->assertSkuSalesUnitChangeAllowed($record, $data['sales_unit_id'] ?? null);
            $categoryIds = $data['category_ids'] ?? null;
            unset($data['category_ids'], $data['reservation_token'], $data['creation_session_id']);
            $record->update($data);

            if ($entity === 'suppliers' && is_array($categoryIds)) {
                $this->supplierCapabilities->syncCategories(
                    (int) $record->getKey(),
                    $categoryIds,
                    $operatorLegacyId,
                    '供应商主数据维护'
                );
            }

            return $record->fresh();
        });
    }

    public function setStatus(Model $record, string $status): Model
    {
        return DB::transaction(function () use ($record, $status) {
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            $locked->update(['status' => $status]);
            return $locked->fresh();
        });
    }

    public function deleteUnusedSku(Sku $sku): void
    {
        DB::transaction(function () use ($sku) {
            $locked = Sku::query()->lockForUpdate()->findOrFail($sku->getKey());
            abort_if($locked->status === 'enabled', 422, '启用状态的 SKU 不能删除，请先停用。');

            $references = [
                ['erp_sales_order_lines', 'sku_id'],
                ['erp_sales_order_production_requirements', 'sku_id'],
                ['erp_boms', 'sku_id'],
                ['erp_boms', 'source_sku_id'],
                ['erp_customization_records', 'base_sku_id'],
                ['erp_customization_records', 'custom_sku_id'],
                ['erp_skus', 'base_sku_id'],
                ['erp_sku_item_relations', 'sku_id'],
                ['erp_sku_item_relation_logs', 'sku_id'],
            ];
            foreach ($references as [$table, $column]) {
                abort_if(DB::table($table)->where($column, $locked->getKey())->exists(), 422, '该 SKU 已产生订单、BOM、定制或默认 Item 关系记录，只能停用，不能删除。');
            }

            $locked->delete();
        });
    }

    public function deleteUnused(string $entity, Model $record): void
    {
        if ($record instanceof Sku) {
            $this->deleteUnusedSku($record);
            return;
        }

        DB::transaction(function () use ($entity, $record) {
            /** @var Model $locked */
            $locked = $record->newQuery()->lockForUpdate()->findOrFail($record->getKey());
            abort_if(($locked->status ?? null) === 'enabled', 422, '启用状态的数据不能删除，请先停用。');

            $references = match ($entity) {
                'products' => [
                    ['erp_skus', 'product_id', '该商品下仍有 SKU'],
                    ['erp_sales_order_lines', 'product_id', '该商品已被销售订单引用'],
                    ['erp_boms', 'product_id', '该商品已被 BOM 引用'],
                ],
                'items' => [
                    ['erp_sku_item_relations', 'item_id', '该物料已建立 SKU-Item 关系'],
                    ['erp_bom_items', 'component_item_id', '该物料已被 BOM 引用'],
                    ['erp_purchase_request_items', 'item_id', '该物料已被采购需求引用'],
                    ['erp_purchase_plan_items', 'item_id', '该物料已被采购计划引用'],
                    ['erp_purchase_order_items', 'item_id', '该物料已被采购订单引用'],
                    ['erp_purchase_receipt_items', 'item_id', '该物料已被采购到货引用'],
                    ['erp_inventory_balances', 'item_id', '该物料已有库存余额'],
                    ['erp_inventory_transaction_items', 'item_id', '该物料已有库存流水'],
                    ['erp_inventory_serials', 'item_id', '该物料已有设备编号或序列号'],
                    ['erp_item_material_policies', 'item_id', '该物料已维护物资归属策略版本'],
                    ['erp_supplier_item_relations', 'item_id', '该物料已维护供应商供应关系'],
                    ['erp_item_supplier_prices', 'item_id', '该物料已维护供应商报价'],
                ],
                'units' => [
                    ['erp_products', 'unit_id', '该单位已被商品引用'],
                    ['erp_skus', 'sales_unit_id', '该单位已被 SKU 引用'],
                    ['erp_items', 'unit_id', '该单位已被物料引用'],
                    ['erp_item_purchase_conversions', 'purchase_unit_id', '该单位已被采购换算引用'],
                    ['erp_purchase_order_items', 'purchase_unit_id', '该单位已被采购订单引用'],
                    ['erp_purchase_receipt_items', 'purchase_unit_id', '该单位已被采购到货引用'],
                ],
                'categories' => [
                    ['erp_item_categories', 'parent_id', '该类目下仍有子类目'],
                    ['erp_items', 'category_id', '该类目已被物料引用'],
                    ['erp_products', 'category_id', '该类目已被商品引用'],
                    ['erp_supplier_category_capabilities', 'item_category_id', '该类目已被供应商供应范围引用'],
                ],
                'suppliers' => [
                    ['erp_purchase_plan_supplier_splits', 'supplier_id', '该供应商已被采购计划引用'],
                    ['erp_purchase_orders', 'supplier_id', '该供应商已被采购订单引用'],
                    ['erp_purchase_receipts', 'supplier_id', '该供应商已被采购到货引用'],
                    ['erp_purchase_returns', 'supplier_id', '该供应商已被采购退货引用'],
                    ['erp_purchase_exchange_orders', 'supplier_id', '该供应商已被采购换货引用'],
                    ['erp_inventory_serials', 'supplier_id', '该供应商已进入序列号追溯记录'],
                ],
                'warehouses' => [
                    ['erp_locations', 'warehouse_id', '该仓库下仍有库位'],
                    ['erp_inventory_balances', 'warehouse_id', '该仓库已有库存余额'],
                    ['erp_inventory_transactions', 'warehouse_id', '该仓库已有库存事务'],
                    ['erp_purchase_receipt_items', 'warehouse_id', '该仓库已被采购到货引用'],
                ],
                'locations' => [
                    ['erp_locations', 'parent_id', '该库位下仍有子库位'],
                    ['erp_inventory_location_balances', 'location_id', '该库位已有库存余额'],
                    ['erp_inventory_transaction_items', 'location_id', '该库位已有库存流水'],
                    ['erp_purchase_receipt_items', 'location_id', '该库位已被采购到货引用'],
                ],
                default => abort(405, '当前数据类型不支持删除。'),
            };

            foreach ($references as [$table, $column, $message]) {
                if (Schema::hasTable($table) && Schema::hasColumn($table, $column)
                    && DB::table($table)->where($column, $locked->getKey())->exists()) {
                    abort(422, $message . '，只能停用，不能删除。');
                }
            }

            if ($locked instanceof Supplier) {
                foreach (['erp_supplier_quotation_histories', 'erp_supplier_item_relation_logs', 'erp_supplier_item_stats', 'erp_item_supplier_prices', 'erp_supplier_item_relations', 'erp_supplier_category_capabilities'] as $table) {
                    if (Schema::hasTable($table) && Schema::hasColumn($table, 'supplier_id')) {
                        DB::table($table)->where('supplier_id', $locked->getKey())->delete();
                    }
                }
            }
            $locked->delete();
        });
    }

    private function numberConfig(string $entity): array
    {
        return match ($entity) {
            'products' => ['product', 'product_code'],
            'skus' => ['sku', 'sku_code'],
            'items' => ['item', 'item_code'],
            'suppliers' => ['supplier', 'supplier_code'],
            'warehouses' => ['warehouse', 'warehouse_code'],
            'locations' => ['location', 'location_code'],
            default => throw new \InvalidArgumentException("{$entity} 不支持预占编号消费。"),
        };
    }

    private function assertItemCategory(?int $categoryId, bool $allowHistoricalNull): void
    {
        if (!$categoryId) {
            abort_unless($allowHistoricalNull, 422, '新增 Item 必须选择一个启用的末级 Item 类目。');
            return;
        }
        $category = ItemCategory::whereKey($categoryId)->where('category_type', 'item')->where('status', 'enabled')->first();
        abort_unless($category, 422, '请选择启用的 Item 类目。');
        abort_if($category->children()->where('category_type', 'item')->exists(), 422, '只能选择末级 Item 类目。');
    }

    private function assertItemBaseUnitChangeAllowed(Model $record, ?int $newUnitId): void
    {
        if (!$newUnitId || (int) $record->unit_id === (int) $newUnitId) return;
        $itemId = (int) $record->getKey();
        $references = [
            ['erp_inventory_balances', 'item_id'],
            ['erp_inventory_location_balances', 'item_id'],
            ['erp_inventory_transaction_items', 'item_id'],
            ['erp_bom_items', 'component_item_id'],
            ['erp_purchase_order_items', 'item_id'],
            ['erp_purchase_receipt_items', 'item_id'],
            ['erp_sales_order_lines', 'item_id'],
            ['erp_sales_order_production_requirements', 'item_id'],
        ];
        foreach ($references as [$table, $column]) {
            if (DB::table($table)->where($column, $itemId)->exists()) {
                abort(422, '该 Item 已存在库存或正式业务引用，库存基本单位不能直接修改。');
            }
        }
    }

    private function assertSkuSalesUnitChangeAllowed(Model $record, ?int $newUnitId): void
    {
        if (!$newUnitId || (int) $record->sales_unit_id === (int) $newUnitId) return;
        $used = DB::table('erp_sales_order_lines as line')
            ->join('erp_sales_orders as orders', 'orders.id', '=', 'line.sales_order_id')
            ->where('line.sku_id', $record->getKey())
            ->whereNotIn('orders.order_status', ['draft', 'cancelled'])
            ->exists();
        abort_if($used, 422, '该 SKU 已存在已确认销售订单，销售单位不能直接修改。');
    }
}
