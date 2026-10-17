<?php

namespace Tests\Unit\Architecture;

use Database\Schema\BomCuttingSchemaSynchronizer;
use Database\Schema\InitialTableMigration;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/InitialTableMigrationRuntimeTest.php';
require_once __DIR__.'/../../../database/schema/BomCuttingSchemaSynchronizer.php';

/** The complete real manifest runs against metadata doubles; no PDO connection is opened. */
class BomCuttingSchemaSynchronizerTest extends TestCase
{
    private function fixture(): BaselineConnection
    {
        $connection = new BaselineConnection;
        foreach (InitialTableMigration::definitions() as $table => $definition) $connection->tables[$table] = $definition->createSql('8.0.12');
        $connection->tables['erp_bom_items'] = str_replace("  `cutting_quantity_snapshot` json DEFAULT NULL,\n", '', $connection->tables['erp_bom_items']);
        $manifest = InitialTableMigration::baseline();
        $connection->records = array_merge(array_values($manifest['tables']), array_keys($manifest['legacy_migrations']));
        $connection->recordingPdo = new class($connection) extends BaselinePdo {
            public bool $failColumn = false;
            public $afterColumn = null;
            public function __construct(private BaselineConnection $state) { parent::__construct($state); }
            public function exec(string $statement): int|false
            {
                if ($statement === 'ALTER TABLE `erp_bom_items` ADD COLUMN `cutting_quantity_snapshot` json DEFAULT NULL AFTER `allow_cut_rotation`') {
                    $this->statements[] = $statement;
                    if ($this->failColumn) throw new RuntimeException('Simulated column failure.');
                    $this->state->tables['erp_bom_items'] = str_replace("  `allow_cut_rotation` tinyint(1) NOT NULL DEFAULT '0',", "  `allow_cut_rotation` tinyint(1) NOT NULL DEFAULT '0',\n  `cutting_quantity_snapshot` json DEFAULT NULL,", $this->state->tables['erp_bom_items']);
                    if ($this->afterColumn) ($this->afterColumn)();
                    return 0;
                }
                return parent::exec($statement);
            }
        };
        $connection->setPdo($connection->recordingPdo);
        return $connection;
    }

    public function test_readonly_plan_and_exact_add_preserve_original_tables_and_ledger_then_retry_without_write(): void
    {
        $connection = $this->fixture(); $service = new BomCuttingSchemaSynchronizer;
        $original = $connection->tables; $ledger = $connection->records;
        $this->assertTrue($service->inspect($connection)['missing_column']);
        $this->assertSame([], $connection->recordingPdo->statements);
        $this->assertTrue($service->synchronize($connection)['changed']);
        $original['erp_bom_items'] = $connection->tables['erp_bom_items'];
        $this->assertSame($original, $connection->tables); $this->assertSame($ledger, $connection->records);
        $this->assertSame(31536000, $connection->lockWaitTimeout); $this->assertSame(1, $connection->foreignKeyChecks);
        $this->assertCount(3, $connection->recordingPdo->statements);
        $this->assertFalse($service->synchronize($connection)['changed']);
        $this->assertCount(3, $connection->recordingPdo->statements);
    }

    public function test_ordinary_preflight_still_refuses_missing_column(): void
    {
        $connection = $this->fixture(); $this->expectExceptionMessage('Schema drift in erp_bom_items');
        InitialTableMigration::preflight($connection);
    }

