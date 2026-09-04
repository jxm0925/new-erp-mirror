<?php

namespace App\Services\Erp;

use App\Events\InventoryAlertChanged;
use App\Models\Erp\InventoryAlert;
use App\Models\Erp\InventoryAlertHistory;
use App\Models\Erp\InventoryAlertPolicy;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Item;
use App\Models\Erp\PurchaseRequest;
use App\Models\Erp\PurchaseRequestItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** The sole inventory warning authority. It consumes the existing availability service, never a parallel quantity formula. */
class InventoryAlertApplicationService
{
    public function __construct(private readonly InventoryAvailabilityService $availability) {}

    public function savePolicy(int $itemId, array $data, ?int $operatorId, bool $activate = false): InventoryAlertPolicy
    {
        return DB::transaction(function () use ($itemId, $data, $operatorId, $activate) {
            $item = Item::query()->lockForUpdate()->findOrFail($itemId);
            if (!(bool) $item->is_stock_item) {
                throw ValidationException::withMessages(['item_id' => '只有库存管理 Item 可以启用库存预警。']);
            }
            $warehouseId = isset($data['warehouse_id']) && $data['warehouse_id'] !== '' ? (int) $data['warehouse_id'] : null;
            $scopeKey = $warehouseId ? 'warehouse:'.$warehouseId : 'company';
            $this->validateThresholds($data);

            $policy = InventoryAlertPolicy::query()->lockForUpdate()->firstOrNew([
                'item_id' => $item->id, 'scope_key' => $scopeKey,
            ]);
            $disable = array_key_exists('enabled', $data) && !$data['enabled'];
            $policy->fill([
                'warehouse_id' => $warehouseId,
                'status' => $disable ? 'disabled' : ($activate ? 'active' : 'draft'),
                'is_enabled' => $disable ? false : $activate,
                'min_stock' => $data['min_stock'] ?? null,
                'safety_stock' => $data['safety_stock'] ?? null,
                'max_stock' => $data['max_stock'] ?? null,
                'suggested_replenishment_qty' => $data['suggested_replenishment_qty'] ?? null,
                'created_by_legacy_id' => $policy->exists ? $policy->created_by_legacy_id : $operatorId,
                'enabled_by_legacy_id' => $activate ? $operatorId : $policy->enabled_by_legacy_id,
                'effective_at' => $activate ? now() : $policy->effective_at,
            ])->save();

            if ($disable) {
                foreach (InventoryAlert::query()->where('policy_id', $policy->id)->lockForUpdate()->get() as $alert) {
                    $fallback = $this->activePolicyFor($itemId, (int) $alert->warehouse_id);
                    if ($fallback) $this->recalculateForPolicy($fallback, 'policy_scope_disabled', (int) $alert->warehouse_id);
                    else $this->resolveAlert($alert, 'policy_scope_disabled');
                }
            } elseif ($activate) {
                $this->recalculateForPolicy($policy, 'policy_enabled');
            }
            return $policy->fresh(['item.unit', 'warehouse']);
        }, 5);
    }

    public function recalculateForItemWarehouse(int $itemId, int $warehouseId, string $reason = 'inventory_change'): ?InventoryAlert
    {
        $policy = $this->activePolicyFor($itemId, $warehouseId);
        if (!$policy) return null;
        return $this->recalculateForPolicy($policy, $reason, $warehouseId);
    }

    /** Explicit maintenance entry for persisted alerts created before a rule correction. */
    public function recalculateActivePolicies(?int $itemId = null, ?int $warehouseId = null): int
    {
        $processed = 0;
        InventoryAlertPolicy::query()
            ->where('status', 'active')->where('is_enabled', true)
            ->when($itemId, fn ($query) => $query->where('item_id', $itemId))
            ->orderBy('id')
            ->chunkById(100, function ($policies) use (&$processed, $warehouseId) {
                foreach ($policies as $policy) {
                    DB::transaction(function () use ($policy, $warehouseId, &$processed) {
                        $locked = InventoryAlertPolicy::query()->lockForUpdate()->findOrFail($policy->id);
                        $this->recalculateForPolicy($locked, 'manual_recalculate', $warehouseId);
                        $processed++;
                    }, 5);
                }
            });

        return $processed;
    }

