<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{InventorySerial, Item, Location, MfgWorkOrderUnit, SalesOrder};
use App\Services\Erp\Manufacturing\WorkOrderQueryService;
use Illuminate\Support\Facades\DB;

/** Codes identify existing resources; permissions and original document scopes still decide what is visible. */
final class InventoryScanResolverService
{
    private const STOCK_READ = ['inventory.balance.view', 'inventory.stocktake.view', 'production.material_delivery.manage',
        'production.material_return.approve', 'inventory.post.view', 'inventory.post.repair', 'sales_return.receive',
        'sales_order.shipment.post', 'sales_order.shipment.packing.execute', 'sales_order.shipment.packing.quality'];
    private const LOCATION_READ = [...self::STOCK_READ, 'master.location.view', 'production.task.execute'];
    private const ITEM_READ = [...self::STOCK_READ, 'master.item.view'];
    private const DOCUMENTS = [
        'purchase_receipt' => ['erp_purchase_receipts', 'receipt_no', '采购到货单', ['inventory.post.view', 'purchase.receipt.view'], 'purchase/receipts'],
        'purchase_return' => ['erp_purchase_returns', 'return_no', '采购退货单', ['purchase_return.view'], 'purchase/returns'],
        'sales_shipment' => ['erp_sales_shipments', 'shipment_no', '销售发货单', ['sales_order.shipment.view'], 'sales/shipments'],
        'sales_return' => ['erp_sales_returns', 'return_no', '销售退货单', ['sales_return.view'], 'sales/returns'],
        'sales_return_receipt' => ['erp_sales_return_receipts', 'receipt_no', '销售退货到货单', ['sales_return.view'], null],
        'material_delivery' => ['erp_mfg_material_deliveries', 'delivery_no', '生产配料单', ['production.material_delivery.manage', 'production.work_order.view'], 'production/material-deliveries'],
        'material_return' => ['erp_mfg_material_returns', 'return_no', '生产退料单', ['production.material_return.request', 'production.material_return.approve'], 'production/material-returns'],
        'stocktake' => ['erp_inventory_stocktakes', 'stocktake_no', '盘点单', ['inventory.stocktake.view'], 'inventory/stocktakes'],
        'inventory_transfer' => ['erp_inventory_transfers', 'transfer_no', '调拨单', ['inventory.transfer.view'], 'inventory/transfers'],
        'inventory_adjustment' => ['erp_inventory_adjustments', 'adjustment_no', '库存调整单', ['inventory.adjustment.view'], 'inventory/adjustments'],
        'work_order' => ['erp_mfg_work_orders', 'work_order_no', '生产工单', ['production.work_order.view', 'production.task.execute'], 'production/work-orders'],
    ];

    public function resolve(string $code, object $user, array $permissions): array
    {
        $code = trim($code);
        if (str_starts_with($code, 'ERP:')) {
            if (! preg_match('/^ERP:(LOC|ITEM|SN|PHY|DOC):(.+)$/sD', $code, $parts)) $this->fail('scan_code_invalid', '二维码格式不正确，请重新扫码。', 422);
            [$type, $value] = [$parts[1], $parts[2]];
            if ($type === 'DOC') {
                $document = explode(':', $value, 2);
                if (count($document) !== 2 || ! isset(self::DOCUMENTS[$document[0]]) || $document[1] === '') {
                    $this->fail('scan_document_kind_invalid', '单据二维码类型或单号不正确。', 422);
                }
                $matches = $this->documents($document[1], $user, $permissions, $document[0]);
                return $this->documentMatch($matches);
            }
            $allowed = match ($type) {
                'LOC' => self::LOCATION_READ, 'ITEM' => self::ITEM_READ,
                'SN' => [...self::STOCK_READ, 'production.serial.view'], 'PHY' => self::STOCK_READ,
            };
            if (! array_intersect($allowed, $permissions)) $this->fail('permission_denied', '当前账号没有查看这类扫码资料的权限。', 403);
            $result = match ($type) {
                'LOC' => $this->location($value, $permissions), 'ITEM' => $this->item($value, $permissions),
                'SN' => $this->serial($value, $permissions), 'PHY' => $this->physical($value, $permissions),
            };
            return $result ?? $this->notFound();
        }
        // Search only permitted resources; a hidden serial must not prevent a legitimate item/location lookup.
        return $this->serial($code, $permissions) ?? $this->item($code, $permissions) ?? $this->location($code, $permissions)
            ?? $this->documentMatch($this->documents($code, $user, $permissions));
    }

