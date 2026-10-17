<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionPerformanceAssignment;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/** The accountable operation owner confirms ratios; this use case never calculates or posts money. */
final class ProductionPerformanceApplicationService
{
    public function __construct(private readonly ProductionPerformanceScopeService $scopes, private readonly ProductionPerformanceCalculator $calculator) {}

    public function confirm(string $type, int $id, array $payload, object $user, array $permissions): array
    {
        $canCorrect = in_array('production.performance.manage', $permissions, true);
        $canPlan = in_array($type === 'shipment_packing_operation' ? 'sales_order.shipment.packing.execute' : 'production.task.collaborate', $permissions, true);
        if (! $canCorrect && ! $canPlan) $this->fail('permission_denied', '当前用户没有维护个人绩效份额的权限。', 403);
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0);
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') $this->fail('client_command_id_required', '请提供操作编号。');
        if (str_starts_with($commandId, 'auto-share:')) $this->fail('client_command_id_reserved', '该操作编号前缀仅供正式完工默认份额使用。');
        $validated = $this->calculator->validateShares((array) ($payload['shares'] ?? []), (bool) ($payload['noncredited_confirmed'] ?? false), $payload['noncredited_reason'] ?? null);
        $normalized = ['command_type' => 'confirm_shares', 'share_policy_version' => 2, 'scope_type' => $type, 'scope_id' => $id, 'expected_scope_version' => (int) ($payload['expected_scope_version'] ?? 0),
            'expected_assignment_version' => (int) ($payload['expected_assignment_version'] ?? 0), 'shares' => $validated['shares'],
            'noncredited_confirmed' => false, 'noncredited_reason' => null];
        $hash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($type, $id, $normalized, $validated, $actor, $commandId, $hash, $canCorrect): array {
                // Lock the real operation first: ownership and the participant list
                // must be rechecked even on a command replay or concurrent revision.
                $scope = $this->scopes->resolve($type, $id, true);
                if ($actor <= 0 || $scope['owner_legacy_id'] !== $actor) $this->fail('performance_owner_required', '只有该工序的接单人可以确认个人绩效份额。', 403);
                $existing = DB::table('erp_production_performance_commands')->where('client_command_id', $commandId)->lockForUpdate()->first();
                if ($existing) return $this->replay($existing, $actor, $hash);
                if ($scope['status'] === 'COMPLETED' && ! $canCorrect) $this->fail('permission_denied', '完工后的个人份额修订需要绩效管理权限。', 403);
                if (! in_array($scope['status'], ['READY', 'WAIT_MATERIAL', 'WAIT_HANDOVER', 'IN_PROGRESS', 'PAUSED', 'REWORK', 'WAIT_QUALITY', 'WAIT_WAREHOUSE', 'COMPLETED'], true)) {
                    $this->fail('performance_scope_not_assignable', '只有已接单的有效工序可以维护个人份额。', 409);
                }
                if ($scope['scope_version'] !== $normalized['expected_scope_version']) $this->fail('version_conflict', '工序记录已变化，请刷新后重试。', 409);
                $this->assertParticipants($scope, $validated['shares']);
                $key = $type.':'.$id;
                $current = ProductionPerformanceAssignment::query()->where('active_scope_key', $key)->lockForUpdate()->first();
                $version = $current ? (int) $current->version_no : 0;
                if ($version !== $normalized['expected_assignment_version']) $this->fail('performance_assignment_version_conflict', '个人份额已被更新，请刷新后重试。', 409, ['current_version' => $version]);
                if ($scope['status'] !== 'COMPLETED' && $type === 'shipment_packing_operation') {
                    DB::table('erp_shipment_packing_operations')->where('id', $id)->increment('business_version', 1, ['updated_at' => now()]);
                    $scope['scope_version']++;
                }
                $assignment = $this->appendAssignment($scope, $validated, $actor, 'owner_confirmation',
                    $scope['status'] === 'COMPLETED' ? 'confirmed' : 'planned', $current);
                if ($scope['status'] !== 'COMPLETED' && $scope['task_id']) {
                    DB::table('erp_production_tasks')->where('id', $scope['task_id'])->increment('business_version', 1, ['updated_at' => now()]);
                }
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

    public function updateScopeRate(string $type, int $id, array $payload, object $user, array $permissions, bool $superAdmin = false): array
    {
        if (! $superAdmin && (! in_array('production.performance.manage', $permissions, true)
            || ! in_array('production.performance.view', $permissions, true))) $this->fail('permission_denied', '当前用户没有调整工序绩效比例的权限。', 403);
        if (! Schema::hasTable(ProductionPerformanceScopeService::RATE_TABLE)) $this->fail('performance_rate_schema_missing', '工序比例版本结构尚未同步，暂不能调整比例。', 409);
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0);
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        $reason = trim((string) ($payload['reason'] ?? ''));
        if ($actor <= 0) $this->fail('unauthenticated', '请先登录 ERP。', 401);
        if ($commandId === '' || strlen($commandId) > 120) $this->fail('client_command_id_required', '请提供有效的操作编号。');
        if ($reason === '' || mb_strlen($reason) > 500) $this->fail('performance_rate_reason_required', '调整工序比例必须填写原因，且不超过 500 字。');
        $normalized = ['command_type' => 'update_scope_rate', 'scope_type' => $type, 'scope_id' => $id,
            'expected_scope_version' => (int) ($payload['expected_scope_version'] ?? 0),
            'expected_rate_version' => (int) ($payload['expected_rate_version'] ?? -1),
            'performance_rate' => $this->calculator->ratio($payload['performance_rate'] ?? null), 'reason' => $reason];
        $hash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        try {
            return DB::transaction(function () use ($type, $id, $normalized, $user, $permissions, $superAdmin, $actor, $commandId, $hash): array {
                $scope = $this->scopes->resolve($type, $id, true);
                $this->assertRateVisible($scope, $user, $permissions, $superAdmin);
                $existing = DB::table(ProductionPerformanceScopeService::RATE_TABLE)->where('client_command_id', $commandId)->lockForUpdate()->first();
                if ($existing) return $this->replayRate($existing, $actor, $hash);
                if ($scope['status'] === 'CANCELLED') $this->fail('performance_scope_cancelled', '已取消的工序不能调整绩效比例。', 409);
                if ($scope['scope_version'] !== $normalized['expected_scope_version']) $this->fail('version_conflict', '工序记录已变化，请刷新后重试。', 409);
                $key = $type.':'.$id;
                $current = DB::table(ProductionPerformanceScopeService::RATE_TABLE)->where('active_scope_key', $key)->lockForUpdate()->first();
                $version = $current ? (int) $current->version_no : 0;
                if ($version !== $normalized['expected_rate_version']) $this->fail('performance_rate_version_conflict', '工序比例已被更新，请刷新后重试。', 409, ['current_version' => $version]);
                if ($current) DB::table(ProductionPerformanceScopeService::RATE_TABLE)->where('id', $current->id)->update(['active_scope_key' => null, 'updated_at' => now()]);
                $now = now();
                $result = $this->canonical(['scope_type' => $type, 'scope_id' => $id, 'scope_version' => $scope['scope_version'],
                    'rate_version' => $version + 1, 'performance_rate_snapshot' => $normalized['performance_rate'],
                    'original_performance_rate_snapshot' => $scope['original_performance_rate_snapshot'],
                    'previous_performance_rate_snapshot' => $scope['performance_rate_snapshot'], 'reason' => $normalized['reason'],
                    'changed_by_legacy_id' => $actor, 'changed_at' => $now->format('Y-m-d H:i:s')]);
                // The immutable source and the before/after result live in an appended
                // revision. Updating a released operation or historical assignment
                // would erase the price-rate basis used by earlier statistics.
                DB::table(ProductionPerformanceScopeService::RATE_TABLE)->insert([
                    'scope_type' => $type, 'scope_id' => $id, 'version_no' => $version + 1, 'active_scope_key' => $key,
                    'performance_rate' => $normalized['performance_rate'], 'original_performance_rate' => $scope['original_performance_rate_snapshot'],
                    'source_scope_version' => $scope['scope_version'], 'source_snapshot' => json_encode($scope, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'reason' => $normalized['reason'], 'changed_by_legacy_id' => $actor, 'changed_at' => $now,
                    'client_command_id' => $commandId, 'request_hash' => $hash, 'response_snapshot' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
                    'created_at' => $now, 'updated_at' => $now,
                ]);
                return $result;
            }, 5);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) throw $exception;
            $scope = $this->scopes->resolve($type, $id);
            $this->assertRateVisible($scope, $user, $permissions, $superAdmin);
            $existing = DB::table(ProductionPerformanceScopeService::RATE_TABLE)->where('client_command_id', $commandId)->first();
            if ($existing) return $this->replayRate($existing, $actor, $hash);
            $this->fail('performance_rate_version_conflict', '工序比例发生并发冲突，请刷新后重试。', 409);
        }
    }

