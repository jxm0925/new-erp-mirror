<?php

namespace App\Services\Erp;

use App\Models\Erp\{Product, Sku, Unit};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

final class ProductMatrixApplicationService
{
    public function __construct(private readonly MasterDataApplicationService $masterData) {}

    public function save(?int $productId, ?array $productData, array $rows, ?int $operatorId): Product
    {
        return DB::transaction(function () use ($productId, $productData, $rows, $operatorId): Product {
            $rows = Validator::make(['rows' => $rows], [
                'rows' => 'required|array|min:1|max:500',
                'rows.*.sku_code' => ['required', 'string', 'max:80', 'distinct', Rule::unique('erp_skus', 'sku_code')],
                'rows.*.sku_name' => 'required|string|max:160', 'rows.*.spec_text' => 'required|string|max:255',
                'rows.*.sale_price' => 'required|numeric|min:0',
                'rows.*.reservation_token' => 'nullable|uuid', 'rows.*.creation_session_id' => 'nullable|uuid',
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
            $normalize = fn ($spec) => mb_strtolower(preg_replace('/\s+/u', '', (string) $spec));
            $specs = $product->skus()->pluck('spec_text')->mapWithKeys(fn ($spec) => [$normalize($spec) => true])->all();
            foreach ($rows as $index => $row) {
                $spec = $normalize($row['spec_text']);
                if ($spec === '' || isset($specs[$spec])) {
                    throw ValidationException::withMessages(['sku_matrix.'.$index.'.spec_text' => '规格为空、批次内重复或该商品已存在相同规格，请刷新后核对。']);
                }
                $specs[$spec] = true;
                $this->masterData->create('skus', Sku::class, [
                    ...$row, 'product_id' => $product->id, 'sales_unit_id' => $product->unit_id,
                    'order_line_type' => 'physical', 'fulfillment_type' => 'physical', 'is_sale_item' => true, 'status' => 'draft',
                ], $operatorId);
            }
            return $product->fresh();
        });
    }
}
