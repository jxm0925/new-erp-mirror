<?php

namespace Tests\Unit\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\{ProductionPersonalSharePlan, ProductionPerformanceCalculator};
use PHPUnit\Framework\TestCase;

class ProductionPersonalSharePlanTest extends TestCase
{
    public function test_execution_projection_excludes_first_layer_rates_amounts_and_unrelated_fields(): void
    {
        $plan = (new ProductionPersonalSharePlan)->projection($this->scope(), [
            ['employee_legacy_id' => 101, 'employee_name' => '接单人', 'identity_exists' => true, 'is_active' => true, 'actual_labor_minutes' => '12', 'performance_amount' => '999'],
            ['employee_legacy_id' => 102, 'employee_name' => '曾参与人员', 'identity_exists' => true, 'has_labor' => true],
        ], ['version_no' => 2, 'status' => 'planned', 'performance_rate' => '0.5', 'shares' => [['employee_legacy_id' => 101, 'share_ratio' => '0.7', 'performance_amount' => '999']]]);
        $encoded = json_encode($plan, JSON_THROW_ON_ERROR);
        $this->assertStringNotContainsString('performance_rate', $encoded);
        $this->assertStringNotContainsString('performance_amount', $encoded);
        $this->assertStringNotContainsString('basis_amount', $encoded);
        $this->assertStringNotContainsString('actual_labor_minutes', $encoded);
        $this->assertSame('0.7', $plan['share_plan']['shares'][0]['share_ratio']);
        $this->assertTrue($plan['participants'][1]['has_labor']);
    }

    public function test_real_scopes_keep_different_historical_contributors_without_synthesizing_a_union(): void
    {
        $policy = new ProductionPersonalSharePlan;
        $common = ['employee_legacy_id' => 101, 'employee_name' => '接单人', 'identity_exists' => true, 'is_active' => true];
        $first = $policy->projection($this->scope(), [$common, ['employee_legacy_id' => 102, 'employee_name' => '实际甲', 'has_labor' => true]], null);
        $second = $policy->projection(array_replace($this->scope(), ['scope_id' => 12]), [$common, ['employee_legacy_id' => 103, 'employee_name' => '实际乙', 'has_labor' => true]], null);
        $this->assertSame([101, 102], array_column($first['participants'], 'employee_legacy_id'));
        $this->assertSame([101, 103], array_column($second['participants'], 'employee_legacy_id'));
    }

    public function test_manual_seventy_thirty_scope_plans_are_normalized_and_stably_ordered(): void
    {
        $policy = new ProductionPersonalSharePlan;
        $plans = $policy->normalizeScopePlans([$this->request(12), $this->request(11)], new ProductionPerformanceCalculator);
        $this->assertSame([11, 12], array_column($plans, 'scope_id'));
        $this->assertSame('0.70000000', $plans[0]['shares'][0]['share_ratio']);
        $this->assertSame('0.30000000', $plans[0]['shares'][1]['share_ratio']);
        $policy->assertScopeCoverage($plans, ['unit_operation:12', 'unit_operation:11']);
        $this->assertSame(3, $plans[0]['expected_scope_version']);
    }

    public function test_different_real_targets_cannot_reuse_a_fake_or_omitted_scope(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        $plans = (new ProductionPersonalSharePlan)->normalizeScopePlans([$this->request(11)], new ProductionPerformanceCalculator);
        (new ProductionPersonalSharePlan)->assertScopeCoverage($plans, ['unit_operation:11', 'unit_operation:12']);
    }

    public function test_duplicate_scope_is_rejected(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        (new ProductionPersonalSharePlan)->normalizeScopePlans([$this->request(11), $this->request(11)], new ProductionPerformanceCalculator);
    }

    public function test_missing_scope_versions_are_rejected(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        (new ProductionPersonalSharePlan)->normalizeScopePlans([array_replace($this->request(11), ['expected_scope_version' => 0])], new ProductionPerformanceCalculator);
    }

    public function test_each_scope_requires_exactly_one_hundred_percent(): void
    {
        $this->expectException(WorkOrderDomainException::class);
        $plan = $this->request(11); $plan['shares'][1]['share_ratio'] = '0.2';
        (new ProductionPersonalSharePlan)->normalizeScopePlans([$plan], new ProductionPerformanceCalculator);
    }

    private function scope(): array
    {
        return ['scope_type' => 'unit_operation', 'scope_id' => 11, 'scope_version' => 3, 'scope_name' => '真实生产单元',
            'owner_legacy_id' => 101, 'status' => 'IN_PROGRESS', 'can_plan' => true, 'performance_rate' => '0.5', 'basis_amount' => '999'];
    }
    private function request(int $id): array
    {
        return ['scope_type' => 'unit_operation', 'scope_id' => $id, 'expected_scope_version' => 3, 'expected_assignment_version' => 0,
            'shares' => [['employee_legacy_id' => 101, 'share_ratio' => '0.7'], ['employee_legacy_id' => 102, 'share_ratio' => '0.3']]];
    }
}
