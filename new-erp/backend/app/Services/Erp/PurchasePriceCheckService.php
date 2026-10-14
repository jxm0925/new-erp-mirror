<?php

namespace App\Services\Erp;

use App\Models\Erp\Item;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchasePriceHistory;

/**
 * 采购价格预警：把本次单价（按库存基本单位折算）与历史采购价比较。
 * 优先比较同一供应商的最近一次价格；该供应商没买过时，参考任一供应商的最近价格。
 */
class PurchasePriceCheckService
{
    public function threshold(): float
    {
        return (float) config('purchase_algorithm.price_alert_percent', 5);
    }

    /** @param array<int, array{item_id:int, base_unit_price:float|string, line_key?:mixed}> $lines */
    public function check(?int $supplierId, array $lines, ?int $excludeOrderId = null): array
    {
        $threshold = $this->threshold();
        $items = Item::query()->whereIn('id', collect($lines)->pluck('item_id')->filter()->unique()->all())->get(['id', 'item_code', 'item_name'])->keyBy('id');
        $rows = [];
        foreach ($lines as $index => $line) {
            $itemId = (int) ($line['item_id'] ?? 0);
            $current = (float) ($line['base_unit_price'] ?? 0);
            if (!$itemId || $current <= 0) continue;
            $history = PurchasePriceHistory::query()->with('supplier:id,supplier_name')->where('item_id', $itemId)->where('base_unit_price', '>', 0)
                ->when($excludeOrderId, fn ($query) => $query->where(fn ($other) => $other->whereNull('order_id')->orWhere('order_id', '<>', $excludeOrderId)))
                ->latest('effective_date')->latest('id');
            $same = $supplierId ? (clone $history)->where('supplier_id', $supplierId)->first() : null;
            $reference = $same ?: $history->first();
            $item = $items->get($itemId);
            $row = [
                'line_key' => $line['line_key'] ?? $index,
                'item_id' => $itemId,
                'item_code' => $item?->item_code,
                'item_name' => $item?->item_name,
                'current_base_price' => round($current, 4),
            ];
            if (!$reference) {
                $rows[] = [...$row, 'level' => 'new', 'diff_percent' => null];
                continue;
            }
            $last = (float) $reference->base_unit_price;
            $diff = round(($current - $last) / $last * 100, 1);
            $order = $reference->order_id ? PurchaseOrder::query()->whereKey($reference->order_id)->value('purchase_order_no') : null;
            $rows[] = [...$row,
                'last_base_price' => round($last, 4),
                'last_date' => $reference->effective_date?->toDateString(),
                'last_supplier_name' => $reference->supplier?->supplier_name,
                'last_order_no' => $order,
                'same_supplier' => (bool) $same,
                'diff_percent' => $diff,
                'level' => $diff >= $threshold ? 'warning' : ($diff > 0.0001 ? 'up' : ($diff < -0.0001 ? 'down' : 'same')),
            ];
        }

        return ['threshold_percent' => $threshold, 'warning_count' => collect($rows)->where('level', 'warning')->count(), 'lines' => $rows];
    }

    public function checkOrder(PurchaseOrder $order): array
    {
        $order->loadMissing('items');
        $lines = $order->items->map(fn ($line) => [
            'line_key' => $line->id,
            'item_id' => $line->item_id,
            'base_unit_price' => (float) $line->base_unit_price > 0 ? $line->base_unit_price
                : ((float) $line->conversion_factor_snapshot > 0 ? (float) $line->unit_price / (float) $line->conversion_factor_snapshot : $line->unit_price),
        ])->all();

        return $this->check((int) $order->supplier_id, $lines, (int) $order->id);
    }
}
