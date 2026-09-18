<?php

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\CuttingDemandService;
use App\Services\Erp\CuttingRecordService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Tests\Support\CommittedInsertTracker;

require dirname(__DIR__, 2).'/vendor/autoload.php';
$database = (string) getenv('ERP_CUTTING_DEMAND_RACE_DATABASE');
if (! str_ends_with($database, '_test')) {
    exit(2);
}
$args = json_decode(base64_decode($argv[1] ?? '', true), true, 512, JSON_THROW_ON_ERROR);
putenv('DB_DATABASE='.$database);
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Config::set('database.connections.mysql.database', $database);
DB::purge('mysql');
if (DB::selectOne('SELECT DATABASE() AS name')->name !== $database) {
    exit(2);
}
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
$connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$emit = static function (array $event) use ($connectionId): void {
    fwrite(STDOUT, json_encode($event + ['connection_id' => $connectionId], JSON_UNESCAPED_UNICODE)."\n");
    fflush(STDOUT);
};
(new CommittedInsertTracker)->listen($emit);
$lockTable = ($args['operation'] ?? 'generate') === 'publish'
    ? '`erp_document_numbers`' : '`erp_work_orders`';
$target = static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select ')
    && str_contains($sql, $lockTable)
    && str_contains(strtolower($sql), 'for update');
$attempted = $held = false;
DB::connection()->beforeExecuting(static function (string $sql) use ($target, $emit, &$attempted): void {
    if (! $attempted && $target($sql)) {
        $attempted = true;
        $emit(['event' => 'lock_attempt']);
    }
});
DB::listen(static function ($query) use ($args, $target, $emit, &$held): void {
    if (! $held && $target($query->sql)) {
        $held = true;
        $emit(['event' => 'locked', 'transaction_level' => DB::transactionLevel()]);
        if ($args['hold'] && trim((string) fgets(STDIN)) !== 'continue') {
            throw new RuntimeException('closed race gate');
        }
    }
});
try {
    $result = ($args['operation'] ?? 'generate') === 'publish'
        ? $app->make(CuttingRecordService::class)->publish(
            $args['payload'], (object) $args['user'], $args['permissions'], true,
        )
        : $app->make(CuttingDemandService::class)->generate(
            $args['payload'], (object) $args['user'], $args['permissions'], true,
        );
    $emit(['event' => 'result', 'status' => 201, 'response' => $result]);
} catch (WorkOrderDomainException $exception) {
    $emit(['event' => 'result', 'status' => $exception->status, 'code' => $exception->errorCode, 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
