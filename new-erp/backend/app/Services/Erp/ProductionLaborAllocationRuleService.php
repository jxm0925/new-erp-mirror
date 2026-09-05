<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionExecutionCommand;
use App\Models\Erp\ProductionLaborAllocationRule;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

class ProductionLaborAllocationRuleService
{
    private const RULE_NO = 'GLOBAL_LABOR_ALLOCATION';

    public function list(array $permissions): array
    {
        $this->permission($permissions, 'production.labor_rule.view');
        return ProductionLaborAllocationRule::query()->orderByDesc('version_no')->get()->map(fn ($rule) => $this->projection($rule))->all();
    }

    public function createVersion(array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.labor_rule.manage');
        return $this->command('create_labor_rule_version', 0, $payload, $user, function () use ($payload, $user): array {
            $this->assertRatios($payload);
            $latest = ProductionLaborAllocationRule::query()->where('rule_no', self::RULE_NO)->lockForUpdate()->max('version_no');
            $rule = ProductionLaborAllocationRule::create([
                'rule_no' => self::RULE_NO,
                'rule_name' => trim((string) ($payload['rule_name'] ?? '生产协同工时分配规则')),
                'version_no' => (int) $latest + 1,
                'owner_ratio' => (float) $payload['owner_ratio'],
                'collaborator_total_ratio' => (float) $payload['collaborator_total_ratio'],
                'collaborator_allocation_method' => 'actual_labor_ratio',
                'status' => 'draft',
                'created_by_legacy_id' => $this->userId($user),
                'updated_by_legacy_id' => $this->userId($user),
                'business_version' => 1,
            ]);
            return $this->projection($rule);
        });
    }

    public function activate(int $id, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.labor_rule.manage');
        return $this->command('activate_labor_rule', $id, $payload, $user, function () use ($id, $payload, $user): array {
            $rule = ProductionLaborAllocationRule::query()->lockForUpdate()->find($id);
            if (! $rule) $this->fail('labor_rule_not_found', '工时分配规则不存在。', 404);
            $this->version($rule, $payload);
            if ($rule->status !== 'draft') $this->fail('labor_rule_state_invalid', '只有草稿规则可以生效。', 409);
            $current = ProductionLaborAllocationRule::query()->where('active_scope_key', self::RULE_NO)->lockForUpdate()->first();
            if ($current) $current->update(['status' => 'retired', 'active_scope_key' => null, 'retired_at' => now(),
                'updated_by_legacy_id' => $this->userId($user), 'business_version' => (int) $current->business_version + 1]);
            $rule->update(['status' => 'active', 'active_scope_key' => self::RULE_NO, 'effective_at' => now(),
                'updated_by_legacy_id' => $this->userId($user), 'business_version' => (int) $rule->business_version + 1]);
            return $this->projection($rule->fresh());
        });
    }

    public function retire(int $id, array $payload, object $user, array $permissions): array
    {
        $this->permission($permissions, 'production.labor_rule.manage');
        return $this->command('retire_labor_rule', $id, $payload, $user, function () use ($id, $payload, $user): array {
            $rule = ProductionLaborAllocationRule::query()->lockForUpdate()->find($id);
            if (! $rule) $this->fail('labor_rule_not_found', '工时分配规则不存在。', 404);
            $this->version($rule, $payload);
            if ($rule->status !== 'active') $this->fail('labor_rule_state_invalid', '只有已生效规则可以退役。', 409);
            $rule->update(['status' => 'retired', 'active_scope_key' => null, 'retired_at' => now(),
                'updated_by_legacy_id' => $this->userId($user), 'business_version' => (int) $rule->business_version + 1]);
            return $this->projection($rule->fresh());
        });
    }