    /** Disable only the selected company/warehouse scope; history stays auditable. */
    public function disablePolicy(int $itemId, ?int $warehouseId, ?int $operatorId): ?InventoryAlertPolicy
    {
        return DB::transaction(function () use ($itemId, $warehouseId, $operatorId) {
            $scopeKey = $warehouseId ? 'warehouse:'.$warehouseId : 'company';
            $policy = InventoryAlertPolicy::query()->where('item_id', $itemId)->where('scope_key', $scopeKey)->lockForUpdate()->first();
            if (!$policy) return null;
            $policy->update(['status' => 'disabled', 'is_enabled' => false, 'enabled_by_legacy_id' => $operatorId]);
            foreach (InventoryAlert::query()->where('policy_id', $policy->id)->lockForUpdate()->get() as $alert) {
                $fallback = $this->activePolicyFor($itemId, (int) $alert->warehouse_id);
                if ($fallback) $this->recalculateForPolicy($fallback, 'policy_scope_disabled', (int) $alert->warehouse_id);
                else $this->resolveAlert($alert, 'policy_scope_disabled');
            }
            return $policy->fresh(['item.unit', 'warehouse']);
        }, 5);
    }

    public function recalculateForPolicy(InventoryAlertPolicy $policy, string $reason, ?int $forceWarehouseId = null): ?InventoryAlert
    {
        $warehouseIds = $forceWarehouseId ? [$forceWarehouseId] : ($policy->warehouse_id ? [$policy->warehouse_id] : $this->warehousesForItem((int) $policy->item_id));
        $latest = null;
        foreach ($warehouseIds as $warehouseId) $latest = $this->recalculate($policy, (int) $warehouseId, $reason);
        return $latest;
    }

    public function activePolicyFor(int $itemId, int $warehouseId): ?InventoryAlertPolicy
    {
        return InventoryAlertPolicy::query()->where('item_id', $itemId)->where('status', 'active')->where('is_enabled', true)
            ->whereIn('scope_key', ['warehouse:'.$warehouseId, 'company'])
            ->orderByRaw("CASE WHEN scope_key = ? THEN 0 ELSE 1 END", ['warehouse:'.$warehouseId])
            ->first();
    }

    public function unreadForUser(?int $userId, int $perPage = 20)
    {
        return InventoryAlert::query()->with(['item:id,item_code,item_name,unit_id', 'warehouse:id,warehouse_name'])
            ->where('is_read', false)
            ->orderByDesc('last_changed_at')->paginate(min(max($perPage, 1), 100));
    }

    /**
     * The single alert-state semantic used by recalculation, detail read models
     * and realtime consumers.  Safety stock is inclusive by decision: an
     * available quantity equal to safety stock is still a warning, not normal.
     */
    public function evaluateState(float $available, ?float $minStock, ?float $safetyStock, ?float $maxStock): array
    {
        if ($available <= 0.0000001) {
            return ['status' => 'out_of_stock', 'severity' => 'critical', 'basis' => 'available_lte_zero'];
        }
        if ($maxStock !== null && $available > (float) $maxStock) {
            return ['status' => 'over_stock', 'severity' => 'info', 'basis' => 'available_gt_max'];
        }
        if ($minStock !== null && $available <= (float) $minStock) {
            return ['status' => 'low_stock', 'severity' => 'critical', 'basis' => 'available_lte_min'];
        }
        if ($safetyStock !== null && $available <= (float) $safetyStock) {
            return ['status' => 'low_stock', 'severity' => 'warning', 'basis' => 'available_lte_safety'];
        }

        return ['status' => 'normal', 'severity' => 'normal', 'basis' => 'available_gt_safety'];
    }

    /** Provides the same evaluated result to every read surface without writing during GET. */
    public function presentationFor(InventoryAlert $alert): array
    {
        return $this->evaluateState(
            (float) $alert->available_qty,
            $alert->min_stock_snapshot === null ? null : (float) $alert->min_stock_snapshot,
            $alert->safety_stock_snapshot === null ? null : (float) $alert->safety_stock_snapshot,
            $alert->max_stock_snapshot === null ? null : (float) $alert->max_stock_snapshot,
        );
    }

    /** Reinterprets a persisted history fact with the threshold snapshot that governed it. */
    public function presentationForHistory(InventoryAlertHistory $history, InventoryAlert $alert): array
    {
        $thresholds = $history->threshold_snapshot ?: [
            'min_stock' => $alert->min_stock_snapshot,
            'safety_stock' => $alert->safety_stock_snapshot,
            'max_stock' => $alert->max_stock_snapshot,
        ];
        $facts = $history->quantity_snapshot ?: [];

        return $this->evaluateState(
            (float) ($facts['available'] ?? $alert->available_qty),
            $thresholds['min_stock'] === null ? null : (float) $thresholds['min_stock'],
            $thresholds['safety_stock'] === null ? null : (float) $thresholds['safety_stock'],
            $thresholds['max_stock'] === null ? null : (float) $thresholds['max_stock'],
        );
    }

