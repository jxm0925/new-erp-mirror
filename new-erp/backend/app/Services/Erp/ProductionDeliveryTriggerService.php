<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionPreparationOrderLine;
use Illuminate\Support\Facades\DB;

/**
 * PB says what is needed; this service records when a line becomes eligible for
 * delivery. It deliberately does not create a PD or a DT: picking/dispatching
 * remain separate, auditable actions.
 */
final class ProductionDeliveryTriggerService
{
    public function __construct(private readonly ProductionDataScopeResolver $scopeResolver) {}

    public function configure(int $lineId, array $payload, object $user, array $permissions, bool $superAdmin = false): ProductionPreparationOrderLine
    {
        $this->permission($permissions);
        return DB::transaction(function () use ($lineId, $payload, $user, $permissions, $superAdmin) {
            $line = ProductionPreparationOrderLine::query()->with('workOrder')->lockForUpdate()->find($lineId);
            $this->visible($line, $user, $permissions, $superAdmin); $this->version($line, $payload);
            $lead = (int) ($payload['delivery_lead_minutes'] ?? -1);
            if ($lead < 0) $this->fail('delivery_lead_invalid', '配送提前时间必须为零或正整数。');
            $start = $payload['planned_start_at'] ?? null;
            if (! $start) $this->fail('planned_start_required', '第一工序必须配置计划开工时间。');
            $planned = now()->parse($start);
            $triggerAt = $planned->copy()->subMinutes($lead);
            $status = $triggerAt->lte(now()) ? 'READY_TO_RELEASE' : 'WAIT_SCHEDULE';
            $line->update(['planned_start_at' => $planned, 'delivery_lead_minutes' => $lead, 'delivery_trigger_status' => $status, 'business_version' => (int) $line->business_version + 1]);
            return $line->fresh(['workOrder', 'componentItem']);
        }, 5);
    }

    public function manualRelease(int $lineId, array $payload, object $user, array $permissions, bool $superAdmin = false): ProductionPreparationOrderLine
    {
        $this->permission($permissions);
        return DB::transaction(function () use ($lineId, $payload, $user, $permissions, $superAdmin) {
            $line = ProductionPreparationOrderLine::query()->with('workOrder')->lockForUpdate()->find($lineId);
            $this->visible($line, $user, $permissions, $superAdmin); $this->version($line, $payload);
            if (in_array($line->delivery_trigger_status, ['RELEASED', 'CANCELLED'], true)) $this->fail('delivery_release_not_allowed', '当前备料行不能再次释放配送。');
            $reason = trim((string) ($payload['reason'] ?? '')); if ($reason === '') $this->fail('delivery_release_reason_required', '立即释放配送必须填写原因。');
            $line->update(['delivery_trigger_status' => 'RELEASED', 'delivery_released_at' => now(), 'delivery_release_source' => 'manual_override', 'delivery_release_reason' => $reason, 'delivery_released_by_legacy_id' => $this->userId($user), 'business_version' => (int) $line->business_version + 1]);
            return $line->fresh(['workOrder', 'componentItem']);
        }, 5);
    }

    private function visible(?ProductionPreparationOrderLine $line, object $user, array $permissions, bool $superAdmin): void
    {
        if (! $line || ! $line->workOrder) $this->fail('preparation_line_not_found', '订单备料明细不存在。', 404);
        $scope = $this->scopeResolver->resolve($user, 'production.material_delivery.view', $permissions, $superAdmin);
        if (! $this->scopeResolver->workOrderVisible($line->workOrder, $scope)) $this->fail('data_scope_denied', '订单备料明细不在当前数据范围内。', 403);
    }
    private function version(ProductionPreparationOrderLine $line, array $payload): void { if ((int) ($payload['expected_version'] ?? 0) !== (int) $line->business_version) $this->fail('version_conflict', '订单备料明细已变化，请刷新后重试。', 409); }
    private function permission(array $permissions): void { if (! in_array('production.material_delivery.dispatch', $permissions, true)) $this->fail('permission_denied', '当前用户没有配置配送触发条件的权限。', 403); }
    private function userId(object $user): int { return (int) ($user->legacy_id ?? $user->id ?? 0); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
