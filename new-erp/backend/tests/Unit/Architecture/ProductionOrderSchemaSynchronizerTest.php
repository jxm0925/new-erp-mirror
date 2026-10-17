<?php

namespace Tests\Unit\Architecture;

use Database\Schema\InitialTableMigration;
use Database\Schema\ProductionOrderSchemaSynchronizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

require_once __DIR__.'/InitialTableMigrationRuntimeTest.php';
require_once __DIR__.'/../../../database/schema/ProductionOrderSchemaSynchronizer.php';

/** Complete manifest and session doubles only: no application or database is opened. */
class ProductionOrderSchemaSynchronizerTest extends TestCase
{
    private const COLUMNS = ['erp_bom_items' => 'cutting_quantity_snapshot', 'erp_shipment_packing_materials' => 'usage_snapshot'];

    private function fixture(bool $oldOutput = false): BaselineConnection
    {
        $connection = new BaselineConnection;
        foreach (InitialTableMigration::definitions() as $table => $definition) $connection->tables[$table] = $definition->createSql('8.0.12');
        foreach (self::COLUMNS as $table => $column) $connection->tables[$table] = str_replace('  `'.$column.'` json DEFAULT NULL,'."\n", '', $connection->tables[$table]);
        if ($oldOutput) $connection->tables['erp_production_output_records'] = str_replace('`output_item_id` bigint(20) unsigned DEFAULT NULL', '`output_item_id` bigint(20) unsigned NOT NULL', $connection->tables['erp_production_output_records']);
        unset($connection->tables['erp_production_performance_rate_versions']);
        $manifest = InitialTableMigration::baseline();
        $connection->records = array_values(array_diff(array_merge(array_values($manifest['tables']), array_keys($manifest['legacy_migrations'])),
            [$manifest['tables']['erp_production_performance_rate_versions']]));
        $connection->hasRows = ['erp_bom_items' => true, 'erp_shipment_packing_materials' => true, 'erp_sales_orders' => true];
        $connection->recordingPdo = new class($connection) extends BaselinePdo {
            public function __construct(private BaselineConnection $state) { parent::__construct($state); }
            public function exec(string $statement): int|false
            {
                $allowed = [
                    'ALTER TABLE `erp_bom_items` ADD COLUMN `cutting_quantity_snapshot` json DEFAULT NULL AFTER `allow_cut_rotation`' => 'erp_bom_items',
                    'ALTER TABLE `erp_shipment_packing_materials` ADD COLUMN `usage_snapshot` json DEFAULT NULL AFTER `serial_snapshot`' => 'erp_shipment_packing_materials',
                    'ALTER TABLE `erp_production_output_records` MODIFY COLUMN `output_item_id` bigint(20) unsigned DEFAULT NULL' => 'erp_production_output_records',
                ];
                if (isset($allowed[$statement])) {
                    $this->statements[] = $statement;
                    $table = $allowed[$statement];
                    $this->state->tables[$table] = InitialTableMigration::definitions()[$table]->createSql($this->state->version);
                    return 0;
                }
                return parent::exec($statement);
            }
        };
        $connection->setPdo($connection->recordingPdo);
        return $connection;
    }

    public static function upgradeOrders(): array
    {
        return ['BOM then packing' => ['erp_bom_items', 'erp_shipment_packing_materials'],
            'packing then BOM' => ['erp_shipment_packing_materials', 'erp_bom_items']];
    }

    #[DataProvider('upgradeOrders')]
    public function test_each_exact_add_is_independent_and_preserves_existing_business_and_ledger_before_the_rate_owner(string $first, string $second): void
    {
        $connection = $this->fixture(); $original = $connection->tables;
        $ledger = $connection->records; $rows = $connection->hasRows;
        $service = new ProductionOrderSchemaSynchronizer($first);
        $plan = $service->inspect($connection);
        $this->assertSame(array_keys(self::COLUMNS), $plan['pending_nullable_columns']);
        $this->assertCount(1, $plan['pending_migrations']);
        $this->assertSame([], $connection->recordingPdo->statements);
        $result = $service->synchronize($connection);
        $this->assertTrue($result['changed']); $this->assertFalse($result['missing_column']);
        $this->assertSame([$second], $result['pending_nullable_columns']);
        $this->assertSame($original[$second], $connection->tables[$second]);
        $this->assertSame($ledger, $connection->records); $this->assertSame($rows, $connection->hasRows);
        $this->assertCount(3, $connection->recordingPdo->statements);
        $this->assertFalse($service->synchronize($connection)['changed']);
        $this->assertCount(3, $connection->recordingPdo->statements);

        $result = (new ProductionOrderSchemaSynchronizer($second))->synchronize($connection);
        $this->assertTrue($result['changed']); $this->assertFalse($result['missing_column']);
        $this->assertSame([], $result['pending_nullable_columns']); $this->assertCount(1, $result['pending_migrations']);
        $this->assertSame($ledger, $connection->records); $this->assertSame($rows, $connection->hasRows);
        $this->assertCount(6, $connection->recordingPdo->statements);
        foreach (array_diff(array_keys($original), array_keys(self::COLUMNS)) as $table) $this->assertSame($original[$table], $connection->tables[$table]);

        $rate = InitialTableMigration::definitions()['erp_production_performance_rate_versions'];
        $extension = $rate->preflightEstablishedExtension($connection);
        $this->assertSame('extension', $extension['mode']);
        $this->assertSame(['erp_production_performance_rate_versions'], $extension['missing']);
        // Simulate only the separately authorized normal owner creation/ledger
        // acceptance; neither nullable ADD action may register the new owner.
        $connection->tables['erp_production_performance_rate_versions'] = $rate->createSql('8.0.12');
        $connection->records[] = InitialTableMigration::baseline()['tables']['erp_production_performance_rate_versions'];
        $this->assertSame('existing', InitialTableMigration::preflight($connection)['mode']);
        $this->assertCount(6, $connection->recordingPdo->statements);
    }

