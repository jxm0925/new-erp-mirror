<?php

use App\Models\Erp\Item;
use App\Models\Erp\Product;
use App\Models\Erp\Sku;
use App\Models\Erp\Unit;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

/** This is a deliberate one-time, operator-run import. It does not create or use legacy mapping data. */
$legacy = DB::connection('fastadmin');
$now = now();
$stats = ['products' => 0, 'skus' => 0, 'items' => 0, 'existing' => ['products' => 0, 'skus' => 0, 'items' => 0]];

$text = static fn ($value): string => trim((string) ($value ?? ''));
$imageUrl = static function ($value) use ($text): ?string {
    $value = $text($value);
    if ($value === '') return null;
    return preg_match('#^https?://#i', $value) ? $value : 'https://jiantan-erp.oss-cn-qingdao.aliyuncs.com' . '/' . ltrim($value, '/');
};
$unitFor = static function (?string $name) use ($text): Unit {
    $name = $text($name) ?: '件';
    $unit = Unit::query()->where('unit_name', $name)->where('status', 'enabled')->first();
    if ($unit) return $unit;
    $code = 'LEGACY-UNIT-' . strtoupper(substr(sha1($name), 0, 12));
    return Unit::query()->firstOrCreate(
        ['unit_code' => $code],
        ['unit_name' => $name, 'unit_type' => 'quantity', 'decimal_places' => 0, 'is_base' => false, 'status' => 'enabled']
    );
};
$notDeleted = static function ($query, string $column) {
    return $query->where(static function ($q) use ($column) {
        $q->whereNull($column)->orWhere($column, 0)->orWhere($column, '0');
    });
};

$products = $legacy->table('product_goods')->orderBy('id')->limit(10)->get();
$skus = $notDeleted($legacy->table('product_goods_sku')->orderBy('id'), 'delete_time')
    ->whereIn('goods_id', $products->pluck('id')->all())->limit(10)->get();
$items = $legacy->table('stock_goods')->orderBy('id')->limit(10)->get();

DB::transaction(function () use ($products, $skus, $items, $text, $imageUrl, $unitFor, $now, &$stats) {
    $productsByLegacyId = [];

    foreach ($products as $row) {
        $code = $text($row->volnum);
        $name = $text($row->goodsname);
        if ($code === '' || $name === '') continue;
        $unit = $unitFor($row->measureunit);
        $existing = Product::query()->where('product_code', $code)->first();
        if ($existing) {
            $stats['existing']['products']++;
            $product = $existing;
        } else {
            $product = Product::query()->create([
                'product_code' => $code,
                'product_name' => $name,
                'product_type' => 'standard',
                'model' => $text($row->productmodel) ?: null,
                'unit_id' => $unit->id,
                'image' => $imageUrl($row->image),
                'description' => $text($row->content) ?: null,
                'status' => 'enabled',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $stats['products']++;
        }
        $productsByLegacyId[(int) $row->id] = $product;
    }

    foreach ($skus as $row) {
        $product = $productsByLegacyId[(int) $row->goods_id] ?? null;
        $code = $text($row->goods_code) ?: $text($row->goods_sn);
        $name = $text($row->sku_name) ?: $text($row->goods_name);
        if (!$product || $code === '' || $name === '') continue;
        if (Sku::query()->where('sku_code', $code)->exists()) {
            $stats['existing']['skus']++;
            continue;
        }
        $unit = $unitFor($row->measureunit ?: optional($product->unit)->unit_name);
        Sku::query()->create([
            'product_id' => $product->id,
            'sales_unit_id' => $unit->id,
            'sales_unit_snapshot' => $unit->unit_name,
            'sku_code' => $code,
            'sku_name' => mb_substr($name, 0, 160),
            'spec_text' => mb_substr($name, 0, 255),
            'image' => $imageUrl($row->image),
            'sale_price' => $row->univalence === null ? null : (float) $row->univalence,
            'reference_cost' => $row->pre_cost === null ? null : (float) $row->pre_cost,
            'product_structure_type' => 'single',
            'production_policy' => 'stock',
            'fulfillment_type' => 'physical',
            'order_line_type' => 'physical',
            'electric_mode' => 'hidden',
            'need_pump_mode' => 'hidden',
            'is_sale_item' => true,
            // Old SKU records have no explicit Item relationship. They remain draft until an operator establishes one.
            'status' => 'draft',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stats['skus']++;
    }

    foreach ($items as $row) {
        $code = $text($row->volnum);
        $name = $text($row->goodsname);
        if ($code === '' || $name === '') continue;
        if (Item::query()->where('item_code', $code)->exists()) {
            $stats['existing']['items']++;
            continue;
        }
        $unit = $unitFor($row->measureunit);
        Item::query()->create([
            'item_code' => $code,
            'item_name' => $name,
            'item_type' => 'raw_material',
            'spec' => $text($row->productmodel) ?: null,
            'unit_id' => $unit->id,
            'is_purchase_item' => true,
            'is_stock_item' => true,
            'is_production_item' => false,
            'standard_cost' => (float) ($row->univalence ?? 0),
            'last_purchase_price' => (float) ($row->univalence ?? 0),
            'status' => 'enabled',
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $stats['items']++;
    }
});

echo json_encode([
    'message' => 'One-time legacy master-data sync completed. No legacy mapping data was written.',
    'selection' => [
        'products' => $products->count(),
        'skus' => $skus->count(),
        'items' => $items->count(),
        'sku_delete_time_excluded' => true,
    ],
    'written' => $stats,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