    /** The collaboration command already locks and audits the task and its participant selection. */
    public function planTaskShares(int $taskId, ?array $shares, object $user, array $permissions, ?array $scopeShares = null): array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Share plans require the collaboration command transaction.');
        $plans = [];
        $targets = DB::table('erp_production_task_targets')->where('task_id', $taskId)->orderBy('target_type')->orderBy('target_id')->get();
        $perScope = $scopeShares === null ? null : collect($scopeShares)->keyBy(fn ($plan) => $plan['scope_type'].':'.$plan['scope_id']);
        $requiredKeys = [];
        if ($perScope !== null) {
            foreach ($targets as $target) {
                $table = $target->target_type === 'unit_operation' ? 'erp_production_unit_operations' : 'erp_production_quantity_operations';
                $status = DB::table($table)->where('id', $target->target_id)->value('status');
                if ($status && ! in_array($status, ['COMPLETED', 'CANCELLED'], true)) $requiredKeys[] = $target->target_type.':'.$target->target_id;
            }
            app(ProductionPersonalSharePlan::class)->assertScopeCoverage($scopeShares, $requiredKeys);
        }
        foreach ($targets as $target) {
            $specific = $perScope?->get($target->target_type.':'.$target->target_id);
            if ($perScope !== null && ! $specific) continue;
            $plan = $this->planScopeShares($target->target_type, (int) $target->target_id, $specific['shares'] ?? $shares, $user, $permissions, $specific);
            if ($plan) $plans[] = $plan;
        }
        return $plans;
    }

    /** Shipment participant selection can freeze its share plan in the same formal command. */
    public function planScopeShares(string $type, int $id, ?array $shares, object $user, array $permissions = [], ?array $expected = null): ?array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Share plans require the participant-selection transaction.');
        $planningPermission = $type === 'shipment_packing_operation' ? 'sales_order.shipment.packing.execute' : 'production.task.collaborate';
        if ($shares !== null && ! in_array($planningPermission, $permissions, true)
            && ! in_array('production.performance.manage', $permissions, true)) $this->fail('permission_denied', '当前用户没有维护本工序协同份额的权限。', 403);
        $scope = $this->scopes->resolve($type, $id, true);
        $actor = (int) ($user->legacy_id ?? $user->id ?? 0);
        if ($actor <= 0 || $scope['owner_legacy_id'] !== $actor) $this->fail('performance_owner_required', '只有工序负责人可以安排个人份额。', 403);
        if (in_array($scope['status'], ['COMPLETED', 'CANCELLED'], true)) return null;
        $validated = $this->calculator->validateShares($shares ?? $this->calculator->defaultShares($scope['participants']));
        $this->assertParticipants($scope, $validated['shares']);
        $current = ProductionPerformanceAssignment::query()->where('active_scope_key', $type.':'.$id)->lockForUpdate()->first();
        if ($expected && ($scope['scope_version'] !== $expected['expected_scope_version']
            || (int) ($current?->version_no ?? 0) !== $expected['expected_assignment_version'])) {
            $this->fail('performance_scope_version_conflict', '工序或个人份额已变化，请刷新对应工序后重新确认。', 409);
        }
        if ($current && $current->status === 'confirmed') return null;
        $assignment = $this->appendAssignment($scope, $validated, $actor, $shares === null ? 'collaboration_equal_default' : 'collaboration_selection', 'planned', $current);
        return $this->present($assignment->load('shares'));
    }

    /** Called only by a genuine completion transaction; a read never creates or rewrites shares. */
    public function ensureDefaultShares(string $type, int $id): ?array
    {
        if (DB::transactionLevel() < 1) throw new \LogicException('Default shares require the execution completion transaction.');
        $scope = $this->scopes->resolve($type, $id, true);
        if ($scope['status'] !== 'COMPLETED' || $scope['owner_legacy_id'] <= 0 || $scope['participants'] === []) return null;
        if (collect($scope['participants'])->contains(fn ($person) => ! $person['identity_exists'])) return null;
        $current = ProductionPerformanceAssignment::query()->with('shares')->where('active_scope_key', $type.':'.$id)->lockForUpdate()->first();
        if ($current && $current->status === 'confirmed') return $this->present($current);
        $shares = $this->calculator->defaultShares($scope['participants']);
        $origin = 'automatic_completion_default';
        if ($current && $current->status === 'planned') {
            $planned = $current->shares->map(fn ($row) => ['employee_legacy_id' => (int) $row->employee_legacy_id,
                'eligible' => (bool) $row->eligible, 'share_ratio' => $row->share_ratio, 'remark' => $row->remark])->all();
            $plannedIds = array_column($planned, 'employee_legacy_id'); $actualIds = array_column($scope['participants'], 'employee_legacy_id');
            sort($plannedIds); sort($actualIds);
            // A new/removed actual contributor must not silently receive a negotiated
            // person's share. Keep this plan visible for an explicit owner revision.
            if ($plannedIds !== $actualIds) {
                if (count($actualIds) !== 1 && ($current->scope_snapshot['share_origin'] ?? '') !== 'collaboration_equal_default') return null;
                $origin = count($actualIds) === 1 ? 'automatic_completion_single_owner' : 'automatic_completion_equal_default';
            } else {
                $shares = $planned; $origin = 'completion_of_owner_plan';
            }
        }
        $validated = $this->calculator->validateShares($shares);
        $this->assertParticipants($scope, $validated['shares']);
        $assignment = $this->appendAssignment($scope, $validated, $scope['owner_legacy_id'], $origin, 'confirmed', $current);
        $result = $this->present($assignment->load('shares'));
        DB::table('erp_production_performance_commands')->insert([
            'client_command_id' => 'auto-share:'.$type.':'.$id.':'.$scope['scope_version'],
            'request_hash' => hash('sha256', json_encode(['action' => $origin, 'scope' => $scope, 'shares' => $validated['shares']], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)),
            'scope_type' => $type, 'scope_id' => $id, 'initiated_by_legacy_id' => $scope['owner_legacy_id'], 'assignment_id' => $assignment->id,
            'response_snapshot' => json_encode($result, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now(),
        ]);
        return $result;
    }

    private function appendAssignment(array $scope, array $validated, int $actor, string $origin, string $status, ?ProductionPerformanceAssignment $current): ProductionPerformanceAssignment
    {
        $version = $current ? (int) $current->version_no : 0;
        if ($current) $current->update(['active_scope_key' => null, 'status' => 'superseded']);
        $assignment = ProductionPerformanceAssignment::create([
            'scope_type' => $scope['scope_type'], 'scope_id' => $scope['scope_id'], 'owner_legacy_id' => $scope['owner_legacy_id'], 'version_no' => $version + 1,
            'active_scope_key' => $scope['scope_type'].':'.$scope['scope_id'], 'status' => $status,
            'credited_share_ratio' => $validated['credited_share_ratio'], 'noncredited_share_ratio' => $validated['noncredited_share_ratio'],
            'noncredited_confirmed' => false, 'noncredited_reason' => null,
            'scope_snapshot' => $scope + ['share_policy_version' => 2, 'share_origin' => $origin],
            'confirmed_by_legacy_id' => $actor, 'confirmed_at' => now(),
        ]);
        $people = collect($scope['participants'])->keyBy('employee_legacy_id');
        foreach ($validated['shares'] as $share) $assignment->shares()->create($share + ['employee_name_snapshot' => $people[$share['employee_legacy_id']]['employee_name']]);
        return $assignment;
    }

    private function assertParticipants(array $scope, array $shares): void
    {
        $people = collect($scope['participants'])->keyBy('employee_legacy_id');
        $actualIds = $people->keys()->map(fn ($id) => (int) $id)->sort()->values()->all();
        $shareIds = array_column($shares, 'employee_legacy_id'); sort($shareIds);
        if ($actualIds !== $shareIds) $this->fail('performance_participant_required', '个人份额必须包含本工序负责人及全部真实登记的参与人员。');
        foreach ($shares as $share) if (! $people[$share['employee_legacy_id']]['identity_exists']) $this->fail('performance_participant_required', '参与人员账号不存在，请先核对真实人员身份。');
    }

    private function assertRateVisible(array $scope, object $user, array $permissions, bool $superAdmin): void
    {
        if (! $this->scopes->canManageRate($scope, $user, $permissions, $superAdmin)) $this->fail('data_scope_denied', '该工序不在当前绩效管理数据范围内。', 403);
    }

    private function replayRate(object $revision, int $actor, string $hash): array
    {
        if ((int) $revision->changed_by_legacy_id !== $actor || $revision->request_hash !== $hash) $this->fail('command_conflict', '该操作编号已用于其他操作。', 409);
        $result = json_decode((string) $revision->response_snapshot, true);
        if (! is_array($result)) $this->fail('command_processing', '相同操作正在处理中，请稍后重试。', 409);
        return $this->canonical($result);
    }

    public function present(ProductionPerformanceAssignment $assignment): array
    {
        return $this->canonical(['id' => (int) $assignment->id, 'scope_type' => $assignment->scope_type, 'scope_id' => (int) $assignment->scope_id,
            'owner_legacy_id' => (int) $assignment->owner_legacy_id, 'version_no' => (int) $assignment->version_no, 'status' => $assignment->status,
            'credited_share_ratio' => $assignment->credited_share_ratio, 'noncredited_share_ratio' => $assignment->noncredited_share_ratio,
            'noncredited_confirmed' => (bool) $assignment->noncredited_confirmed, 'noncredited_reason' => $assignment->noncredited_reason,
            'confirmed_at' => $assignment->confirmed_at?->format('Y-m-d H:i:s'), 'confirmed_by_legacy_id' => (int) $assignment->confirmed_by_legacy_id,
            'scope_version' => (int) ($assignment->scope_snapshot['scope_version'] ?? 0),
            'share_policy_version' => (int) ($assignment->scope_snapshot['share_policy_version'] ?? 1),
            'share_origin' => $assignment->scope_snapshot['share_origin'] ?? 'historical_owner_confirmation',
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
