<?php

declare(strict_types=1);

// Existing development data only: no Laravel bootstrap, fixtures, migrations or commands.
// A session-scoped READ ONLY transaction guards every business-data query below.
$base = dirname(__DIR__);
require $base.'/vendor/autoload.php';

$pdo = null;
$stage = 'configuration';
$report = ['mode' => 'development_readonly', 'business_writes' => false];

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

    $stage = 'schema';
    $required = [
        'migrations' => ['migration'],
        'erp_work_orders' => ['id', 'assembly_root_work_order_id', 'assembly_parent_work_order_id', 'assembly_component_demand_id', 'status'],
        'erp_assembly_production_plans' => ['id', 'root_work_order_id', 'status'],
        'erp_assembly_component_demands' => ['id', 'root_work_order_id', 'parent_work_order_id', 'child_work_order_id'],
        'erp_assembly_inventory_reservations' => ['id', 'component_demand_id', 'reserved_base_qty', 'consumed_base_qty'],
        'erp_inventory_transaction_items' => ['change_qty'],
        'erp_work_order_material_preparations' => ['id', 'work_order_id', 'version', 'work_order_version', 'status', 'input_hash', 'input_snapshot'],
        'erp_work_order_material_preparation_versions' => ['id', 'preparation_id', 'version', 'input_hash', 'input_snapshot'],
        'erp_work_order_preparation_materials' => ['id', 'work_order_id', 'preparation_id', 'preparation_version', 'identity_key', 'material_requirement_id'],
        'erp_material_procurement_sources' => ['preparation_material_requirement_id', 'preparation_version'],
    ];
    $columns = [];
    $statement = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?');
    foreach ($required as $table => $fields) {
        $statement->execute([$database, $table]);
        $columns[$table] = $statement->fetchAll(PDO::FETCH_COLUMN);
        $missing = array_values(array_diff($fields, $columns[$table]));
        $report['schema'][$table] = ['status' => $missing ? 'MISSING' : 'PASS', 'missing_columns' => $missing];
    }
    if (in_array('migration', $columns['migrations'], true)) {
        $applied = $pdo->query('SELECT migration FROM migrations')->fetchAll(PDO::FETCH_COLUMN);
        $files = array_map(static fn ($path) => basename($path, '.php'), glob($base.'/database/migrations/*.php'));
        $report['applied_migration_count'] = count($applied);
        $report['pending_migrations'] = array_values(array_diff($files, $applied));
        $baselinePath = $base.'/database/schema/baseline.json';
        $baseline = is_file($baselinePath) ? json_decode(file_get_contents($baselinePath), true, 512, JSON_THROW_ON_ERROR) : [];
        $archived = array_keys($baseline['legacy_migrations'] ?? []);
        $report['archived_legacy_migrations'] = array_values(array_intersect($applied, $archived));
        $report['recorded_migrations_without_files'] = array_values(array_diff($applied, $files, $archived));
        $report['migration_compatibility'] = [];
        $pendingBaseline = array_values(array_intersect($report['pending_migrations'], array_values($baseline['tables'] ?? [])));
        if ($pendingBaseline) $report['migration_compatibility'][] = ['status' => 'BASELINE_REGISTRATION_NOT_EXECUTED',
            'pending_count' => count($pendingBaseline), 'verify_with' => 'php tools/verify-schema-baseline-readonly.php'];
        foreach ($report['pending_migrations'] as $migration) {
            $source = file_get_contents($base.'/database/migrations/'.$migration.'.php');
            preg_match_all("/Schema::create\('([^']+)'/", $source, $created);
            foreach ($created[1] as $table) {
                if (! empty($columns[$table])) {
                    $report['migration_compatibility'][] = ['migration' => $migration, 'existing_table' => $table,
                        'status' => 'REQUIRES_REVIEWED_SCHEMA_AND_LEDGER_SYNC'];
                }
            }
        }
    }

    $stage = 'existing_data';
    $checks = [
        'assembly_plan_missing_root' => [['erp_assembly_production_plans', 'erp_work_orders'],
            'SELECT COUNT(*) FROM erp_assembly_production_plans p LEFT JOIN erp_work_orders w ON w.id=p.root_work_order_id WHERE w.id IS NULL'],
        'child_missing_root' => [['erp_work_orders'],
            'SELECT COUNT(*) FROM erp_work_orders c LEFT JOIN erp_work_orders r ON r.id=c.assembly_root_work_order_id WHERE c.assembly_root_work_order_id IS NOT NULL AND r.id IS NULL'],
        'child_missing_parent' => [['erp_work_orders'],
            'SELECT COUNT(*) FROM erp_work_orders c LEFT JOIN erp_work_orders p ON p.id=c.assembly_parent_work_order_id WHERE c.assembly_parent_work_order_id IS NOT NULL AND p.id IS NULL'],
        'demand_missing_child' => [['erp_assembly_component_demands', 'erp_work_orders'],
            'SELECT COUNT(*) FROM erp_assembly_component_demands d LEFT JOIN erp_work_orders c ON c.id=d.child_work_order_id WHERE d.child_work_order_id IS NOT NULL AND c.id IS NULL'],
        'demand_child_relation_mismatch' => [['erp_assembly_component_demands', 'erp_work_orders'],
            'SELECT COUNT(*) FROM erp_assembly_component_demands d JOIN erp_work_orders c ON c.id=d.child_work_order_id WHERE NOT (c.assembly_root_work_order_id <=> d.root_work_order_id) OR NOT (c.assembly_parent_work_order_id <=> d.parent_work_order_id) OR NOT (c.assembly_component_demand_id <=> d.id)'],
        'reservation_missing_demand' => [['erp_assembly_inventory_reservations', 'erp_assembly_component_demands'],
            'SELECT COUNT(*) FROM erp_assembly_inventory_reservations r LEFT JOIN erp_assembly_component_demands d ON d.id=r.component_demand_id WHERE d.id IS NULL'],
        'reservation_invalid_quantity' => [['erp_assembly_inventory_reservations'],
            'SELECT COUNT(*) FROM erp_assembly_inventory_reservations WHERE reserved_base_qty < 0 OR consumed_base_qty < 0 OR consumed_base_qty > reserved_base_qty'],
    ];
    foreach ($checks as $name => [$tables, $sql]) {
        $schemaReady = true;
        foreach ($tables as $table) $schemaReady = $schemaReady && $report['schema'][$table]['status'] === 'PASS';
        if (! $schemaReady) {
            $report['existing_data'][$name] = ['status' => 'SKIPPED_MISSING_SCHEMA'];
            continue;
        }
        $count = (int) $pdo->query($sql)->fetchColumn();
        $report['existing_data'][$name] = ['status' => $count === 0 ? 'PASS' : 'REVIEW', 'matching_rows' => $count];
    }
    foreach (['erp_work_orders', 'erp_assembly_production_plans', 'erp_assembly_component_demands', 'erp_assembly_inventory_reservations'] as $table) {
        if (! empty($columns[$table])) $report['observed_row_counts'][$table] = (int) $pdo->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
    }
    $pdo->rollBack();
    $pdo = null;
    $report['limitations'] = ['Existing-data snapshot only; empty tables do not exercise business flows.',
        'No fixture insertion, application commands, API permission checks, retries, release or concurrency execution.',
        'Does not replace the 26 write-based Feature regressions or real-page acceptance.'];
    $missingSchema = in_array('MISSING', array_column($report['schema'], 'status'), true);
    $reviewData = in_array('REVIEW', array_column($report['existing_data'], 'status'), true);
    $baselineConflict = ! empty($report['migration_compatibility']) || ! empty($report['recorded_migrations_without_files']);
    $report['result'] = $missingSchema || $reviewData || $baselineConflict ? 'REVIEW_REQUIRED' : 'READONLY_CHECKS_PASS';
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
    exit($missingSchema || $reviewData || $baselineConflict ? 1 : 0);
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
