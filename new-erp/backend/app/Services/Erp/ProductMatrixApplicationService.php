<?php

namespace App\Services\Erp;

use App\Models\Erp\{Product, Unit};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductMatrixApplicationService
{
    public function __construct(
        private readonly MasterDataApplicationService $masterData,
        private readonly SkuAggregateApplicationService $skus,
        private readonly DocumentNumberService $numbers,
    ) {}

    public function save(?int $productId, ?array $productData, array $rows, ?int $operatorId): Product
    {
        return DB::transaction(function () use ($productId, $productData, $rows, $operatorId): Product {
            $operator = app(AuthContextService::class)->currentUser(request());
            abort_unless($operator && (int) $operator->legacy_id === (int) $operatorId, 401, '未登录或登录已过期。');
            $rows = array_map(function ($row) {
                if (!is_array($row)) return $row;
                if (array_key_exists('sku_code', $row) && blank($row['sku_code'])) unset($row['sku_code']);
                if (array_key_exists('reservation_token', $row) && blank($row['reservation_token'])) unset($row['reservation_token']);
                return $row;
            }, $rows);
            $rows = Validator::make(['rows' => $rows], [
                'rows' => 'required|array|min:1|max:500',
                'rows.*.sku_code' => ['nullable', 'string', 'max:80', 'distinct', Rule::unique('erp_skus', 'sku_code')],
                'rows.*.sku_name' => 'required|string|max:160', 'rows.*.spec_text' => 'required|string|max:255',
                'rows.*.sale_price' => 'nullable|numeric|min:0',
                'rows.*.reservation_token' => 'nullable|uuid|distinct', 'rows.*.creation_session_id' => 'nullable|uuid',
                'rows.*.default_item_id' => 'nullable|integer|exists:erp_items,id',
                'rows.*.default_item_factor' => 'nullable|numeric|gt:0', 'rows.*.factor' => 'nullable|numeric|gt:0',
                'rows.*.default_item_reason' => 'nullable|string|max:80', 'rows.*.default_item_remark' => 'nullable|string|max:1000',
            ])->validate()['rows'];

            $product = $productId ? Product::query()->lockForUpdate()->findOrFail($productId) : null;
            if ($productData !== null) {
                $product = $product
                    ? $this->masterData->update('products', $product, $productData, $operatorId)
                    : $this->masterData->create('products', Product::class, $productData, $operatorId);
            }
            abort_unless($product, 404, '商品不存在。');
            abort_unless($product->unit_id && Unit::whereKey($product->unit_id)->where('status', 'enabled')->exists(),
                422, '请先在商品档案维护有效计量单位。');

            // 锁定所属商品后核对全部已存规格，不能依赖页面当前展开的5条SKU。
            // 整批记录和编号消费同一事务，最后一行失败时前面行与新商品一并回滚。
            $normalize = [SkuAggregateApplicationService::class, 'normalizeSpec'];
            $specs = $product->skus()->lockForUpdate()->pluck('spec_text')->mapWithKeys(fn ($spec) => [$normalize($spec) => true])->all();
            $missingCount = count(array_filter($rows, fn ($row) => blank($row['sku_code'] ?? null) && empty($row['reservation_token'])));
            $codes = $missingCount ? $this->numbers->allocateBatch('sku', $missingCount) : [];
            $codeIndex = 0;
            foreach ($rows as $index => $row) {
                $spec = $normalize($row['spec_text']);
                if ($spec === '' || isset($specs[$spec])) {
                    throw ValidationException::withMessages(['sku_matrix.'.$index.'.spec_text' => '规格为空、批次内重复或该商品已存在相同规格，请刷新后核对。']);
                }
                $specs[$spec] = true;
                if (blank($row['sku_code'] ?? null) && empty($row['reservation_token'])) $row['sku_code'] = $codes[$codeIndex++];
                $this->skus->save(null, [
                    ...$row, 'product_id' => $product->id, 'sales_unit_id' => $product->unit_id,
                    'order_line_type' => 'physical', 'fulfillment_type' => 'physical', 'is_sale_item' => true, 'status' => 'draft',
                ], $operator);
            }
            return $product->fresh();
        });
    }
}
