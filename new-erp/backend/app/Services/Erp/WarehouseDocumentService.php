<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{InventoryTransaction, PurchaseReceipt, PurchaseReturn, SalesOrder, SalesReturn, SalesReturnReceipt, SalesShipment};
use App\Models\Erp\{InventoryStocktake, Item, MfgMaterialDelivery, MfgMaterialReturn, MfgWorkOrderOperation};
use App\Services\Erp\Manufacturing\{MaterialDeliveryService, MaterialReturnService};
use App\Services\Erp\Inventory\{InventoryStocktakeService, InventoryStocktakeEntryService};
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** Mobile document projection: one authorized header and a server-paginated set of lines. */
final class WarehouseDocumentService
{
    public function show(string $kind, int $id, array $filters, object $user, array $permissions, bool $super): array
    {
        if (in_array($kind, ['material_preparation', 'material_delivery', 'material_return', 'stocktake'], true))
            return $this->executionDocument($kind, $id, $filters, $user, $permissions);
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
            $orders = SalesOrder::query()->select('id'); app(SalesOrderVisibilityService::class)->apply($orders, $user, true);
            if ($kind === 'sales_shipment') {
                $document = SalesShipment::with(['order'])->whereIn('sales_order_id', $orders)->findOrFail($id);
                $lines = $document->lines()->with(['orderLine.item.unit', 'reservation.balance.warehouse', 'reservation.balance.location']);
                $extra['packages'] = $document->packages()->orderBy('id')->paginate($size, ['*'], 'packages_page', max(1, (int) ($filters['packages_page'] ?? 1)))->toArray();
                if ($document->shipment_status === 'pending_outbound') $actions = ['sales_shipment.post'];
                if (in_array($document->shipment_status, ['prepared', 'partially_shipped', 'outbound_posted'], true)) $actions = ['sales_shipment.dispatch'];
                // 一个订单一张发货单：后来追加的明细还没移至待发区，先移位（已就绪的包裹照样能发）
                if (in_array($document->shipment_status, ['prepared', 'partially_shipped'], true) && $document->lines()->where('line_status', 'pending_outbound')->exists())
                    $actions = ['sales_shipment.post', 'sales_shipment.dispatch'];
                $extra['dispatches'] = $document->dispatches()->orderBy('id')->get(['id', 'dispatch_no', 'delivery_mode', 'carrier_name', 'tracking_no', 'package_ids', 'dispatched_at', 'operator_name'])
                    ->map(fn ($row) => array_merge($row->toArray(), ['dispatched_at' => $row->dispatched_at?->toDateTimeString()]))->all();
                $extra['dispatchable_packages'] = app(ShipmentPackingApplicationService::class)->dispatchablePackages($document);
                $extra['serial_results'] = app(ShipmentSerialApplicationService::class)->projection($document);
                $receipt = $this->transaction('sales_shipment', $id);
                if (! $receipt) {
                    $transactionId = $document->dispatches()->whereNotNull('inventory_transaction_id')->orderByDesc('id')->value('inventory_transaction_id');
                    $posting = $transactionId ? InventoryTransaction::find($transactionId) : null;
                    $receipt = $posting?->only(['id', 'transaction_no', 'transaction_type', 'posted_at', 'source_no', 'source_id']);
                }
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
        if ($actions && str_starts_with($kind, 'sales_')) {
            $orderId = $kind === 'sales_return' && ($filters['stage'] ?? 'post') !== 'receive'
                ? $document->salesReturn->sales_order_id : $document->sales_order_id;
            $writable = SalesOrder::query()->whereKey($orderId);
            app(SalesOrderVisibilityService::class)->apply($writable, $user);
            if (! $writable->exists()) $actions = [];
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
            $inventoryAmounts = in_array('inventory.amount.view', $permissions, true);
            if (! $inventoryAmounts) $response = app(InventoryAmountVisibilityService::class)->redact($response, false, false, false, false, true);
            if (! $super && ! in_array('sales_order.amount.view', $permissions, true)) {
                $response = app(SalesAmountVisibilityService::class)->redact($response, $inventoryAmounts);
            }
        }
        return $response;
    }

    private function executionDocument(string $kind, int $id, array $filters, object $user, array $permissions): array
    {
        $required = match ($kind) {
            'material_preparation' => ['production.material_delivery.manage'],
            'material_delivery' => ['production.material_delivery.manage', 'production.work_order.view'],
            'material_return' => ['production.material_return.request', 'production.material_return.approve'],
            'stocktake' => ['inventory.stocktake.view'],
        };
        if (! array_intersect($required, $permissions)) throw new WorkOrderDomainException('permission_denied', '无权查看当前仓库单据。', 403);
        $size = min(100, max(1, (int) ($filters['per_page'] ?? 10))); $page = max(1, (int) ($filters['page'] ?? 1));
        $actions = []; $extra = [];
        if ($kind === 'stocktake') {
            $stocktake = InventoryStocktake::query()->with(['warehouse:id,warehouse_code,warehouse_name', 'location:id,location_code,location_name'])->findOrFail($id);
            $header = $stocktake->toArray() + ['document_type' => 'inventory_stocktake', 'status_label' => InventoryStocktakeService::STATUSES[$stocktake->stocktake_status] ?? $stocktake->stocktake_status];
            $summary = (array) DB::table('erp_inventory_stocktake_lines')->where('stocktake_id', $id)->selectRaw('COUNT(*) AS total_lines, SUM(counted_qty IS NOT NULL) AS counted_lines,
                SUM(counted_qty IS NULL) AS uncounted_lines, SUM(difference_qty IS NOT NULL AND difference_qty <> 0) AS diff_lines,
                COALESCE(SUM(difference_amount),0) AS difference_amount')->first();
            $header['summary'] = array_map(fn ($value) => $value === null ? 0 : (float) $value, $summary);
            $keyword = trim((string) ($filters['keyword'] ?? ''));
            $lines = $stocktake->lines()->with(['item:id,item_code,item_name,spec', 'location:id,location_code,location_name', 'unit:id,unit_name'])
                ->when($keyword !== '', fn ($q) => $q->where(fn ($w) => $w->where('batch_no', 'like', '%'.$keyword.'%')
                    ->orWhereHas('item', fn ($item) => $item->where('item_code', 'like', '%'.$keyword.'%')->orWhere('item_name', 'like', '%'.$keyword.'%')->orWhere('spec', 'like', '%'.$keyword.'%'))))
                ->when($filters['location_id'] ?? null, fn ($q, $locationId) => $q->where('location_id', $locationId))
                ->when(($filters['line_status'] ?? null) === 'uncounted', fn ($q) => $q->whereNull('counted_qty'))
                ->when(($filters['line_status'] ?? null) === 'diff', fn ($q) => $q->where('difference_qty', '<>', 0))
                ->orderBy('location_id')->orderBy('item_id')->orderBy('id')->paginate($size, ['*'], 'page', $page);
            $entries = app(InventoryStocktakeEntryService::class);
            $stats = $entries->stats($lines->getCollection()->where('count_mode', '!=', 'quantity')->pluck('id')->all());
            $lines->getCollection()->each(fn ($line) => $line->setAttribute('entry_stats', $line->count_mode === 'quantity' ? null : ($stats[$line->id] ?? $entries->emptyStats())));
            if ($stocktake->stocktake_status === 'counting') $actions = ['stocktake.counts', 'stocktake.complete'];
        } else {
            if ($kind === 'material_preparation') {
                $operation = MfgWorkOrderOperation::query()->with('workOrder:id,item_name,product_name')->findOrFail($id);
                $preview = app(MaterialDeliveryService::class)->preview((int) $operation->work_order_id, $id, isset($filters['units']) ? (int) $filters['units'] : null);
                $header = ['id' => $id, 'work_order_id' => (int) $operation->work_order_id, 'operation_id' => $id,
                    'work_order_no' => $preview['work_order_no'], 'operation_name' => $preview['operation_name'],
                    'item_name' => $operation->workOrder?->item_name, 'product_name' => $operation->workOrder?->product_name,
                    'status' => 'pending', 'status_label' => '待配料',
                    'source_revision' => MaterialDeliveryService::sourceRevision($preview['lines'])];
                $rows = $preview['lines']; $extra['preview'] = $preview;
                $actions = ['material_delivery.prepare'];
            } elseif ($kind === 'material_delivery') {
                $delivery = MfgMaterialDelivery::query()->with(['workOrder', 'operation'])->findOrFail($id);
                $header = app(MaterialDeliveryService::class)->row($delivery); $rows = $header['lines'];
                $header['source_revision'] = MaterialDeliveryService::sourceRevision($rows);
                unset($header['lines']);
                $header['item_name'] = $delivery->workOrder?->item_name;
                $header['product_name'] = $delivery->workOrder?->product_name;
                $actions = match ($delivery->status) {
                    'prepared' => ['material_delivery.dispatch', 'material_delivery.repick', 'material_delivery.cancel'],
                    'rejected' => ['material_delivery.redeliver', 'material_delivery.recall'],
                    'dispatched' => ['material_delivery.recall'], default => [],
                };
            } else {
                $query = MfgMaterialReturn::query();
                if (! in_array('production.material_return.approve', $permissions, true)) $query->where('requested_by_legacy_id', (int) ($user->legacy_id ?? $user->id ?? 0));
                $return = $query->findOrFail($id);
                $header = app(MaterialReturnService::class)->detail($id, in_array('inventory.amount.view', $permissions, true));
                $rows = $header['lines']; unset($header['lines']);
                $items = Item::query()->whereIn('id', array_column($rows, 'component_item_id'))->get(['id', 'item_code', 'spec'])->keyBy('id');
                $rows = array_map(fn ($row) => $row + ['component_item_code' => $items->get($row['component_item_id'])?->item_code,
                    'component_item_spec' => $items->get($row['component_item_id'])?->spec, 'spec' => $items->get($row['component_item_id'])?->spec], $rows);
                if ($return->status === 'pending') {
                    $actions = ['material_return.cancel'];
                    if (! $return->disposition_plan) $actions = ['material_return.approve', 'material_return.reject', ...$actions];
                    else $extra['plan_review_required'] = true;
                } elseif ($return->status === 'approved' && (float) $header['held_qty'] > 0) $actions = ['material_return.dispose'];
            }
            $rows = array_map(fn ($row) => $row + ['id' => $row['material_id'] ?? null, 'item_id' => $row['component_item_id'] ?? null,
                'item_name' => $row['component_item_name'] ?? '', 'item_code' => $row['component_item_code'] ?? null], $rows);
            $lines = new LengthAwarePaginator(array_slice($rows, ($page - 1) * $size, $size), count($rows), $size, $page);
        }
        return ['kind' => $kind, 'header' => $header, 'lines' => $lines->toArray(), 'actions' => $this->allowed($actions, $permissions), 'receipt' => null] + $extra;
    }

    private function transaction(string $type, int $id): ?array
    {
        $transaction = InventoryTransaction::where('source_type', $type)->where('source_id', $id)->where('posting_status', 'posted')->latest('id')->first();
        return $transaction ? $transaction->only(['id', 'transaction_no', 'transaction_type', 'posted_at', 'source_no', 'source_id']) : null;
    }

    private function allowed(array $actions, array $permissions): array
    { return array_values(array_filter($actions, fn ($action) => array_intersect((array) WarehouseActionService::PERMISSIONS[$action], $permissions))); }
}
