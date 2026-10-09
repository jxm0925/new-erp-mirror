<?php

namespace App\Services\Erp;

use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\PurchaseReceipt;
use Illuminate\Support\Collection;

class PurchaseReceiptPostingEligibilityService
{
    public function evaluate(PurchaseReceipt $receipt): array
    {
        $receipt->loadMissing([
            'items.item',
            'items.warehouse',
            'items.location',
            'items.allocations.warehouse',
            'items.allocations.location',
        ]);

        $reasons = collect();
        $this->checkDocumentState($receipt, $reasons);
        $this->checkScope($receipt, $reasons);
        $this->checkLines($receipt, $reasons);

        $reasons = $reasons->unique('code')->values();
        $primary = $reasons->first();

        return [
            'can_post' => $reasons->isEmpty(),
            'reason_code' => $primary['code'] ?? null,
            'reason_text' => $primary['message'] ?? '过账检查通过',
            'reasons' => $reasons->all(),
            'action_label' => $reasons->isEmpty() || str_starts_with($primary['code'] ?? '', 'scope_') ? null : '补充入库分配',
            'action_type' => $reasons->isEmpty() || str_starts_with($primary['code'] ?? '', 'scope_') ? null : 'repair_receipt_allocation',
        ];
    }

    private function checkDocumentState(PurchaseReceipt $receipt, Collection $reasons): void
    {
        if ($receipt->stock_post_status !== 'pending') {
            $this->reason($reasons, 'posting_status_invalid', '该到货单不是待库存过账状态。');
        }
        if ($receipt->receipt_status !== 'confirmed' || $receipt->confirm_status !== 'confirmed') {
            $this->reason($reasons, 'receipt_not_confirmed', '采购到货单尚未完成确认到货。');
        }
        if (InventoryTransaction::query()
            ->where('source_type', 'purchase_receipt')
            ->where('source_id', $receipt->id)
            ->where('transaction_type', 'purchase_receipt_posting')
            ->exists()) {
            $this->reason($reasons, 'posting_transaction_exists', '该到货单已经存在库存过账流水，不能重复过账。');
        }
    }

    private function checkLines(PurchaseReceipt $receipt, Collection $reasons): void
    {
        $qualifiedTotal = 0.0;
        foreach ($receipt->items as $line) {
            if (!(bool) $line->is_stock_item_snapshot) continue;
            $qualified = round((float) ($line->final_stockable_base_qty ?? $line->qualified_base_qty ?? $line->qualified_qty), 8);
            if ($qualified <= 0) continue;
            $qualifiedTotal += $qualified;
            $code = $line->item?->item_code ?: '未知物料';

            if ((float) $line->quality_hold_amount > 0.0001) {
                $this->reason(
                    $reasons,
                    "quality_pending_{$line->id}",
                    "物料 {$code} 仍有未完成的不合格品处理，须先完成退货、换货、返修、让步接收或报废流程。",
                    $line->id,
                );
            }

            if (!trim((string) $line->batch_no)) {
                $this->reason($reasons, "batch_missing_{$line->id}", "物料 {$code} 缺少批次号，请先补齐到货入库信息。", $line->id);
            }

            $allocations = $line->allocations;
            if ($allocations->isEmpty()) {
                if (!$line->warehouse_id || !$line->location_id) {
                    $this->reason($reasons, "allocation_missing_{$line->id}", "物料 {$code} 合格数量 {$this->number($qualified)} 尚未分配仓库和库位。", $line->id);
                    continue;
                }
                $this->checkLocatorScope($receipt, $line, $line->warehouse, $line->location, (int) $line->warehouse_id, $reasons);
            } else {
                $duplicateLocator = $allocations
                    ->groupBy(fn ($row) => $row->warehouse_id.'-'.$row->location_id)
                    ->contains(fn (Collection $rows) => $rows->count() > 1);
                if ($duplicateLocator) {
                    $this->reason($reasons, "allocation_duplicate_{$line->id}", "物料 {$code} 的同一仓库/库位被重复分配。", $line->id);
                }

                foreach ($allocations as $allocation) {
                    $this->checkLocatorScope($receipt, $line, $allocation->warehouse, $allocation->location, (int) $allocation->warehouse_id, $reasons);
                    if (!$allocation->warehouse || !in_array($allocation->warehouse->status, ['active', 'enabled'], true)) {
                        $this->reason($reasons, "warehouse_disabled_{$line->id}", "物料 {$code} 的入库仓库不存在或已停用。", $line->id);
                    }
                    if (!$allocation->location
                        || (int) $allocation->location->warehouse_id !== (int) $allocation->warehouse_id
                        || !in_array($allocation->location->status, ['active', 'enabled'], true)) {
                        $this->reason($reasons, "location_disabled_{$line->id}", "物料 {$code} 的入库库位不属于所选仓库或已停用。", $line->id);
                    }
                }

                $allocated = round((float) $allocations->sum('base_qty'), 8);
                if (abs($allocated - $qualified) > 0.00000001) {
                    $this->reason(
                        $reasons,
                        "allocation_quantity_mismatch_{$line->id}",
                        "物料 {$code} 合格数量为 {$this->number($qualified)}，库位仅分配 {$this->number($allocated)}，两者必须一致。",
                        $line->id
                    );
                }
            }

            $this->checkSerials($line, $qualified, $reasons);
        }

        if ($qualifiedTotal <= 0) {
            $this->reason($reasons, 'qualified_quantity_empty', '没有可进入正常库存的合格数量。');
        }
    }

