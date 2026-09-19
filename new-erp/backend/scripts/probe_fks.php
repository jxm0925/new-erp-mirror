<?php
require __DIR__ . '/../vendor/autoload.php';
$app = require __DIR__ . '/../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
use Illuminate\Support\Facades\DB;
$rows = DB::select("SELECT CONSTRAINT_NAME, COLUMN_NAME, REFERENCED_TABLE_NAME
  FROM information_schema.KEY_COLUMN_USAGE
  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'erp_cutting_allowed_outputs' AND REFERENCED_TABLE_NAME IS NOT NULL");
$cols = DB::select('SHOW COLUMNS FROM erp_cutting_orders');
echo json_encode(['fks'=>$rows,'order_cols'=>$cols], JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE), PHP_EOL;
