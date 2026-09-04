<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require __DIR__ . '/../vendor/autoload.php';

$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$legacy = DB::connection('fastadmin');
$tables = ['product_goods', 'product_goods_sku', 'stock_goods'];
$result = [
    'new_database' => [
        'units' => DB::table('erp_units')->select('id', 'unit_code', 'unit_name', 'status')->get()->map(static fn ($row) => (array) $row)->all(),
        'counts' => [
            'products' => DB::table('erp_products')->count(),
            'skus' => DB::table('erp_skus')->count(),
            'items' => DB::table('erp_items')->count(),
        ],
        'sku_columns' => DB::getSchemaBuilder()->getColumnListing('erp_skus'),
    ],
];

foreach ($tables as $table) {
    $columns = $legacy->getSchemaBuilder()->getColumnListing($table);
    $deleteColumn = in_array('delete_time', $columns, true) ? 'delete_time'
        : (in_array('deletetime', $columns, true) ? 'deletetime' : null);
    $query = $legacy->table($table)->orderBy('id');
    if ($deleteColumn) {
        $query->where(function ($q) use ($deleteColumn) {
            $q->whereNull($deleteColumn)->orWhere($deleteColumn, 0)->orWhere($deleteColumn, '0');
        });
    }
    $result[$table] = [
        'columns' => $columns,
        'count_not_deleted' => (clone $query)->count(),
        'sample' => $query->limit(15)->get()->map(static fn ($row) => (array) $row)->all(),
    ];
}

echo json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
