<?php

namespace Tests\Unit\Architecture;

use Database\Schema\InitialTableMigration;
use Illuminate\Database\Connection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\DB;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/../../../database/schema/InitialTableMigration.php';

/** All metadata is in memory; the PDO double never opens a connection. */
class InitialTableMigrationRuntimeTest extends TestCase
{
    private function connection(array $tables = ['a', 'b'], array $records = ['legacy_one', 'legacy_two']): BaselineConnection
    {
        $connection = new BaselineConnection;
        foreach ($tables as $table) {
            $connection->tables[$table] = BaselineA::definitions()[$table]::CREATE_SQL;
        }
        $connection->records = $records;

        return $connection;
    }

    private function migrate(Connection $connection, string $table = 'a'): void
    {
        $definition = BaselineA::definitions()[$table];
        $definition->fixtureConnection = $connection;
        $definition->up();
    }

    public function test_complete_legacy_database_is_validated_without_any_ddl_or_session_change(): void
    {
        $connection = $this->connection();
        $connection->hasRows = ['a' => true, 'b' => true];
        $plan = BaselineA::preflight($connection);
        $this->assertSame('existing', $plan['mode']);
        $this->assertCount(1, $plan['warnings']);
        $this->migrate($connection);
        $this->migrate($connection, 'b');
        $this->assertSame([], $connection->recordingPdo->statements);
        $this->assertSame(['legacy_one', 'legacy_two'], $connection->records);
        $this->assertContains('SHOW CREATE TABLE `b`', $connection->reads);
    }