    private function checkSerials(object $line, float $qualified, Collection $reasons): void
    {
        $mode = $line->item?->serialTrackingMode() ?? 'none';
        $parsed = collect(preg_split('/[\r\n,;，；]+/u', trim((string) $line->serial_text)) ?: [])
            ->map(fn ($value) => trim((string) $value))->filter()->values();
        if ($mode === 'none' || ($mode === 'optional' && $parsed->isEmpty())) return;

        $code = $line->item?->item_code ?: '未知物料';
        if (abs($qualified - round($qualified)) > 0.00000001) {
            $this->reason($reasons, "serial_quantity_not_integer_{$line->id}", "序列号物料 {$code} 的合格数量必须是整数。", $line->id);
            return;
        }
        if ($parsed->count() !== $parsed->unique()->count()) {
            $this->reason($reasons, "serial_duplicate_{$line->id}", "物料 {$code} 存在重复设备编号。", $line->id);
        }
        if ($parsed->unique()->count() !== (int) round($qualified)) {
            $this->reason($reasons, "serial_count_mismatch_{$line->id}", "物料 {$code} 合格数量为 {$this->number($qualified)}，已登记设备编号 {$parsed->unique()->count()} 个。", $line->id);
        }

        if ($line->allocations->isNotEmpty()) {
            $assigned = $line->allocations->flatMap(fn ($row) => $row->serial_nos ?: [])->map(fn ($value) => trim((string) $value))->filter();
            if ($assigned->count() !== $assigned->unique()->count() || $assigned->sort()->values()->all() !== $parsed->unique()->sort()->values()->all()) {
                $this->reason($reasons, "serial_allocation_mismatch_{$line->id}", "物料 {$code} 的每个设备编号都必须且只能分配到一个库位。", $line->id);
            }
        }

        $registered = InventorySerial::query()
            ->where('source_receipt_item_id', $line->id)
            ->whereIn('serial_no', $parsed->unique()->all())
            ->where('serial_status', 'pending_posting')
            ->count();
        if ($registered !== $parsed->unique()->count()) {
            $this->reason($reasons, "serial_not_registered_{$line->id}", "物料 {$code} 的设备编号尚未在到货入库环节完整建档。", $line->id);
        }
    }

    private function checkScope(PurchaseReceipt $receipt, Collection $reasons): void
    {
        if (!in_array($receipt->management_scope, ['factory', 'office'], true)) {
            $this->reason($reasons, 'scope_document_unknown', '历史到货单管理范围未确定或含混合物料，请核对并分开重建后再办理新入库。');
        }
        foreach ($receipt->items as $line) {
            if ($line->management_scope_snapshot !== $receipt->management_scope
                || !in_array($line->management_scope_snapshot, ['factory', 'office'], true)
                || !$line->item || $line->item->management_scope !== $line->management_scope_snapshot) {
                $this->reason($reasons, 'scope_item_changed_'.$line->id, '到货单范围、明细快照或当前物料范围不一致，请核对来源单据。', $line->id);
            }
            if ($line->is_stock_item_snapshot === null || !$line->item
                || (bool) $line->is_stock_item_snapshot !== (bool) $line->item->is_stock_item
                || (is_array($line->material_policy_snapshot)
                    && array_key_exists('is_stock_managed', $line->material_policy_snapshot)
                    && (bool) $line->material_policy_snapshot['is_stock_managed'] !== (bool) $line->is_stock_item_snapshot)) {
                $this->reason($reasons, 'scope_stock_policy_changed_'.$line->id, '库存政策与到货单冻结快照不一致，请核对原政策后重建草稿。', $line->id);
            }
        }
    }

    private function checkLocatorScope(PurchaseReceipt $receipt, object $line, ?object $warehouse, ?object $location, int $warehouseId, Collection $reasons): void
    {
        if (!$warehouse || !in_array($warehouse->status, ['active', 'enabled'], true)) {
            $this->reason($reasons, 'warehouse_disabled_'.$line->id, '入库仓库不存在或已停用。', $line->id);
        }
        if (!$warehouse || !in_array($warehouse->management_scope, ['factory', 'office'], true)
            || $warehouse->management_scope !== $receipt->management_scope
            || $warehouse->management_scope !== $line->management_scope_snapshot) {
            $this->reason($reasons, 'scope_warehouse_mismatch_'.$line->id, '入库仓库与到货单管理范围不一致或仓库范围未确定。', $line->id);
        }
        if (!$location || (int) $location->warehouse_id !== $warehouseId || !in_array($location->status, ['active', 'enabled'], true)) {
            $this->reason($reasons, 'location_disabled_'.$line->id, '入库库位不属于所选仓库或已停用。', $line->id);
        }
    }

    private function reason(Collection $reasons, string $code, string $message, ?int $receiptItemId = null): void
    {
        $reasons->push([
            'code' => $code,
            'message' => $message,
            'receipt_item_id' => $receiptItemId,
        ]);
    }

    private function number(float $value): string
    {
        return rtrim(rtrim(number_format($value, 8, '.', ''), '0'), '.');
    }
}
