<?php

namespace App\Services\Erp;

use App\Models\Erp\InventoryBalance;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Unit;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class InventoryAvailabilityService
{
    /**
     * Paged pickers need a compact, read-only availability projection for a
     * small set of Item ids. It intentionally uses the same warehouse,
     * location, batch and safe-available rules as outbound allocation.
     *
     * @return array<int, float>
     */
    public function availableBaseQuantities(array $itemIds): array
    {
        $ids = collect($itemIds)->map(fn ($id) => (int) $id)->filter()->unique()->values();
        if ($ids->isEmpty()) return [];

        return InventoryBalance::query()
            ->select('erp_inventory_balances.*')
            ->join('erp_warehouses', 'erp_warehouses.id', '=', 'erp_inventory_balances.warehouse_id')
            ->join('erp_locations', 'erp_locations.id', '=', 'erp_inventory_balances.location_id')
            ->join('erp_inventory_batches', function ($join): void {
                $join->on('erp_inventory_batches.item_id', '=', 'erp_inventory_balances.item_id')
                    ->on('erp_inventory_batches.batch_no', '=', 'erp_inventory_balances.batch_no');
            })
            ->whereIn('erp_inventory_balances.item_id', $ids)
            ->whereIn('erp_warehouses.status', ['enabled', 'active'])
            ->whereIn('erp_locations.status', ['enabled', 'active'])
            ->whereIn('erp_inventory_batches.status', ['enabled', 'active'])
            ->where(function ($query): void {
                $query->whereNull('erp_inventory_batches.expire_date')
                    ->orWhere('erp_inventory_batches.expire_date', '>=', now()->toDateString());
            })
            ->get()
            ->groupBy('item_id')
            ->map(fn (Collection $balances) => round($balances->sum(
                fn (InventoryBalance $balance) => $this->availableForOutbound($balance)
            ), 8))
            ->all();
    }

    /**
     * Shared outbound boundary for order/work reservations, supplier returns,
     * exchanges and quality handling.
     */
    public function availableForOutbound(InventoryBalance $balance): float
    {
        // Plates and remnants are selected by physical identity, not Item totals.
        // Configured/staged lots also require their specific supply adapter; an
        // Item-only sales/picking query must not widen their eligibility.
        if ($balance->item?->materialManagementMode() === 'physical') return 0.0;
        if ($balance->material_lot_id) {
            $lot = \Illuminate\Support\Facades\DB::table('erp_material_lots')->where('id', $balance->material_lot_id)->first();
            if (! $lot) return 0.0;
            if ($lot->configuration_id || $lot->stage_id || ! in_array($lot->material_form, ['FULL_STOCK','STANDARD_LENGTH'], true)) return 0.0;
        }
        $calculated = $this->calculate(
            (float) $balance->quantity_on_hand,
            (float) $balance->quantity_locked,
            (float) $balance->quantity_defective,
            (float) $balance->quantity_pending,
        );

        return max(0, min((float) $balance->quantity_available, $calculated));
    }

    /** Exact decimal projection for planning; eligibility remains governed by the shared outbound boundary. */
    public function availableForOutboundDecimal(InventoryBalance $balance): string
    {
        if ($this->availableForOutbound($balance) <= 0) return '0.00000000';
        $calculated = bcsub(bcsub(bcsub((string) $balance->quantity_on_hand, (string) $balance->quantity_locked, 8),
            (string) $balance->quantity_defective, 8), (string) $balance->quantity_pending, 8);
        $available = bccomp($calculated, (string) $balance->quantity_available, 8) < 0 ? $calculated : (string) $balance->quantity_available;
        return bccomp($available, '0', 8) > 0 ? bcadd($available, '0', 8) : '0.00000000';
    }

    public function calculate(float $onHand, float $locked, float $defective, float $pending): float
    {
        return max(0, $onHand - $locked - $defective - $pending);
    }

    public function analyzeSalesOrderLine(SalesOrderLine $line, float $salesQty, bool $lock = false, array $plannedByBalance = []): array
    {
        if (!$line->item_id) {
            return $this->emptyAnalysis($line, $salesQty, '当前订单行没有默认库存物料');
        }

        $factor = (float) ($line->fulfillment_factor_snapshot ?: 0);
        if ($factor <= 0) {
            throw ValidationException::withMessages([
                'lines' => "第 {$line->line_no} 行缺少有效单位换算比例。",
            ]);
        }

        $balances = $this->eligibleBalances((int) $line->item_id, $lock);
        $availableBaseQty = round($balances->sum(fn (InventoryBalance $balance) => max(0,
            $this->safeAvailable($balance) - (float) ($plannedByBalance[$balance->id] ?? 0))), 8);
        $precision = (int) (Unit::find($line->unit_id)?->decimal_places ?? 0);
        $availableSalesQty = $this->floorToPrecision($availableBaseQty / $factor, $precision);
        $split = SalesOrderFulfillmentPolicy::split($salesQty, $availableSalesQty, SalesOrderFulfillmentPolicy::isPurchaseLine($line));
        $shortage = $split['suggested_production_qty'] + $split['suggested_purchase_qty'];

        if ($availableBaseQty <= 0.00000001) {
            $reason = '当前无可用成品库存';
        } elseif ($shortage <= 0.00000001) {
            $reason = '当前可用成品库存能够满足本次确认数量';
        } else {
            $reason = $split['suggested_purchase_qty'] > 0
                ? '当前可用库存仅能部分满足，剩余数量等待外购到货'
                : '当前可用成品库存仅能部分满足，剩余数量进入生产';
        }
        if ($availableBaseQty <= 0.00000001 && $split['suggested_purchase_qty'] > 0) $reason = '当前无可用库存，外购物料等待采购到货';

        return [
            'calculated_at' => now()->toDateTimeString(),
            'available_base_qty' => $availableBaseQty,
            'available_sales_qty' => $availableSalesQty,
            ...$split,
            'suggestion_reason' => $reason,
            'fulfillment_factor' => $factor,
            'balances' => $balances,
            'planned_by_balance' => $plannedByBalance,
        ];
    }

    public function allocateBaseQuantity(array $analysis, float $requiredBaseQty): array
    {
        $remaining = max(0, $requiredBaseQty);
        $allocations = [];
        /** @var Collection<int, InventoryBalance> $balances */
        $balances = $analysis['balances'];

        foreach ($balances as $balance) {
            if ($remaining <= 0.00000001) break;
            $available = max(0, $this->safeAvailable($balance) - (float) ($analysis['planned_by_balance'][$balance->id] ?? 0));
            $allocated = min($remaining, $available);
            if ($allocated <= 0.00000001) continue;
            $allocations[] = [
                'inventory_balance_id' => $balance->id,
                'warehouse_id' => $balance->warehouse_id,
                'location_id' => $balance->location_id,
                'batch_no' => $balance->batch_no,
                'base_qty' => round($allocated, 8),
                'available_before_qty' => round($available, 8),
            ];
            $remaining = max(0, $remaining - $allocated);
        }

        if ($remaining > 0.00000001) {
            throw ValidationException::withMessages([
                'lines' => '提交时成品可用库存已发生变化，请重新计算备货方案后再提交。',
            ]);
        }

        return $allocations;
    }

    public function eligibleBalances(int $itemId, bool $lock = false): Collection
    {
        $query = InventoryBalance::query()
            ->select('erp_inventory_balances.*')
            ->join('erp_warehouses', 'erp_warehouses.id', '=', 'erp_inventory_balances.warehouse_id')
            ->join('erp_locations', 'erp_locations.id', '=', 'erp_inventory_balances.location_id')
            ->join('erp_inventory_batches', function ($join) {
                $join->on('erp_inventory_batches.item_id', '=', 'erp_inventory_balances.item_id')
                    ->on('erp_inventory_batches.batch_no', '=', 'erp_inventory_balances.batch_no');
            })
            ->where('erp_inventory_balances.item_id', $itemId)
            ->whereIn('erp_warehouses.status', ['enabled', 'active'])
            ->whereIn('erp_locations.status', ['enabled', 'active'])
            ->whereIn('erp_inventory_batches.status', ['enabled', 'active'])
            ->where(function ($query) {
                $query->whereNull('erp_inventory_batches.expire_date')
                    ->orWhere('erp_inventory_batches.expire_date', '>=', now()->toDateString());
            })
            ->where('erp_inventory_balances.quantity_available', '>', 0)
            ->whereRaw('(erp_inventory_balances.quantity_on_hand - erp_inventory_balances.quantity_locked - erp_inventory_balances.quantity_defective - erp_inventory_balances.quantity_pending) > 0')
            ->orderByRaw('CASE WHEN erp_inventory_batches.expire_date IS NULL THEN 1 ELSE 0 END')
            ->orderBy('erp_inventory_batches.expire_date')
            ->orderBy('erp_inventory_batches.created_at')
            ->orderBy('erp_inventory_balances.id');

        if ($lock) $query->lockForUpdate();
        return $query->get();
    }

    private function safeAvailable(InventoryBalance $balance): float
    {
        return $this->availableForOutbound($balance);
    }

    private function floorToPrecision(float $value, int $precision): float
    {
        $power = 10 ** max(0, $precision);
        return floor(($value + 0.0000000001) * $power) / $power;
    }

    private function emptyAnalysis(SalesOrderLine $line, float $salesQty, string $reason): array
    {
        return [
            'calculated_at' => now()->toDateTimeString(),
            'available_base_qty' => 0.0,
            'available_sales_qty' => 0.0,
            ...SalesOrderFulfillmentPolicy::split($salesQty, 0, SalesOrderFulfillmentPolicy::isPurchaseLine($line)),
            'suggestion_reason' => $reason,
            'fulfillment_factor' => (float) ($line->fulfillment_factor_snapshot ?: 0),
            'balances' => collect(),
        ];
    }
}
