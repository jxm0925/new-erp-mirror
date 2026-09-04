<?php

use App\Models\Erp\Item;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\Unit;
use App\Services\Erp\ProductionMasterDataService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$mode = $argv[1] ?? '';

try {
    if ($mode === 'setup') {
        $ownerId = random_int(300000000, 399999999);
        $prefix = 'P6A2-C1-'.$ownerId;
        DB::table('erp_legacy_admin_users')->insert([
            'legacy_id' => $ownerId, 'username' => strtolower($prefix), 'nickname' => '6A.2 并发验收员',
            'status' => 'normal', 'auth_group_names' => '[]', 'created_at' => now(), 'updated_at' => now(),
        ]);
        $unit = Unit::create(['unit_code' => $prefix.'-U', 'unit_name' => '件', 'unit_type' => 'count', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $prefix.'-FG', 'item_name' => '6A.2 并发成品', 'unit_id' => $unit->id, 'status' => 'enabled', 'is_production_item' => true, 'is_stock_item' => true]);
        $operationId = DB::table('erp_production_operations')->insertGetId([
            'operation_no' => $prefix.'-OP', 'operation_name' => '并发复制工序', 'status' => 'enabled',
            'sort' => 10, 'business_version' => 1, 'created_at' => now(), 'updated_at' => now(),
        ]);
        $routingNo = $prefix.'-RT';
        $ids = [];
        foreach ([1, 2] as $version) {
            $ids[] = DB::table('erp_production_routings')->insertGetId([
                'routing_no' => $routingNo, 'routing_name' => '并发路线 V'.$version,
                'output_item_id' => $item->id, 'version' => $version, 'status' => $version === 1 ? 'active' : 'draft',
                'is_default' => $version === 1, 'default_scope_key' => $version === 1 ? $item->id : null,
                'business_version' => 1, 'created_by_legacy_id' => $ownerId,
                'updated_by_legacy_id' => $ownerId, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('erp_production_routing_operations')->insert([
                'routing_id' => end($ids), 'operation_id' => $operationId, 'sequence' => 10,
                'parameters' => json_encode(['fixture' => 'C1']), 'is_key_operation' => true,
                'remark' => 'C1 完整工序', 'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        echo json_encode(['ok' => true, 'owner_id' => $ownerId, 'routing_no' => $routingNo, 'v1_id' => $ids[0], 'v2_id' => $ids[1]]);
        exit(0);
    }

    if ($mode === 'copy') {
        $sourceId = (int) ($argv[2] ?? 0);
        $ownerId = (int) ($argv[3] ?? 0);
        $commandId = (string) ($argv[4] ?? '');
        $user = DB::table('erp_legacy_admin_users')->where('legacy_id', $ownerId)->first();
        if (! $user) throw new RuntimeException('probe user not found');
        putenv('PRODUCTION_ROUTING_COPY_TEST_DELAY_MS=750');
        $copy = app(ProductionMasterDataService::class)->copyRouting(
            $sourceId,
            ['client_command_id' => $commandId],
            $user,
            ['production.routing.create'],
            true,
        );
        echo json_encode(['ok' => true, 'id' => $copy->id, 'version' => $copy->version, 'operation_count' => $copy->operations->count()]);
        exit(0);
    }

    if ($mode === 'inspect') {
        $routingNo = (string) ($argv[2] ?? '');
        $versions = ProductionRouting::where('routing_no', $routingNo)->orderBy('version')->get();
        echo json_encode([
            'ok' => true,
            'versions' => $versions->pluck('version')->map(fn ($value) => (int) $value)->all(),
            'unique_versions' => $versions->pluck('version')->unique()->count(),
            'operation_counts' => $versions->map(fn ($route) => $route->operations()->count())->all(),
        ]);
        exit(0);
    }

    if ($mode === 'cleanup') {
        $ownerId = (int) ($argv[2] ?? 0);
        $prefix = 'P6A2-C1-'.$ownerId;
        $routingIds = DB::table('erp_production_routings')->where('routing_no', $prefix.'-RT')->pluck('id');
        DB::table('erp_production_master_commands')->where('initiated_by_legacy_id', $ownerId)->delete();
        DB::table('erp_production_routing_operations')->whereIn('routing_id', $routingIds)->delete();
        DB::table('erp_production_routings')->whereIn('id', $routingIds)->delete();
        DB::table('erp_production_operations')->where('operation_no', $prefix.'-OP')->delete();
        DB::table('erp_items')->where('item_code', $prefix.'-FG')->delete();
        DB::table('erp_units')->where('unit_code', $prefix.'-U')->delete();
        DB::table('erp_legacy_admin_users')->where('legacy_id', $ownerId)->delete();
        echo json_encode(['ok' => true]);
        exit(0);
    }

    throw new RuntimeException('unknown probe mode');
} catch (Throwable $exception) {
    echo json_encode([
        'ok' => false,
        'error_code' => get_class($exception),
        'message' => $exception->getMessage(),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit(0);
}
