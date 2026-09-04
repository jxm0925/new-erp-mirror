<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\PurchaseReceiptItemAllocation;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class InventorySerialApplicationService
{
    public function generateForItem(\App\Models\Erp\Item $item, int $quantity): array
    {
        if ($item->serialTrackingMode() === 'none') {
            throw ValidationException::withMessages([
                'item_id' => '当前 Item 未启用单件编号追溯，不能生成序列号。',
            ]);
        }

        $prefix = strtoupper((string) ($item->serial_number_prefix ?: $item->item_code));
        $prefix = trim((string) preg_replace('/[^A-Z0-9_-]+/', '-', $prefix), '-_');
        $prefix = substr($prefix ?: 'SN', 0, 30);
        $date = now()->format('ymd');
        $generated = [];

        while (count($generated) < $quantity) {
            $serialNo = sprintf('%s-%s-%s', $prefix, $date, strtoupper(substr((string) Str::ulid(), -8)));
            if (isset($generated[$serialNo]) || InventorySerial::where('serial_no', $serialNo)->exists()) continue;
            $generated[$serialNo] = $serialNo;
        }

        return array_values($generated);
    }

    public function registerAcceptedReceipt(PurchaseReceipt $receipt): void
    {
        $receipt->loadMissing(['items.item', 'items.allocations']);

        foreach ($receipt->items as $line) {
            if (!$line->item
                || !(bool) ($line->is_stock_item_snapshot ?? $line->item->is_stock_item)
                || $this->qualifiedQuantity($line) <= 0) continue;

            $mode = $line->item->serialTrackingMode();
            $hasSerials = trim((string) $line->serial_text) !== '';
            if ($mode === 'none' || ($mode === 'optional' && !$hasSerials)) continue;

            if ($line->allocations->isEmpty() || !trim((string) $line->batch_no)) {
                throw ValidationException::withMessages([
                    'serial_text' => "单件追溯物料 {$line->item->item_code} 必须在确认到货前确定入库仓库、库位和批次。",
                ]);
            }

            $serialNumbers = $this->validatedSerialNumbers($line);
            $entrySources = collect($line->serial_entries ?: [])->keyBy(fn ($entry) => trim((string) ($entry['serial_no'] ?? '')));
            foreach ($serialNumbers as $serialNo) {
                $allocation = app(PurchaseReceiptAllocationService::class)->serialLocator($line, $serialNo);
                if (!$allocation) {
                    throw ValidationException::withMessages(['allocations' => "设备编号 {$serialNo} 尚未分配入库库位。"]);
                }
                $existing = InventorySerial::query()->where('serial_no', $serialNo)->lockForUpdate()->first();
                if ($existing && (int) $existing->source_receipt_item_id !== (int) $line->id) {
                    throw ValidationException::withMessages([
                        'serial_text' => "设备编号 {$serialNo} 已属于其他实物，不能重复建档。",
                    ]);
                }

                $serial = $existing ?: new InventorySerial(['serial_no' => $serialNo]);
                $numberSource = (string) data_get($entrySources->get($serialNo), 'source', $line->serial_number_source ?: 'supplier');
                $isNew = !$serial->exists;
                $serial->fill([
                    'inventory_balance_id' => null,
                    'item_id' => $line->item_id,
                    'warehouse_id' => $allocation->warehouse_id,
                    'location_id' => $allocation->location_id,
                    'batch_no' => $line->batch_no,
                    'origin_type' => 'purchase',
                    'number_source' => $numberSource,
                    'manufacturer_serial_no' => $numberSource === 'supplier' ? $serialNo : null,
                    'source_document_type' => 'purchase_receipt',
                    'source_document_id' => $receipt->id,
                    'source_document_no' => $receipt->receipt_no,
                    'source_receipt_id' => $receipt->id,
                    'source_receipt_item_id' => $line->id,
                    'supplier_id' => $receipt->supplier_id,
                    'serial_status' => 'pending_posting',
                    'received_at' => $serial->received_at ?: now(),
                    'registered_at' => $serial->registered_at ?: now(),
                ])->save();

                if ($isNew) {
                    $this->event($serial, 'receipt_accepted', 'pending_receipt', 'pending_posting', $receipt, $line, $allocation->warehouse_id, $allocation->location_id);
                }
            }
        }
    }

    public function attachPostedReceiptLine(PurchaseReceipt $receipt, PurchaseReceiptItem $line, InventoryBalance $balance): void
    {
        if (!$line->item || $line->item->serialTrackingMode() === 'none') return;
        if ($line->item->serialTrackingMode() === 'optional' && !trim((string) $line->serial_text)) return;

        $serialNumbers = $this->validatedSerialNumbers($line);
        $serials = InventorySerial::query()
            ->where('source_receipt_item_id', $line->id)
            ->whereIn('serial_no', $serialNumbers)
            ->lockForUpdate()
            ->get();

        if ($serials->count() !== $serialNumbers->count() || $serials->contains(fn (InventorySerial $serial) => $serial->serial_status !== 'pending_posting')) {
            throw ValidationException::withMessages([
                'serial_text' => "物料 {$line->item->item_code} 的设备编号尚未在实物入库环节完整建档，不能库存过账。",
            ]);
        }

        foreach ($serials as $serial) {
            $serial->update([
                'inventory_balance_id' => $balance->id,
                'warehouse_id' => $line->warehouse_id,
                'location_id' => $line->location_id,
                'batch_no' => $line->batch_no,
                'serial_status' => 'available',
                'posted_at' => now(),
            ]);
            $this->event($serial, 'inventory_posted', 'pending_posting', 'available', $receipt, $line);
        }
    }

    public function attachPostedReceiptAllocation(PurchaseReceipt $receipt, PurchaseReceiptItem $line, PurchaseReceiptItemAllocation $allocation, InventoryBalance $balance): void
    {
        if (!$line->item || $line->item->serialTrackingMode() === 'none') return;
        if ($line->item->serialTrackingMode() === 'optional' && !trim((string) $line->serial_text)) return;

        $serialNumbers = collect($allocation->serial_nos ?: [])->map(fn ($value) => trim((string) $value))->filter()->values();
        if ($serialNumbers->count() !== (int) round((float) $allocation->base_qty)) {
            throw ValidationException::withMessages(['allocations' => "物料 {$line->item->item_code} 的库位编号数量与库位分配数量不一致。"]);
        }
        $serials = InventorySerial::query()
            ->where('source_receipt_item_id', $line->id)
            ->whereIn('serial_no', $serialNumbers)
            ->lockForUpdate()->get();
        if ($serials->count() !== $serialNumbers->count() || $serials->contains(fn (InventorySerial $serial) => $serial->serial_status !== 'pending_posting')) {
            throw ValidationException::withMessages(['serial_text' => "物料 {$line->item->item_code} 的库位设备编号未完整建档，不能过账。"]);
        }
        foreach ($serials as $serial) {
            $serial->update([
                'inventory_balance_id' => $balance->id,
                'warehouse_id' => $allocation->warehouse_id,
                'location_id' => $allocation->location_id,
                'batch_no' => $line->batch_no,
                'serial_status' => 'available',
                'posted_at' => now(),
            ]);
            $this->event($serial, 'inventory_posted', 'pending_posting', 'available', $receipt, $line, $allocation->warehouse_id, $allocation->location_id);
        }
    }

    private function validatedSerialNumbers(PurchaseReceiptItem $line): Collection
    {
        $parsed = collect(preg_split('/[\r\n,;，；]+/u', trim((string) $line->serial_text)) ?: [])
            ->map(fn ($value) => trim((string) $value))
            ->filter()
            ->values();
        $unique = $parsed->unique()->values();
        $qualified = $this->qualifiedQuantity($line);

        if (abs($qualified - round($qualified)) > 0.00000001) {
            throw ValidationException::withMessages([
                'serial_text' => "序列号管理物料 {$line->item->item_code} 的合格入库数量必须是整数。",
            ]);
        }
        if ($parsed->count() !== $unique->count()) {
            throw ValidationException::withMessages([
                'serial_text' => "物料 {$line->item->item_code} 的设备编号存在重复，请逐台核对。",
            ]);
        }
        if ($unique->count() !== (int) round($qualified)) {
            throw ValidationException::withMessages([
                'serial_text' => "物料 {$line->item->item_code} 合格入库 {$qualified} 台，必须在实物入库环节录入相同数量的唯一设备编号，当前为 {$unique->count()} 个。",
            ]);
        }

        return $unique;
    }

    private function qualifiedQuantity(PurchaseReceiptItem $line): float
    {
        return round((float) ($line->qualified_base_qty ?: $line->qualified_qty), 8);
    }

    private function event(InventorySerial $serial, string $type, string $from, string $to, PurchaseReceipt $receipt, PurchaseReceiptItem $line, ?int $warehouseId = null, ?int $locationId = null): void
    {
        InventorySerialEvent::create([
            'inventory_serial_id' => $serial->id,
            'event_type' => $type,
            'document_type' => 'purchase_receipt',
            'document_id' => $receipt->id,
            'document_no' => $receipt->receipt_no,
            'from_status' => $from,
            'to_status' => $to,
            'warehouse_id' => $warehouseId ?: $line->warehouse_id,
            'location_id' => $locationId ?: $line->location_id,
            'batch_no' => $line->batch_no,
            'event_payload' => [
                'item_id' => $line->item_id,
                'receipt_item_id' => $line->id,
                'supplier_id' => $receipt->supplier_id,
            ],
            'occurred_at' => now(),
        ]);
    }
}