    public static function unsafeStates(): array
    {
        return ['lost original table' => ['lost'], 'unexpected table' => ['extra'],
            'rate recorded but table lost' => ['rate_lost'], 'unregistered rate table' => ['rate_unregistered'],
            'wrong packing nullable type' => ['packing_type'], 'wrong original BOM type' => ['bom_type'],
            'incomplete original ledger' => ['ledger']];
    }

    #[DataProvider('unsafeStates')]
    public function test_allowing_two_named_columns_never_masks_other_unsafe_state(string $state): void
    {
        $connection = $this->fixture(); $manifest = InitialTableMigration::baseline();
        if ($state === 'lost') unset($connection->tables['erp_items']);
        if ($state === 'extra') $connection->tables['unexpected'] = 'unused';
        if ($state === 'rate_lost') $connection->records[] = $manifest['tables']['erp_production_performance_rate_versions'];
        if ($state === 'rate_unregistered') $connection->tables['erp_production_performance_rate_versions'] = InitialTableMigration::definitions()['erp_production_performance_rate_versions']->createSql('8.0.12');
        if ($state === 'packing_type') $connection->tables['erp_shipment_packing_materials'] = str_replace('`usage_snapshot` json DEFAULT NULL', '`usage_snapshot` text DEFAULT NULL', InitialTableMigration::definitions()['erp_shipment_packing_materials']->createSql('8.0.12'));
        if ($state === 'bom_type') $connection->tables['erp_bom_items'] = str_replace('decimal(14,4)', 'decimal(14,3)', $connection->tables['erp_bom_items']);
        if ($state === 'ledger') $connection->records = array_values(array_diff($connection->records, [$manifest['tables']['erp_sales_orders']]));
        try { (new ProductionOrderSchemaSynchronizer('erp_shipment_packing_materials'))->synchronize($connection); $this->fail('Unsafe metadata must block before ADD.'); }
        catch (RuntimeException $error) { $this->assertNotSame('', $error->getMessage()); }
        $this->assertSame([], $connection->recordingPdo->statements);
    }

    public function test_a_third_table_cannot_become_a_synchronization_target(): void
    {
        $this->expectExceptionMessage('Unsupported schema synchronization target');
        new ProductionOrderSchemaSynchronizer('erp_sales_orders');
    }

    public static function outputUpgradeOrders(): array
    {
        return ['output first' => [['erp_production_output_records', 'erp_bom_items', 'erp_shipment_packing_materials']],
            'output between' => [['erp_shipment_packing_materials', 'erp_production_output_records', 'erp_bom_items']],
            'output last' => [['erp_bom_items', 'erp_shipment_packing_materials', 'erp_production_output_records']]];
    }

    #[DataProvider('outputUpgradeOrders')]
    public function test_component_bundle_nullable_identity_preserves_all_original_tables_rows_and_ledger(array $order): void
    {
        $connection = $this->fixture(true); $original = $connection->tables;
        $rows = $connection->hasRows; $ledger = $connection->records;
        foreach ($order as $target) {
            $sync = new ProductionOrderSchemaSynchronizer($target);
            $this->assertTrue($sync->inspect($connection)['missing_column']);
            $this->assertTrue($sync->synchronize($connection)['changed']);
            $this->assertFalse($sync->synchronize($connection)['changed']);
            $this->assertSame($rows, $connection->hasRows); $this->assertSame($ledger, $connection->records);
        }
        foreach ($original as $table => $sql) {
            if (in_array($table, $order, true)) $this->assertSame(InitialTableMigration::definitions()[$table]->createSql('8.0.12'), $connection->tables[$table]);
            else $this->assertSame($sql, $connection->tables[$table]);
        }
        $this->assertCount(9, $connection->recordingPdo->statements);
        $this->assertSame([], (new ProductionOrderSchemaSynchronizer('erp_production_output_records'))->inspect($connection)['pending_nullable_columns']);
        $this->assertSame(['erp_production_performance_rate_versions'], InitialTableMigration::definitions()['erp_production_performance_rate_versions']->preflightEstablishedExtension($connection)['missing']);
    }

    public function test_component_nullable_upgrade_never_accepts_a_type_or_foreign_key_change(): void
    {
        foreach (['bigint(20)' => 'int(10)', 'REFERENCES `erp_items` (`id`)' => 'REFERENCES `erp_skus` (`id`)'] as $before => $after) {
            $connection = $this->fixture(true);
            $connection->tables['erp_production_output_records'] = str_replace($before, $after, $connection->tables['erp_production_output_records']);
            try { (new ProductionOrderSchemaSynchronizer('erp_production_output_records'))->synchronize($connection); $this->fail('Identity drift must block before ALTER.'); }
            catch (RuntimeException $error) { $this->assertNotSame('', $error->getMessage()); }
            $this->assertSame([], $connection->recordingPdo->statements);
        }
    }
}
