<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\MaterialDelivery;
use App\Models\Erp\MaterialDeliveryWave;
use App\Models\Erp\ProductionPreparationOrder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

final class ProductionDeliveryWaveService
{
    public function __construct(private readonly ProductionDataScopeResolver $scopeResolver) {}

    public function create(array $payload, object $user, array $permissions, bool $superAdmin = false): MaterialDeliveryWave
    {
        $this->permission($permissions, 'production.material_delivery.create');
        $commandId = trim((string) ($payload['client_command_id'] ?? ''));
        if ($commandId === '') $this->fail('client_command_required', 'client_command_id 不能为空。');
        $normalized = $payload;
        unset($normalized['client_command_id']);
        $hash = hash('sha256', json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));

        return DB::transaction(function () use ($payload, $user, $permissions, $superAdmin, $commandId, $hash): MaterialDeliveryWave {
            $existing = MaterialDeliveryWave::query()->where('client_command_id', $commandId)->lockForUpdate()->first();
            if ($existing) {
                if ($existing->request_hash !== $hash) $this->fail('idempotency_hash_conflict', '该请求标识已用于不同的配送波次。', 409);
                return $existing->fresh($this->relations());
            }
            $preparation = ProductionPreparationOrder::query()->with('lines')->lockForUpdate()->find((int) ($payload['production_preparation_order_id'] ?? 0));
            if (! $preparation) $this->fail('preparation_order_not_found', '订单备料单不存在。', 404);
            $assignments = collect($payload['tasks'] ?? []);
            if ($assignments->isEmpty()) $this->fail('delivery_tasks_required', '配送波次至少需要一个配送执行任务。');
            $deliveryIds = $assignments->pluck('delivery_id')->map(fn ($id) => (int) $id);
            if ($deliveryIds->contains(fn ($id) => $id <= 0) || $deliveryIds->duplicates()->isNotEmpty()) {
                $this->fail('delivery_tasks_invalid', '配送执行任务不能为空且不能重复。');
            }
            $deliveries = MaterialDelivery::query()->with(['workOrder', 'lines'])->whereIn('id', $deliveryIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            if ($deliveries->count() !== $deliveryIds->count()) $this->fail('delivery_task_not_found', '部分配送执行任务不存在。', 404);
            $validWorkOrderIds = $preparation->lines->pluck('work_order_id')->map(fn ($id) => (int) $id)->unique();
            foreach ($deliveries as $delivery) {
                $this->visible($delivery, $user, $permissions, $superAdmin);
                if ($delivery->status !== 'READY' || $delivery->delivery_wave_id) $this->fail('delivery_task_state_invalid', '只有尚未进入波次的待配送任务可加入配送波次。');
                if (! $validWorkOrderIds->contains((int) $delivery->work_order_id)
                    || (int) $delivery->workOrder->production_master_order_id !== (int) $preparation->production_master_order_id) {
                    $this->fail('delivery_task_scope_invalid', '配送执行任务必须来自当前订单备料单所辖的生产工单。');
                }
                $formalRequirementIds = $preparation->lines->where('work_order_id', $delivery->work_order_id)->pluck('material_requirement_id')->map(fn ($id) => (int) $id);
                if ($delivery->lines->contains(fn ($line) => ! $formalRequirementIds->contains((int) $line->material_requirement_id))) {
                    $this->fail('delivery_line_requirement_invalid', '配送任务行只能引用订单备料单中的冻结正式物料需求。');
                }
            }

            $wave = MaterialDeliveryWave::create([
                'wave_no' => 'TMP-'.bin2hex(random_bytes(12)),
                'production_preparation_order_id' => $preparation->id,
                'production_master_order_id' => $preparation->production_master_order_id,
                'status' => 'WAIT_EXECUTION',
                'client_command_id' => $commandId,
                'request_hash' => $hash,
                'business_version' => 1,
                'organization_code' => $preparation->organization_code,
                'created_by_legacy_id' => $this->userId($user),
                'updated_by_legacy_id' => $this->userId($user),
            ]);
            $wave->wave_no = 'DW'.now()->format('Ymd').str_pad((string) $wave->id, 6, '0', STR_PAD_LEFT);
            $wave->save();

            foreach ($assignments as $assignment) {
                $delivery = $deliveries[(int) $assignment['delivery_id']];
                $mode = (string) ($assignment['assignment_mode'] ?? 'pool_claim');
                if (! in_array($mode, ['pool_claim', 'dispatcher_assign'], true)) $this->fail('assignment_mode_invalid', '配送任务分配方式不合法。');
                $deliveryUserId = $mode === 'dispatcher_assign' ? (int) ($assignment['delivery_user_legacy_id'] ?? 0) : null;
                $pool = $mode === 'pool_claim' ? trim((string) ($assignment['zone_pool_code'] ?? '')) : null;
                if ($mode === 'dispatcher_assign') $this->assertActiveUser($deliveryUserId);
                if ($mode === 'pool_claim' && $pool === '') $this->fail('zone_pool_required', '区域池接单任务必须指定区域池。');
                $delivery->update([
                    'delivery_wave_id' => $wave->id,
                    'assignment_mode' => $mode,
                    'zone_pool_code' => $pool ?: null,
                    'delivery_user_legacy_id' => $deliveryUserId ?: null,
                    'assigned_by_legacy_id' => $deliveryUserId ? $this->userId($user) : null,
                    'assigned_at' => $deliveryUserId ? now() : null,
                    'business_version' => (int) $delivery->business_version + 1,
                ]);
            }
            return $wave->fresh($this->relations());
        }, 5);
    }