    private function item(string $code, array $permissions): ?array
    {
        if (! array_intersect(self::ITEM_READ, $permissions)) return null;
        $query = Item::query()->with('unit:id,unit_name')->where('item_code', $code);
        if ($this->factoryOnly($permissions)) $query->where('management_scope', 'factory');
        $item = $query->first();
        if (! $item) return null;
        return ['type' => 'item', 'resource' => 'item', 'id' => (int) $item->id, 'code' => $item->item_code, 'name' => $item->item_name,
            'item_id' => (int) $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name, 'spec' => $item->spec,
            'unit_id' => $item->unit_id, 'unit_name' => $item->unit?->unit_name, 'status' => $item->status,
            'serial_tracking_mode' => $item->serialTrackingMode(), 'material_management_mode' => $item->materialManagementMode(),
            'target' => in_array('inventory.balance.view', $permissions, true) ? ['path' => 'inventory/balances/items/'.$item->id.'/batches', 'query' => []] : null];
    }

    private function location(string $code, array $permissions): ?array
    {
        if (! array_intersect(self::LOCATION_READ, $permissions)) return null;
        $query = Location::query()->with('warehouse')->where('location_code', $code);
        if ($this->factoryOnly($permissions)) $query->whereHas('warehouse', fn ($q) => $q->where('management_scope', 'factory'));
        $location = $query->first();
        if (! $location) return null;
        return ['type' => 'location', 'resource' => 'location', 'id' => (int) $location->id, 'code' => $location->location_code, 'name' => $location->location_name,
            'location_id' => (int) $location->id, 'location_code' => $location->location_code, 'location_name' => $location->location_name,
            'warehouse_id' => (int) $location->warehouse_id, 'warehouse_code' => $location->warehouse?->warehouse_code,
            'warehouse_name' => $location->warehouse?->warehouse_name, 'status' => $location->status, 'location_purpose' => $location->location_purpose,
            'warehouse_status' => $location->warehouse?->status,
            'target' => in_array('inventory.balance.view', $permissions, true) ? ['path' => 'inventory/balances', 'query' => ['location_id' => (int) $location->id]] : null];
    }

    private function serial(string $code, array $permissions): ?array
    {
        if (array_intersect(self::STOCK_READ, $permissions)) {
            $query = InventorySerial::query()->with(['item:id,item_code,item_name,spec,management_scope', 'warehouse', 'location'])->where('serial_no', $code);
            if ($this->factoryOnly($permissions)) $query->whereHas('item', fn ($q) => $q->where('management_scope', 'factory'));
            if ($serial = $query->first()) {
                $unitId = in_array('production.serial.view', $permissions, true) ? MfgWorkOrderUnit::query()->where('inventory_serial_id', $serial->id)->value('id') : null;
                return ['type' => 'serial', 'resource' => 'inventory_serial', 'id' => (int) $serial->id, 'code' => $serial->serial_no,
                    'name' => trim(($serial->item?->item_name ?? '').' '.$serial->serial_no), 'inventory_serial_id' => (int) $serial->id,
                    'work_order_unit_id' => $unitId ? (int) $unitId : null, 'inventory_balance_id' => $serial->inventory_balance_id,
                    'serial_no' => $serial->serial_no, 'status' => $serial->serial_status, 'item_id' => (int) $serial->item_id,
                    'item_code' => $serial->item?->item_code, 'item_name' => $serial->item?->item_name, 'spec' => $serial->item?->spec,
                    'batch_no' => $serial->batch_no, 'warehouse_id' => (int) $serial->warehouse_id, 'warehouse_name' => $serial->warehouse?->warehouse_name,
                    'location_id' => (int) $serial->location_id, 'location_code' => $serial->location?->location_code, 'location_name' => $serial->location?->location_name,
                    'target' => in_array('inventory.balance.view', $permissions, true) && $serial->inventory_balance_id
                        ? ['path' => 'inventory/balances/'.$serial->inventory_balance_id.'/serials', 'query' => ['ids' => [(int) $serial->id]]] : null];
            }
        }
        if (! in_array('production.serial.view', $permissions, true)) return null;
        $trace = app(WorkOrderQueryService::class)->serial($code);
        if (! $trace) return null;
        $unit = MfgWorkOrderUnit::query()->where('serial_no', $trace['serial_no'])->firstOrFail();
        return ['type' => 'serial', 'resource' => 'work_order_unit', 'id' => (int) $unit->id, 'code' => $unit->serial_no,
            'name' => trim(($trace['work_order']['item_name'] ?? '').' '.$unit->serial_no), 'serial_no' => $unit->serial_no,
            'inventory_serial_id' => null, 'work_order_unit_id' => (int) $unit->id, 'work_order_id' => (int) $unit->work_order_id,
            'work_order_no' => $trace['work_order']['work_order_no'], 'status' => $unit->status, 'status_label' => $trace['status_label'],
            'current_operation_name' => $trace['current_operation_name'], 'matched_component_serial_no' => $trace['matched_component_serial_no'],
            'target' => ['path' => 'production/serials/lookup', 'query' => ['serial_no' => $code]]];
    }

