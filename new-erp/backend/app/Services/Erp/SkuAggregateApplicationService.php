<?php

namespace App\Services\Erp;

use App\Models\Erp\{Item, Product, Sku, SkuItemRelation, Unit};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final class SkuAggregateApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly SkuItemDefaultRelationService $defaultItems,
        private readonly AuthContextService $auth,
    ) {}

    /** SKU、默认物料及预占号必须一起提交，不能先落下一个无法启用的半成品档案。 */
    public function save(?int $skuId, array $data, object $operator): Sku
    {
        return DB::transaction(function () use ($skuId, $data, $operator): Sku {
            // 与矩阵统一先锁父商品；普通建档虽允许相同规格，也不能绕过矩阵的串行边界。
            $productId = $data['product_id'] ?? ($skuId ? Sku::whereKey($skuId)->value('product_id') : null);
            if ($productId) Product::whereKey($productId)->lockForUpdate()->firstOrFail();
            $sku = $skuId ? Sku::query()->lockForUpdate()->findOrFail($skuId) : null;
            $data = $this->validateData($sku, $data, $operator);
            $operatorId = $operator->legacy_id ?? null;
            $reservationToken = $data['reservation_token'] ?? null;
            if (!$sku) {
                $data['sku_code'] = $reservationToken
                    ? $this->numbers->reservedNumber($reservationToken, 'sku', $operatorId, $data['creation_session_id'] ?? null)
                    : (filled($data['sku_code']) ? $data['sku_code'] : $this->numbers->next('sku'));
            }
            $targetStatus = $data['status'];
            $itemId = $data['default_item_id'] ?? null;
            $factor = $data['default_item_factor'] ?? 1;
            $reason = $data['default_item_reason'] ?? null;
            $remark = $data['default_item_remark'] ?? null;
            unset($data['reservation_token'], $data['creation_session_id'], $data['default_item_id'],
                $data['default_item_factor'], $data['default_item_reason'], $data['default_item_remark']);
            if ($sku) $this->assertSalesUnitChangeAllowed($sku, $data['sales_unit_id'] ?? null);
            // 先保存草稿以便关系服务取得 SKU，最终启用校验在绑定完成后、同一事务内执行。
            $data['status'] = $targetStatus === 'enabled' ? 'draft' : $targetStatus;
            if ($sku) $sku->update($data);
            else $sku = Sku::create($data);

            if ($sku->order_line_type === 'physical' && $itemId) {
                $this->authorizeDefaultItem($operator);
                $this->defaultItems->setPrimary((int) $sku->id, (int) $itemId, $reason, $remark, $operatorId,
                    ($operator->nickname ?? null) ?: ($operator->name ?? null) ?: ($operator->username ?? '系统'), $factor, true);
            }
            if ($targetStatus === 'enabled') {
                $this->assertPhysicalSkuCanBeEnabled($sku);
                $sku->update(['status' => 'enabled']);
            }
            if (!$skuId && $reservationToken) {
                $this->numbers->consume($reservationToken, 'sku', $sku->sku_code, $operatorId, 'skus', (int) $sku->id);
            }
            return $sku->fresh(['product', 'salesUnit.standardUnit', 'itemRelations.item.unit.standardUnit']);
        }, 5);
    }

    /** 导入预检复用字段、权限和归一规则；此入口只读，不分配或消费编号。 */
    public function validate(?int $skuId, array $data, object $operator): array
    {
        return $this->validateData($skuId ? Sku::findOrFail($skuId) : null, $data, $operator);
    }

    private function validateData(?Sku $sku, array $data, object $operator): array
    {
        $skuId = $sku?->id;
        foreach (['spec_model' => 'spec_text', 'line_type' => 'order_line_type', 'is_sellable' => 'is_sale_item',
            'factor' => 'default_item_factor'] as $alias => $field) {
            if (array_key_exists($alias, $data)) $data[$field] = $data[$alias];
        }
        if (!array_key_exists('order_line_type', $data) && array_key_exists('fulfillment_type', $data)) {
            $data['order_line_type'] = $data['fulfillment_type'];
        }
        $data = array_merge($sku?->getAttributes() ?? [], $data);
        $type = $data['order_line_type'] ?? $data['fulfillment_type'] ?? 'physical';
        $data['order_line_type'] = $type === 'virtual' ? 'no_delivery' : $type;
        $data['fulfillment_type'] = $data['order_line_type'] === 'no_delivery' ? 'virtual' : $data['order_line_type'];
        $data += ['sku_code' => null, 'status' => 'draft', 'is_sale_item' => true, 'electric_mode' => 'hidden',
            'need_pump_mode' => 'hidden', 'allow_customized' => false, 'allow_special_customized' => false];
        $salesUnitExists = Rule::exists('erp_units', 'id')->where(fn ($query) => $query->where('status', 'enabled')
            ->where(function ($unit) use ($sku) {
                $unit->where('is_legacy', false);
                if ($sku?->sales_unit_id) $unit->orWhere('id', $sku->sales_unit_id);
            }));
        $data = Validator::make($data, [
            'product_id' => 'required|integer|exists:erp_products,id',
            'sku_code' => ['nullable', 'string', 'max:80', Rule::unique('erp_skus', 'sku_code')->ignore($skuId)],
            'sku_name' => 'required|string|max:160', 'spec_text' => 'nullable|string|max:255', 'image' => 'nullable|string|max:255',
            'search_aliases' => 'nullable|string|max:500', 'search_keywords' => 'nullable|string',
            'sale_price' => 'nullable|numeric|min:0', 'reference_cost' => 'nullable|numeric|min:0',
            'default_tax_rate' => 'nullable|numeric|min:0|max:1',
            'default_price_tax_mode' => 'nullable|in:tax_inclusive,tax_exclusive',
            'product_structure_type' => 'nullable|string|max:40', 'production_policy' => 'nullable|string|max:40',
            'order_line_type' => 'required|in:physical,service,no_delivery,deduction',
            'fulfillment_type' => 'nullable|in:physical,service,virtual,deduction',
            'sales_unit_id' => ['nullable', $salesUnitExists], 'sales_unit_snapshot' => 'nullable|string|max:80',
            'is_need_production' => 'boolean', 'is_need_bom' => 'boolean', 'is_sale_item' => 'boolean', 'is_custom_sku' => 'boolean',
            'electric_mode' => 'nullable|in:hidden,optional,required', 'need_pump_mode' => 'nullable|in:hidden,optional,required',
            'allow_customized' => 'boolean', 'allow_special_customized' => 'boolean',
            'special_custom_drawing_required' => 'boolean', 'special_custom_agreement_required' => 'boolean',
            'special_custom_description_required' => 'boolean', 'delivery_inspection_required' => 'boolean',
            'base_sku_id' => 'nullable|exists:erp_skus,id', 'custom_scope' => 'nullable|in:none,order,customer,reusable',
            'custom_source_type' => 'nullable|in:sales_order,manual,import', 'custom_source_id' => 'nullable|integer|min:0',
            'customer_id' => 'nullable|integer|min:0', 'sales_order_id' => 'nullable|integer|min:0',
            'sales_order_line_id' => 'nullable|integer|min:0', 'custom_status' => 'nullable|in:draft,active,disabled,archived',
            'custom_description' => 'nullable|string', 'status' => 'required|in:draft,enabled,disabled', 'remark' => 'nullable|string',
            'reservation_token' => 'nullable|uuid', 'creation_session_id' => 'nullable|uuid',
            'default_item_id' => 'nullable|integer|exists:erp_items,id', 'default_item_factor' => 'nullable|numeric|gt:0',
            'default_item_reason' => 'nullable|string|max:80',
            'default_item_remark' => [Rule::requiredIf(($data['default_item_reason'] ?? null) === '其他'), 'nullable', 'string', 'max:1000'],
        ])->validate();
        if ($sku && blank($data['sku_code'])) $data['sku_code'] = $sku->sku_code;
        $data['electric_mode'] = $data['electric_mode'] ?? 'hidden';
        $data['need_pump_mode'] = $data['need_pump_mode'] ?? 'hidden';
        $data['is_customizable'] = (bool) ($data['allow_customized'] || $data['allow_special_customized']);
        if (!$data['allow_special_customized']) {
            $data['special_custom_drawing_required'] = false;
            $data['special_custom_agreement_required'] = false;
            $data['special_custom_description_required'] = false;
        }
        if (!in_array('inventory.amount.view', $this->auth->permissionCodes($operator), true)) unset($data['reference_cost']);
        if ($data['order_line_type'] === 'physical' && !empty($data['default_item_id'])) {
            $this->authorizeDefaultItem($operator);
            $data['default_item_factor'] = $this->defaultItems->validateFactor($data['default_item_factor'] ?? 1);
            $item = Item::with('unit')->findOrFail($data['default_item_id']);
            abort_unless($item->status === 'enabled' && $item->unit?->status === 'enabled', 422, '默认履约 Item 及其库存基本单位必须处于启用状态。');
            app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'default_item_id');
        }
        if ($data['status'] === 'enabled') {
            $this->assertEnabledData($data, $skuId, $data['default_item_id'] ?? null);
        }
        return $data;
    }

    public function authorizeDefaultItem(object $operator): void
    {
        $this->authorizeAny($operator, ['master.sku.create', 'master.sku.edit',
            'master.sku_item_relation.create', 'master.sku_item_relation.set_primary']);
    }

    public static function normalizeSpec(?string $spec): string
    {
        return mb_strtolower(preg_replace('/\s+/u', '', (string) $spec));
    }

    public function assertPhysicalSkuCanBeEnabled(Sku $sku): void
    {
        $this->assertEnabledData($sku->getAttributes(), (int) $sku->id, null, true);
    }

    private function assertEnabledData(array $data, ?int $skuId, ?int $defaultItemId = null, bool $lock = false): void
    {
        abort_unless((!$lock || !empty($data['sku_code'])) && !empty($data['sku_name']) && !empty($data['product_id']) && !blank($data['spec_text'] ?? null), 422, 'SKU 编码、名称、所属 Product 与规格型号必须完整后才能启用。');
        abort_unless(!empty($data['sales_unit_id']), 422, '请先维护销售单位后再启用 SKU。');
        abort_unless(in_array($data['order_line_type'] ?? '', ['physical', 'service', 'no_delivery', 'deduction'], true), 422, '订单行类型无效，不能启用 SKU。');
        abort_unless(in_array($data['electric_mode'] ?? 'hidden', ['hidden', 'optional', 'required'], true), 422, '电压属性模式无效。');
        abort_unless(in_array($data['need_pump_mode'] ?? 'hidden', ['hidden', 'optional', 'required'], true), 422, '原水泵控制属性模式无效。');
        $productQuery = Product::whereKey($data['product_id']);
        $unitQuery = Unit::whereKey($data['sales_unit_id']);
        if ($lock) { $productQuery->lockForUpdate(); $unitQuery->lockForUpdate(); }
        $product = $productQuery->first();
        $unit = $unitQuery->first();
        abort_unless($product?->status === 'enabled', 422, '所属 Product 必须处于启用状态。');
        abort_unless($unit?->status === 'enabled', 422, '销售单位必须处于启用状态。');
        if ($data['order_line_type'] !== 'physical') return;
        if (!$defaultItemId && $skuId) {
            $relationsQuery = SkuItemRelation::where('sku_id', $skuId)->where('status', 'active')->where('is_primary', true);
            if ($lock) $relationsQuery->lockForUpdate();
            $relations = $relationsQuery->get();
            $defaultItemId = $relations->count() === 1 ? $relations->first()->item_id : null;
        }
        $itemQuery = Item::whereKey($defaultItemId);
        if ($lock) $itemQuery->lockForUpdate();
        $item = $itemQuery->first();
        abort_unless($item?->status === 'enabled', 422, '实物 SKU 保存并启用前必须绑定一个有效默认物料；可先保存草稿。');
        $itemUnitQuery = Unit::whereKey($item->unit_id);
        if ($lock) $itemUnitQuery->lockForUpdate();
        abort_unless($itemUnitQuery->value('status') === 'enabled', 422, '默认履约 Item 的库存基本单位必须处于启用状态。');
        app(ItemManagementScopeService::class)->assertProductionAllowed($item, 'default_item_id');
    }

    private function assertSalesUnitChangeAllowed(Sku $sku, ?int $unitId): void
    {
        if ((int) $sku->sales_unit_id === (int) $unitId) return;
        $used = DB::table('erp_sales_order_lines as line')->join('erp_sales_orders as orders', 'orders.id', '=', 'line.sales_order_id')
            ->where('line.sku_id', $sku->id)->whereNotIn('orders.order_status', ['draft', 'cancelled'])->exists();
        abort_if($used, 422, '该 SKU 已存在已确认销售订单，销售单位不能直接修改。');
    }

    private function authorizeAny(object $operator, array $permissions): void
    {
        abort_unless($this->auth->isSuperAdmin($operator) || array_intersect($permissions, $this->auth->permissionCodes($operator)),
            403, '没有当前 SKU 操作权限。');
    }
}
