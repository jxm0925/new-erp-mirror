<?php

namespace Tests\Unit\Erp;

use Database\Seeders\{ErpStandardUnitSeeder, ErpDocumentNumberRuleSeeder, ErpApprovedMasterDataSeeder, ErpProductionCatalogSeeder, ErpFinanceReferenceSeeder, ErpSalesReferenceSeeder, ErpRbacSeeder, ErpApprovalConfigurationSeeder, ErpAdministratorSeeder};
use Database\Seeders\Support\SeedSafety;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Schema, Facade};
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** No Laravel application, PDO, real connection or seeder command is created. */
class SeederPreservationTest extends TestCase
{
    private MemorySeedDatabase $db;

    protected function setUp(): void
    {
        $this->db = new MemorySeedDatabase;
        DB::swap($this->db);
        Schema::swap(new MemorySeedSchema($this->db));
    }

    protected function tearDown(): void
    {
        Facade::clearResolvedInstances();
    }

    private function seeders(): array
    {
        return [new TestUnits, new TestNumbers, new TestMaster, new TestProduction, new TestFinance, new TestSales, new TestRbac, new TestApproval];
    }

    private function populate(): void
    {
        foreach ($this->seeders() as $seeder) $seeder->run();
    }

    public function test_second_run_preserves_every_existing_row_and_all_authorizations(): void
    {
        $this->populate();
        self::assertGreaterThan(500, array_sum(array_map('count', $this->db->rows)));
        foreach ($this->db->rows as $table => &$rows) {
            if ($table === 'information_schema.COLUMNS') continue;
            foreach ($rows as &$row) {
                foreach (['name', 'unit_name', 'currency_name', 'policy_name', 'channel_name', 'symbol', 'remark'] as $field) {
                    if (array_key_exists($field, $row)) $row[$field] = 'manual';
                }
                foreach (['enabled', 'is_system', 'allow_decimal', 'allow_manual_edit', 'shipment_requires_full_payment'] as $field) {
                    if (array_key_exists($field, $row)) $row[$field] = false;
                }
                if (array_key_exists('prefix', $row)) $row['prefix'] = 'MANUAL';
                if (array_key_exists('decimal_places', $row)) $row['decimal_places'] = 4;
                if (array_key_exists('production_threshold_value', $row)) $row['production_threshold_value'] = 0.75;
                if (array_key_exists('current_sequence', $row)) $row['current_sequence'] += 500;
                if (array_key_exists('status', $row)) $row['status'] = 'disabled';
                if (array_key_exists('data_scope', $row)) $row['data_scope'] = 'self';
                $row['updated_at'] = '2001-01-01 00:00:00';
            }
            unset($row);
        }
        unset($rows);
        $template = &$this->db->rows['erp_approval_flow_templates'][0];
        $template['current_version'] = 2;
        $template['status'] = 'enabled';
        $version = $this->db->rows['erp_approval_flow_versions'][0];
        $version['id'] = 900;
        $version['version_no'] = 2;
        $version['version_status'] = 'published';
        $version['published_by'] = 'manual publisher';
        $version['published_at'] = '2001-01-01 00:00:00';
        $version['definition_snapshot'] = '{"manual":true}';
        $this->db->rows['erp_approval_flow_versions'][] = $version;
        unset($template);
        $this->db->rows['erp_legacy_admin_users'] = [['legacy_id' => 77, 'username' => 'admin', 'status' => 'locked', 'password_hash' => 'manual']];
        $this->db->rows['erp_rbac_user_roles'] = [['user_legacy_id' => 77, 'role_id' => 2]];
        $this->db->rows['erp_rbac_role_permissions'] = [['role_id' => 2, 'permission_id' => 123]];
        $this->db->rows['erp_rbac_user_role_sources'] = [['user_legacy_id' => 77, 'role_id' => 2, 'source_type' => 'manual']];
        $before = $this->db->rows;
        $writes = $this->db->writes;
        foreach ($this->seeders() as $seeder) $seeder->run();
        (new TestAdmin)->run();
        self::assertSame($before, $this->db->rows);
        self::assertSame($writes, $this->db->writes);
    }