    private function physical(string $value, array $permissions): ?array
    {
        if (! preg_match('/^[1-9][0-9]{0,17}$/D', $value)) $this->fail('scan_physical_invalid', '实物二维码编号不正确。', 422);
        $query = DB::table('erp_material_physicals as p')->join('erp_items as i', 'i.id', '=', 'p.item_id')
            ->leftJoin('erp_material_holdings as h', 'h.id', '=', 'p.current_holding_id')
            ->leftJoin('erp_inventory_balances as b', 'b.id', '=', 'h.inventory_balance_id')
            ->leftJoin('erp_warehouses as w', 'w.id', '=', 'b.warehouse_id')->leftJoin('erp_locations as l', 'l.id', '=', 'b.location_id')
            ->where('p.id', (int) $value);
        if ($this->factoryOnly($permissions)) $query->where('i.management_scope', 'factory');
        $row = $query->first(['p.id', 'p.physical_no', 'p.item_id', 'p.shape', 'p.dimensions', 'p.status', 'p.material_form', 'p.total_cost', 'p.business_version',
            'i.item_code', 'i.item_name', 'b.id as inventory_balance_id', 'b.batch_no', 'b.warehouse_id', 'w.warehouse_name', 'b.location_id', 'l.location_code', 'l.location_name']);
        if (! $row) return null;
        return ['type' => 'physical', 'resource' => 'material_physical', 'code' => $row->physical_no, 'name' => trim($row->item_name.' '.$row->physical_no),
            ...((array) $row), 'id' => (int) $row->id, 'dimensions' => json_decode($row->dimensions, true, 512, JSON_THROW_ON_ERROR),
            'target' => in_array('inventory.balance.view', $permissions, true) ? ['path' => 'inventory/balances/items/'.$row->item_id.'/physicals', 'query' => ['physical_id' => (int) $row->id]] : null];
    }

    private function documents(string $number, object $user, array $permissions, ?string $onlyKind = null): array
    {
        $matches = [];
        foreach (self::DOCUMENTS as $kind => [$table, $column, $title, $required, $path]) {
            if ($onlyKind !== null && $kind !== $onlyKind) continue;
            if (! array_intersect($required, $permissions)) {
                if ($onlyKind !== null) $this->fail('permission_denied', '当前账号没有查看这类单据的权限。', 403);
                continue;
            }
            $query = DB::table($table.' as d')->where('d.'.$column, $number);
            if (str_starts_with($kind, 'sales_')) {
                $orders = SalesOrder::query()->select('id'); app(SalesOrderVisibilityService::class)->apply($orders, $user, true);
                if ($kind === 'sales_return_receipt') $query->whereIn('d.sales_return_id', DB::table('erp_sales_returns')->whereIn('sales_order_id', $orders)->select('id'));
                else $query->whereIn('d.sales_order_id', $orders);
            }
            if ($kind === 'material_return' && ! in_array('production.material_return.approve', $permissions, true))
                $query->where('d.requested_by_legacy_id', (int) ($user->legacy_id ?? $user->id ?? 0));
            $row = $query->first(['d.id', 'd.'.$column.' as number']);
            if (! $row) continue;
            $target = $path ? ['path' => $path.'/'.$row->id, 'query' => []] : null;
            $workspaceKind = $kind === 'sales_return_receipt' ? 'sales_return' : $kind;
            if (in_array($kind, ['material_delivery', 'material_return', 'stocktake', 'sales_return', 'sales_return_receipt', 'sales_shipment', 'purchase_return'], true)
                || ($kind === 'purchase_receipt' && in_array('inventory.post.view', $permissions, true))) {
                $target = ['path' => 'inventory/warehouse-workspace/documents/'.$workspaceKind.'/'.$row->id,
                    'query' => $kind === 'sales_return' ? ['stage' => 'receive'] : ($kind === 'sales_return_receipt' ? ['stage' => 'post'] : [])];
            }
            $matches[] = ['type' => 'document', 'resource' => 'document', 'kind' => $kind, 'id' => (int) $row->id, 'code' => $row->number,
                'name' => $title.' '.$row->number, 'number' => $row->number, 'target' => $target];
        }
        return $matches;
    }

    private function documentMatch(array $matches): array
    {
        if ($matches === []) return $this->notFound();
        if (count($matches) > 1) throw new WorkOrderDomainException('scan_document_ambiguous', '这个单号对应多种单据，请扫描带单据类型的二维码。', 409, ['matches' => $matches]);
        return $matches[0];
    }

    private function factoryOnly(array $permissions): bool
    {
        return ! array_intersect(['inventory.balance.view', 'inventory.stocktake.view', 'inventory.post.view', 'inventory.post.repair',
            'sales_return.receive', 'master.item.view', 'master.location.view'], $permissions);
    }

    private function notFound(): never { $this->fail('scan_not_found', '没有找到可访问的扫码资料，请核对编码和权限。', 404); }
    private function fail(string $code, string $message, int $status): never { throw new WorkOrderDomainException($code, $message, $status); }
}
