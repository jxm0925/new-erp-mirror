<?php

use App\Exceptions\ErpSsoException;
use App\Services\Erp\LegacySsoService;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Config;

require dirname(__DIR__, 2).'/vendor/autoload.php';

$database = (string) getenv('ERP_SSO_PROBE_DATABASE');
$secret = (string) getenv('ERP_SSO_PROBE_SECRET');
if ($database === '' || $secret === '' || ($argv[1] ?? '') === '') {
    fwrite(STDERR, "missing probe arguments\n");
    exit(2);
}

putenv('DB_DATABASE='.$database);
$_ENV['DB_DATABASE'] = $database;
$_SERVER['DB_DATABASE'] = $database;

$app = require dirname(__DIR__, 2).'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
Config::set('database.connections.mysql.database', $database);
Config::set('sso.shared_secret', $secret);
Config::set('sso.ticket_ttl', 300);

try {
    $legacyId = $app->make(LegacySsoService::class)->consume($argv[1], '127.0.0.1');
    fwrite(STDOUT, json_encode(['status' => 200, 'legacy_id' => $legacyId]));
    exit(0);
} catch (ErpSsoException $exception) {
    fwrite(STDOUT, json_encode(['status' => $exception->httpStatus()]));
    exit(0);
} catch (Throwable $exception) {
    fwrite(STDERR, get_class($exception).': '.$exception->getMessage()."\n");
    exit(1);
}
