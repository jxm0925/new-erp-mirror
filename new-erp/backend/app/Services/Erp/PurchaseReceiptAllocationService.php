<?php

namespace App\Services\Erp;

use App\Models\Erp\Location;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\PurchaseReceiptItemAllocation;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class PurchaseReceiptAllocationService
{
    public function __construct(private readonly MaterialPhysicalService $physicalMaterials) {}

    public function replace(PurchaseReceiptItem $line, array $allocations): void
    {
        app(PurchaseReceiptManagementScopeService::class)->assertLine($line);
        foreach ($allocations as $allocation) {
            if ((bool) $line->is_stock_item_snapshot && !empty($allocation['warehouse_id']) && !empty($allocation['location_id'])
                && (float) ($allocation['base_qty'] ?? 0) > 0) {
                $this->assertLocator($line, (int) $allocation['warehouse_id'], (int) $allocation['location_id']);
            }
        }
        $line->allocations()->delete();
        if (!(bool) $line->is_stock_item_snapshot) {
            $line->update(['warehouse_id' => null, 'location_id' => null]);
            return;
        }
        foreach (array_values($allocations) as $index => $allocation) {
            $warehouseId = (int) ($allocation['warehouse_id'] ?? 0);
            $locationId = (int) ($allocation['location_id'] ?? 0);
            $quantity = round((float) ($allocation['base_qty'] ?? 0), 8);
            if (!$warehouseId || !$locationId || $quantity <= 0) continue;
            $this->assertLocator($line, $warehouseId, $locationId);
            $stored = PurchaseReceiptItemAllocation::create([
                'receipt_item_id' => $line->id,
                'warehouse_id' => $warehouseId,
                'location_id' => $locationId,
                'base_qty' => $quantity,
                'serial_nos' => array_values(array_unique(array_filter(array_map('strval', $allocation['serial_nos'] ?? [])))),
                'sort_order' => $index,
            ]);
            $this->physicalMaterials->replaceReceiptAllocationEntries(
                $stored,
                $line->item,
                $allocation['physical_entries'] ?? [],
            );
        }

        $first = $line->allocations()->first();
        if ($first) $line->update(['warehouse_id' => $first->warehouse_id, 'location_id' => $first->location_id]);
    }

    public function ensureForConfirmation(PurchaseReceipt $receipt, array $pendingDraftScopeLineIds = []): void
    {
        app(PurchaseReceiptManagementScopeService::class)->assertReceipt($receipt, $pendingDraftScopeLineIds);
        $receipt->load(['items.item', 'items.allocations.physicalEntries']);
        foreach ($receipt->items as $line) {
            app(PurchaseReceiptManagementScopeService::class)->assertLine($line, $receipt->management_scope, $pendingDraftScopeLineIds);
            if (!(bool) $line->is_stock_item_snapshot) continue;
            $qualified = round((float) $line->qualified_base_qty, 8);
            if ($qualified <= 0) continue;

            if ($line->allocations->isEmpty()) {
                if (!$line->warehouse_id || !$line->location_id) {
                    throw ValidationException::withMessages(['allocations' => "物料 {$line->item?->item_code} 必须完成入库库位分配。"]);
                }
                $this->assertLocator($line, (int) $line->warehouse_id, (int) $line->location_id, $receipt->management_scope);
                PurchaseReceiptItemAllocation::create([
                    'receipt_item_id' => $line->id,
                    'warehouse_id' => $line->warehouse_id,
                    'location_id' => $line->location_id,
                    'base_qty' => $qualified,
                    'serial_nos' => $this->lineSerialNumbers($line)->values()->all(),
                    'sort_order' => 0,
                ]);
                $line->load('allocations.physicalEntries');
            }

            $duplicates = $line->allocations
                ->groupBy(fn ($row) => $row->warehouse_id.'-'.$row->location_id)
                ->filter(fn (Collection $rows) => $rows->count() > 1);
            if ($duplicates->isNotEmpty()) {
                throw ValidationException::withMessages(['allocations' => "物料 {$line->item?->item_code} 的同一库位不能重复分配。"]);
            }
            foreach ($line->allocations as $allocation) $this->assertLocator($line, (int) $allocation->warehouse_id, (int) $allocation->location_id, $receipt->management_scope);

            $allocated = round((float) $line->allocations->sum('base_qty'), 8);
            if (abs($allocated - $qualified) > 0.00000001) {
                throw ValidationException::withMessages([
                    'allocations' => "物料 {$line->item?->item_code} 合格基本数量为 {$qualified}，库位已分配 {$allocated}，两者必须一致。",
                ]);
            }

            if ($line->item?->serialTrackingMode() !== 'none' && trim((string) $line->serial_text) !== '') {
                $expected = $this->lineSerialNumbers($line)->sort()->values();
                $assigned = $line->allocations->flatMap(fn ($row) => $row->serial_nos ?: [])->map(fn ($no) => trim((string) $no))->filter();
                if ($assigned->count() !== $assigned->unique()->count()) {
                    throw ValidationException::withMessages(['allocations' => "物料 {$line->item->item_code} 存在重复分配到库位的设备编号。"]);
                }
                if ($assigned->sort()->values()->all() !== $expected->all()) {
                    throw ValidationException::withMessages(['allocations' => "物料 {$line->item->item_code} 的每个合格设备编号都必须分配且只能分配到一个库位。"]);
                }
                foreach ($line->allocations as $allocation) {
                    if (count($allocation->serial_nos ?: []) !== (int) round((float) $allocation->base_qty)) {
                        throw ValidationException::withMessages(['allocations' => "序列号物料 {$line->item->item_code} 的库位数量必须等于该库位已分配编号数。"]);
                    }
                }
            }
        }
        $this->physicalMaterials->assertReceiptReady($receipt);
    }

    public function serialLocator(PurchaseReceiptItem $line, string $serialNo): ?PurchaseReceiptItemAllocation
    {
        return $line->allocations->first(fn ($allocation) => in_array($serialNo, $allocation->serial_nos ?: [], true));
    }

    private function lineSerialNumbers(PurchaseReceiptItem $line): Collection
    {
        return collect($line->serial_entries ?: [])
            ->pluck('serial_no')->map(fn ($no) => trim((string) $no))->filter()->unique()->values();
    }

    private function assertLocator(PurchaseReceiptItem $line, int $warehouseId, int $locationId, ?string $expectedScope = null): void
    {
        app(WarehouseManagementScopeService::class)->assertWarehouseItem((int) $line->item_id, $warehouseId, 'allocations', $expectedScope ?? $line->management_scope_snapshot);
        if (!Location::query()->whereKey($locationId)->where('warehouse_id', $warehouseId)->whereIn('status', ['active', 'enabled'])->exists()) {
            throw ValidationException::withMessages(['allocations' => '入库分配所选库位不属于该仓库或已停用。']);
        }
    }
}