    public function activeSnapshot(): array
    {
        $rule = ProductionLaborAllocationRule::query()->where('active_scope_key', self::RULE_NO)->where('status', 'active')->first();
        if ($rule) return $this->projection($rule);
        return [
            'id' => null, 'rule_no' => 'SYSTEM_DEFAULT', 'rule_name' => '系统默认生产协同工时分配规则',
            'version_no' => 1, 'owner_ratio' => 0.6, 'collaborator_total_ratio' => 0.4,
            'collaborator_allocation_method' => 'actual_labor_ratio', 'status' => 'built_in',
        ];
    }

    private function assertRatios(array $payload): void
    {
        $owner = (float) ($payload['owner_ratio'] ?? -1);
        $collaborators = (float) ($payload['collaborator_total_ratio'] ?? -1);
        if ($owner < 0 || $collaborators < 0 || abs($owner + $collaborators - 1) > 0.0001) {
            $this->fail('labor_rule_ratio_invalid', '负责人占比与协同合计占比必须均不小于 0，且合计为 100%。');
        }
    }

    private function version(ProductionLaborAllocationRule $rule, array $payload): void
    {
        if ((int) ($payload['expected_version'] ?? 0) !== (int) $rule->business_version) {
            $this->fail('version_conflict', '工时分配规则版本已变化，请刷新后重试。', 409, ['current_version' => (int) $rule->business_version]);
        }
    }

    private function projection(ProductionLaborAllocationRule $rule): array
    {
        return ['id' => (int) $rule->id, 'rule_no' => $rule->rule_no, 'rule_name' => $rule->rule_name,
            'version_no' => (int) $rule->version_no, 'owner_ratio' => (float) $rule->owner_ratio,
            'collaborator_total_ratio' => (float) $rule->collaborator_total_ratio,
            'collaborator_allocation_method' => $rule->collaborator_allocation_method, 'status' => $rule->status,
            'effective_at' => optional($rule->effective_at)->toISOString(), 'retired_at' => optional($rule->retired_at)->toISOString(),
            'business_version' => (int) $rule->business_version];
    }

    private function command(string $type, int $id, array $payload, object $user, callable $action): array
    {
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') $this->fail('client_command_id_required', '写操作必须提供 client_command_id。');
        $hashPayload = $payload + ['aggregate_id' => $id]; ksort($hashPayload);
        $hash = hash('sha256', json_encode($hashPayload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        try {
            return DB::transaction(function () use ($type, $id, $payload, $user, $action, $commandId, $hash): array {
                $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
                if ($existing) return $this->replay($existing, $type, $hash);
                $ledger = ProductionExecutionCommand::create(['client_command_id' => $commandId, 'command_type' => $type,
                    'aggregate_type' => 'production_labor_rule', 'aggregate_id' => $id ?: null, 'request_hash' => $hash,
                    'status' => 'processing', 'initiated_by_legacy_id' => $this->userId($user), 'processing_started_at' => now()]);
                $result = $action();
                $ledger->update(['result_type' => 'production_labor_rule', 'result_id' => $result['id'] ?? $id,
                    'response_snapshot' => $result, 'status' => 'succeeded', 'processing_finished_at' => now()]);
                return $result;
            }, 5);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            $existing = ProductionExecutionCommand::query()->where('client_command_id', $commandId)->first();
            if ($existing) return $this->replay($existing, $type, $hash);
            $this->fail('labor_rule_version_conflict', '工时分配规则版本发生并发冲突，请刷新后重试。', 409);
        }
    }

    private function replay(ProductionExecutionCommand $command, string $type, string $hash): array
    {
        if ($command->command_type !== $type || $command->request_hash !== $hash) $this->fail('command_conflict', '该 client_command_id 已用于不同请求。', 409);
        if ($command->status !== 'succeeded' || ! is_array($command->response_snapshot)) $this->fail('command_processing', '相同命令正在处理中，请稍后重试。', 409);
        return $command->response_snapshot;
    }
    private function permission(array $permissions, string $code): void { if (! in_array($code, $permissions, true)) $this->fail('permission_denied', '当前用户没有工时分配规则权限。', 403, ['permission' => $code]); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422, array $details = []): never { throw new WorkOrderDomainException($code, $message, $status, $details); }
}