    public function test_bom_add_can_precede_the_unique_pending_rate_extension_without_changing_its_ledger(): void
    {
        $connection = $this->fixture();
        $manifest = InitialTableMigration::baseline();
        $table = 'erp_production_performance_rate_versions';
        $owner = InitialTableMigration::definitions()[$table];
        $this->assertTrue($owner::ESTABLISHED_EXTENSION);
        unset($connection->tables[$table]);
        $connection->records = array_values(array_diff($connection->records, [$manifest['tables'][$table]]));
        $tables = $connection->tables; $ledger = $connection->records;

        // The older, accepted business schema cannot create the new rate owner
        // while the BOM field is missing. The explicit BOM action must break
        // that dependency without pretending that the pending owner is accepted.
        try { $owner->preflightEstablishedExtension($connection); $this->fail('Missing BOM facts must remain strict for the ordinary extension path.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('Schema drift in erp_bom_items', $error->getMessage()); }
        $result = (new BomCuttingSchemaSynchronizer)->synchronize($connection);
        $this->assertTrue($result['changed']);
        $tables['erp_bom_items'] = $connection->tables['erp_bom_items'];
        $this->assertSame($tables, $connection->tables);
        $this->assertSame($ledger, $connection->records);
        $this->assertCount(3, $connection->recordingPdo->statements);
        $plan = $owner->preflightEstablishedExtension($connection);
        $this->assertSame('extension', $plan['mode']);
        $this->assertSame([$table], $plan['missing']);
        $this->assertFalse((new BomCuttingSchemaSynchronizer)->synchronize($connection)['changed']);
        $this->assertCount(3, $connection->recordingPdo->statements);
    }

    public function test_pending_rate_owner_cannot_mask_a_missing_recorded_original_table(): void
    {
        $connection = $this->fixture(); $manifest = InitialTableMigration::baseline();
        unset($connection->tables['erp_production_performance_rate_versions'], $connection->tables['erp_items']);
        $connection->records = array_values(array_diff($connection->records, [$manifest['tables']['erp_production_performance_rate_versions']]));
        try { (new BomCuttingSchemaSynchronizer)->synchronize($connection); $this->fail('An original table loss must block before ADD.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('baseline', $error->getMessage()); }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_an_existing_unregistered_rate_table_is_not_exempted_by_bom_maintenance(): void
    {
        $connection = $this->fixture(); $manifest = InitialTableMigration::baseline();
        $connection->records = array_values(array_diff($connection->records, [$manifest['tables']['erp_production_performance_rate_versions']]));
        try { (new BomCuttingSchemaSynchronizer)->synchronize($connection); $this->fail('The rate owner must accept its existing table before BOM maintenance.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('migration ledger', $error->getMessage()); }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_unexpected_original_column_drift_stops_before_any_statement(): void
    {
        $connection = $this->fixture();
        $connection->tables['erp_bom_items'] = str_replace('decimal(14,4)', 'decimal(14,3)', $connection->tables['erp_bom_items']);
        try { (new BomCuttingSchemaSynchronizer)->synchronize($connection); $this->fail('Unexpected type drift must stop.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('Schema drift', $error->getMessage()); }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_populated_baseline_and_a_shorter_lock_timeout_are_preserved(): void
    {
        $connection = $this->fixture();
        $connection->hasRows = ['erp_bom_items' => true, 'erp_sales_orders' => true, 'erp_items' => true];
        $rows = $connection->hasRows; $ledger = $connection->records;
        $connection->lockWaitTimeout = 2;
        $this->assertTrue((new BomCuttingSchemaSynchronizer)->synchronize($connection)['changed']);
        $this->assertSame($rows, $connection->hasRows);
        $this->assertSame($ledger, $connection->records);
        $this->assertSame('SET SESSION lock_wait_timeout = 2', $connection->recordingPdo->statements[0]);
        $this->assertSame('SET SESSION lock_wait_timeout = 2', $connection->recordingPdo->statements[2]);
        $this->assertSame(2, $connection->lockWaitTimeout);
    }

    public function test_invalid_original_timeout_stops_before_first_statement(): void
    {
        $connection = $this->fixture(); $connection->lockWaitTimeout = 0;
        try { (new BomCuttingSchemaSynchronizer)->synchronize($connection); $this->fail('Unknown timeout must not be replaced.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('original lock_wait_timeout', $error->getMessage()); }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_unsupported_mysql_version_stops_before_first_statement(): void
    {
        $connection = $this->fixture(); $connection->version = '5.7.44';
        try { (new BomCuttingSchemaSynchronizer)->synchronize($connection); $this->fail('Unsupported version must be refused before ADD.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('Unsupported MySQL server version', $error->getMessage()); }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_incomplete_ledger_stops_before_any_statement(): void
    {
        $connection = $this->fixture(); array_pop($connection->records);
        try { (new BomCuttingSchemaSynchronizer)->synchronize($connection); $this->fail('Incomplete ledger must stop.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('migration ledger', $error->getMessage()); }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_ddl_failure_restores_timeout_and_allows_exact_retry(): void
    {
        $connection = $this->fixture(); $connection->recordingPdo->failColumn = true;
        try { (new BomCuttingSchemaSynchronizer)->synchronize($connection); $this->fail('DDL failure must stop.'); }
        catch (RuntimeException $error) { $this->assertSame('Simulated column failure.', $error->getMessage()); }
        $this->assertSame(31536000, $connection->lockWaitTimeout);
        $connection->recordingPdo->failColumn = false;
        $this->assertTrue((new BomCuttingSchemaSynchronizer)->synchronize($connection)['changed']);
    }

    public function test_session_restore_failure_disconnects_and_quarantines_the_original_pdo(): void
    {
        $connection = $this->fixture(); $connection->recordingPdo->failLockRestore = true;
        try { (new BomCuttingSchemaSynchronizer)->synchronize($connection); $this->fail('Restore failure must stop.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('unsafe connection disconnected', $error->getMessage()); }
        $this->assertNull($connection->getRawPdo());
        $connection->setPdo($connection->recordingPdo);
        $this->expectExceptionMessage('Unsafe or changed database session');
        (new BomCuttingSchemaSynchronizer)->synchronize($connection);
    }

    public function test_restore_failure_can_retry_on_a_new_pdo_without_repeating_a_successful_add(): void
    {
        $connection = $this->fixture(); $connection->recordingPdo->failLockRestore = true;
        try { (new BomCuttingSchemaSynchronizer)->synchronize($connection); $this->fail('Restoration failure must stop.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('unsafe connection disconnected', $error->getMessage()); }
        $connection->recordingPdo = new BaselinePdo($connection);
        $connection->setPdo($connection->recordingPdo); $connection->lockWaitTimeout = 31536000;
        $this->assertFalse((new BomCuttingSchemaSynchronizer)->synchronize($connection)['changed']);
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_post_add_drift_is_reported_and_rechecking_does_not_repeat_the_completed_add(): void
    {
        $connection = $this->fixture(); $original = $connection->tables['erp_items'];
        $ledger = $connection->records;
        $connection->recordingPdo->afterColumn = function () use ($connection): void {
            $connection->tables['erp_items'] = str_replace('`item_name` varchar(160)', '`item_name` varchar(159)', $connection->tables['erp_items']);
        };
        try { (new BomCuttingSchemaSynchronizer)->synchronize($connection); $this->fail('A completed ADD cannot hide unrelated concurrent schema drift.'); }
        catch (RuntimeException $error) { $this->assertStringContainsString('Schema drift in erp_items', $error->getMessage()); }
        $this->assertStringContainsString('`cutting_quantity_snapshot` json DEFAULT NULL', $connection->tables['erp_bom_items']);
        $this->assertSame(31536000, $connection->lockWaitTimeout);
        $this->assertSame($ledger, $connection->records);
        $connection->tables['erp_items'] = $original;
        $this->assertFalse((new BomCuttingSchemaSynchronizer)->synchronize($connection)['changed']);
        $this->assertCount(3, $connection->recordingPdo->statements);
    }
}
