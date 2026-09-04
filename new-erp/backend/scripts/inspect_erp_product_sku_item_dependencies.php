<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

require __DIR__.'/../vendor/autoload.php';
$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$tables = ['erp_products', 'erp_skus', 'erp_items', 'erp_sku_item_relations'];
$counts = [];
foreach ($tables as $table) $counts[$table] = Schema::hasTable($table) ? DB::table($table)->count() : null;

$foreignKeys = DB::select(
    "SELECT TABLE_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME, REFERENCED_COLUMN_NAME
       FROM information_schema.KEY_COLUMN_USAGE
      WHERE REFERENCED_TABLE_SCHEMA = DATABASE()
        AND REFERENCED_TABLE_NAME IN ('erp_products', 'erp_skus', 'erp_items')
      ORDER BY TABLE_NAME, COLUMN_NAME"
);

$references = DB::select(
    "SELECT TABLE_NAME, COLUMN_NAME
       FROM information_schema.COLUMNS
      WHERE TABLE_SCHEMA = DATABASE()
        AND COLUMN_NAME IN ('product_id', 'sku_id', 'item_id')
      ORDER BY TABLE_NAME, COLUMN_NAME"
);
$referenceCounts = [];
foreach (collect($references)->pluck('TABLE_NAME')->unique() as $table) {
    $referenceCounts[$table] = DB::table($table)->count();
}
echo json_encode(['counts' => $counts, 'foreign_keys' => $foreignKeys, 'references' => $references, 'reference_counts' => $referenceCounts], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT).PHP_EOL;