    public function markRead(int $alertId): InventoryAlert
    {
        return DB::transaction(function () use ($alertId) {
            $alert = InventoryAlert::query()->lockForUpdate()->findOrFail($alertId);
            if (!$alert->is_read) $alert->update(['is_read' => true, 'read_at' => now()]);
            return $alert->fresh();
        }, 5);
    }

    /** Creates only a draft purchase request; source_alert is persisted to prevent repeat-click duplicates. */
    public function createPurchaseRequestFromAlert(int $alertId, ?int $operatorId): PurchaseRequest
    {
        return DB::transaction(function () use ($alertId, $operatorId) {
            $alert = InventoryAlert::query()->with('item')->lockForUpdate()->findOrFail($alertId);
            if (!$alert->is_active) throw ValidationException::withMessages(['alert' => '该预警已解除，不能再生成采购需求。']);
            if ($alert->purchase_request_id) return PurchaseRequest::query()->with(['items.item', 'items.unit', 'items.warehouse'])->findOrFail($alert->purchase_request_id);
            $exists = PurchaseRequest::query()->where('source_type', 'inventory_alert')->where('source_id', (string) $alert->id)
                ->whereNotIn('request_status', ['cancelled', 'closed'])->first();
            if ($exists) { $alert->update(['purchase_request_id' => $exists->id]); return $exists; }
            $item = $alert->item ?: Item::query()->findOrFail($alert->item_id);
            $qty = max(0.000001, (float) ($alert->suggested_replenishment_qty_snapshot ?: 0));
            $request = PurchaseRequest::query()->create([
                'request_no' => 'PRQ'.now()->format('YmdHis').random_int(100, 999), 'request_date' => now()->toDateString(),
                'source_type' => 'inventory_alert', 'source_id' => (string) $alert->id, 'source_no' => 'ALERT-'.$alert->id,
                'request_status' => 'draft', 'status' => 'draft', 'data_source' => 'manual', 'item_id' => $item->id,
                'request_qty' => $qty, 'planned_qty' => 0, 'required_date' => now()->toDateString(),
                'requester' => $operatorId ? '用户#'.$operatorId : null, 'remark' => '由库存预警 #'.$alert->id.' 生成，目标仓库：'.$alert->warehouse_id,
            ]);
            PurchaseRequestItem::query()->create([
                'request_id' => $request->id, 'item_id' => $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name,
                'unit_id' => $item->unit_id, 'request_qty' => $qty, 'converted_qty' => 0, 'remaining_qty' => $qty,
                'expected_date' => now()->toDateString(), 'warehouse_id' => $alert->warehouse_id, 'priority' => $alert->severity === 'critical' ? 'urgent' : 'high',
                'line_status' => 'open', 'data_source' => 'manual', 'remark' => '库存预警来源 #'.$alert->id,
                ...app(MaterialPolicySnapshotService::class)->fromItem($item),
            ]);
            $alert->update(['purchase_request_id' => $request->id]);
            return $request->fresh(['items.item', 'items.unit', 'items.warehouse']);
        }, 5);
    }

    private function recalculate(InventoryAlertPolicy $policy, int $warehouseId, string $reason): InventoryAlert
    {
        $balances = InventoryBalance::query()->where('item_id', $policy->item_id)->where('warehouse_id', $warehouseId)->get();
        $onHand = (float) $balances->sum('quantity_on_hand');
        $locked = (float) $balances->sum('quantity_locked');
        $defective = (float) $balances->sum('quantity_defective');
        $pending = (float) $balances->sum('quantity_pending');
        // Reuse the existing per-balance availability boundary. This protects against stale quantity_available values.
        $available = (float) $balances->sum(fn (InventoryBalance $balance) => $this->availability->availableForOutbound($balance));
        $state = $this->stateFor($available, $policy);
        $alert = InventoryAlert::query()->lockForUpdate()->firstOrNew(['item_id' => $policy->item_id, 'warehouse_id' => $warehouseId]);
        $oldStatus = $alert->exists ? $alert->alert_status : null;
        $oldSeverity = $alert->exists ? $alert->severity : null;
        $changed = !$alert->exists || $oldStatus !== $state['status'] || $oldSeverity !== $state['severity'];
        $wasActive = (bool) ($alert->is_active ?? false);

        $alert->fill([
            'policy_id' => $policy->id, 'alert_status' => $state['status'], 'severity' => $state['severity'],
            'is_active' => $state['status'] !== 'normal', 'quantity_on_hand' => $onHand, 'quantity_locked' => $locked,
            'quantity_reserved' => $locked, 'quantity_defective' => $defective, 'available_qty' => $available,
            'min_stock_snapshot' => $policy->min_stock, 'safety_stock_snapshot' => $policy->safety_stock,
            'max_stock_snapshot' => $policy->max_stock, 'suggested_replenishment_qty_snapshot' => $policy->suggested_replenishment_qty,
            'first_triggered_at' => $state['status'] !== 'normal' ? ($alert->first_triggered_at ?: now()) : $alert->first_triggered_at,
            'last_changed_at' => $changed ? now() : $alert->last_changed_at,
            'resolved_at' => $state['status'] === 'normal' && $wasActive ? now() : ($state['status'] !== 'normal' ? null : $alert->resolved_at),
            'is_read' => $changed ? false : $alert->is_read, 'read_at' => $changed ? null : $alert->read_at,
        ])->save();

        if ($changed) {
            InventoryAlertHistory::query()->create([
                'alert_id' => $alert->id, 'old_status' => $oldStatus, 'new_status' => $state['status'],
                'old_severity' => $oldSeverity, 'new_severity' => $state['severity'], 'change_reason' => $reason,
                'quantity_snapshot' => compact('onHand', 'locked', 'defective', 'pending', 'available'),
                'threshold_snapshot' => $this->thresholdSnapshot($policy), 'occurred_at' => now(),
            ]);
            InventoryAlertChanged::dispatch($alert->fresh(['item', 'warehouse']));
        }
        return $alert;
    }

