<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{InventoryTransaction, PurchaseReceipt, PurchaseReturn, SalesOrder, SalesReturn, SalesReturnReceipt, SalesShipment};
use Illuminate\Support\Facades\DB;

/** Mobile document projection: one authorized header and a server-paginated set of lines. */
final class WarehouseDocumentService
{
    public function show(string $kind, int $id, array $filters, object $user, array $permissions, bool $super): array
    {
        $permission = match ($kind) {
            'purchase_receipt' => 'inventory.post.view', 'purchase_return' => 'purchase_return.view',
            'sales_return' => 'sales_return.view', 'sales_shipment' => 'sales_order.shipment.view',
            default => throw new WorkOrderDomainException('not_found', '仓库单据类型不存在。', 404),
        };
        if (! in_array($permission, $permissions, true)) throw new WorkOrderDomainException('permission_denied', '无权查看当前仓库单据。', 403);
        $size = min(100, max(1, (int) ($filters['per_page'] ?? 10)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $actions = []; $extra = []; $receipt = null;
        if ($kind === 'purchase_receipt') {
            $document = PurchaseReceipt::with(['supplier', 'order'])->findOrFail($id);
            $lines = $document->items()->with(['item.unit', 'allocations.warehouse', 'allocations.location', 'allocations.physicalEntries']);
            if ($document->stock_post_status === 'pending' && $document->receipt_status === 'confirmed' && $document->confirm_status === 'confirmed') {
                $actions = ['purchase.allocate', 'purchase.post'];
                $extra['posting_eligibility'] = app(PurchaseReceiptPostingEligibilityService::class)->evaluate($document);
            }
            $receipt = $this->transaction('purchase_receipt', $id);
        } elseif ($kind === 'purchase_return') {
            $document = PurchaseReturn::with(['supplier', 'receipt'])->findOrFail($id);
            $lines = $document->items()->with(['item.unit', 'warehouse', 'location', 'baseUnit', 'physicalLinks', 'serialLinks', 'sourceReceiptItem.receipt']);
            if ($document->return_status === 'pending_outbound' && $document->audit_status === 'approved' && $document->stock_post_status === 'pending') $actions = ['purchase_return.post'];
            $receipt = $this->transaction('purchase_return', $id);
        } elseif ($kind === 'sales_shipment' || $kind === 'sales_return') {
            $orders = SalesOrder::query()->select('id'); app(SalesOrderVisibilityService::class)->apply($orders, $user);
            if ($kind === 'sales_shipment') {
                $document = SalesShipment::with(['order'])->whereIn('sales_order_id', $orders)->findOrFail($id);
                $lines = $document->lines()->with(['orderLine.item.unit', 'reservation.balance.warehouse', 'reservation.balance.location']);
                $extra['packages'] = $document->packages()->orderBy('id')->paginate($size, ['*'], 'packages_page', max(1, (int) ($filters['packages_page'] ?? 1)))->toArray();
                if ($document->shipment_status === 'pending_outbound') $actions = ['sales_shipment.post'];
                if ($document->shipment_status === 'outbound_posted') $actions = ['sales_shipment.dispatch'];
                $receipt = $this->transaction('sales_shipment', $id);
            } elseif (($filters['stage'] ?? 'post') === 'receive') {
                $document = SalesReturn::with(['order', 'customer'])->whereIn('sales_order_id', $orders)->findOrFail($id);
                $lines = $document->items()->with(['item.unit', 'baseUnit', 'costAllocations.shipment', 'costAllocations.shipmentLine']);
                if (in_array($document->return_status, ['pending_receipt', 'partial_received'], true)) $actions = ['sales_return.receive'];
            } else {
                $document = SalesReturnReceipt::with(['salesReturn.order', 'salesReturn.customer'])
                    ->whereHas('salesReturn', fn ($q) => $q->whereIn('sales_order_id', $orders))->findOrFail($id);
                $lines = $document->items()->with(['item.unit', 'warehouse', 'location', 'baseUnit', 'salesReturnItem.costAllocations.shipment', 'salesReturnItem.costAllocations.shipmentLine']);
                if ($document->receipt_status === 'confirmed' && $document->stock_post_status === 'pending') $actions = ['sales_return.post'];
                $receipt = $this->transaction('sales_return_receipt', $id);
            }
        }
        $header = $document->toArray(); unset($header['items'], $header['lines'], $header['packages']);
        $linePage = $lines->orderBy('id')->paginate($size, ['*'], 'page', $page);
        $linePage->getCollection()->transform(function ($line) use ($kind, $filters) {
            if ($kind === 'purchase_receipt') $line->setAttribute('allocation_revision', app(PurchaseReceiptPostingRepairApplicationService::class)->allocationRevision($line, $line->allocations));
            if (in_array($kind, ['sales_shipment', 'purchase_return'], true)) {
                $serialIds = $kind === 'sales_shipment' ? ($line->serial_snapshot['inventory_serial_ids'] ?? []) : $line->serialLinks->pluck('inventory_serial_id')->all();
                $line->setAttribute('serial_nos', DB::table('erp_inventory_serials')->whereIn('id', $serialIds)->orderBy('id')->pluck('serial_no'));
            }
            if ($kind === 'sales_return') {
                $costs = ($filters['stage'] ?? 'post') === 'receive' ? $line->costAllocations : $line->salesReturnItem->costAllocations;
                $line->setAttribute('source_shipments', $costs->map(fn ($c) => $c->shipment?->shipment_no)->filter()->unique()->values());
                $line->setAttribute('source_batches', $costs->map(fn ($c) => $c->shipmentLine?->batch_no)->filter()->unique()->values());
            }
            if ($kind === 'sales_return' && ($filters['stage'] ?? 'post') === 'receive') {
                $line->setAttribute('remaining_receivable_qty', bcsub((string) $line->requested_base_qty, (string) $line->received_base_qty, 8));
                $line->setAttribute('serial_tracked', app(SalesReturnIdentityService::class)->requiresIdentities($line));
            }
            if ($kind === 'purchase_return') {
                $line->setAttribute('physicals', DB::table('erp_material_physicals')->whereIn('id', $line->physicalLinks->pluck('physical_material_id'))
                    ->select('id', 'physical_no', 'dimensions')->orderBy('id')->get()->map(function ($p) { $p->dimensions = json_decode($p->dimensions ?: '{}', true); return $p; }));
            }
            return $line;
        });
        $response = ['kind' => $kind, 'header' => $header, 'lines' => $linePage->toArray(),
            'actions' => $this->allowed($actions, $permissions), 'receipt' => $receipt] + $extra;
        if (str_starts_with($kind, 'sales_')) {
            // Sales amount permission authorizes customer prices, never internal costs.
            $response = app(SalesCostVisibilityService::class)->redact($response);
            if (! $super && ! in_array('sales_order.amount.view', $permissions, true)) {
                $response = app(SalesAmountVisibilityService::class)->redact($response);
            }
        }
        return $response;
    }

    private function transaction(string $type, int $id): ?array
    {
        $transaction = InventoryTransaction::where('source_type', $type)->where('source_id', $id)->where('posting_status', 'posted')->latest('id')->first();
        return $transaction ? $transaction->only(['id', 'transaction_no', 'transaction_type', 'posted_at', 'source_no', 'source_id']) : null;
    }

    private function allowed(array $actions, array $permissions): array
    { return array_values(array_filter($actions, fn ($action) => in_array(WarehouseActionService::PERMISSIONS[$action], $permissions, true))); }
}
