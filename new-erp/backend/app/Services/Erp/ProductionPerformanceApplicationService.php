<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionPerformanceAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** The accountable operation owner confirms ratios; this use case never calculates or posts money. */
final class ProductionPerformanceApplicationService
{
    public function __construct(private readonly ProductionPerformanceScopeService $scopes, private readonly ProductionPerformanceCalculator $calculator) {}

    public function confirm(string $type, int $id, array $payload, object $user, array $permissions): array
    {
        if (! in_array('production.performance.manage', $permissions, true)) $this->fail('permission_denied', '当前用户没有确认个人绩效份额的权限。', 403);
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0);
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') $this->fail('client_command_id_required', '请提供操作编号。');
        $validated = $this->calculator->validateShares((array) ($payload['shares'] ?? []), (bool) ($payload['noncredited_confirmed'] ?? false), $payload['noncredited_reason'] ?? null);
        $normalized = ['scope_type' => $type, 'scope_id' => $id, 'expected_scope_version' => (int) ($payload['expected_scope_version'] ?? 0),
            'expected_assignment_version' => (int) ($payload['expected_assignment_version'] ?? 0), 'shares' => $validated['shares'],
            'noncredited_confirmed' => (bool) ($payload['noncredited_confirmed'] ?? false), 'noncredited_reason' => trim((string) ($payload['noncredited_reason'] ?? '')) ?: null];
        $hash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($type, $id, $normalized, $validated, $actor, $commandId, $hash): array {
                // Lock the real operation first: ownership and the participant list
                // must be rechecked even on a command replay or concurrent revision.
                $scope = $this->scopes->resolve($type, $id, true);
                if ($actor <= 0 || $scope['owner_legacy_id'] !== $actor) $this->fail('performance_owner_required', '只有该工序的接单人可以确认个人绩效份额。', 403);
                $existing = DB::table('erp_production_performance_commands')->where('client_command_id', $commandId)->lockForUpdate()->first();
                if ($existing) return $this->replay($existing, $actor, $hash);
                if ($scope['status'] !== 'COMPLETED') $this->fail('performance_scope_not_completed', '工序完成后才能确认个人绩效份额。', 409);
                if ($scope['scope_version'] !== $normalized['expected_scope_version']) $this->fail('version_conflict', '工序记录已变化，请刷新后重试。', 409);
                $people = collect($scope['participants'])->keyBy('employee_legacy_id');
                foreach ($validated['shares'] as $share) {
                    $person = $people->get($share['employee_legacy_id']);
                    if (! $person || ! $person['identity_exists']) $this->fail('performance_participant_required', '只能为该工序实际登记的参与人员确认个人绩效份额。');
                }
                $key = $type.':'.$id;
                $current = ProductionPerformanceAssignment::query()->where('active_scope_key', $key)->lockForUpdate()->first();
                $version = $current ? (int) $current->version_no : 0;
                if ($version !== $normalized['expected_assignment_version']) $this->fail('performance_assignment_version_conflict', '个人份额已被更新，请刷新后重试。', 409, ['current_version' => $version]);
                if ($current) $current->update(['active_scope_key' => null, 'status' => 'superseded']);
                $assignment = ProductionPerformanceAssignment::create([
                    'scope_type' => $type, 'scope_id' => $id, 'owner_legacy_id' => $actor, 'version_no' => $version + 1,
                    'active_scope_key' => $key, 'status' => 'confirmed', 'credited_share_ratio' => $validated['credited_share_ratio'],
                    'noncredited_share_ratio' => $validated['noncredited_share_ratio'], 'noncredited_confirmed' => $normalized['noncredited_confirmed'],
                    'noncredited_reason' => $normalized['noncredited_reason'], 'scope_snapshot' => $scope,
                    'confirmed_by_legacy_id' => $actor, 'confirmed_at' => now(),
                ]);
                foreach ($validated['shares'] as $share) $assignment->shares()->create($share + ['employee_name_snapshot' => $people[$share['employee_legacy_id']]['employee_name']]);
                $result = $this->present($assignment->load('shares'));
                DB::table('erp_production_performance_commands')->insert(['client_command_id' => $commandId, 'request_hash' => $hash,
                    'scope_type' => $type, 'scope_id' => $id, 'initiated_by_legacy_id' => $actor, 'assignment_id' => $assignment->id,
                    'response_snapshot' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]);
                return $result;
            }, 5);
        } catch (QueryException $e) {
            if ((int) ($e->errorInfo[1] ?? 0) !== 1062) throw $e;
            // A colliding command may belong to another subject or actor. Never
            // return its data merely because the client chose the same identifier.
            $scope = $this->scopes->resolve($type, $id);
            if ($scope['owner_legacy_id'] !== $actor) $this->fail('performance_owner_required', '只有该工序的接单人可以确认个人绩效份额。', 403);
            $existing = DB::table('erp_production_performance_commands')->where('client_command_id', $commandId)->first();
            if ($existing) return $this->replay($existing, $actor, $hash);
            $this->fail('performance_assignment_version_conflict', '个人份额版本发生并发冲突，请刷新重试。', 409);
        }
    }

    public function present(ProductionPerformanceAssignment $assignment): array
    {
        return $this->canonical(['id' => (int) $assignment->id, 'scope_type' => $assignment->scope_type, 'scope_id' => (int) $assignment->scope_id,
            'owner_legacy_id' => (int) $assignment->owner_legacy_id, 'version_no' => (int) $assignment->version_no, 'status' => $assignment->status,
            'credited_share_ratio' => $assignment->credited_share_ratio, 'noncredited_share_ratio' => $assignment->noncredited_share_ratio,
            'noncredited_confirmed' => (bool) $assignment->noncredited_confirmed, 'noncredited_reason' => $assignment->noncredited_reason,
            'confirmed_at' => $assignment->confirmed_at?->format('Y-m-d H:i:s'), 'confirmed_by_legacy_id' => (int) $assignment->confirmed_by_legacy_id,
            'scope_version' => (int) ($assignment->scope_snapshot['scope_version'] ?? 0),
            'shares' => $assignment->shares->map(fn ($share) => ['employee_legacy_id' => (int) $share->employee_legacy_id,
                'employee_name' => $share->employee_name_snapshot, 'eligible' => (bool) $share->eligible,
                'share_ratio' => $share->share_ratio, 'remark' => $share->remark])->all()]);
    }

    private function replay(object $command, int $actor, string $hash): array
    {
        if ((int) $command->initiated_by_legacy_id !== $actor || $command->request_hash !== $hash) $this->fail('command_conflict', '该操作编号已用于其他操作。', 409);
        $result = json_decode((string) $command->response_snapshot, true);
        if (! is_array($result)) $this->fail('command_processing', '相同操作正在处理中，请稍后重试。', 409);
        return $this->canonical($result);
    }

    private function canonical(array $value): array
    {
        foreach ($value as $key => $item) if (is_array($item)) $value[$key] = $this->canonical($item);
        if (! array_is_list($value)) ksort($value, SORT_STRING);
        return $value;
    }

    private function fail(string $code, string $message, int $status = 422, array $details = []): never
    {
        throw new WorkOrderDomainException($code, $message, $status, $details);
    }
}