    public function test_new_approval_template_is_an_unpublished_current_format_draft(): void
    {
        $this->populate();
        $template = $this->db->rows['erp_approval_flow_templates'][0];
        $versions = $this->db->rows['erp_approval_flow_versions'];
        self::assertSame('draft', $template['status']);
        self::assertSame(0, $template['current_version']);
        self::assertCount(1, $versions);
        $version = $versions[0];
        self::assertSame('draft', $version['version_status']);
        self::assertSame(1, $version['version_no']);
        self::assertNull($version['validation_snapshot']);
        self::assertNull($version['published_by']);
        self::assertNull($version['published_at']);
        $definition = json_decode($version['definition_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(2, $definition['schema_version']);
        self::assertSame('existing', $definition['source_mode']);
        self::assertSame('SALES_ORDER_CHANGE', $definition['business_object_code']);
        self::assertSame('submit_change', $definition['event_code']);
        self::assertSame('EVENT_TRIGGER', $definition['trigger_mode']);
        self::assertSame([], $definition['completion_actions'], 'A business owner must configure and validate completion behavior before publication.');
        self::assertArrayNotHasKey('callbacks', $definition);
        $before = $this->db->rows;
        $writes = $this->db->writes;
        (new TestApproval)->run();
        self::assertSame($before, $this->db->rows);
        self::assertSame($writes, $this->db->writes);
    }

    public function test_new_definitions_never_grant_permissions_or_create_an_administrator(): void
    {
        (new TestRbac)->run();
        (new TestAdmin)->run();
        foreach (['erp_legacy_admin_users', 'erp_rbac_user_roles', 'erp_rbac_role_permissions', 'erp_rbac_user_role_sources'] as $table) {
            self::assertEmpty($this->db->rows[$table] ?? []);
        }
        $source = file_get_contents(__DIR__.'/../../../database/seeders/DatabaseSeeder.php');
        self::assertStringNotContainsString('ErpAdministratorSeeder::class', $source);
    }

    public function test_empty_rbac_catalog_is_supplemented_before_building_the_approval_draft(): void
    {
        self::assertEmpty($this->db->rows['erp_rbac_roles'] ?? []);
        (new TestRbac)->run();
        foreach (['sales_manager', 'finance_manager', 'fulfillment_manager', 'purchaser', 'warehouse_manager'] as $code) {
            $role = DB::table('erp_rbac_roles')->where('code', $code)->first();
            self::assertNotNull($role, $code);
            self::assertTrue($role->enabled);
            self::assertSame('department', $role->data_scope);
        }
        self::assertEmpty($this->db->rows['erp_rbac_role_permissions'] ?? []);
        self::assertEmpty($this->db->rows['erp_rbac_user_roles'] ?? []);
        (new TestApproval)->run();
        self::assertSame('draft', $this->db->rows['erp_approval_flow_templates'][0]['status']);
        self::assertSame(0, $this->db->rows['erp_approval_flow_templates'][0]['current_version']);
        self::assertSame('draft', $this->db->rows['erp_approval_flow_versions'][0]['version_status']);
    }

    public function test_legacy_permission_identity_and_grants_survive_definition_supplement(): void
    {
        $this->db->rows['erp_rbac_permissions'] = [['id' => 800, 'code' => 'item_category.edit', 'enabled' => false, 'is_system' => false]];
        $this->db->rows['erp_rbac_role_permissions'] = [['role_id' => 50, 'permission_id' => 800]];
        (new TestRbac)->run();
        self::assertSame(['id' => 800, 'code' => 'item_category.edit', 'enabled' => false, 'is_system' => false], $this->db->rows['erp_rbac_permissions'][0]);
        self::assertSame([['role_id' => 50, 'permission_id' => 800]], $this->db->rows['erp_rbac_role_permissions']);
    }

    public function test_missing_approval_dependency_fails_before_any_write(): void
    {
        $this->assertNoWriteFailure(fn () => (new TestApproval)->run(), '角色定义');
    }

    public function test_invalid_existing_approval_version_fails_before_filling_other_gaps(): void
    {
        $this->populate();
        $this->db->rows['erp_approval_flow_templates'][0]['current_version'] = 1;
        $this->db->rows['erp_approval_flow_templates'][0]['status'] = 'enabled';
        $this->db->rows['erp_approval_flow_versions'] = [];
        $this->db->rows['erp_approval_business_object_fields'] = [];
        $this->assertNoWriteFailure(fn () => (new TestApproval)->run(), '当前版本');
    }

    public function test_low_existing_counter_is_not_advanced_or_followed_by_any_write(): void
    {
        (new TestNumbers)->run();
        $this->db->rows['erp_document_numbers'][] = ['id' => 1, 'document_type' => 'supplier', 'number_date' => '1970-01-01', 'current_sequence' => 0];
        $this->assertNoWriteFailure(fn () => (new TestMaster)->run(), '计数不足');
    }

    public function test_conflicting_base_currency_fails_before_inserting_other_currencies(): void
    {
        $this->db->rows['erp_finance_currencies'] = [['id' => 1, 'currency_code' => 'USD', 'is_base' => true]];
        $this->assertNoWriteFailure(fn () => (new TestFinance)->run(), '本位币');
    }

    public function test_new_channel_cannot_silently_reference_a_different_policy(): void
    {
        $this->db->rows['erp_sales_funding_policies'] = [['policy_code' => 'FULL_PREPAY', 'policy_type' => 'manual', 'status' => 'enabled']];
        $this->assertNoWriteFailure(fn () => (new TestSales)->run(), '既有政策语义');
    }

    public function test_new_approval_flow_cannot_reuse_a_disabled_configured_event(): void
    {
        $this->populate();
        $this->db->rows['erp_approval_flow_templates'] = [];
        $this->db->rows['erp_approval_flow_versions'] = [];
        foreach ($this->db->rows['erp_approval_business_events'] as &$event) {
            if ($event['event_code'] === 'submit_change') $event['enabled'] = false;
        }
        unset($event);
        $this->assertNoWriteFailure(fn () => (new TestApproval)->run(), '既有事件');
    }

    public function test_missing_approval_source_column_is_rejected_before_any_write(): void
    {
        $schema = $this->getMockBuilder(MemorySeedSchema::class)->disableOriginalConstructor()->onlyMethods(['hasTable', 'getColumnListing'])->getMock();
        $schema->method('hasTable')->willReturn(true);
        $schema->method('getColumnListing')->willReturn([]);
        Schema::swap($schema);
        $this->assertNoWriteFailure(fn () => (new TestApproval)->run(), '来源字段缺失');
    }

    public function test_schema_guard_rejects_fresh_or_incomplete_plans_and_precedes_transaction(): void
    {
        SeedSafety::assertPlan(['mode' => 'existing', 'missing' => []]);
        foreach ([['mode' => 'fresh', 'missing' => []], ['mode' => 'existing', 'missing' => ['erp_units']], []] as $plan) {
            try { SeedSafety::assertPlan($plan); self::fail('Unsafe plan accepted'); } catch (RuntimeException $e) { self::assertStringContainsString('严格结构预检', $e->getMessage()); }
        }
        $seeder = new class extends ErpStandardUnitSeeder {
            protected function preflightSchema(): void { throw new RuntimeException('rejected schema'); }
        };
        $this->assertNoWriteFailure(fn () => $seeder->run(), 'rejected schema');
        self::assertSame(0, $this->db->transactions);
    }

    public function test_insert_errors_are_propagated_without_ignore_or_update(): void
    {
        $this->db->failInsert = true;
        $this->expectExceptionMessage('simulated non-duplicate constraint failure');
        SeedSafety::insertMissing('erp_units', ['unit_code' => 'X'], ['name' => 'X']);
    }

    private function assertNoWriteFailure(callable $run, string $message): void
    {
        $before = $this->db->rows;
        $writes = $this->db->writes;
        try { $run(); self::fail('Expected dependency failure'); }
        catch (RuntimeException $e) { self::assertStringContainsString($message, $e->getMessage()); }
        self::assertSame($writes, $this->db->writes, 'Even rolled-back insert attempts are forbidden before dependency checks.');
        self::assertSame($before, $this->db->rows);
    }
}

trait MemorySchemaPreflight { protected function preflightSchema(): void {} }
class TestUnits extends ErpStandardUnitSeeder { use MemorySchemaPreflight; }
class TestNumbers extends ErpDocumentNumberRuleSeeder { use MemorySchemaPreflight; }
class TestMaster extends ErpApprovedMasterDataSeeder { use MemorySchemaPreflight; }
class TestProduction extends ErpProductionCatalogSeeder { use MemorySchemaPreflight; }
class TestFinance extends ErpFinanceReferenceSeeder { use MemorySchemaPreflight; }
class TestSales extends ErpSalesReferenceSeeder { use MemorySchemaPreflight; }
class TestRbac extends ErpRbacSeeder { use MemorySchemaPreflight; }
class TestApproval extends ErpApprovalConfigurationSeeder { use MemorySchemaPreflight; }
class TestAdmin extends ErpAdministratorSeeder { use MemorySchemaPreflight; }

class MemorySeedDatabase
{
    public array $rows = [];
    public int $writes = 0;
    public int $transactions = 0;
    public bool $failInsert = false;
    public function table(string $name): MemorySeedQuery { return new MemorySeedQuery($this, $name); }
    public function connection(): self { return $this; }
    public function getDatabaseName(): string { return 'memory_only'; }
    public function transaction(callable $callback): mixed
    {
        ++$this->transactions;
        $before = $this->rows;
        try { return $callback(); } catch (\Throwable $error) { $this->rows = $before; throw $error; }
    }
}
class MemorySeedQuery
{
    private array $conditions = [];
    public function __construct(private MemorySeedDatabase $db, private string $table) {}
    public function where(array|string $key, mixed $value = null): self
    {
        foreach (is_array($key) ? $key : [$key => $value] as $field => $match) $this->conditions[] = ['and', $field, [$match]];
        return $this;
    }
    public function orWhere(string $key, mixed $value): self { $this->conditions[] = ['or', $key, [$value]]; return $this; }
    public function whereIn(string $key, array $value): self { $this->conditions[] = ['and', $key, $value]; return $this; }
    public function lockForUpdate(): self { return $this; }
    public function orderBy(string $field): self { return $this; }
    public function get(array $columns = ['*']): Collection
    {
        $rows = array_filter($this->db->rows[$this->table] ?? [], function ($row) {
            $accepted = true;
            foreach ($this->conditions as [$operator, $field, $values]) {
                $matches = in_array($row[$field] ?? null, $values, false);
                $accepted = $operator === 'or' ? $accepted || $matches : $accepted && $matches;
            }
            return $accepted;
        });
        return new Collection(array_map(fn ($row) => (object) $row, array_values($rows)));
    }
    public function first(): ?object { return $this->get()->first(); }
    public function exists(): bool { return $this->first() !== null; }
    public function value(string $column): mixed { return $this->first()?->{$column}; }
    public function pluck(string $value, ?string $key = null): Collection { return $this->get()->pluck($value, $key); }
    public function insert(array $row): bool
    {
        if ($this->db->failInsert) throw new RuntimeException('simulated non-duplicate constraint failure');
        ++$this->db->writes;
        if (!isset($row['id'])) $row['id'] = max(array_column($this->db->rows[$this->table] ?? [], 'id') ?: [0]) + 1;
        $this->db->rows[$this->table][] = $row;
        return true;
    }
    public function __call(string $method, array $args): never { throw new RuntimeException('Unexpected mutation/query: '.$method); }
}
class MemorySeedSchema
{
    private array $columns = [];
    public function __construct(MemorySeedDatabase $db)
    {
        $root = __DIR__.'/../../../database';
        $baseline = json_decode(file_get_contents($root.'/schema/baseline.json'), true, 512, JSON_THROW_ON_ERROR);
        foreach ($baseline['tables'] as $table => $file) {
            $sql = file_get_contents($root.'/migrations/'.$file.'.php');
            preg_match_all('/^  \x60([^\x60]+)\x60 ([^\n]+)/m', $sql, $matches);
            $this->columns[$table] = $matches[1];
            foreach ($matches[1] as $name) $db->rows['information_schema.COLUMNS'][] = ['TABLE_SCHEMA' => 'memory_only', 'TABLE_NAME' => $table, 'COLUMN_NAME' => $name, 'DATA_TYPE' => 'varchar', 'COLUMN_TYPE' => 'varchar(255)', 'COLUMN_COMMENT' => ''];
        }
    }
    public function hasTable(string $table): bool { return isset($this->columns[$table]); }
    public function hasColumn(string $table, string $column): bool { return in_array($column, $this->columns[$table] ?? [], true); }
    public function getColumnListing(string $table): array { return $this->columns[$table] ?? []; }
}
