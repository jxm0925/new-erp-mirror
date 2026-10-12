<?php

declare(strict_types=1);

// Existing development data only: no Laravel bootstrap, fixtures, migrations or commands.
// A session-scoped READ ONLY transaction guards every business-data query below.
$base = dirname(__DIR__);
require $base.'/vendor/autoload.php';

$pdo = null;
$stage = 'configuration';
$report = ['mode' => 'whole_schema_development_readonly', 'business_writes' => false];

try {
    $fail = static function (string $reason): never { throw new RuntimeException($reason); };
    if (is_file($base.'/bootstrap/cache/config.php') || getenv('APP_CONFIG_CACHE')) {
        $fail('configuration_cache_requires_review');
    }
    $values = Dotenv\Dotenv::createArrayBacked($base)->load();
    // Do not quietly inspect another target when a shell override or environment file
    // would make the running application's connection differ from the reviewed .env.
    foreach (['DB_CONNECTION', 'DB_HOST', 'DB_PORT', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'DB_URL', 'DB_SOCKET'] as $key) {
        $override = getenv($key);
        if ($override !== false && (string) $override !== (string) ($values[$key] ?? '')) {
            $fail('connection_environment_override_requires_review');
        }
    }
    $appEnv = getenv('APP_ENV') ?: ($values['APP_ENV'] ?? '');
    if (! preg_match('/^[A-Za-z0-9_-]*$/D', $appEnv)
        || ($appEnv !== '' && is_file($base.'/.env.'.$appEnv))) {
        $fail('environment_specific_configuration_requires_review');
    }
    if (($values['DB_CONNECTION'] ?? 'mysql') !== 'mysql' || ! empty($values['DB_URL']) || ! empty($values['DB_SOCKET'])) {
        $fail('nonstandard_connection_requires_review');
    }
    $database = $values['DB_DATABASE'] ?? '';
    $host = $values['DB_HOST'] ?? '127.0.0.1';
    $port = (string) ($values['DB_PORT'] ?? '3306');
    if (! preg_match('/^[A-Za-z0-9_]+$/D', $database) || str_ends_with(strtolower($database), '_test')
        || ! in_array($host, ['127.0.0.1', 'localhost', '::1'], true)
        || ! ctype_digit($port) || (int) $port < 1 || (int) $port > 65535) {
        $fail('expected_local_development_target_required');
    }
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false, PDO::ATTR_TIMEOUT => 5, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false];
    if (! empty($values['MYSQL_ATTR_SSL_CA'])) $options[PDO::MYSQL_ATTR_SSL_CA] = $values['MYSQL_ATTR_SSL_CA'];
    $stage = 'connection';
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4",
        $values['DB_USERNAME'] ?? 'root', $values['DB_PASSWORD'] ?? '', $options);
    $stage = 'readonly_transaction';
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $pdo->exec('SET SESSION TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    if ($pdo->query('SELECT DATABASE()')->fetchColumn() !== $database) $fail('database_identity_mismatch');
    $report['connection'] = ['driver' => 'mysql', 'host_type' => 'loopback', 'port' => (int) $port,
        'matches_development_env' => true, 'read_only_transaction' => true];


    $stage = 'schema_definitions';
    require_once $base.'/database/schema/InitialTableMigration.php';
    $manifest = \Database\Schema\InitialTableMigration::baseline();
    $definitions = \Database\Schema\InitialTableMigration::definitions();
    $version = (string) $pdo->query('SELECT VERSION()')->fetchColumn();
    $report['server_version'] = $version;
    $actual = [];
    $types = $pdo->query('SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_KEY_PAIR);
    foreach ($types as $table => $type) {
        $quoted = chr(96).str_replace(chr(96), chr(96).chr(96), $table).chr(96);
        $actual[$table] = array_values($pdo->query('SHOW CREATE TABLE '.$quoted)->fetch(PDO::FETCH_ASSOC))[1];
    }
    $report['base_table_count'] = count(array_filter($types, static fn ($type) => $type === 'BASE TABLE'));
    $report['view_count'] = count(array_filter($types, static fn ($type) => $type !== 'BASE TABLE'));
    $report['initial_definition_count'] = count($definitions);
    $report['unmanaged_tables'] = array_values(array_diff(array_keys($actual), array_merge(array_keys($definitions), ['migrations'])));
    $report['schema_differences'] = [];
    $report['check_capability_warnings'] = [];
    foreach ($definitions as $table => $definition) {
        $differences = isset($actual[$table]) ? $definition->schemaDifferences($actual[$table], $version) : ['missing table'];
        if ($differences) $report['schema_differences'][$table] = $differences;
        if (version_compare($version, '8.0.16', '<') && $definition::DECLARED_CHECKS) {
            $report['check_capability_warnings'][$table] = array_keys($definition::DECLARED_CHECKS);
        }
    }
    $report['schema_match_count'] = count($definitions) - count($report['schema_differences']);
    $ledger = isset($actual['migrations']) ? $pdo->query('SELECT id,migration,batch FROM migrations ORDER BY id')->fetchAll(PDO::FETCH_ASSOC) : [];
    $applied = array_column($ledger, 'migration');
    $report['migration_records'] = count($ledger);
    $report['known_archived_record_count'] = count(array_intersect($applied, array_keys($manifest['legacy_migrations'])));
    $report['pending_initial_registration_count'] = count(array_diff(array_values($manifest['tables']), $applied));
    $report['unknown_migration_records'] = array_values(array_diff($applied, array_keys($manifest['legacy_migrations']), array_values($manifest['tables'])));
    $connection = new \Illuminate\Database\MySqlConnection($pdo, $database, '', [
        'driver' => 'mysql', 'prefix' => '', 'charset' => 'utf8mb4', 'collation' => 'utf8mb4_unicode_ci',
    ]);
    $connection->useDefaultSchemaGrammar();
    try {
        $plan = \Database\Schema\InitialTableMigration::preflight($connection);
        $report['existing_baseline_preflight'] = ['status' => 'PASS', 'mode' => $plan['mode']];
    } catch (RuntimeException $error) {
        // Helper errors contain schema identifiers only. Driver exceptions are handled separately below.
        if ($error instanceof PDOException || $error instanceof \Illuminate\Database\QueryException) throw $error;
        $report['existing_baseline_preflight'] = ['status' => 'REFUSED', 'reason' => $error->getMessage()];
    }
    $stage = 'existing_unique_constraint_evidence';
    if (isset($report['schema_differences']['erp_production_material_consumptions'])) {
        $report['material_consumption_unique_evidence'] = [
            'required_index' => 'prod_material_consumption_source_uq',
            'columns' => ['output_record_id', 'source_holding_id'],
            'rows' => (int) $pdo->query('SELECT COUNT(*) FROM erp_production_material_consumptions')->fetchColumn(),
            'duplicate_nonnull_groups' => (int) $pdo->query('SELECT COUNT(*) FROM (SELECT output_record_id,source_holding_id FROM erp_production_material_consumptions WHERE output_record_id IS NOT NULL AND source_holding_id IS NOT NULL GROUP BY output_record_id,source_holding_id HAVING COUNT(*)>1) AS duplicate_groups')->fetchColumn(),
        ];
    }
    $stage = 'snapshot_comparison';
    foreach (array_slice($argv, 1) as $argument) {
        if (!str_starts_with($argument, '--snapshot=')) $fail('unknown_readonly_argument');
        $snapshot = json_decode(file_get_contents(substr($argument, strlen('--snapshot='))), true, 512, JSON_THROW_ON_ERROR);
        if (($snapshot['database'] ?? null) !== $database) $fail('snapshot_database_mismatch');
        $changed = [];
        foreach ($snapshot['tables'] as $table => $metadata) {
            if (!isset($actual[$table]) || \Database\Schema\InitialTableMigration::normalizeSql($actual[$table]) !== \Database\Schema\InitialTableMigration::normalizeSql($metadata['create_sql'])) $changed[] = $table;
        }
        $report['snapshot_comparison'] = [
            'compared_tables' => count($snapshot['tables']),
            'changed_structures' => $changed,
            'new_tables' => array_values(array_diff(array_keys($actual), array_keys($snapshot['tables']), array_keys($snapshot['views'] ?? []))),
            'migration_records_unchanged' => $ledger === $snapshot['migration_records'],
            'environment_file_unchanged' => hash_file('sha256', $base.'/.env') === strtolower($snapshot['environment_sha256']),
        ];
    }
    $pdo->rollBack();
    $report['limitations'] = ['Read-only metadata and aggregate evidence only; no migration, seeder, schema repair, ledger registration or business writes.',
        'Empty-database migration and write-based feature regression were not executed.'];
    $needsReview = $report['schema_differences'] || $report['unmanaged_tables'] || $report['unknown_migration_records'] || $report['existing_baseline_preflight']['status'] !== 'PASS';
    $report['result'] = $needsReview ? 'SCHEMA_REVIEW_REQUIRED' : ($report['pending_initial_registration_count'] ? 'SCHEMA_MATCH_LEDGER_PENDING' : 'SCHEMA_AND_LEDGER_MATCH');
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    exit($needsReview || $report['pending_initial_registration_count'] ? 1 : 0);
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    // Driver messages may embed hostnames or credentials. Keep stable error codes only.
    $report['result'] = 'DIAGNOSTIC_FAILED';
    $report['error'] = ['stage' => $stage, 'class' => get_class($error), 'code' => (string) $error->getCode(),
        'driver_code' => $error instanceof PDOException ? ($error->errorInfo[1] ?? null) : null];
    if (get_class($error) === RuntimeException::class) $report['error']['reason'] = $error->getMessage();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE).PHP_EOL;
    exit(2);
}
