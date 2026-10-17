<?php

declare(strict_types=1);

// Explicit, non-destructive development maintenance. No application boot, fixtures,
// business UPDATEs, migration-ledger writes or automatic schema repair.
$base = dirname(__DIR__);
require $base.'/vendor/autoload.php';
require_once $base.'/database/schema/InitialTableMigration.php';
require_once $base.'/database/schema/BomCuttingSchemaSynchronizer.php';

$stage = 'configuration';
$ddlMayHaveApplied = false;
try {
    $args = []; $apply = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--apply') { $apply = true; continue; }
        if (!preg_match('/^--(table|before|backup|backup-manifest|output)=(.+)$/D', $arg, $match)) throw new RuntimeException('Unknown maintenance argument.');
        $args[$match[1]] = $match[2];
    }
    if (is_file($base.'/bootstrap/cache/config.php') || getenv('APP_CONFIG_CACHE')) throw new RuntimeException('Configuration cache requires review.');
    $values = Dotenv\Dotenv::createArrayBacked($base)->load();
    foreach (['DB_CONNECTION','DB_HOST','DB_PORT','DB_DATABASE','DB_USERNAME','DB_PASSWORD','DB_URL','DB_SOCKET','APP_ENV'] as $key) {
        $override = getenv($key);
        if ($override !== false && (string) $override !== (string) ($values[$key] ?? '')) throw new RuntimeException('Environment override requires review.');
    }
    $host = $values['DB_HOST'] ?? '';
    $port = (string) ($values['DB_PORT'] ?? '3306');
    $database = $values['DB_DATABASE'] ?? '';
    if (($values['APP_ENV'] ?? '') !== 'local' || is_file($base.'/.env.local') || $database !== 'erp_sdjiantan'
        || ($values['DB_CONNECTION'] ?? '') !== 'mysql' || !empty($values['DB_URL']) || !empty($values['DB_SOCKET'])
        || !in_array($host, ['127.0.0.1','localhost','::1'], true) || !ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        throw new RuntimeException('The explicitly reviewed local development target is required.');
    }
    if ($apply && (empty($args['before']) || empty($args['backup']) || empty($args['backup-manifest']) || empty($args['output']) || file_exists($args['output']))) {
        throw new RuntimeException('Apply requires bound evidence/backup manifest and a new output path.');
    }
    $options = [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES=>false,
        PDO::ATTR_PERSISTENT=>false, PDO::ATTR_TIMEOUT=>5, PDO::MYSQL_ATTR_MULTI_STATEMENTS=>false];
    if (!empty($values['MYSQL_ATTR_SSL_CA'])) $options[PDO::MYSQL_ATTR_SSL_CA] = $values['MYSQL_ATTR_SSL_CA'];
    $stage = 'connection';
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $values['DB_USERNAME'] ?? '', $values['DB_PASSWORD'] ?? '', $options);
    $connection = new Illuminate\Database\MySqlConnection($pdo, $database, '', ['driver'=>'mysql']);
    $table = $args['table'] ?? 'erp_bom_items';
    $service = new Database\Schema\ProductionOrderSchemaSynchronizer($table);
    $plan = $service->inspect($connection);
    if (!$apply) { echo json_encode(['result'=>'READONLY_PLAN','plan'=>$plan], JSON_PRETTY_PRINT).PHP_EOL; exit(0); }
    $stage = 'backup_and_evidence';
    $before = json_decode(file_get_contents($args['before']), true, 512, JSON_THROW_ON_ERROR);
    if (($before['database'] ?? '') !== $database || ($before['environment_sha256'] ?? '') !== hash_file('sha256', $base.'/.env')) throw new RuntimeException('Evidence target or environment differs.');
    $backup = json_decode(file_get_contents($args['backup-manifest']), true, 512, JSON_THROW_ON_ERROR);
    if (($backup['result'] ?? '') !== 'BACKUP_COMPLETE' || ($backup['database'] ?? '') !== $database
        || ($backup['environment_sha256'] ?? '') !== $before['environment_sha256']
        || realpath($backup['path'] ?? '') !== realpath($args['backup'])
        || ($backup['sha256'] ?? '') !== hash_file('sha256', $args['backup'])
        || ($backup['bytes'] ?? 0) !== filesize($args['backup'])
        || ($backup['source_evidence_sha256'] ?? '') !== hash_file('sha256', $args['before'])) {
        throw new RuntimeException('Backup manifest is not bound to this database, dump and source evidence.');
    }
    $dump = file_get_contents($args['backup']);
    if ($dump === false || !str_contains($dump, '-- Dump completed on')) throw new RuntimeException('Completed recoverable backup is required.');
    $oldDdl = (array) $connection->selectOne('SHOW CREATE TABLE `'.$table.'`', [], false);
    if ($plan['missing_column'] && Database\Schema\InitialTableMigration::normalizeSql($oldDdl['Create Table']) !== Database\Schema\InitialTableMigration::normalizeSql($before['tables'][$table]['create_sql'] ?? '')) {
        throw new RuntimeException('Target structure differs from captured evidence.');
    }
    // Hash only original columns: adding a nullable field changes SELECT * but
    // must not change any established business value, even on a populated table.
    $newColumn = match ($table) { 'erp_bom_items' => 'cutting_quantity_snapshot', 'erp_shipment_packing_materials' => 'usage_snapshot', 'erp_production_output_records' => null };
    $columnFilter = $newColumn === null ? '' : " AND COLUMN_NAME<>'$newColumn'";
    $columns = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='$table'$columnFilter ORDER BY ORDINAL_POSITION")->fetchAll(PDO::FETCH_COLUMN);
    $readRows = static function () use ($pdo, $columns, $table): array {
        $hashes = [];
        $query = $pdo->query('SELECT '.implode(',', array_map(fn ($name)=>'`'.$name.'`', $columns)).' FROM `'.$table.'` ORDER BY `id`');
        while ($row = $query->fetch(PDO::FETCH_ASSOC)) $hashes[] = hash('sha256', serialize($row));
        $query->closeCursor();
        return ['count'=>count($hashes), 'sha256'=>hash('sha256', implode("\n", $hashes))];
    };
    $rowsBefore = $readRows();
    $ledgerBefore = $pdo->query('SELECT id,migration,batch FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    $stage = 'exact_nullable_column_sync';
    $ddlMayHaveApplied = (bool) $plan['missing_column'];
    $result = $service->synchronize($connection);
    $rowsAfter = $readRows();
    $ledgerAfter = $pdo->query('SELECT id,migration,batch FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC);
    if ($rowsBefore !== $rowsAfter || $ledgerBefore !== $ledgerAfter) throw new RuntimeException('Original business values or migration history changed.');
    $report = ['result'=>'PRODUCTION_ORDER_SCHEMA_TARGET_MATCH','captured_at'=>gmdate(DATE_ATOM),'plan'=>$result,
        'backup_sha256'=>hash_file('sha256',$args['backup']),'original_target_values_unchanged'=>true,
        'migration_records_unchanged'=>true,'original_target_rows'=>$rowsAfter];
    $file = fopen($args['output'], 'x');
    if (!$file) throw new RuntimeException('Cannot create exclusive evidence output.');
    try {
        $json = json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);
        if (fwrite($file, $json) !== strlen($json)) throw new RuntimeException('Evidence output is incomplete; schema may already match.');
    }
    finally { fclose($file); }
    echo json_encode($report, JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    echo json_encode(['result'=>'FAILED','stage'=>$stage,'ddl_may_have_applied'=>$ddlMayHaveApplied,
        'needs_readonly_recheck'=>$ddlMayHaveApplied,'rollback_attempted'=>false,
        'reason'=>get_class($error) === RuntimeException::class ? $error->getMessage() : 'Maintenance failed; connection details omitted.']).PHP_EOL;
    exit(1);
}
