<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\ItemCategory;
use App\Models\Erp\Supplier;
use App\Models\Erp\SupplierCategoryCapability;
use App\Models\Erp\SupplierItemRelation;
use App\Models\Erp\SupplierItemRelationLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class SupplierCapabilityService
{
    public const SOURCES = ['manual_confirmed', 'quotation', 'purchase_history', 'category_candidate'];

    public function syncCategories(int $supplierId, array $categoryIds, ?int $operatorId, ?string $remark = null): void
    {
        DB::transaction(function () use ($supplierId, $categoryIds, $operatorId, $remark) {
            Supplier::whereKey($supplierId)->lockForUpdate()->firstOrFail();
            $ids = collect($categoryIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();

            if ($ids->isNotEmpty()) {
                $validIds = ItemCategory::query()
                    ->whereIn('id', $ids->all())
                    ->where('category_type', 'item')
                    ->where('status', 'enabled')
                    ->whereDoesntHave('children', fn ($query) => $query->where('category_type', 'item'))
                    ->pluck('id')->map(fn ($id) => (int) $id);
                abort_unless($validIds->count() === $ids->count(), 422, '供应商可供范围只能选择启用的 Item 叶子类目。');
            }

            SupplierCategoryCapability::where('supplier_id', $supplierId)
                ->where('status', 'active')
                ->whereNotIn('item_category_id', $ids->all())
                ->update([
                    'status' => 'inactive',
                    'expired_at' => now(),
                    'updated_by' => $operatorId,
                    'updated_at' => now(),
                ]);

            foreach ($ids as $categoryId) {
                SupplierCategoryCapability::updateOrCreate(
                    ['supplier_id' => $supplierId, 'item_category_id' => $categoryId],
                    [
                        'status' => 'active',
                        'effective_at' => now(),
                        'expired_at' => null,
                        'remark' => $remark,
                        'created_by' => $operatorId,
                        'updated_by' => $operatorId,
                    ]
                );
            }
        });
    }

    public function updateItemRelation(int $supplierId, int $relationId, array $data, ?int $operatorId): SupplierItemRelation
    {
        abort_if(isset($data['supplier_id']) && (int) $data['supplier_id'] !== $supplierId, 422, '不能改变供货关系的供应商。');
        return $this->saveItemRelation($supplierId, $data, $operatorId, $relationId);
    }

    public function saveItemRelation(int $supplierId, array $data, ?int $operatorId, ?int $relationId = null): SupplierItemRelation
    {
        return $this->persistItemRelation($supplierId, $data, $operatorId, $relationId);
    }

    public function importItemRelation(int $supplierId, array $data, ?int $operatorId): SupplierItemRelation
    {
        $data['capability_source'] = 'import';
        return $this->persistItemRelation($supplierId, $data, $operatorId, null, true);
    }

    private function persistItemRelation(int $supplierId, array $data, ?int $operatorId, ?int $relationId = null, bool $imported = false): SupplierItemRelation
    {
        return DB::transaction(function () use ($supplierId, $data, $operatorId, $relationId, $imported) {
            Supplier::whereKey($supplierId)->lockForUpdate()->firstOrFail();
            if ($relationId !== null) {
                $itemId = SupplierItemRelation::where('supplier_id', $supplierId)->whereKey($relationId)->value('item_id');
                abort_unless($itemId, 404);
                abort_if(isset($data['item_id']) && (int) $data['item_id'] !== (int) $itemId, 422, '不能改变供货关系的物料，请为新物料另建关系。');
                $data['item_id'] = (int) $itemId;
            }
            Item::whereKey($data['item_id'])->lockForUpdate()->firstOrFail();

            $relation = SupplierItemRelation::where('supplier_id', $supplierId)
                ->where('item_id', $data['item_id'])
                ->when($relationId !== null, fn ($query) => $query->whereKey($relationId), fn ($query) => $query->where('relation_status', 'active'))
                ->lockForUpdate()
                ->latest('id')
                ->first();
            abort_if($relationId !== null && !$relation, 404);
            $old = $relation?->toArray();

            if (!$relation) {
                $relation = new SupplierItemRelation([
                    'supplier_id' => $supplierId,
                    'item_id' => $data['item_id'],
                    'created_by' => $operatorId,
                ]);
            }

            $sourceRank = ['category_candidate' => 1, 'purchase_history' => 2, 'quotation' => 3, 'manual_confirmed' => 4];
            $existingSource = $relation->capability_source;
            $incomingSource = $data['capability_source'] ?? $existingSource ?? 'manual_confirmed';
            abort_unless(in_array($incomingSource, ['manual_confirmed', 'quotation', 'purchase_history'], true)
                || ($incomingSource === 'import' && ($imported || $existingSource === 'import')),
                422, '品类候选不能直接保存为正式物料供货关系。');
            $status = $data['relation_status'] ?? ($relation->relation_status ?: 'active');
            if ($relationId !== null && $relation->relation_status !== 'active' && $status === 'active') {
                abort_if(SupplierItemRelation::where('supplier_id', $supplierId)->where('item_id', $relation->item_id)
                    ->where('relation_status', 'active')->whereKeyNot($relation->id)->exists(),
                    422, '该供应商和物料已有启用的供货关系，请编辑现有关系。');
            }
            $resolvedSource = $existingSource
                && ($sourceRank[$existingSource] ?? 0) > ($sourceRank[$incomingSource] ?? 0)
                ? $existingSource
                : $incomingSource;
            $preserveStrongerDates = $existingSource && $resolvedSource === $existingSource && $existingSource !== $incomingSource;

            $relation->fill([
                'capability_source' => $resolvedSource,
                'relation_status' => $status,
                'is_default' => $status === 'active'
                    && (array_key_exists('is_default', $data) ? (bool) $data['is_default'] : (bool) $relation->is_default),
                'effective_at' => $preserveStrongerDates ? $relation->effective_at
                    : (array_key_exists('effective_at', $data) ? $data['effective_at'] : ($relation->effective_at ?? now())),
                'expired_at' => $preserveStrongerDates ? $relation->expired_at
                    : (array_key_exists('expired_at', $data) ? $data['expired_at'] : $relation->expired_at),
                'remark' => array_key_exists('remark', $data) ? $data['remark'] : $relation->remark,
                'updated_by' => $operatorId,
            ]);
            abort_if($relation->effective_at && $relation->expired_at && $relation->expired_at->lessThanOrEqualTo($relation->effective_at),
                422, '供货关系的失效时间必须晚于生效时间。');
            $relation->save();

            if ($relation->is_default && $relation->relation_status === 'active') {
                $this->switchDefaultSupplier($relation, $operatorId, $data['change_reason'] ?? '设置默认供应商');
            } elseif (($old['is_default'] ?? false) || $relation->relation_status !== 'active') {
                $hasOtherDefault = $this->activeRelationQuery()->where('item_id', $relation->item_id)
                    ->where('supplier_id', $relation->supplier_id)->where('is_default', true)->whereKeyNot($relation->id)->exists();
                if (!$hasOtherDefault) {
                    Item::whereKey($relation->item_id)->where('default_supplier_id', $relation->supplier_id)
                        ->update(['default_supplier_id' => null, 'updated_at' => now()]);
                }
            }

            $this->writeLog($relation, $old ? 'update' : 'create', $old, $relation->fresh()->toArray(), $data['change_reason'] ?? null, $operatorId);

            return $relation->fresh(['supplier', 'item.category', 'item.unit']);
        });
    }

    public function disableRelation(SupplierItemRelation $relation, string $reason, ?int $operatorId): SupplierItemRelation
    {
        return DB::transaction(function () use ($relation, $reason, $operatorId) {
            Supplier::whereKey($relation->supplier_id)->lockForUpdate()->firstOrFail();
            Item::whereKey($relation->item_id)->lockForUpdate()->firstOrFail();
            $locked = SupplierItemRelation::where('supplier_id', $relation->supplier_id)->where('item_id', $relation->item_id)
                ->whereKey($relation->id)->lockForUpdate()->firstOrFail();
            $old = $locked->toArray();
            $locked->update([
                'relation_status' => 'inactive',
                'is_default' => false,
                'expired_at' => now(),
                'remark' => $reason,
                'updated_by' => $operatorId,
            ]);
            Item::whereKey($locked->item_id)
                ->where('default_supplier_id', $locked->supplier_id)
                ->update(['default_supplier_id' => null, 'updated_at' => now()]);
            $this->writeLog($locked, 'disable', $old, $locked->fresh()->toArray(), $reason, $operatorId);

            return $locked->fresh(['supplier', 'item.category', 'item.unit']);
        });
    }

    public function supplierEligibleQuery(): Builder
    {
        return Supplier::query()
            ->where('status', 'enabled')
            ->where('approval_status', 'approved')
            ->where('is_blacklisted', false)
            ->where('cooperation_status', 'normal')
            ->where('purchase_restricted', false)
            ->where(function (Builder $query) {
                $query->where('quality_status', '!=', 'frozen')
                    ->orWhere(function (Builder $frozen) {
                        $frozen->where('quality_status', 'frozen')
                            ->whereNotNull('quality_frozen_until')
                            ->where('quality_frozen_until', '<=', now());
                    });
            });
    }

    public function activeRelationQuery(): Builder
    {
        return SupplierItemRelation::query()
            ->where('relation_status', 'active')
            ->where(function (Builder $query) {
                $query->whereNull('effective_at')->orWhere('effective_at', '<=', now());
            })
            ->where(function (Builder $query) {
                $query->whereNull('expired_at')->orWhere('expired_at', '>', now());
            });
    }

    private function switchDefaultSupplier(SupplierItemRelation $relation, ?int $operatorId, string $reason): void
    {
        $oldDefaults = $this->activeRelationQuery()
            ->where('item_id', $relation->item_id)
            ->where('is_default', true)
            ->where('id', '!=', $relation->id)
            ->lockForUpdate()
            ->get();

        foreach ($oldDefaults as $oldDefault) {
            $before = $oldDefault->toArray();
            $oldDefault->update([
                'is_default' => false,
                'relation_status' => 'inactive',
                'expired_at' => now(),
                'updated_by' => $operatorId,
            ]);
            $this->writeLog($oldDefault, 'replace_default', $before, $oldDefault->fresh()->toArray(), $reason, $operatorId);
        }

        Item::whereKey($relation->item_id)->update([
            'default_supplier_id' => $relation->supplier_id,
            'updated_at' => now(),
        ]);
    }

    private function writeLog(
        SupplierItemRelation $relation,
        string $action,
        ?array $old,
        ?array $new,
        ?string $reason,
        ?int $operatorId
    ): void {
        SupplierItemRelationLog::create([
            'relation_id' => $relation->id,
            'supplier_id' => $relation->supplier_id,
            'item_id' => $relation->item_id,
            'action' => $action,
            'old_snapshot' => $old,
            'new_snapshot' => $new,
            'reason' => $reason,
            'operator_id' => $operatorId,
            'created_at' => now(),
        ]);
    }
}
