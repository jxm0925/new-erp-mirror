<?php

namespace Tests\Unit\Architecture;

use Illuminate\Database\MySqlConnection;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/InitialTableMigrationRuntimeTest.php';

/** Metadata and PDO execution are in memory; neither the app nor a database is booted. */
class EstablishedBaselineExtensionTest extends TestCase
{
    private function fixture(bool $legacy = true): array
    {
        $connection = new BaselineConnection;
        $connection->tables = ['a' => BaselineA::CREATE_SQL, 'b' => BaselineB::CREATE_SQL];
        $connection->hasRows = ['a' => true, 'b' => true];
        $connection->records = array_merge($legacy ? ['legacy_one', 'legacy_two'] : [], ['initial_a', 'initial_b']);
        $owner = new EstablishedC;
        $owner->fixtureConnection = $connection;
        return [$connection, $owner];
    }

    public function test_legacy_extension_creates_only_new_table_without_changing_data_ledger_or_foreign_keys(): void
    {
        [$connection, $owner] = $this->fixture();
        $before = $connection->tables;
        $records = $connection->records;
        $this->assertSame('extension', $owner->preflightEstablishedExtension($connection)['mode']);
        $this->assertSame([], $connection->recordingPdo->statements);
        $owner->up();
        $this->assertSame($before, array_intersect_key($connection->tables, $before));
        $this->assertSame($records, $connection->records);
        $this->assertSame(['a' => true, 'b' => true], $connection->hasRows);
        $this->assertSame(1, $connection->foreignKeyChecks);
        $this->assertSame(31536000, $connection->lockWaitTimeout);
        $this->assertCount(3, $connection->recordingPdo->statements);
        $this->assertSame('SET SESSION lock_wait_timeout = 5', $connection->recordingPdo->statements[0]);
        $this->assertStringStartsWith('CREATE TABLE `c`', $connection->recordingPdo->statements[1]);
        $this->assertSame('SET SESSION lock_wait_timeout = 31536000', $connection->recordingPdo->statements[2]);
        $owner->up();
        $this->assertCount(3, $connection->recordingPdo->statements, 'Successful CREATE with missing ledger may be retried without another mutation.');
    }

    public function test_canonical_established_database_can_extend_then_retry_and_register_without_rebuilding(): void
    {
        [$connection, $owner] = $this->fixture(false);
        $owner->up();
        $owner->up();
        $this->assertCount(3, $connection->recordingPdo->statements);
        $connection->records[] = 'initial_c';
        $owner->up();
        $this->assertCount(3, $connection->recordingPdo->statements);
        $this->assertSame('existing', EstablishedC::preflight($connection)['mode']);
    }

    public function test_ordinary_preflight_does_not_bypass_missing_table_guard(): void
    {
        [$connection] = $this->fixture();
        $this->expectExceptionMessage('Partial legacy database');
        EstablishedC::preflight($connection);
    }

    public static function rejectedStates(): array
    {
        return [
            ['missing_old', 'Recorded initial migration has no table'],
            ['missing_old_and_record', 'Partial legacy database'],
            ['old_drift', 'Schema drift in b'],
            ['unknown_table', 'Unknown database tables'],
            ['unknown_record', 'Unknown migration ledger entries'],
            ['legacy_incomplete', 'Incomplete legacy migration ledger'],
            ['new_record_without_table', 'Recorded initial migration has no table'],
            ['unaccepted_old_canonical', 'Partial legacy database'],
        ];
    }

