<?php

declare(strict_types=1);

namespace Database\Schema;

use Illuminate\Database\Connection;
use PDO;
use RuntimeException;
use Throwable;
use WeakMap;

/** Explicit maintenance for named facts in this implementation; no general repair. */
class ProductionOrderSchemaSynchronizer
{
    private const COLUMNS = [
        'erp_bom_items' => ['name' => 'cutting_quantity_snapshot', 'after' => 'allow_cut_rotation'],
        'erp_shipment_packing_materials' => ['name' => 'usage_snapshot', 'after' => 'serial_snapshot'],
        'erp_production_output_records' => ['name' => 'output_item_id', 'operation' => 'allow_component_bundle'],
    ];
    private const NEXT_TABLE = 'erp_production_performance_rate_versions';
    private static ?WeakMap $unsafe = null;

    public function __construct(private readonly string $target)
    {
        if (!isset(self::COLUMNS[$target])) throw new RuntimeException('Unsupported schema synchronization target.');
    }

    public function inspect(Connection $connection): array
    {
        if ($connection->pretending() || $connection->getDriverName() !== 'mysql' || $connection->getTablePrefix() !== '') {
            throw new RuntimeException('Actual MySQL metadata with an empty prefix is required.');
        }
        $pdo = $connection->getPdo();
        $this->session($connection, $pdo);
        $identity = (array) $connection->selectOne('SELECT DATABASE() AS database_name, VERSION() AS server_version', [], false);
        if (empty($identity['database_name'])) throw new RuntimeException('No database is selected.');
        $version = (string) $identity['server_version'];
        $manifest = InitialTableMigration::baseline();
        $definitions = InitialTableMigration::definitions();
        if (!isset($definitions[$this->target])) throw new RuntimeException('Target owner is absent from the canonical baseline.');
        $tables = [];
        foreach ($connection->select('SELECT TABLE_NAME,TABLE_TYPE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()', [], false) as $row) {
            if ($row->TABLE_TYPE !== 'BASE TABLE') throw new RuntimeException('Views are not accepted.');
            $tables[$row->TABLE_NAME] = true;
        }
        $expectedTables = array_fill_keys(array_merge(array_keys($definitions), ['migrations']), true);
        $absent = array_keys(array_diff_key($expectedTables, $tables));
        $pendingRate = $absent === [self::NEXT_TABLE];
        if (array_diff_key($tables, $expectedTables) || ($absent && !$pendingRate)
            || ($pendingRate && !($definitions[self::NEXT_TABLE]::ESTABLISHED_EXTENSION ?? false))) {
            throw new RuntimeException('The complete established table baseline is required.');
        }
        $records = array_map(fn ($row) => (string) $row->migration, $connection->select('SELECT migration FROM `migrations`', [], false));
        if (count($records) !== count(array_unique($records))) throw new RuntimeException('Duplicate migration records.');
        $canonical = array_values($manifest['tables']);
        $rateMigration = $manifest['tables'][self::NEXT_TABLE];
        if ($pendingRate && in_array($rateMigration, $records, true)) throw new RuntimeException('Recorded rate migration has no table.');
        $requiredCanonical = $pendingRate ? array_values(array_diff($canonical, [$rateMigration])) : $canonical;
        $legacy = array_keys($manifest['legacy_migrations']);
        $foundLegacy = array_intersect($records, $legacy);
        if (array_diff($records, array_merge($canonical, $legacy)) || array_diff($requiredCanonical, $records)
            || ($foundLegacy && count($foundLegacy) !== count($legacy))) {
            throw new RuntimeException('The complete accepted migration ledger is required.');
        }
        $missingColumns = [];
        foreach ($definitions as $table => $definition) {
            if ($pendingRate && $table === self::NEXT_TABLE) continue;
            $actual = (array) $connection->selectOne('SHOW CREATE TABLE `'.$table.'`', [], false);
            $sql = (string) ($actual['Create Table'] ?? '');
            if (!isset(self::COLUMNS[$table]) || ! $definition->schemaDifferences($sql, $version)) {
                $definition->assertEquivalent($sql, $version);
                continue;
            }
            // Each accepted old form is exact. A component bundle has no Item;
            // all established output identities, keys and foreign keys remain.
            $complete = $definition->createSql($version);
            $componentBundle = $table === 'erp_production_output_records';
            $column = '  `'.self::COLUMNS[$table]['name'].'` '.($componentBundle ? 'bigint(20) unsigned' : 'json').' DEFAULT NULL,';
            if (substr_count($complete, $column) !== 1) throw new RuntimeException('Unexpected nullable-column owner definition.');
            $old = $componentBundle
                ? str_replace($column, '  `output_item_id` bigint(20) unsigned NOT NULL,', $complete)
                : str_replace($column."\n", '', str_replace("\r\n", "\n", $complete));
            $oldDefinition = new class($old) extends InitialTableMigration {
                public function __construct(private string $sql) {}
                public function createSql(string $version): string { return $this->sql; }
            };
            $oldDefinition->assertEquivalent($sql, $version);
            $missingColumns[] = $table;
        }
        $after = (array) $connection->selectOne('SELECT DATABASE() AS database_name', [], false);
        $this->session($connection, $pdo);
        if ($identity['database_name'] !== ($after['database_name'] ?? null)) throw new RuntimeException('Database changed during preflight.');
        return ['database' => $identity['database_name'], 'version' => $version, 'target_table' => $this->target,
            'missing_column' => in_array($this->target, $missingColumns, true), 'pending_nullable_columns' => $missingColumns,
            'pending_migrations' => $pendingRate ? [$rateMigration] : []];
    }

