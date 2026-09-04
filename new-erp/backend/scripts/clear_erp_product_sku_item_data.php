<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

// Deliberately limited to Product, SKU and Item data.
// Downstream tables were inspected before execution; they contain no rows.
$result = DB::transaction(function (): array {
    $deleted = [];
    $deleted['erp_sku_item_relations'] = DB::table('erp_sku_item_relations')->delete();
    $deleted['erp_skus'] = DB::table('erp_skus')->delete();
    $deleted['erp_items'] = DB::table('erp_items')->delete();
    $deleted['erp_products'] = DB::table('erp_products')->delete();

    return $deleted;
});

echo json_encode([
    'deleted' => $result,
    'remaining' => [
        'erp_products' => DB::table('erp_products')->count(),
        'erp_skus' => DB::table('erp_skus')->count(),
        'erp_items' => DB::table('erp_items')->count(),
        'erp_sku_item_relations' => DB::table('erp_sku_item_relations')->count(),
    ],
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
