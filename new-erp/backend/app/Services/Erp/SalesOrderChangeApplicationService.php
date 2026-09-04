<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderChange;
use App\Models\Erp\SalesOrderFulfillment;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\SalesOrderLog;
use App\Models\Erp\SalesOrderProductionRequirement;
use App\Models\Erp\SalesOrderVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Applies a controlled change to a confirmed sales order before any shipment or
 * production consumption. It intentionally never rewrites shipped facts:
 * downstream fulfillment is superseded and must be re-confirmed afterwards.
 */
class SalesOrderChangeApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly InventoryReservationService $reservations,
        private readonly SalesOrderAmountService $amounts,
        private readonly SalesOrderFundingGateService $fundingGates,
    ) {
    }

    public function apply(int $orderId, array $payload, string $operator): SalesOrderChange
    {
        return DB::transaction(function () use ($orderId, $payload, $operator): SalesOrderChange {
            $order = SalesOrder::query()->with(['lines', 'shipments', 'salesReturns'])
                ->lockForUpdate()->findOrFail($orderId);
            $this->assertChangeable($order);

            $before = $order->fresh(['lines', 'fulfillments', 'productionRequirements'])->toArray();
            $rows = collect($payload['lines'])->keyBy('sales_order_line_id');
            if ($rows->count() !== count($payload['lines'])) {
                throw ValidationException::withMessages(['lines' => '同一订单行只能提交一次变更。']);
            }

            $changes = [];
            foreach ($rows as $lineId => $row) {
                $line = $order->lines->firstWhere('id', (int) $lineId);
                if (!$line) throw ValidationException::withMessages(['lines' => '变更行不属于当前销售订单。']);
                $new = $this->normalizeLine($line, $row);
                if ($this->isChanged($line, $new)) $changes[] = [$line, $new];
            }
            if (!$changes) throw ValidationException::withMessages(['lines' => '没有检测到可保存的数量或商业金额变更。']);

            // Release only active order reservations. A shipment or consumed
            // production requirement was rejected before this point, so no real
            // inventory/factory fact can be rewritten by a change request.
            $this->reservations->releaseForSalesOrder($order, '订单变更，释放旧履约预留');
            SalesOrderFulfillment::where('sales_order_id', $order->id)
                ->whereIn('demand_status', ['pending', 'confirmed'])
                ->update([
                    'demand_status' => 'superseded',
                    'reservation_status' => 'superseded',
                    'production_requirement_status' => 'superseded',
                ]);
            SalesOrderProductionRequirement::where('sales_order_id', $order->id)
                ->where('is_active', true)
                ->whereIn('requirement_status', ['draft', 'blocked', 'ready'])
                ->update(['requirement_status' => 'superseded', 'is_active' => false]);

            foreach ($changes as [$line, $new]) {
                $line->update($new);
            }
            $this->amounts->refresh($order);
            $order->update([
                'change_status' => 'applied',
                'fulfillment_status' => 'pending',
                'production_confirm_status' => 'pending',
            ]);
            $this->fundingGates->refreshProjection($order->fresh());

            $after = $order->fresh(['lines', 'fulfillments', 'productionRequirements'])->toArray();
            $versionNo = (int) SalesOrderVersion::where('sales_order_id', $order->id)->max('version_no') + 1;
            $change = SalesOrderChange::create([
                'change_no' => $this->numbers->next('sales_order_change', 'SOC'),
                'sales_order_id' => $order->id,
                'version_no' => $versionNo,
                'reason' => trim((string) $payload['reason']),
                'before_snapshot' => $before,
                'after_snapshot' => $after,
                'operator' => $operator,
                'applied_at' => now(),
            ]);
            SalesOrderVersion::create([
                'sales_order_id' => $order->id,
                'version_no' => $versionNo,
                'change_type' => 'confirmed_order_change',
                'before_snapshot' => $before,
                'after_snapshot' => $after,
                'operator' => $operator,
                'remark' => '订单变更单 '.$change->change_no.'：'.$change->reason,
            ]);
            SalesOrderLog::create([
                'sales_order_id' => $order->id,
                'action' => 'confirmed_order_change',
                'before_status' => 'confirmed',
                'after_status' => 'pending_reconfirmation',
                'payload' => ['change_no' => $change->change_no, 'changed_line_ids' => collect($changes)->map(fn ($row) => $row[0]->id)->values()->all()],
                'operator' => $operator,
                'content' => '已应用订单变更 '.$change->change_no.'；旧预留与旧履约计划已作废，必须重新进行订单生产确认。原因：'.$change->reason,
            ]);

            return $change->fresh('order.lines');
        });
    }

    /**
     * UI uses this exact server-side rule result for the order-detail entry and
     * its blocked-state explanation. apply() still performs the same check in
     * its transaction; this preview never grants authority by itself.
     */
    public function eligibility(SalesOrder $order): array
    {
        try {
            $this->assertChangeable($order);
            return ['allowed' => true, 'reason' => null, 'field' => null];
        } catch (ValidationException $exception) {
            $errors = $exception->errors();
            return [
                'allowed' => false,
                'field' => array_key_first($errors),
                'reason' => collect($errors)->flatten()->first() ?: '当前订单不能原地变更。',
            ];
        }
    }

    private function assertChangeable(SalesOrder $order): void
    {
        if ($order->order_status !== 'confirmed' || $order->confirm_status !== 'confirmed') {
            throw ValidationException::withMessages(['order_status' => '只有已正式确认的销售订单可走订单变更；草稿请直接编辑。']);
        }
        if ($order->shipment_status !== 'not_shipped' || $order->lines->sum('shipped_qty') > 0 || $order->shipments->isNotEmpty()) {
            throw ValidationException::withMessages(['shipment_status' => '已有发货事实或发货单，禁止直接订单变更，请走售后/退货流程。']);
        }
        if ($order->salesReturns->whereNotIn('return_status', ['cancelled'])->isNotEmpty()) {
            throw ValidationException::withMessages(['sales_return' => '订单已进入销售退货流程，禁止直接订单变更。']);
        }
        $consumedProduction = SalesOrderProductionRequirement::where('sales_order_id', $order->id)
            ->where(function ($query): void {
                $query->where('consumed_qty', '>', 0)
                    ->orWhereIn('requirement_status', ['partially_consumed', 'consumed', 'closed']);
            })->exists();
        if ($consumedProduction) {
            throw ValidationException::withMessages(['production_requirement' => '生产需求已被工单或完工事实消耗，禁止直接订单变更。']);
        }
    }

    private function normalizeLine(SalesOrderLine $line, array $row): array
    {
        $qty = array_key_exists('order_qty', $row) ? round((float) $row['order_qty'], 4) : (float) $line->order_qty;
        $price = array_key_exists('unit_price', $row) ? round((float) $row['unit_price'], 4) : (float) $line->unit_price;
        $discount = array_key_exists('discount_rate', $row) ? round((float) $row['discount_rate'], 6) : (float) $line->discount_rate;
        $tax = array_key_exists('tax_rate', $row) ? round((float) $row['tax_rate'], 6) : (float) $line->tax_rate;
        if ($tax > 1) $tax = round($tax / 100, 6);
        $mode = $row['price_tax_mode'] ?? $line->price_tax_mode ?? 'tax_inclusive';

        if ($qty <= 0) throw ValidationException::withMessages(['lines.'.$line->id.'.order_qty' => '变更后的订单数量必须大于 0；删除订单行需走独立变更审批。']);
        if ($price <= 0) throw ValidationException::withMessages(['lines.'.$line->id.'.unit_price' => '变更后的销售单价必须大于 0。']);
        if ($discount < 0 || $discount > 1) throw ValidationException::withMessages(['lines.'.$line->id.'.discount_rate' => '折扣率必须在 0 到 1 之间。']);
        if ($tax < 0 || $tax > 1) throw ValidationException::withMessages(['lines.'.$line->id.'.tax_rate' => '税率不合法。']);
        if (!in_array($mode, ['tax_inclusive', 'tax_exclusive'], true)) throw ValidationException::withMessages(['lines.'.$line->id.'.price_tax_mode' => '含税方式不合法。']);

        $commercial = round($qty * $price * $discount, 4);
        $excl = $mode === 'tax_exclusive' ? $commercial : round($commercial / (1 + $tax), 4);
        $taxAmount = round($commercial - $excl, 4);
        $incl = $mode === 'tax_exclusive' ? round($commercial + $taxAmount, 4) : $commercial;
        $commercialSnapshot = (array) $line->commercial_snapshot;
        $commercialSnapshot = array_merge($commercialSnapshot, [
            'unit_price' => $price, 'price_tax_mode' => $mode, 'discount_rate' => $discount,
            'tax_rate' => $tax, 'amount_excl_tax' => $excl, 'tax_amount' => $taxAmount, 'amount_incl_tax' => $incl,
        ]);

        return [
            'order_qty' => $qty,
            'unit_price' => $price,
            'discount_rate' => $discount,
            'tax_rate' => $tax,
            'price_tax_mode' => $mode,
            'amount' => $incl,
            'amount_excl_tax' => $excl,
            'tax_amount' => $taxAmount,
            'amount_incl_tax' => $incl,
            'commercial_snapshot' => $commercialSnapshot,
            'item_base_required_qty' => round($qty * (float) ($line->fulfillment_factor_snapshot ?: 1), 8),
        ];
    }

    private function isChanged(SalesOrderLine $line, array $new): bool
    {
        foreach (['order_qty', 'unit_price', 'discount_rate', 'tax_rate', 'price_tax_mode'] as $key) {
            if ((string) $line->{$key} !== (string) $new[$key]) return true;
        }
        return false;
    }
}
