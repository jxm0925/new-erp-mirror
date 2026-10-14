<?php

namespace Tests\Unit\Erp;

use App\Models\Erp\ApprovalFlowTemplate;
use App\Models\Erp\ApprovalFlowVersion;
use Illuminate\Database\ConnectionResolver;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Query\Builder;
use PHPUnit\Framework\TestCase;
use RuntimeException;

/** Real Eloquent/MySQL grammar, without a Laravel application or a PDO connection. */
class ApprovalFlowCurrentVersionRelationTest extends TestCase
{
    private MySqlConnection $connection;
    private ?ConnectionResolverInterface $previousResolver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->previousResolver = Model::getConnectionResolver();
        $this->connection = new MySqlConnection(
            static fn () => throw new RuntimeException('PDO access is forbidden in this regression test.'),
            'offline',
            '',
            ['version' => '8.0.12'],
        );
        $resolver = new ConnectionResolver(['offline' => $this->connection]);
        $resolver->setDefaultConnection('offline');
        Model::setConnectionResolver($resolver);
    }

    protected function tearDown(): void
    {
        if ($this->previousResolver) {
            Model::setConnectionResolver($this->previousResolver);
        } else {
            Model::unsetConnectionResolver();
        }
        parent::tearDown();
    }

    public function test_lazy_load_has_its_own_parent_scope_and_preserves_version_columns(): void
    {
        $relation = $this->template(101, 2)->currentVersion();
        $this->assertCurrentPointerScope($relation->getQuery()->getQuery());

        $queries = $this->connection->pretend(fn () => $relation->getResults());

        self::assertCount(1, $queries);
        self::assertSame([101], $queries[0]['bindings']);
        self::assertStringStartsWith('select * from `erp_approval_flow_versions`', $queries[0]['query']);
        self::assertStringContainsString('`erp_approval_flow_versions`.`flow_template_id` = 101', $queries[0]['query']);
        self::assertStringContainsString('from `erp_approval_flow_templates` as `current_flow_template`', $queries[0]['query']);
        self::assertStringContainsString('limit 1', $queries[0]['query']);
    }

    public function test_eager_load_builds_one_query_for_different_current_pointers(): void
    {
        $parents = [$this->template(101, 2), $this->template(102, 1), $this->template(103, 0), $this->template(104, 9)];
        $builder = ApprovalFlowTemplate::query()->with('currentVersion');
        $relation = $builder->getRelation('currentVersion');
        $relation->addEagerConstraints($parents);
        $this->assertCurrentPointerScope($relation->getQuery()->getQuery());

        $queries = $this->connection->pretend(fn () => $builder->eagerLoadRelations($parents));

        self::assertCount(1, $queries);
        self::assertSame([], $queries[0]['bindings']);
        self::assertStringContainsString('`erp_approval_flow_versions`.`flow_template_id` in (101, 102, 103, 104)', $queries[0]['query']);
        self::assertStringNotContainsString('`version_no` is null', $queries[0]['query']);
        self::assertStringNotContainsString('max(', $queries[0]['query']);
        foreach ($parents as $parent) {
            self::assertTrue($parent->relationLoaded('currentVersion'));
            self::assertNull($parent->currentVersion);
        }
    }

    public function test_eager_matching_keeps_each_template_and_unpublished_or_missing_pointers_separate(): void
    {
        $parents = [$this->template(101, 2), $this->template(102, 1), $this->template(103, 0), $this->template(104, 9)];
        $relation = ApprovalFlowTemplate::query()->getRelation('currentVersion');
        $relation->addEagerConstraints($parents);
        $this->assertCurrentPointerScope($relation->getQuery()->getQuery());

        // Matching is tested independently of SQL compilation: these are the exact rows
        // returned by the pointer constraint, even when newer drafts exist in storage.
        $results = new Collection([
            $this->version(502, 102, 1),
            $this->version(501, 101, 2),
        ]);
        $matched = $relation->match($relation->initRelation($parents, 'currentVersion'), $results, 'currentVersion');

        self::assertSame(501, $matched[0]->currentVersion->id);
        self::assertSame(2, $matched[0]->currentVersion->version_no);
        self::assertSame(502, $matched[1]->currentVersion->id);
        self::assertSame(1, $matched[1]->currentVersion->version_no);
        self::assertNull($matched[2]->currentVersion);
        self::assertNull($matched[3]->currentVersion);
    }

    public function test_zero_current_pointer_is_not_used_as_an_eager_instance_filter(): void
    {
        $parent = $this->template(103, 0);
        $relation = $parent->currentVersion();
        $this->assertCurrentPointerScope($relation->getQuery()->getQuery());
        self::assertSame([103], $relation->getBindings());
        self::assertStringNotContainsString('`version_no` = ?', $relation->toSql());
        self::assertStringContainsString('`current_flow_template`.`current_version` = `erp_approval_flow_versions`.`version_no`', $relation->toSql());

        $unloaded = ApprovalFlowTemplate::query()->getRelation('currentVersion');
        $this->assertCurrentPointerScope($unloaded->getQuery()->getQuery());
        self::assertSame([], $unloaded->getBindings());
    }

    public function test_null_parent_key_does_not_execute_a_query(): void
    {
        $parent = new ApprovalFlowTemplate();
        $result = false;
        $queries = $this->connection->pretend(function () use ($parent, &$result): void {
            $result = $parent->currentVersion()->getResults();
        });

        self::assertNull($result);
        self::assertSame([], $queries);
    }

    public function test_existence_and_aggregate_queries_retain_the_complete_pointer_scope(): void
    {
        $queries = [
            ApprovalFlowTemplate::query()->whereHas('currentVersion'),
            ApprovalFlowTemplate::query()->whereDoesntHave('currentVersion'),
            ApprovalFlowTemplate::query()->has('currentVersion', '>=', 2),
            ApprovalFlowTemplate::query()->withCount('currentVersion'),
        ];

        foreach ($queries as $query) {
            $sql = $query->toSql();
            self::assertStringContainsString('`erp_approval_flow_templates`.`id` = `erp_approval_flow_versions`.`flow_template_id`', $sql);
            self::assertStringContainsString('exists (select 1 from `erp_approval_flow_templates` as `current_flow_template`', $sql);
            self::assertStringContainsString('`current_flow_template`.`id` = `erp_approval_flow_versions`.`flow_template_id`', $sql);
            self::assertStringContainsString('`current_flow_template`.`current_version` = `erp_approval_flow_versions`.`version_no`', $sql);
            self::assertSame([], $query->getBindings());
        }
    }

    private function assertCurrentPointerScope(Builder $query): void
    {
        $exists = array_values(array_filter($query->wheres, fn (array $where) => $where['type'] === 'Exists'));
        self::assertCount(1, $exists, 'A standalone relation query must include the template table inside its own scope.');
        $scope = $exists[0]['query'];
        self::assertSame('erp_approval_flow_templates as current_flow_template', $scope->from);
        self::assertCount(2, $scope->wheres);
        self::assertSame('Column', $scope->wheres[0]['type']);
        self::assertSame('current_flow_template.id', $scope->wheres[0]['first']);
        self::assertSame('erp_approval_flow_versions.flow_template_id', $scope->wheres[0]['second']);
        self::assertSame('Column', $scope->wheres[1]['type']);
        self::assertSame('current_flow_template.current_version', $scope->wheres[1]['first']);
        self::assertSame('erp_approval_flow_versions.version_no', $scope->wheres[1]['second']);
    }

    private function template(int $id, int $currentVersion): ApprovalFlowTemplate
    {
        return (new ApprovalFlowTemplate())->newFromBuilder(['id' => $id, 'current_version' => $currentVersion]);
    }

    private function version(int $id, int $templateId, int $versionNo): ApprovalFlowVersion
    {
        return (new ApprovalFlowVersion())->newFromBuilder([
            'id' => $id,
            'flow_template_id' => $templateId,
            'version_no' => $versionNo,
            'version_status' => 'published',
            'definition_snapshot' => '{"nodes":[]}',
        ]);
    }
}
