<?php

declare(strict_types=1);

// Explicit local-development repair: keep historical outputs, relax one column,
// and copy the old item scope only when a route has no applicability at all.
use Database\Schema\InitialTableMigration;

$base = dirname(__DIR__);
require $base.'/vendor/autoload.php';
require_once $base.'/database/schema/InitialTableMigration.php';

$pdo = null;
$output = null;
$stage = 'configuration';
$report = ['result' => 'FAILED', 'ddl_may_have_applied' => false, 'scope_backfill_committed' => false];
try {
    $args = []; $apply = false;
    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--apply' && !$apply) { $apply = true; continue; }
        if (!preg_match('/^--(before|output)=(.+)$/D', $arg, $match) || isset($args[$match[1]])) throw new RuntimeException('Unknown or repeated maintenance argument.');
        $args[$match[1]] = $match[2];
    }
    $evidenceRoot = realpath('D:/codex-introduce/new_erp/docs/ui-check/seven-fixes-20261027');
    foreach ($args as $path) {
        if (!$evidenceRoot || realpath(dirname($path)) !== $evidenceRoot) throw new RuntimeException('Evidence must remain in the reviewed external evidence directory.');
    }
    if (($apply && (empty($args['before']) || empty($args['output']))) || (!$apply && isset($args['before']))) throw new RuntimeException('Apply requires before evidence and a new output file.');
    if (isset($args['output']) && file_exists($args['output'])) throw new RuntimeException('Evidence output already exists.');
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
        throw new RuntimeException('The reviewed loopback development database is required.');
    }
    $options = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_EMULATE_PREPARES => false,
        PDO::ATTR_PERSISTENT => false, PDO::ATTR_TIMEOUT => 5, PDO::MYSQL_ATTR_MULTI_STATEMENTS => false];
    if (!empty($values['MYSQL_ATTR_SSL_CA'])) $options[PDO::MYSQL_ATTR_SSL_CA] = $values['MYSQL_ATTR_SSL_CA'];
    $stage = 'connection';
    $pdo = new PDO("mysql:host={$host};port={$port};dbname={$database};charset=utf8mb4", $values['DB_USERNAME'] ?? '', $values['DB_PASSWORD'] ?? '', $options);
    $identity = $pdo->query('SELECT DATABASE() AS database_name, VERSION() AS version, @@server_uuid AS server_uuid')->fetch(PDO::FETCH_ASSOC);
    if ($identity['database_name'] !== $database || !preg_match('/^8\.0\.[0-9]+$/D', $identity['version'])) throw new RuntimeException('The reviewed MySQL 8.0 development target is required.');
    $pdo->exec('SET SESSION lock_wait_timeout=5');
    $pdo->exec('SET SESSION innodb_lock_wait_timeout=5');
    $pdo->exec('SET SESSION TRANSACTION ISOLATION LEVEL REPEATABLE READ');
    $definitions = InitialTableMigration::definitions();
    $routeTable = 'erp_production_routings';
    $operationTable = 'erp_production_routing_operations';
    $scopeTable = 'erp_production_routing_applicabilities';
    $targetTables = [$routeTable, $operationTable, $scopeTable];
    $protectedTables = $targetTables;
    foreach ($definitions as $table => $definition) {
        if (str_contains($definition::CREATE_SQL, 'erp_production_routing')
            || preg_match('/`(?:production_)?routing(?:_operation)?_id`|`routing_snapshot`/', $definition::CREATE_SQL)) $protectedTables[] = $table;
    }
    $protectedTables = array_values(array_unique(array_merge($protectedTables, ['migrations'])));
    $definitionHashes = array_map(static fn ($definition) => hash('sha256', InitialTableMigration::normalizeSql($definition::CREATE_SQL)), $definitions);
    $inspectSchema = static function () use ($pdo, $definitions, $routeTable, $operationTable, $scopeTable, $identity): array {
        $actualTables = $pdo->query('SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_TYPE="BASE TABLE" ORDER BY TABLE_NAME')->fetchAll(PDO::FETCH_COLUMN);
        $expectedTables = array_merge(array_keys($definitions), ['migrations']); sort($expectedTables);
        if ($actualTables !== $expectedTables || (int) $pdo->query('SELECT COUNT(*) FROM information_schema.VIEWS WHERE TABLE_SCHEMA=DATABASE()')->fetchColumn()) throw new RuntimeException('The complete reviewed baseline is required.');
        $applied = $pdo->query('SELECT migration FROM migrations ORDER BY migration')->fetchAll(PDO::FETCH_COLUMN);
        $expectedLedger = array_values(InitialTableMigration::baseline()['tables']); sort($expectedLedger);
        if ($applied !== $expectedLedger) throw new RuntimeException('Migration ledger differs from the complete reviewed baseline.');
        $sql = []; $needsNullable = false;
        foreach ($definitions as $table => $definition) {
            $actual = $pdo->query('SHOW CREATE TABLE `'.$table.'`')->fetch(PDO::FETCH_NUM)[1];
            if (in_array($table, [$routeTable, $operationTable, $scopeTable], true)) $sql[$table] = $actual;
            if (!$definition->schemaDifferences($actual, $identity['version'])) continue;
            $old = str_replace('`output_item_id` bigint(20) unsigned DEFAULT NULL,', '`output_item_id` bigint(20) unsigned NOT NULL,', $definition::CREATE_SQL, $replacements);
            if ($table !== $routeTable || $replacements !== 1 || InitialTableMigration::normalizeSql($actual) !== InitialTableMigration::normalizeSql($old)) throw new RuntimeException('Unexpected schema drift in '.$table.'.');
            $needsNullable = true;
        }
        return ['needs_nullable_column' => $needsNullable, 'create_sql' => $sql, 'matched_definitions' => count($definitions) - (int) $needsNullable];
    };
    $capture = static function (bool $lockTargets = false) use ($pdo, $protectedTables, $targetTables): array {
        $result = ['summaries' => [], 'rows' => []];
        foreach ($protectedTables as $table) {
            $keepRows = in_array($table, $targetTables, true);
            if ($keepRows) $result['rows'][$table] = [];
            $query = $pdo->query('SELECT * FROM `'.$table.'` ORDER BY `id`'.($lockTargets && $keepRows ? ' FOR UPDATE' : ''));
            $hash = hash_init('sha256'); $count = 0;
            while ($row = $query->fetch(PDO::FETCH_ASSOC)) {
                hash_update($hash, serialize($row)."\n"); $count++;
                if ($keepRows) $result['rows'][$table][] = $row;
            }
            $query->closeCursor();
            $result['summaries'][$table] = ['count' => $count, 'sha256' => hash_final($hash)];
        }
        return $result;
    };
    $missingScopes = static function (array $snapshot) use ($routeTable, $scopeTable): array {
        $scoped = array_fill_keys(array_column($snapshot['rows'][$scopeTable], 'routing_id'), true);
        $missing = [];
        foreach ($snapshot['rows'][$routeTable] as $route) {
            if ($route['output_item_id'] !== null && !isset($scoped[$route['id']])) $missing[] = [
                'routing_id' => (int) $route['id'], 'scope_type' => 'item', 'item_id' => (int) $route['output_item_id'],
                'item_category_id' => null, 'scope_key' => 'item:'.$route['output_item_id'],
            ];
        }
        return $missing;
    };
    $preserved = static function (array $current, array $before) use ($scopeTable): void {
        foreach ($before['snapshot']['summaries'] as $table => $summary) {
            if ($table !== $scopeTable && ($current['summaries'][$table] ?? null) !== $summary) throw new RuntimeException('Original rows changed in '.$table.'.');
        }
        $original = array_column($before['snapshot']['rows'][$scopeTable], null, 'id');
        foreach ($current['rows'][$scopeTable] as $row) {
            if (isset($original[$row['id']])) {
                if ($row !== $original[$row['id']]) throw new RuntimeException('An original applicability changed.');
                unset($original[$row['id']]); continue;
            }
            $core = array_intersect_key($row, array_flip(['routing_id','scope_type','item_id','item_category_id','scope_key']));
            if (!in_array($core, $before['scope_backfill'], true)) throw new RuntimeException('An unplanned applicability was added.');
        }
        if ($original) throw new RuntimeException('An original applicability was removed.');
    };
    $writeReport = static function () use (&$output, &$report): void {
        if (!$output) return;
        $json = json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR).PHP_EOL;
        rewind($output);
        if (!ftruncate($output, 0) || fwrite($output, $json) !== strlen($json) || !fflush($output)) throw new RuntimeException('Evidence output could not be completed.');
    };
    $stage = 'readonly_preflight';
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $schema = $inspectSchema();
    $snapshot = $capture();
    $pdo->rollBack();
    $plan = ['result' => 'READONLY_PLAN', 'captured_at' => gmdate(DATE_ATOM), 'database' => $database,
        'server_uuid' => $identity['server_uuid'], 'server_version' => $identity['version'],
        'environment_sha256' => hash_file('sha256', $base.'/.env'), 'definition_sha256' => $definitionHashes,
        'schema' => $schema, 'snapshot' => $snapshot, 'scope_backfill' => $missingScopes($snapshot),
        'ddl' => $schema['needs_nullable_column'] ? 'ALTER TABLE `erp_production_routings` MODIFY COLUMN `output_item_id` bigint(20) unsigned NULL DEFAULT NULL, ALGORITHM=INPLACE, LOCK=NONE' : null];
    if (isset($args['output'])) {
        $output = fopen($args['output'], 'x');
        if (!$output) throw new RuntimeException('Cannot exclusively create evidence output.');
    }
    if (!$apply) {
        $report = $plan; $writeReport();
        echo json_encode(['result' => 'READONLY_PLAN', 'schema' => $schema, 'scope_backfill' => $plan['scope_backfill'], 'summaries' => $snapshot['summaries']], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
        exit(0);
    }
    $stage = 'bound_evidence';
    $before = json_decode(file_get_contents($args['before']), true, 512, JSON_THROW_ON_ERROR);
    foreach (['database','server_uuid','server_version','environment_sha256','definition_sha256'] as $key) {
        if (($before[$key] ?? null) !== $plan[$key]) throw new RuntimeException('Reviewed evidence no longer matches '.$key.'.');
    }
    if (($before['result'] ?? null) !== 'READONLY_PLAN') throw new RuntimeException('A successful read-only plan is required.');
    $preserved($snapshot, $before);
    foreach ($plan['scope_backfill'] as $scope) if (!in_array($scope, $before['scope_backfill'], true)) throw new RuntimeException('The required backfill differs from reviewed evidence.');
    $report += ['before_sha256' => hash_file('sha256', $args['before']), 'database' => $database,
        'original_summaries' => $before['snapshot']['summaries'], 'schema_before' => $schema, 'scope_insertions' => []];
    $report['result'] = 'IN_PROGRESS'; $writeReport();
    if ((int) $pdo->query("SELECT GET_LOCK('erp_routing_legacy_schema_compat', 5)")->fetchColumn() !== 1) throw new RuntimeException('Another routing maintenance operation holds the lock.');
    $stage = 'nullable_column';
    // DDL commits independently; retain the old definition and full original route/node rows in before evidence.
    if ($schema['needs_nullable_column']) { $report['ddl_may_have_applied'] = true; $writeReport(); $pdo->exec($plan['ddl']); }
    $stage = 'scope_backfill';
    $pdo->beginTransaction();
    $locked = $capture(true);
    $preserved($locked, $before);
    $insert = $pdo->prepare('INSERT INTO `erp_production_routing_applicabilities` (`routing_id`,`scope_type`,`item_id`,`item_category_id`,`scope_key`,`created_at`,`updated_at`) VALUES (?,?,?,?,?,CURRENT_TIMESTAMP,CURRENT_TIMESTAMP)');
    foreach ($missingScopes($locked) as $scope) {
        if (!in_array($scope, $before['scope_backfill'], true)) throw new RuntimeException('Locked route scope differs from reviewed evidence.');
        $insert->execute(array_values($scope));
        $report['scope_insertions'][] = ['id' => (int) $pdo->lastInsertId()] + $scope;
    }
    $preserved($capture(), $before);
    $schemaAfter = $inspectSchema();
    if ($schemaAfter['needs_nullable_column']) throw new RuntimeException('The target nullable definition was not reached.');
    $pdo->commit();
    $report['scope_backfill_committed'] = true; $writeReport();
    $stage = 'after_readonly_verification';
    $pdo->exec('SET TRANSACTION READ ONLY');
    $pdo->beginTransaction();
    $after = $capture();
    $preserved($after, $before);
    if ($missingScopes($after)) throw new RuntimeException('A legacy route still has no applicability.');
    $pdo->rollBack();
    $report['result'] = 'ROUTING_SCHEMA_AND_LEGACY_SCOPES_MATCH';
    $report['schema_after'] = $schemaAfter;
    $report['after_summaries'] = $after['summaries'];
    $report['original_rows_and_associations_unchanged'] = true;
    $report['migration_records_unchanged'] = true;
    $report['completed_at'] = gmdate(DATE_ATOM);
    $writeReport();
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
} catch (Throwable $error) {
    if ($pdo instanceof PDO && $pdo->inTransaction()) $pdo->rollBack();
    $report['result'] = 'FAILED'; $report['stage'] = $stage;
    $report['reason'] = get_class($error) === RuntimeException::class ? $error->getMessage() : 'Maintenance failed; connection details omitted.';
    $report['driver_code'] = $error instanceof PDOException ? ($error->errorInfo[1] ?? null) : null;
    $report['rollback_attempted_for_ddl'] = false;
    if (isset($writeReport)) { try { $writeReport(); } catch (Throwable) {} }
    echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL;
    exit(1);
} finally {
    if (is_resource($output)) fclose($output);
    // Connection close also releases the advisory lock, including every failure path.
    $pdo = null;
}
