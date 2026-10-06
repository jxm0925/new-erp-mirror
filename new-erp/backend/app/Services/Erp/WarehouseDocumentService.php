<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{InventoryTransaction, Item, PurchaseReceipt, PurchaseReturn, SalesOrder, SalesReturn, SalesReturnReceipt, SalesShipment};
use Illuminate\Support\Facades\DB;

/** Mobile document projection: one authorized header and a server-paginated set of lines. */
final class WarehouseDocumentService
{
    public function show(string $kind, int $id, array $filters, object $user, array $permissions, bool $super): array
    {
        $permission = match ($kind) {
            'purchase_receipt' => 'inventory.post.view', 'purchase_return' => 'purchase_return.view',
            'sales_return' => 'sales_return.view', 'sales_shipment' => 'sales_order.shipment.view',
            'output', 'production_return' => 'production.task.view', 'cutting_product' => 'production.cutting.view',
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
        } elseif ($kind === 'production_return') {
            $header = app(ProductionExecutionInboxService::class)->show('material_returns', $id, $user, $permissions, $super);
            unset($header['lines'], $header['quality_inspections']);
            $lines = DB::table('erp_production_material_return_lines as l')->leftJoin('erp_items as i', 'i.id', '=', 'l.component_item_id')
                ->leftJoin('erp_units as u', 'u.id', '=', 'i.unit_id')->leftJoin('erp_warehouses as w', 'w.id', '=', 'l.warehouse_id')->leftJoin('erp_locations as loc', 'loc.id', '=', 'l.location_id')
                ->where('l.return_id', $id)->select('l.*', 'i.item_name', 'i.item_code', 'i.spec', 'u.unit_name', 'w.warehouse_name', 'loc.location_name');
            if ($header['status'] === 'SUBMITTED') $actions = ['production_return.receive'];
            return ['kind' => $kind, 'header' => $header, 'lines' => $lines->orderBy('l.id')->paginate($size, ['*'], 'page', $page)->toArray(),
                'actions' => $this->allowed($actions, $permissions), 'receipt' => $this->transaction('production_material_return', $id)];
        } elseif ($kind === 'output') {
            return $this->output($id, $filters, $user, $permissions, $super);
        } else {
            return $this->cutting($id, $filters, $user, $permissions, $super);
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

    private function output(int $id, array $f, object $user, array $permissions, bool $super): array
    {
        $header = app(ProductionExecutionInboxService::class)->show('outputs', $id, $user, $permissions, $super);
        unset($header['warehouse_postings'], $header['quality_inspections']);
        $terminal = app(ProductionOutputService::class)->terminalOutputIdsQuery()->where('terminal_output.id', $id)->exists();
        $completion = $terminal ? app(WorkOrderCompletionService::class)->approvedLineForOutput($id) : null;
        $remaining = $terminal ? ($completion ? bcsub((string) $completion->qualified_base_qty, (string) DB::table('erp_work_order_finished_goods_receipts')
            ->where('completion_line_id', $completion->id)->where('status', 'POSTED')->sum('posted_base_qty'), 8) : '0') : (string) $header['output_base_qty'];
        $wo = DB::table('erp_work_orders')->where('id', $header['work_order_id'])->first(['production_batch', 'work_order_no']);
        $item = Item::with('unit')->findOrFail($header['output_item_id']);
        $receipt = null;
        if (! empty($f['receipt_id'])) {
            $receipt = DB::table('erp_production_output_warehouse_postings as p')->leftJoin('erp_warehouses as w', 'w.id', '=', 'p.warehouse_id')
                ->leftJoin('erp_locations as l', 'l.id', '=', 'p.location_id')->leftJoin('erp_work_order_finished_goods_receipts as fgr', 'fgr.output_warehouse_posting_id', '=', 'p.id')
                ->where('p.output_record_id', $id)->where('p.id', $f['receipt_id'])->where('p.status', 'POSTED')
                ->first(['p.*', 'fgr.receipt_no', 'w.warehouse_name', 'l.location_name']);
            if (! $receipt) throw new WorkOrderDomainException('receipt_not_found', '入库凭证不存在或不属于当前产出。', 404);
            $receipt->receipt_no = $receipt->receipt_no ?: $receipt->posting_no;
        }
        $qualityPassed = $header['quality_mode_snapshot'] === 'none' || DB::table('erp_production_quality_inspections')->where('output_record_id', $id)->where('result', 'passed')->exists();
        $actions = ! $receipt && $qualityPassed && $header['output_mode_snapshot'] !== 'flow_only' && bccomp($remaining, '0', 8) > 0
            && in_array($header['status'], ['CREATED', 'WAIT_WAREHOUSE'], true) ? ['output.warehouse'] : [];
        return ['kind' => 'output', 'header' => $header + ['remaining_qty' => $remaining, 'terminal' => $terminal, 'completion_approved' => (bool) $completion,
            'batch_no' => $wo->production_batch ?: $wo->work_order_no, 'item' => $item->toArray()],
            'lines' => ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1], 'receipt' => $receipt, 'actions' => $this->allowed($actions, $permissions)];
    }

    private function cutting(int $id, array $f, object $user, array $permissions, bool $super): array
    {
        $route = DB::table('erp_cutting_result_routes')->where('id', $id)->first();
        if (! $route) throw new WorkOrderDomainException('not_found', '下料产出去向不存在。', 404);
        app(CuttingCommandService::class)->assertResultVisible($route->result_id, $user, $permissions, $super, 'production.cutting.view');
        $result = DB::table('erp_cutting_results')->where('id', $route->result_id)->first();
        $batch = DB::table('erp_cutting_settlement_batches')->where('id', $result->settlement_batch_id)->first();
        $order = DB::table('erp_cutting_orders')->where('id', $batch->cutting_order_id)->first();
        $item = Item::with('unit')->findOrFail($result->item_id);
        $holding = DB::table('erp_material_holdings')->where('id', $route->holding_id)->first();
        $receipt = null;
        if (! empty($f['receipt_id'])) {
            $receipt = DB::table('erp_cutting_warehouse_receipts as r')->leftJoin('erp_warehouses as w', 'w.id', '=', 'r.warehouse_id')->leftJoin('erp_locations as l', 'l.id', '=', 'r.location_id')
                ->where('r.route_id', $id)->where('r.id', $f['receipt_id'])->where('r.status', 'POSTED')->first(['r.*', 'w.warehouse_name', 'l.location_name']);
            if (! $receipt) throw new WorkOrderDomainException('receipt_not_found', '入库凭证不存在或不属于当前下料产出。', 404);
        }
        $remaining = bcsub((string) $route->quantity, (string) $route->warehoused_qty, 8);
        $actions = ! $receipt && $route->route_type === 'WAREHOUSE' && ! $route->target_material_requirement_id
            && in_array($route->status, ['WAIT_WAREHOUSE', 'PART_WAREHOUSED'], true) && bccomp($remaining, '0', 8) > 0 ? ['cutting.warehouse'] : [];
        $response = ['kind' => 'cutting_product', 'header' => (array) $route + ['cutting_order_no' => $order->cutting_order_no,
            'batch_no' => $holding ? DB::table('erp_material_lots')->where('id', $holding->material_lot_id)->value('lot_no') : null,
            'remaining_qty' => $remaining, 'material_total_cost' => $holding?->total_cost, 'item' => $item->toArray()],
            'lines' => ['data' => [], 'total' => 0, 'current_page' => 1, 'last_page' => 1], 'receipt' => $receipt, 'actions' => $this->allowed($actions, $permissions)];
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