    #[DataProvider('rejectedStates')]
    public function test_extension_refuses_invalid_existing_baseline_before_any_statement(string $case, string $message): void
    {
        [$connection, $owner] = $this->fixture();
        switch ($case) {
            case 'missing_old': unset($connection->tables['b']); break;
            case 'missing_old_and_record': unset($connection->tables['b']); $connection->records = ['legacy_one', 'legacy_two', 'initial_a']; break;
            case 'old_drift': $connection->tables['b'] = str_replace('bigint', 'int', $connection->tables['b']); break;
            case 'unknown_table': $connection->tables['unexpected'] = 'unused'; break;
            case 'unknown_record': $connection->records[] = 'unknown'; break;
            case 'legacy_incomplete': $connection->records = ['legacy_one', 'initial_a', 'initial_b']; break;
            case 'new_record_without_table': $connection->records[] = 'initial_c'; break;
            case 'unaccepted_old_canonical': $connection->records = ['legacy_one', 'legacy_two']; break;
        }
        try {
            $owner->up();
            $this->fail('Unsafe baseline must not be extended.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString($message, $error->getMessage());
        }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_canonical_owner_sql_cannot_be_substituted(): void
    {
        [$connection] = $this->fixture();
        $owner = new ForgedEstablishedC;
        $owner->fixtureConnection = $connection;
        $this->expectExceptionMessage('New-table owner differs from the canonical manifest');
        $owner->up();
    }

    public function test_create_failure_restores_session_and_allows_fresh_retry_without_ledger_changes(): void
    {
        [$connection, $owner] = $this->fixture();
        $connection->recordingPdo->failCreate = true;
        try {
            $owner->up();
            $this->fail('CREATE failure must stop acceptance.');
        } catch (RuntimeException $error) {
            $this->assertSame('Simulated CREATE failure.', $error->getMessage());
        }
        $this->assertSame(31536000, $connection->lockWaitTimeout);
        $this->assertArrayNotHasKey('c', $connection->tables);
        $this->assertCount(4, $connection->records);
        $connection->recordingPdo->failCreate = false;
        $owner->up();
        $this->assertArrayHasKey('c', $connection->tables);
        $this->assertCount(4, $connection->records);
    }

    public function test_restore_failure_quarantines_session_and_never_rolls_back_created_table(): void
    {
        [$connection, $owner] = $this->fixture();
        $connection->recordingPdo->failLockRestore = true;
        try {
            $owner->up();
            $this->fail('Session restoration failure must stop acceptance.');
        } catch (RuntimeException $error) {
            $this->assertStringContainsString('Failed to restore lock_wait_timeout', $error->getMessage());
        }
        $this->assertArrayHasKey('c', $connection->tables);
        $this->assertNull($connection->getRawPdo());
        $this->assertSame(1, $connection->disconnectCount);
        $this->assertCount(4, $connection->records);
        $connection->setPdo($connection->recordingPdo);
        $this->expectExceptionMessage('unsafe database session must not be reused');
        $owner->up();
    }

    public function test_post_create_schema_drift_cannot_be_accepted(): void
    {
        [$connection, $owner] = $this->fixture();
        $connection->recordingPdo = new class($connection) extends BaselinePdo {
            public function __construct(private BaselineConnection $state) { parent::__construct($state); }
            public function exec(string $statement): int|false
            {
                $result = parent::exec($statement);
                if (str_starts_with($statement, 'CREATE TABLE')) $this->state->tables['b'] = str_replace('bigint', 'int', $this->state->tables['b']);
                return $result;
            }
        };
        $connection->setPdo($connection->recordingPdo);
        $this->expectExceptionMessage('Schema drift in b');
        $owner->up();
    }

    public static function invalidLockTimeouts(): array
    {
        return [[0], [-1], ['1.5'], ['1e3'], [31536001], ['invalid']];
    }

    #[DataProvider('invalidLockTimeouts')]
    public function test_invalid_session_timeout_cannot_produce_any_statement(mixed $timeout): void
    {
        [$old, $owner] = $this->fixture();
        $connection = new class($timeout) extends BaselineConnection {
            public function __construct(private mixed $timeout) { parent::__construct(); }
            public function select($query, $bindings = [], $useReadPdo = true): array
            {
                if ($query === 'SELECT @@SESSION.lock_wait_timeout AS lock_wait_timeout') return [(object) ['lock_wait_timeout' => $this->timeout]];
                return parent::select($query, $bindings, $useReadPdo);
            }
        };
        $connection->tables = $old->tables; $connection->records = $old->records; $connection->hasRows = $old->hasRows;
        $owner->fixtureConnection = $connection;
        try { $owner->up(); $this->fail('Invalid timeout must stop CREATE.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('Cannot determine the original lock_wait_timeout', $error->getMessage()); }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_session_replacement_during_timeout_read_cannot_write_either_session(): void
    {
        [$old, $owner] = $this->fixture();
        $connection = new class extends BaselineConnection {
            public ?BaselinePdo $replacement = null;
            public function select($query, $bindings = [], $useReadPdo = true): array
            {
                $rows = parent::select($query, $bindings, $useReadPdo);
                if ($query === 'SELECT @@SESSION.lock_wait_timeout AS lock_wait_timeout') {
                    $this->replacement = new BaselinePdo($this); $this->setPdo($this->replacement);
                }
                return $rows;
            }
        };
        $connection->tables = $old->tables; $connection->records = $old->records; $connection->hasRows = $old->hasRows;
        $owner->fixtureConnection = $connection;
        try { $owner->up(); $this->fail('Replaced PDO must stop CREATE.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('session', $error->getMessage()); }
        $this->assertSame([], $connection->recordingPdo->statements);
        $this->assertSame([], $connection->replacement->statements);
    }

    public function test_same_pdo_changing_selected_database_after_create_cannot_be_accepted(): void
    {
        [$old, $owner] = $this->fixture();
        $connection = new class extends BaselineConnection {
            public string $selectedDatabase = 'unit_memory';
            public function select($query, $bindings = [], $useReadPdo = true): array
            {
                if (str_starts_with($query, 'SELECT DATABASE()')) return [(object) ['database_name' => $this->selectedDatabase, 'server_version' => $this->version]];
                return parent::select($query, $bindings, $useReadPdo);
            }
        };
        $connection->tables = $old->tables; $connection->records = $old->records; $connection->hasRows = $old->hasRows;
        $connection->recordingPdo = new class($connection) extends BaselinePdo {
            public function __construct(private BaselineConnection $state) { parent::__construct($state); }
            public function exec(string $statement): int|false
            {
                $result = parent::exec($statement);
                if (str_starts_with($statement, 'CREATE TABLE')) $this->state->selectedDatabase = 'other_memory';
                return $result;
            }
        };
        $connection->setPdo($connection->recordingPdo); $owner->fixtureConnection = $connection;
        $this->expectExceptionMessage('Database changed after extension CREATE.');
        $owner->up();
    }

    public function test_fresh_empty_database_still_uses_initial_create_flow(): void
    {
        $connection = new BaselineConnection;
        $owner = new EstablishedC;
        $owner->fixtureConnection = $connection;
        $owner->up();
        $this->assertArrayHasKey('c', $connection->tables);
        $this->assertSame(1, $connection->foreignKeyChecks);
        $this->assertSame([], $connection->records);
    }

    public function test_pretend_compiles_real_create_sql_without_resolving_pdo(): void
    {
        $connection = new MySqlConnection(fn () => throw new RuntimeException('PDO must not be resolved.'), '', '', ['driver' => 'mysql', 'version' => '8.0.12']);
        $owner = new EstablishedC;
        $owner->fixtureConnection = $connection;
        $queries = $connection->pretend(fn () => $owner->up());
        $this->assertCount(1, $queries);
        $this->assertStringStartsWith('CREATE TABLE `c`', $queries[0]['query']);
    }
}

class EstablishedC extends BaselineA
{
    public const TABLE = 'c';
    public const CREATE_SQL = 'CREATE TABLE `c` (`id` bigint(20) unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    public const DECLARED_CHECKS = [];
    public const ESTABLISHED_EXTENSION = true;

    public static function baseline(): array
    {
        $baseline = parent::baseline();
        $baseline['tables']['c'] = 'initial_c';
        return $baseline;
    }

    public static function definitions(): array { return ['a' => new BaselineA, 'b' => new BaselineB, 'c' => new self]; }
    public function up(): void { $this->createEstablishedExtension(); }
}

class ForgedEstablishedC extends EstablishedC
{
    public const CREATE_SQL = 'CREATE TABLE `c` (`id` int(10) unsigned NOT NULL, PRIMARY KEY (`id`)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
}
