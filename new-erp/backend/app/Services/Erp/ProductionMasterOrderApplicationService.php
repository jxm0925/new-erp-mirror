<?php

namespace App\Services\Erp;

use App\Models\Erp\ProductionMasterOrder;
use App\Models\Erp\SalesOrder;
use Illuminate\Database\QueryException;

/**
 * Owns the one-active-MWO-per-sales-order identity boundary.
 *
 * Sales confirmation and production planning can both encounter an older order
 * without an MWO. They must converge on the same aggregate; allowing either
 * caller to insert directly would make concurrent confirmation/create-draft
 * requests split one sales order across multiple master orders.
 */
final class ProductionMasterOrderApplicationService
{
    public function __construct(private readonly DocumentNumberService $numbers) {}

    public function ensureForSalesOrder(int $salesOrderId, object $operator): ProductionMasterOrder
    {
        $order = SalesOrder::query()->whereKey($salesOrderId)->lockForUpdate()->firstOrFail();
        $existing = ProductionMasterOrder::query()
            ->where('active_sales_order_id', $order->id)
            ->lockForUpdate()
            ->first();
        if ($existing) return $existing;

        $salesperson = $order->sales_user_legacy_id
            ? \Illuminate\Support\Facades\DB::table('erp_legacy_admin_users')
                ->where('legacy_id', $order->sales_user_legacy_id)->first()
            : null;

        try {
            return ProductionMasterOrder::create([
                'master_order_no' => $this->numbers->next('production_master_order', 'MWO'),
                'sales_order_id' => $order->id,
                'active_sales_order_id' => $order->id,
                'sales_order_no_snapshot' => $order->sales_order_no,
                'salesperson_legacy_id' => $order->sales_user_legacy_id,
                'salesperson_name_snapshot' => $salesperson?->nickname ?: $salesperson?->username,
                'customer_snapshot' => [
                    'customer_id' => $order->customer_id,
                    'customer_name' => $order->customer_name_snapshot ?: $order->customer_name,
                    'contact_name' => $order->contact_name_snapshot ?: $order->contact_name,
                    'contact_phone' => $order->contact_phone_snapshot ?: $order->contact_phone,
                ],
                'order_remark_snapshot' => $order->order_remark ?: $order->customer_remark ?: $order->remark,
                'required_delivery_date_snapshot' => $order->required_delivery_date,
                'status' => 'WAIT_CONDITION',
                'production_progress' => 0,
                'completed_unit_qty' => 0,
                'total_unit_qty' => 0,
                'in_progress_unit_qty' => 0,
                'exception_unit_qty' => 0,
                'material_status' => 'WAIT_PREPARE',
                'funding_status' => $order->production_funding_status ?: 'blocked',
                'shipment_status' => $order->shipment_funding_status ?: 'blocked',
                'business_version' => 1,
                'organization_code' => $operator->organization_code ?? null,
                'created_by_legacy_id' => $this->userId($operator),
                'updated_by_legacy_id' => $this->userId($operator),
            ]);
        } catch (QueryException $exception) {
            if ((int) ($exception->errorInfo[1] ?? 0) !== 1062) throw $exception;
            return ProductionMasterOrder::query()
                ->where('active_sales_order_id', $order->id)
                ->lockForUpdate()
                ->firstOrFail();
        }
    }

    private function userId(object $operator): ?int
    {
        $id = (int) ($operator->legacy_id ?? $operator->id ?? 0);
        return $id > 0 ? $id : null;
    }
}