    private function stateFor(float $available, InventoryAlertPolicy $policy): array
    {
        return $this->evaluateState(
            $available,
            $policy->min_stock === null ? null : (float) $policy->min_stock,
            $policy->safety_stock === null ? null : (float) $policy->safety_stock,
            $policy->max_stock === null ? null : (float) $policy->max_stock,
        );
    }

    private function resolveAlert(InventoryAlert $alert, string $reason): void
    {
        if (!$alert->is_active && $alert->alert_status === 'normal') return;
        $oldStatus = $alert->alert_status;
        $oldSeverity = $alert->severity;
        $alert->update(['alert_status' => 'normal', 'severity' => 'normal', 'is_active' => false, 'last_changed_at' => now(), 'resolved_at' => now(), 'is_read' => false, 'read_at' => null]);
        InventoryAlertHistory::query()->create(['alert_id' => $alert->id, 'old_status' => $oldStatus, 'new_status' => 'normal', 'old_severity' => $oldSeverity, 'new_severity' => 'normal', 'change_reason' => $reason, 'quantity_snapshot' => ['available' => (float) $alert->available_qty], 'threshold_snapshot' => ['min_stock' => $alert->min_stock_snapshot, 'safety_stock' => $alert->safety_stock_snapshot, 'max_stock' => $alert->max_stock_snapshot], 'occurred_at' => now()]);
        InventoryAlertChanged::dispatch($alert->fresh(['item', 'warehouse']));
    }

    private function thresholdSnapshot(InventoryAlertPolicy $policy): array
    {
        return ['min_stock' => $policy->min_stock, 'safety_stock' => $policy->safety_stock, 'max_stock' => $policy->max_stock];
    }

    private function warehousesForItem(int $itemId): array
    {
        // A zero-balance warehouse can have no remaining balance row. Keep its persisted
        // alert scope in the evaluation set so re-enabling a rule cannot leave stale state.
        return InventoryBalance::query()->where('item_id', $itemId)->distinct()->pluck('warehouse_id')
            ->merge(InventoryAlert::query()->where('item_id', $itemId)->distinct()->pluck('warehouse_id'))
            ->filter()->unique()->map(fn ($id) => (int) $id)->values()->all();
    }

    private function validateThresholds(array $data): void
    {
        foreach (['min_stock', 'safety_stock', 'max_stock', 'suggested_replenishment_qty'] as $field) {
            if (isset($data[$field]) && (float) $data[$field] < 0) throw ValidationException::withMessages([$field => '库存阈值不能小于 0。']);
        }
        $min = isset($data['min_stock']) ? (float) $data['min_stock'] : null;
        $safe = isset($data['safety_stock']) ? (float) $data['safety_stock'] : null;
        $max = isset($data['max_stock']) ? (float) $data['max_stock'] : null;
        if ($min !== null && $safe !== null && $min > $safe) throw ValidationException::withMessages(['safety_stock' => '安全库存必须大于或等于最低库存。']);
        if ($safe !== null && $max !== null && $safe > $max) throw ValidationException::withMessages(['max_stock' => '最高库存必须大于或等于安全库存。']);
    }
}