    public function test_complete_canonical_ledger_accepts_data_without_recreating_tables(): void
    {
        $connection = $this->connection(records: ['initial_a', 'initial_b']);
        $connection->hasRows['a'] = true;
        $this->assertSame('existing', BaselineA::preflight($connection)['mode']);
        $this->migrate($connection);
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public static function unsafeDatabases(): array
    {
        return [
            'drift in later table' => ['drift', 'Schema drift in b'],
            'partial legacy schema' => ['partial', 'Partial legacy database'],
            'incomplete legacy ledger' => ['legacy_subset', 'Incomplete legacy migration ledger'],
            'unknown table' => ['unknown_table', 'Unknown database tables'],
            'unknown migration' => ['unknown_record', 'Unknown migration ledger entries'],
            'duplicate ledger' => ['duplicate_record', 'Duplicate migration ledger entry'],
            'record without table' => ['missing_recorded_table', 'Recorded initial migration has no table'],
            'data with no provenance' => ['unrecorded_data', 'requires empty tables'],
            'partial canonical with data' => ['partial_canonical_data', 'requires empty tables'],
            'view' => ['view', 'Views are not accepted'],
        ];
    }

    #[DataProvider('unsafeDatabases')]
    public function test_whole_database_preflight_rejects_unsafe_state_before_first_ddl(string $case, string $message): void
    {
        $connection = $this->connection();
        switch ($case) {
            case 'drift': $connection->tables['b'] = str_replace('bigint', 'int', $connection->tables['b']); break;
            case 'partial': unset($connection->tables['b']); break;
            case 'legacy_subset': $connection->records = ['legacy_one']; break;
            case 'unknown_table': $connection->tables['unexpected'] = 'unused'; break;
            case 'unknown_record': $connection->records[] = 'unknown'; break;
            case 'duplicate_record': $connection->records[] = 'legacy_one'; break;
            case 'missing_recorded_table':
                unset($connection->tables['b']);
                $connection->records = ['initial_b'];
                break;
            case 'unrecorded_data':
                $connection->records = [];
                $connection->hasRows['a'] = true;
                break;
            case 'partial_canonical_data':
                $connection->records = ['initial_a'];
                $connection->hasRows['a'] = true;
                break;
            case 'view': $connection->views = ['b']; break;
        }
        try {
            $this->migrate($connection);
            $this->fail('Unsafe baseline was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_fresh_cyclic_foreign_keys_create_each_table_and_restore_original_session_setting(): void
    {
        $connection = $this->connection([], []);
        $this->migrate($connection);
        // Simulate only Laravel's normal post-up ledger insertion, never helper behavior.
        $connection->records[] = 'initial_a';
        $this->migrate($connection, 'b');
        $statements = $connection->recordingPdo->statements;
        $this->assertCount(6, $statements);
        $this->assertSame('SET SESSION FOREIGN_KEY_CHECKS = 0', $statements[0]);
        $this->assertStringContainsString('REFERENCES `b`', $statements[1]);
        $this->assertSame('SET SESSION FOREIGN_KEY_CHECKS = 1', $statements[2]);
        $this->assertStringContainsString('REFERENCES `a`', $statements[4]);
        $this->assertSame('SET SESSION FOREIGN_KEY_CHECKS = 1', $statements[5]);
        $this->assertSame(['initial_a'], $connection->records);
        $this->assertSame(1, $connection->foreignKeyChecks);
    }

    public function test_empty_interrupted_initial_creation_is_resumable_with_known_records(): void
    {
        $connection = $this->connection(['a'], ['initial_a']);
        $connection->foreignKeyChecks = 0;
        $plan = BaselineA::preflight($connection);
        $this->assertSame('fresh', $plan['mode']);
        $this->assertSame(['b'], $plan['missing']);
        $this->migrate($connection, 'b');
        $this->assertSame('SET SESSION FOREIGN_KEY_CHECKS = 0', $connection->recordingPdo->statements[2]);
        $this->assertSame(0, $connection->foreignKeyChecks);
    }

    public function test_failed_create_restores_session_and_never_writes_ledger(): void
    {
        $connection = $this->connection([], []);
        $connection->recordingPdo->failCreate = true;
        try {
            $this->migrate($connection);
            $this->fail('Expected CREATE failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated CREATE failure.', $exception->getMessage());
        }
        $this->assertSame('SET SESSION FOREIGN_KEY_CHECKS = 1', $connection->recordingPdo->statements[2]);
        $this->assertSame(1, $connection->foreignKeyChecks);
        $this->assertSame([], $connection->records);
        $this->assertSame([], $connection->tables);
    }

    public function test_failure_to_restore_session_is_not_reported_as_success(): void
    {
        $connection = $this->connection([], []);
        $connection->recordingPdo->failRestore = true;
        try {
            $this->migrate($connection);
            $this->fail('Restoration failure must stop migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Failed to restore FOREIGN_KEY_CHECKS', $exception->getMessage());
        }
        $this->assertNull($connection->getRawPdo());
        $this->assertSame(1, $connection->disconnectCount);
        $connection->setPdo($connection->recordingPdo);
        $this->expectExceptionMessage('unsafe database session must not be reused');
        $this->migrate($connection);
    }

    public function test_later_table_drift_is_rechecked_after_cached_preflight(): void
    {
        $connection = $this->connection();
        $this->migrate($connection);
        $connection->tables['b'] = str_replace('bigint', 'int', $connection->tables['b']);
        try {
            $this->migrate($connection, 'b');
            $this->fail('Expected drift rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Schema drift in b', $exception->getMessage());
        }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_rows_inserted_after_first_fresh_create_block_next_create(): void
    {
        $connection = $this->connection([], []);
        $this->migrate($connection);
        $connection->hasRows['a'] = true;
        try {
            $this->migrate($connection, 'b');
            $this->fail('Expected nonempty table rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('requires empty tables', $exception->getMessage());
        }
        $this->assertCount(3, $connection->recordingPdo->statements);
    }

    public function test_a_created_table_that_disappears_is_not_recreated_using_stale_cached_plan(): void
    {
        $connection = $this->connection([], []);
        $this->migrate($connection);
        unset($connection->tables['a']);
        try {
            $this->migrate($connection);
            $this->fail('Expected vanished table rejection.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('table disappeared', $exception->getMessage());
        }
        $this->assertCount(3, $connection->recordingPdo->statements);
    }

    public function test_failure_to_disable_checks_still_attempts_original_session_restore(): void
    {
        $connection = $this->connection([], []);
        $connection->recordingPdo->failDisable = true;
        try {
            $this->migrate($connection);
            $this->fail('Expected SET failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Initial schema statement failed', $exception->getMessage());
        }
        $this->assertSame([
            'SET SESSION FOREIGN_KEY_CHECKS = 0',
            'SET SESSION FOREIGN_KEY_CHECKS = 1',
        ], $connection->recordingPdo->statements);
        $this->assertSame([], $connection->tables);
    }

    public function test_pretend_compiles_without_resolving_pdo_or_selecting_metadata(): void
    {
        foreach (['8.0.12', '8.0.36'] as $version) {
            $connection = new MySqlConnection(static fn () => throw new RuntimeException('PDO resolution is forbidden.'), 'offline', '', ['version' => $version]);
            $queries = $connection->pretend(fn () => $this->migrate($connection));
            $this->assertCount(1, $queries);
            $query = $queries[0]['query'];
            $this->assertStringStartsWith('create table', strtolower($query));
            $this->assertSame($version === '8.0.36', str_contains(strtolower($query), 'check'));
        }
    }

    public function test_check_enforcement_is_required_on_supported_servers_but_never_added_to_existing_tables(): void
    {
        $migration = new BaselineA;
        $this->assertSame([], $migration->schemaDifferences(BaselineA::CREATE_SQL, '8.0.12'));
        $this->assertSame(['checks differ'], $migration->schemaDifferences(BaselineA::CREATE_SQL, '8.0.36'));
        $this->assertSame([], $migration->schemaDifferences($migration->createSql('8.0.36'), '8.0.36'));
        $connection = $this->connection();
        $connection->version = '8.0.36';
        try {
            $this->migrate($connection);
            $this->fail('Missing CHECK must stop existing baseline adoption.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('checks differ', $exception->getMessage());
        }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_check_parentheses_and_identifier_quoting_preserve_boolean_semantics(): void
    {
        $migration = new class extends InitialTableMigration {
            public const TABLE = 'scoped';
            public const CREATE_SQL = "CREATE TABLE `scoped` (`scope` varchar(10) DEFAULT NULL) ENGINE=InnoDB";
            public const DECLARED_CHECKS = ['scope_ck' => "scope IS NULL OR scope IN ('factory','office')"];
        };
        $actual = "CREATE TABLE `scoped` (`scope` varchar(10) DEFAULT NULL, CONSTRAINT `scope_ck` CHECK (((`scope` is null) or (`scope` in ('factory','office'))))) ENGINE=InnoDB";
        $this->assertSame([], $migration->schemaDifferences($actual, '8.0.36'));
        $this->assertSame(['checks differ'], $migration->schemaDifferences(str_replace(' or ', ' and ', $actual), '8.0.36'));
        $this->assertSame(['checks differ'], $migration->schemaDifferences(str_replace('factory', 'warehouse', $actual), '8.0.36'));
    }

    public function test_show_create_check_charset_introducers_are_only_accepted_for_table_charset(): void
    {
        $migration = new class extends InitialTableMigration {
            public const TABLE = 'scoped';
            public const CREATE_SQL = "CREATE TABLE `scoped` (`scope` varchar(10) DEFAULT 'factory') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            public const DECLARED_CHECKS = ['scope_ck' => "scope IN ('factory','office')"];
        };
        $actual = "CREATE TABLE `scoped` (`scope` varchar(10) DEFAULT 'factory', CONSTRAINT `scope_ck` CHECK ((`scope` in (_utf8mb4'factory',_utf8mb4'office')))) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
        $this->assertSame([], $migration->schemaDifferences($actual, '8.0.36'));
        $this->assertSame(['checks differ'], $migration->schemaDifferences(str_replace('_utf8mb4', '_latin1', $actual), '8.0.36'));
        $notEnforced = str_replace(')) ENGINE', ') NOT ENFORCED) ENGINE', $actual);
        $this->assertSame(['checks differ'], $migration->schemaDifferences($notEnforced, '8.0.36'));
    }

    public function test_quoted_column_named_null_is_not_equivalent_to_null_keyword(): void
    {
        $migration = new class extends InitialTableMigration {
            public const CREATE_SQL = 'CREATE TABLE `t` (`id` int, `null` int) ENGINE=InnoDB';
            public const DECLARED_CHECKS = ['null_ck' => 'id is null'];
        };
        $actual = 'CREATE TABLE `t` (`id` int, `null` int, CONSTRAINT `null_ck` CHECK (`id` is `null`)) ENGINE=InnoDB';
        $this->assertSame(['checks differ'], $migration->schemaDifferences($actual, '8.0.36'));
    }

    public function test_inline_check_is_not_duplicated_and_conflicting_declaration_is_rejected(): void
    {
        $migration = new class extends InitialTableMigration {
            public const TABLE = 'scoped';
            public const CREATE_SQL = "CREATE TABLE `scoped` (`scope` varchar(10), CONSTRAINT `scope_ck` CHECK (`scope` IN ('factory','office'))) ENGINE=InnoDB";
            public const DECLARED_CHECKS = ['scope_ck' => "scope IN ('factory','office')"];
        };
        $this->assertSame($migration::CREATE_SQL, $migration->createSql('8.0.36'));
        $conflicting = new class extends InitialTableMigration {
            public const CREATE_SQL = "CREATE TABLE `scoped` (`scope` varchar(10), CONSTRAINT `scope_ck` CHECK (`scope` IN ('factory','office'))) ENGINE=InnoDB";
            public const DECLARED_CHECKS = ['scope_ck' => "scope IN ('factory','warehouse')"];
        };
        $this->expectExceptionMessage('Conflicting inline and declared CHECK');
        $conflicting->createSql('8.0.36');
    }

    public function test_check_insertion_preserves_original_sql_literals_and_default_character_set_prefixes(): void
    {
        $migration = new class extends InitialTableMigration {
            public const CREATE_SQL = "CREATE TABLE `scoped` (`id` bigint, `scope` varchar(100) DEFAULT _utf8mb4'factory' COMMENT 'quote \\' ) ENGINE=InnoDB') ENGINE=InnoDB DEFAULT CHARSET=utf8mb4";
            public const DECLARED_CHECKS = ['positive_ck' => 'id >= 0'];
        };
        $sql = $migration->createSql('8.0.36');
        $this->assertStringContainsString("DEFAULT _utf8mb4'factory'", $sql);
        $this->assertStringContainsString("COMMENT 'quote \\' ) ENGINE=InnoDB'", $sql);
        $this->assertStringContainsString(", CONSTRAINT `positive_ck` CHECK (id >= 0)) ENGINE=InnoDB", $sql);
    }

    public function test_sql_normalization_does_not_erase_semantic_changes_or_modify_literals(): void
    {
        $sql = "CREATE TABLE `t` (`id` int(11) unsigned NOT NULL, `s` varchar(100) DEFAULT 'AUTO_INCREMENT=7 int(11)' COMMENT 'UPPER  CASE') ENGINE=InnoDB AUTO_INCREMENT=17";
        $normalized = InitialTableMigration::normalizeSql($sql);
        $this->assertStringContainsString("'AUTO_INCREMENT=7 int(11)'", $normalized);
        $this->assertStringContainsString("'UPPER  CASE'", $normalized);
        $this->assertSame($normalized, InitialTableMigration::normalizeSql(str_replace(['int(11) unsigned', 'AUTO_INCREMENT=17'], ['int unsigned', 'AUTO_INCREMENT=456'], $sql)));
        $this->assertNotSame($normalized, InitialTableMigration::normalizeSql(str_replace('UPPER  CASE', 'upper  case', $sql)));
        $this->assertNotSame(
            InitialTableMigration::normalizeSql('CREATE TABLE `z` (`id` int(4) zerofill) ENGINE=InnoDB'),
            InitialTableMigration::normalizeSql('CREATE TABLE `z` (`id` int(8) zerofill) ENGINE=InnoDB'),
        );
        $this->assertNotSame(
            InitialTableMigration::normalizeSql('CREATE TABLE `d` (`amount` decimal(10,2)) ENGINE=InnoDB'),
            InitialTableMigration::normalizeSql('CREATE TABLE `d` (`amount` decimal(12,2)) ENGINE=InnoDB'),
        );
    }

    public function test_index_foreign_key_and_default_drift_are_not_ignored(): void
    {
        $migration = new BaselineA;
        foreach ([
            str_replace('UNIQUE KEY', 'KEY', BaselineA::CREATE_SQL),
            str_replace('ON DELETE RESTRICT', 'ON DELETE CASCADE', BaselineA::CREATE_SQL),
            str_replace('NOT NULL', 'NULL', BaselineA::CREATE_SQL),
            str_replace('DEFAULT 0', 'DEFAULT 1', BaselineA::CREATE_SQL),
        ] as $drift) {
            $this->assertNotSame([], $migration->schemaDifferences($drift, '8.0.12'));
        }
    }

    private function legacyIndexOwner(BaselineConnection $connection): CompatibilityA
    {
        $owner = new CompatibilityA;
        $owner->fixtureConnection = $connection;

        return $owner;
    }

    private function missingIndexConnection(): BaselineConnection
    {
        $connection = $this->connection();
        $connection->tables['a'] = str_replace('UNIQUE KEY `b_uq` (`b_id`), ', '', BaselineA::CREATE_SQL);
        $connection->alterTargets['a'] = BaselineA::CREATE_SQL;

        return $connection;
    }

    public function test_normal_preflight_stays_strict_and_owner_preview_is_readonly(): void
    {
        $connection = $this->missingIndexConnection();
        try {
            BaselineA::preflight($connection);
            $this->fail('Ordinary preflight must reject missing UNIQUE.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Schema drift in a', $exception->getMessage());
        }
        $plan = $this->legacyIndexOwner($connection)->preflightLegacyUniqueIndex($connection);
        $this->assertTrue($plan['pending_legacy_unique_index']);
        $this->assertSame('existing', $plan['mode']);
        $this->assertSame([], $connection->recordingPdo->statements);
        $this->assertNotEmpty(array_filter($connection->reads, static fn ($sql) => str_contains($sql, 'HAVING COUNT(*) > 1')));
    }

    public function test_authorized_index_addition_preserves_session_data_ledger_and_supports_unrecorded_retry(): void
    {
        $connection = $this->missingIndexConnection();
        $connection->hasRows = ['a' => true, 'b' => true];
        $owner = $this->legacyIndexOwner($connection);
        $owner->up();
        $this->assertSame([
            'SET SESSION lock_wait_timeout = 5',
            'ALTER TABLE `a` ADD UNIQUE KEY `b_uq` (`b_id`)',
            'SET SESSION lock_wait_timeout = 31536000',
        ], $connection->recordingPdo->statements);
        $this->assertSame(['legacy_one', 'legacy_two'], $connection->records);
        $this->assertSame(['a' => true, 'b' => true], $connection->hasRows);
        $this->assertSame(31536000, $connection->lockWaitTimeout);
        $this->assertSame(1, $connection->foreignKeyChecks);
        $this->assertFalse($owner->preflightLegacyUniqueIndex($connection)['pending_legacy_unique_index']);
        $owner->up();
        $this->assertCount(3, $connection->recordingPdo->statements, 'DDL succeeded without ledger insertion: retry is read-only.');
    }

    public static function unsafeIndexCompatibility(): array
    {
        return [
            'duplicate combinations' => ['duplicate', 'Duplicate non-null combinations'],
            'later table drift' => ['later_drift', 'Schema drift in b'],
            'owner additional column drift' => ['owner_drift', 'exceeds the authorized missing unique index'],
            'index exists with wrong columns' => ['wrong_index', 'exceeds the authorized missing unique index'],
            'partial legacy ledger' => ['partial_legacy', 'Incomplete legacy migration ledger'],
            'no legacy ledger' => ['no_legacy', 'requires the complete legacy database and ledger'],
            'unknown ledger' => ['unknown_ledger', 'Unknown migration ledger entries'],
            'unknown table' => ['unknown_table', 'Unknown database tables'],
            'missing table' => ['missing_table', 'Partial legacy database'],
            'owner already recorded' => ['already_recorded', 'Recorded initial migration lost its unique index'],
        ];
    }

    #[DataProvider('unsafeIndexCompatibility')]
    public function test_legacy_index_compatibility_refuses_every_unapproved_state_before_mutation(string $case, string $message): void
    {
        $connection = $this->missingIndexConnection();
        switch ($case) {
            case 'duplicate': $connection->duplicateGroups = true; break;
            case 'later_drift': $connection->tables['b'] = str_replace('bigint', 'int', $connection->tables['b']); break;
            case 'owner_drift': $connection->tables['a'] = str_replace('bigint', 'int', $connection->tables['a']); break;
            case 'wrong_index': $connection->tables['a'] = str_replace('UNIQUE KEY `b_uq` (`b_id`)', 'UNIQUE KEY `b_uq` (`id`)', BaselineA::CREATE_SQL); break;
            case 'partial_legacy': $connection->records = ['legacy_one']; break;
            case 'no_legacy': $connection->records = []; break;
            case 'unknown_ledger': $connection->records[] = 'unknown'; break;
            case 'unknown_table': $connection->tables['surprise'] = 'unused'; break;
            case 'missing_table': unset($connection->tables['b']); break;
            case 'already_recorded': $connection->records[] = 'initial_a'; break;
        }
        try {
            $this->legacyIndexOwner($connection)->up();
            $this->fail('Unauthorized compatibility state must be refused.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_failed_alter_restores_lock_timeout_without_recording_or_disconnecting_safe_session(): void
    {
        $connection = $this->missingIndexConnection();
        $connection->recordingPdo->failAlter = true;
        try {
            $this->legacyIndexOwner($connection)->up();
            $this->fail('ALTER failure must stop migration.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Simulated ALTER failure.', $exception->getMessage());
        }
        $this->assertSame(31536000, $connection->lockWaitTimeout);
        $this->assertSame(0, $connection->disconnectCount);
        $this->assertSame(['legacy_one', 'legacy_two'], $connection->records);
        $this->assertStringNotContainsString('UNIQUE KEY', $connection->tables['a']);
    }

    public function test_lock_timeout_restore_failure_disconnects_and_forbids_dangerous_pdo_reuse(): void
    {
        $connection = $this->missingIndexConnection();
        $connection->recordingPdo->failLockRestore = true;
        try {
            $this->legacyIndexOwner($connection)->up();
            $this->fail('Restoration failure must stop migration.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Failed to restore lock_wait_timeout', $exception->getMessage());
        }
        $this->assertNull($connection->getRawPdo());
        $this->assertSame(1, $connection->disconnectCount);
        $this->assertSame(['legacy_one', 'legacy_two'], $connection->records);
        $connection->setPdo($connection->recordingPdo);
        $this->expectExceptionMessage('unsafe database session must not be reused');
        $this->legacyIndexOwner($connection)->up();
    }

    public function test_restoration_failure_can_retry_on_a_new_session_without_repeating_successful_alter(): void
    {
        $connection = $this->missingIndexConnection();
        $connection->recordingPdo->failLockRestore = true;
        try {
            $this->legacyIndexOwner($connection)->up();
            $this->fail('Expected restoration failure.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Failed to restore lock_wait_timeout', $exception->getMessage());
        }
        // A new PDO means a new MySQL session with its normal session defaults.
        $connection->recordingPdo = new BaselinePdo($connection);
        $connection->setPdo($connection->recordingPdo);
        $connection->lockWaitTimeout = 31536000;
        $this->legacyIndexOwner($connection)->up();
        $this->assertSame([], $connection->recordingPdo->statements);
        $this->assertSame(['legacy_one', 'legacy_two'], $connection->records);
    }

    public function test_compatibility_never_increases_an_existing_shorter_lock_timeout(): void
    {
        $connection = $this->missingIndexConnection();
        $connection->lockWaitTimeout = 2;
        $this->legacyIndexOwner($connection)->up();
        $this->assertSame('SET SESSION lock_wait_timeout = 2', $connection->recordingPdo->statements[0]);
        $this->assertSame('SET SESSION lock_wait_timeout = 2', $connection->recordingPdo->statements[2]);
        $this->assertSame(2, $connection->lockWaitTimeout);
    }

    public function test_after_alter_whole_database_is_rechecked_before_owner_can_return(): void
    {
        $connection = $this->missingIndexConnection();
        $connection->recordingPdo->afterAlter = static function () use ($connection) {
            $connection->tables['b'] = str_replace('bigint', 'int', $connection->tables['b']);
        };
        try {
            $this->legacyIndexOwner($connection)->up();
            $this->fail('Post-ALTER drift must prevent acceptance.');
        } catch (RuntimeException $exception) {
            $this->assertStringContainsString('Schema drift in b', $exception->getMessage());
        }
        $this->assertSame(31536000, $connection->lockWaitTimeout);
        $this->assertSame(['legacy_one', 'legacy_two'], $connection->records);
    }

    public function test_real_owner_and_all_285_definitions_share_the_exact_compatibility_contract(): void
    {
        $definitions = InitialTableMigration::definitions();
        $table = 'erp_production_material_consumptions';
        $owner = $definitions[$table];
        $connection = $this->connection([], array_keys(InitialTableMigration::baseline()['legacy_migrations']));
        foreach ($definitions as $name => $definition) $connection->tables[$name] = $definition::CREATE_SQL;
        $connection->tables[$table] = str_replace("  UNIQUE KEY `prod_material_consumption_source_uq` (`output_record_id`,`source_holding_id`),\n", '', $owner::CREATE_SQL);
        $connection->alterTargets[$table] = $owner::CREATE_SQL;
        $connection->hasRows[$table] = true;
        DB::swap(new class($connection) {
            public function __construct(private Connection $connection) {}
            public function connection($name = null): Connection { return $this->connection; }
        });
        try {
            $owner->up();
            $this->assertSame('ALTER TABLE `erp_production_material_consumptions` ADD UNIQUE KEY `prod_material_consumption_source_uq` (`output_record_id`,`source_holding_id`)', $connection->recordingPdo->statements[1]);
            $this->assertCount(3, $connection->recordingPdo->statements);
            $this->assertCount(189, $connection->records);
            $this->assertCount(285, $connection->tables);
            $this->assertFalse(InitialTableMigration::preflight($connection)['pending_legacy_unique_index']);
        } finally {
            DB::clearResolvedInstance('db');
        }
    }

    public function test_rollback_is_always_refused_without_resolving_connection(): void
    {
        $this->expectExceptionMessage('Automatic rollback');
        (new BaselineA)->down();
    }
}

class BaselineA extends InitialTableMigration
{
    public const TABLE = 'a';
    public const CREATE_SQL = 'CREATE TABLE `a` (`id` bigint(20) unsigned NOT NULL, `b_id` bigint(20) unsigned DEFAULT 0, PRIMARY KEY (`id`), UNIQUE KEY `b_uq` (`b_id`), CONSTRAINT `a_b_fk` FOREIGN KEY (`b_id`) REFERENCES `b` (`id`) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    public const DECLARED_CHECKS = ['positive_id' => 'id >= 0'];
    public Connection $fixtureConnection;

    public static function baseline(): array
    {
        return ['tables' => ['a' => 'initial_a', 'b' => 'initial_b'], 'legacy_migrations' => ['legacy_one' => 'hash', 'legacy_two' => 'hash'], 'source_server_version' => '8.0.12'];
    }

    public static function definitions(): array
    {
        return ['a' => new BaselineA, 'b' => new BaselineB];
    }

    protected function databaseConnection(): Connection { return $this->fixtureConnection; }

    protected function reportWarnings(array $warnings): void {}
}

class BaselineB extends BaselineA
{
    public const TABLE = 'b';
    public const CREATE_SQL = 'CREATE TABLE `b` (`id` bigint(20) unsigned NOT NULL, `a_id` bigint(20) unsigned DEFAULT NULL, PRIMARY KEY (`id`), CONSTRAINT `b_a_fk` FOREIGN KEY (`a_id`) REFERENCES `a` (`id`) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4';
    public const DECLARED_CHECKS = [];
}

class CompatibilityA extends BaselineA
{
    public const LEGACY_UNIQUE_INDEX = ['name' => 'b_uq', 'columns' => ['b_id']];

    public static function definitions(): array
    {
        return ['a' => new self, 'b' => new BaselineB];
    }

    public function up(): void
    {
        $this->completeLegacyUniqueIndex();
        parent::up();
    }
}

class BaselineConnection extends MySqlConnection
{
    public array $tables = [];
    public array $records = [];
    public array $views = [];
    public array $hasRows = [];
    public array $reads = [];
    public int $foreignKeyChecks = 1;
    public int $lockWaitTimeout = 31536000;
    public int $disconnectCount = 0;
    public bool $duplicateGroups = false;
    public array $alterTargets = [];
    public string $version = '8.0.12';
    public BaselinePdo $recordingPdo;

    public function __construct()
    {
        $this->recordingPdo = new BaselinePdo($this);
        parent::__construct($this->recordingPdo, 'unit_memory', '', ['driver' => 'mysql', 'version' => '8.0.12']);
    }

    public function disconnect()
    {
        $this->disconnectCount++;
        parent::disconnect();
    }

    public function select($query, $bindings = [], $useReadPdo = true): array
    {
        if ($useReadPdo) throw new RuntimeException('Metadata must use the writer connection.');
        $this->reads[] = $query;
        if (str_starts_with($query, 'SELECT DATABASE()')) {
            return [(object) ['database_name' => 'unit_memory', 'server_version' => $this->version]];
        }
        if (str_contains($query, 'information_schema.TABLES')) {
            $rows = [(object) ['TABLE_NAME' => 'migrations', 'TABLE_TYPE' => 'BASE TABLE']];
            foreach ($this->tables as $name => $_) {
                $rows[] = (object) ['TABLE_NAME' => $name, 'TABLE_TYPE' => in_array($name, $this->views, true) ? 'VIEW' : 'BASE TABLE'];
            }
            return $rows;
        }
        if (str_starts_with($query, 'SELECT migration')) {
            return array_map(static fn ($name) => (object) ['migration' => $name], $this->records);
        }
        if (preg_match('/^SHOW CREATE TABLE `(\w+)`$/', $query, $match)) {
            return [(object) ['Create Table' => $this->tables[$match[1]]]];
        }
        if (preg_match('/^SELECT 1 FROM `(\w+)` LIMIT 1$/', $query, $match)) {
            return empty($this->hasRows[$match[1]]) ? [] : [(object) ['1' => 1]];
        }
        if (str_contains($query, 'HAVING COUNT(*) > 1')) {
            return $this->duplicateGroups ? [(object) ['1' => 1]] : [];
        }
        if ($query === 'SELECT @@SESSION.lock_wait_timeout AS lock_wait_timeout') {
            return [(object) ['lock_wait_timeout' => $this->lockWaitTimeout]];
        }
        if ($query === 'SELECT @@SESSION.FOREIGN_KEY_CHECKS AS foreign_key_checks') {
            return [(object) ['foreign_key_checks' => $this->foreignKeyChecks]];
        }
        throw new RuntimeException('Unexpected metadata query: '.$query);
    }
}

class BaselinePdo extends PDO
{
    public array $statements = [];
    public bool $failCreate = false;
    public bool $failRestore = false;
    public bool $failDisable = false;
    public bool $failAlter = false;
    public bool $failLockRestore = false;
    public $afterAlter = null;

    public function __construct(private BaselineConnection $connection) {}

    public function exec(string $statement): int|false
    {
        $this->statements[] = $statement;
        if (preg_match('/^SET SESSION FOREIGN_KEY_CHECKS = ([01])$/', $statement, $match)) {
            if ($match[1] === '0' && $this->failDisable) return false;
            if ($match[1] === '1' && $this->failRestore) return false;
            $this->connection->foreignKeyChecks = (int) $match[1];
            return 0;
        }
        if (preg_match('/^CREATE TABLE\s+`(\w+)`/i', $statement, $match)) {
            if ($this->failCreate) throw new RuntimeException('Simulated CREATE failure.');
            $this->connection->tables[$match[1]] = $statement;
            return 0;
        }
        if (preg_match('/^SET SESSION lock_wait_timeout = (\d+)$/', $statement, $match)) {
            if ($match[1] === '31536000' && $this->failLockRestore) return false;
            $this->connection->lockWaitTimeout = (int) $match[1];
            return 0;
        }
        if (preg_match('/^ALTER TABLE `(\w+)` ADD UNIQUE KEY /', $statement, $match)) {
            if ($this->failAlter) throw new RuntimeException('Simulated ALTER failure.');
            $this->connection->tables[$match[1]] = $this->connection->alterTargets[$match[1]];
            if ($this->afterAlter) ($this->afterAlter)();
            return 0;
        }
        throw new RuntimeException('Unexpected mutation: '.$statement);
    }
}