    public function poolClaim(int $deliveryId, array $payload, object $user, array $permissions, bool $superAdmin = false): MaterialDelivery
    {
        $this->permission($permissions, 'production.material_delivery.dispatch');
        return DB::transaction(function () use ($deliveryId, $payload, $user, $permissions, $superAdmin): MaterialDelivery {
            $delivery = MaterialDelivery::query()->with(['workOrder', 'deliveryWave', 'lines'])->lockForUpdate()->find($deliveryId);
            if (! $delivery) $this->fail('delivery_task_not_found', '配送执行任务不存在。', 404);
            $this->visible($delivery, $user, $permissions, $superAdmin);
            $this->version($delivery, $payload);
            if ($delivery->status !== 'READY' || $delivery->assignment_mode !== 'pool_claim' || ! $delivery->delivery_wave_id) {
                $this->fail('pool_claim_not_allowed', '当前配送任务不在区域池待接单状态。');
            }
            if ($delivery->delivery_user_legacy_id) $this->fail('delivery_task_already_claimed', '配送任务已被接单。', 409);
            $delivery->update([
                'delivery_user_legacy_id' => $this->userId($user),
                'assigned_by_legacy_id' => $this->userId($user),
                'assigned_at' => now(),
                'claimed_at' => now(),
                'business_version' => (int) $delivery->business_version + 1,
            ]);
            return $delivery->fresh(['deliveryWave', 'workOrder', 'lines']);
        }, 5);
    }

    public function dispatcherAssign(int $deliveryId, array $payload, object $user, array $permissions, bool $superAdmin = false): MaterialDelivery
    {
        $this->permission($permissions, 'production.material_delivery.dispatch');
        return DB::transaction(function () use ($deliveryId, $payload, $user, $permissions, $superAdmin): MaterialDelivery {
            $delivery = MaterialDelivery::query()->with(['workOrder', 'deliveryWave', 'lines'])->lockForUpdate()->find($deliveryId);
            if (! $delivery) $this->fail('delivery_task_not_found', '配送执行任务不存在。', 404);
            $this->visible($delivery, $user, $permissions, $superAdmin);
            $this->version($delivery, $payload);
            if ($delivery->status !== 'READY' || $delivery->assignment_mode !== 'dispatcher_assign' || ! $delivery->delivery_wave_id) {
                $this->fail('dispatcher_assign_not_allowed', '当前配送任务不在调度派单状态。');
            }
            $assignee = (int) ($payload['delivery_user_legacy_id'] ?? 0);
            $this->assertActiveUser($assignee);
            $delivery->update([
                'delivery_user_legacy_id' => $assignee,
                'assigned_by_legacy_id' => $this->userId($user),
                'assigned_at' => now(),
                'claimed_at' => null,
                'business_version' => (int) $delivery->business_version + 1,
            ]);
            return $delivery->fresh(['deliveryWave', 'workOrder', 'lines']);
        }, 5);
    }

    public function paginate(array $filters, object $user, array $permissions, bool $superAdmin = false): LengthAwarePaginator
    {
        $this->permission($permissions, 'production.material_delivery.view');
        $scope = $this->scopeResolver->resolve($user, 'production.material_delivery.view', $permissions, $superAdmin);
        $query = MaterialDeliveryWave::query()->with($this->relations())->orderByDesc('id');
        $query->whereHas('deliveryTasks.workOrder', fn (Builder $workOrders) => $this->scopeResolver->applyWorkOrderScope($workOrders, $scope));
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['production_preparation_order_id'])) $query->where('production_preparation_order_id', (int) $filters['production_preparation_order_id']);
        return $query->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
    }

    private function relations(): array { return ['preparationOrder', 'masterOrder', 'deliveryTasks.workOrder', 'deliveryTasks.lines']; }
    private function visible(MaterialDelivery $delivery, object $user, array $permissions, bool $superAdmin): void
    {
        $scope = $this->scopeResolver->resolve($user, 'production.material_delivery.view', $permissions, $superAdmin);
        if (! $this->scopeResolver->workOrderVisible($delivery->workOrder, $scope)) $this->fail('data_scope_denied', '配送执行任务不在当前数据范围内。', 403);
    }
    private function assertActiveUser(int $userId): void
    {
        if ($userId <= 0 || ! DB::table('erp_legacy_admin_users')->where('legacy_id', $userId)->whereIn('status', ['normal', 'active'])->exists()) {
            $this->fail('delivery_user_invalid', '配送人不存在或已停用。');
        }
    }
    private function version(MaterialDelivery $delivery, array $payload): void
    {
        if ((int) ($payload['expected_version'] ?? 0) !== (int) $delivery->business_version) $this->fail('version_conflict', '配送任务版本已变化，请刷新后重试。', 409);
    }
    private function permission(array $permissions, string $required): void
    {
        if (! in_array($required, $permissions, true)) $this->fail('permission_denied', '当前用户没有执行配送波次操作的权限。', 403);
    }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