    public function synchronize(Connection $connection): array
    {
        $pdo = $connection->getPdo();
        $plan = $this->inspect($connection);
        if (! $plan['missing_column']) return $plan + ['changed' => false];
        $setting = (array) $connection->selectOne('SELECT @@SESSION.lock_wait_timeout AS lock_wait_timeout', [], false);
        $timeout = $setting['lock_wait_timeout'] ?? null;
        if (!ctype_digit((string) $timeout) || (int) $timeout < 1 || (int) $timeout > 31536000) {
            throw new RuntimeException('Cannot determine the original lock_wait_timeout.');
        }
        $this->session($connection, $pdo);
        $rechecked = $this->inspect($connection);
        if ($plan !== $rechecked) throw new RuntimeException('Schema plan changed before synchronization.');
        try {
            $this->execute($pdo, 'SET SESSION lock_wait_timeout = '.min(5, (int) $timeout));
            $this->session($connection, $pdo);
            $column = self::COLUMNS[$this->target];
            $statement = $this->target === 'erp_production_output_records'
                ? 'ALTER TABLE `erp_production_output_records` MODIFY COLUMN `output_item_id` bigint(20) unsigned DEFAULT NULL'
                : 'ALTER TABLE `'.$this->target.'` ADD COLUMN `'.$column['name'].'` json DEFAULT NULL AFTER `'.$column['after'].'`';
            $this->execute($pdo, $statement);
        } finally {
            try { $this->execute($pdo, 'SET SESSION lock_wait_timeout = '.(int) $timeout); }
            catch (Throwable $error) {
                self::$unsafe ??= new WeakMap;
                self::$unsafe[$pdo] = true;
                $connection->disconnect();
                throw new RuntimeException('Failed to restore lock_wait_timeout; unsafe connection disconnected.', 0, $error);
            }
        }
        $this->session($connection, $pdo);
        // On the older accepted baseline, add this nullable fact first and then
        // let Laravel create/register the new rate-table owner normally. Neither
        // stage may claim the other owner's missing structure has been adopted.
        $after = $this->inspect($connection);
        if ($after['database'] !== $plan['database']) throw new RuntimeException('Database changed after synchronization.');
        if ($after['missing_column']) throw new RuntimeException('Target nullable field did not match after synchronization.');
        if (!$after['pending_migrations'] && !$after['pending_nullable_columns']) InitialTableMigration::preflight($connection);
        return $after + ['changed' => true, 'was_missing_column' => true];
    }

    private function session(Connection $connection, PDO $pdo): void
    {
        if (isset(self::$unsafe[$pdo]) || $connection->getPdo() !== $pdo) throw new RuntimeException('Unsafe or changed database session.');
    }

    private function execute(PDO $pdo, string $sql): void
    {
        if ($pdo->exec($sql) === false) throw new RuntimeException('Schema statement failed.');
    }
}
