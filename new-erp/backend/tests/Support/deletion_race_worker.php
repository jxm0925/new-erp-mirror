<?php

use App\Http\Controllers\Api\V1\Erp\PurchaseController;
use App\Models\Erp\Supplier;
use App\Services\Erp\{FinanceCashDocumentApplicationService, FinanceInvoiceApplicationService, MasterDataApplicationService, PurchaseDraftDeletionApplicationService, SalesCustomerDeletionApplicationService};
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\{Config, DB};
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = (string) getenv('ERP_DELETION_RACE_DATABASE');
$args = json_decode(base64_decode($argv[1] ?? '', true) ?: '', true, 512, JSON_THROW_ON_ERROR);
if (strtolower($database) !== 'erp_sdjiantan') {
    fwrite(STDERR, "unsafe race database\n");
    exit(2);
}
putenv('DB_DATABASE='.$database);
$_ENV['DB_DATABASE'] = $_SERVER['DB_DATABASE'] = $database;
$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Config::set('database.connections.mysql.database', $database);
DB::purge('mysql');
DB::statement('SET SESSION innodb_lock_wait_timeout = 10');
$connectionId = (int) DB::selectOne('SELECT CONNECTION_ID() AS id')->id;
$emit = static function (array $event) use ($connectionId): void {
    fwrite(STDOUT, json_encode($event + ['connection_id' => $connectionId], JSON_UNESCAPED_UNICODE)."\n");
    fflush(STDOUT);
};
$isTargetLock = static fn (string $sql): bool => str_starts_with(strtolower($sql), 'select ')
    && str_contains($sql, '`'.$args['lock_table'].'`') && str_contains(strtolower($sql), 'for update');
$attempted = false;
DB::connection()->beforeExecuting(static function (string $sql) use ($isTargetLock, $emit, &$attempted): void {
    if (!$attempted && $isTargetLock($sql)) {
        $attempted = true;
        $emit(['event' => 'lock_attempt']);
    }
});
$held = false;
DB::listen(static function ($query) use ($args, $isTargetLock, $emit, &$held): void {
    if (!$held && $isTargetLock($query->sql)) {
        $held = true;
        $emit(['event' => 'locked', 'transaction_level' => DB::transactionLevel()]);
        // 只在测试进程暂停实际业务取得的锁；不在生产服务中加入测试钩子。
        if ($args['hold'] && trim((string) fgets(STDIN)) !== 'continue') {
            throw new RuntimeException('race gate closed before release');
        }
    }
});

try {
    $id = (int) $args['id'];
    $payload = $args['payload'] ?? [];
    $result = match ($args['action']) {
        'cash_create' => $app->make(FinanceCashDocumentApplicationService::class)->create($payload['direction'], $payload, null, '并发测试'),
        'invoice_create' => $app->make(FinanceInvoiceApplicationService::class)->create($payload, null),
        'supplier_delete' => $app->make(MasterDataApplicationService::class)->deleteUnused('suppliers', Supplier::findOrFail($id)),
        'customer_delete' => $app->make(SalesCustomerDeletionApplicationService::class)->delete($id),
        'plan_submit' => $app->make(PurchaseController::class)->submitPlan($id),
        'plan_update' => $app->make(PurchaseController::class)->updatePlan(Request::create('/race', 'PUT', $payload), $id),
        'plan_delete' => $app->make(PurchaseDraftDeletionApplicationService::class)->deletePlan($id, '并发测试'),
        default => throw new InvalidArgumentException('unknown race action'),
    };
    $emit(['event' => 'result', 'status' => 200]);
} catch (ValidationException $exception) {
    $emit(['event' => 'result', 'status' => 422, 'message' => $exception->getMessage()]);
} catch (ModelNotFoundException $exception) {
    $emit(['event' => 'result', 'status' => 404]);
} catch (HttpExceptionInterface $exception) {
    $emit(['event' => 'result', 'status' => $exception->getStatusCode(), 'message' => $exception->getMessage()]);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
