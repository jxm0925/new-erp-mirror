<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\SalesOrder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use App\Services\Erp\Manufacturing\{CuttingRemnantReceiptService, MaterialDeliveryService, MaterialReturnService, TaskService};
use App\Services\Erp\Inventory\{InventoryTransferService, StocktakeFreezeQuery};

/** Discovery only: all mutations still use their existing domain application services. */
final class WarehouseWorkspaceService
{
    private const TYPES = [
        'mfg_receipt' => ['生产入库确认', 'inbound', ['inventory.inbound.confirm', 'production.receipt.execute', 'production.task.execute', 'production.work_order.view']],
        'cutting_remnant' => ['开料余料历史', 'inbound', ['inventory.inbound.confirm', 'production.work_order.view', 'production.work_order.edit', 'production.task.execute', 'production.task.view']],
        'inventory_transfer' => ['调拨入库确认', 'inbound', ['inventory.inbound.confirm', 'inventory.transfer.view', 'inventory.transfer.post']],
        'material_physical_transfer' => ['实物调拨入库确认', 'inbound', ['inventory.inbound.confirm', 'inventory.transfer.view', 'inventory.physical.manage']],
        'purchase_receipt' => ['采购入库', 'inbound', 'inventory.post.view'],
        'sales_return' => ['销售退货', 'inbound', 'sales_return.view'],
        'sales_shipment' => ['销售出库', 'outbound', 'sales_order.shipment.view'],
        'purchase_return' => ['采购退货', 'outbound', 'purchase_return.view'],
        'material_preparation' => ['待配料', 'outbound', 'production.material_delivery.manage'],
        'material_delivery' => ['生产配送', 'outbound', ['production.material_delivery.manage', 'production.work_order.view']],
        'material_return' => ['生产退料', 'inbound', ['production.material_return.request', 'production.material_return.approve', 'inventory.inbound.confirm']],
        'stocktake' => ['库存盘点', 'all', 'inventory.stocktake.view'],
    ];

    private array $preparationRows = [];
    private array $preparationById = [];

    public function __construct(private readonly SalesOrderVisibilityService $salesScope, private readonly MaterialDeliveryService $deliveries) {}

    public function types(array $permissions): array
    {
        $types = [];
        foreach (self::TYPES as $key => [$name, $direction, $permission]) {
            if (array_intersect((array) $permission, $permissions)) $types[] = compact('key', 'name', 'direction');
        }
        return $types;
    }

    public function paginate(array $filters, object $user, array $permissions, bool $super): array
    {
        $this->assertAccess($permissions);
        $query = $this->query($filters, $user, $permissions, $super);
        $page = $query->orderByDesc('urgency')->orderBy('sort_priority')->orderByDesc('sort_at')->orderBy('kind')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $page->setCollection($page->getCollection()->map(fn ($row) => $this->present($row)));
        return ['data' => $page->items(), 'meta' => ['total' => $page->total(), 'current_page' => $page->currentPage(),
            'per_page' => $page->perPage(), 'last_page' => $page->lastPage()], 'types' => $this->types($permissions)];
    }

    public function summary(object $user, array $permissions, bool $super): array
    {
        $this->assertAccess($permissions);
        // Count posted documents, never quantities of different units. Completed receipt branches
        // retain the immutable posting date, so edits/dispatches cannot inflate today's count.
        $done = $this->query(['status_group' => 'completed'], $user, $permissions, $super)
            ->whereDate('completed_at', now()->toDateString())->where('counts_as_posting', 1)
            ->selectRaw('direction, COUNT(*) as aggregate')->groupBy('direction')->pluck('aggregate', 'direction');
        $pending = $this->query([], $user, $permissions, $super);
        $counts = (clone $pending)->selectRaw('kind, COUNT(*) as aggregate')->groupBy('kind')->pluck('aggregate', 'kind');
        return ['today_inbound' => (int) ($done['inbound'] ?? 0), 'today_outbound' => (int) ($done['outbound'] ?? 0),
            'pending_counts' => (object) $counts->map(fn ($count) => (int) $count)->all(),
            'pending_preparations' => (int) ($counts['material_preparation'] ?? 0), 'pending_deliveries' => (int) ($counts['material_delivery'] ?? 0),
            'pending_returns' => (int) ($counts['material_return'] ?? 0), 'pending_stocktakes' => (int) ($counts['stocktake'] ?? 0),
            'pending_mfg_receipts' => (int) ($counts['mfg_receipt'] ?? 0),
            'pending_cutting_remnants' => (int) ($counts['cutting_remnant'] ?? 0),
            'overdue_cutting_remnants' => (clone $pending)->where('kind', 'cutting_remnant')->where('overdue', 1)->count(),
            'pending_transfers' => (int) ($counts['inventory_transfer'] ?? 0),
            'pending_physical_transfers' => (int) ($counts['material_physical_transfer'] ?? 0),
            'transfer_exceptions' => (clone $pending)->whereIn('kind', ['inventory_transfer', 'material_physical_transfer'])->where('has_exception', 1)->count(),
            'overdue_stocktakes' => (clone $pending)->where('kind', 'stocktake')->where('overdue', 1)->count(),
            'types' => $this->types($permissions),
            'todos' => $this->paginate(['per_page' => 3], $user, $permissions, $super)];
    }

    private function assertAccess(array $permissions): void
    {
        if ($this->types($permissions) === [] && ! array_intersect(['inventory.balance.view', 'sales_order.shipment.post', 'sales_order.shipment.packing.execute', 'sales_order.shipment.packing.quality'], $permissions))
            throw new WorkOrderDomainException('warehouse_access_denied', '当前账号没有仓库业务查看权限。', 403);
    }

    private function query(array $filters, object $user, array $permissions, bool $super): Builder
    {
        $completed = ($filters['status_group'] ?? 'pending') === 'completed';
        $queries = [];
        foreach ($this->types($permissions) as $type) {
            if (! empty($filters['direction']) && $filters['direction'] !== 'all' && $type['direction'] !== $filters['direction']) continue;
            if (! empty($filters['kind']) && $type['key'] !== $filters['kind']) continue;
            foreach ($this->sources($type['key'], $completed, $filters, $user, $permissions, $super) as $query) $queries[] = $query;
        }
        if ($queries === []) return DB::query()->fromSub(DB::query()->selectRaw("0 as id, '' as kind, '' as direction, '' as number, NULL as sort_at, NULL as completed_at, 0 as counts_as_posting, 0 as urgency, 3 as sort_priority, 0 as overdue, 0 as has_exception")->whereRaw('1=0'), 'workspace');
        $union = array_shift($queries);
        foreach ($queries as $query) $union->unionAll($query);
        $query = DB::query()->fromSub($union, 'workspace');
        if (! empty($filters['date_from'])) $query->whereDate('sort_at', '>=', $filters['date_from']);
        if (! empty($filters['date_to'])) $query->whereDate('sort_at', '<=', $filters['date_to']);
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (isset($filters['has_exception'])) $query->where('has_exception', ! empty($filters['has_exception']) ? 1 : 0);
        if (! empty($filters['warehouse_id'])) $query->where('target_warehouse_id', (int) $filters['warehouse_id']);
        return $query;
    }

    private function sources(string $kind, bool $done, array $f, object $user, array $permissions, bool $super): array
    {
        if ($kind === 'cutting_remnant') {
            if (! $done) return [];
            $q = DB::table('erp_mfg_cutting_remnant_receipts as d')->join('erp_mfg_cutting_batches as batch', 'batch.id', '=', 'd.cutting_batch_id')
                ->leftJoin('erp_mfg_work_orders as wo', 'wo.id', '=', 'batch.work_order_id')
                ->leftJoin('erp_mfg_cutting_runs as run', 'run.id', '=', 'batch.cutting_run_id');
            if (! empty($f['status'])) $q->where('d.status', $f['status']);
            if (! array_intersect(['inventory.inbound.confirm', 'production.work_order.view', 'production.work_order.edit'], $permissions))
                $q->where('d.requested_by_legacy_id', (int) ($user->legacy_id ?? $user->id ?? 0));
            if ($keyword = trim((string) ($f['keyword'] ?? ''))) {
                $like = '%'.$keyword.'%';
                $q->where(fn ($w) => $w->where('d.receipt_no', 'like', $like)->orWhere('batch.batch_no', 'like', $like)
                    ->orWhere('wo.work_order_no', 'like', $like)->orWhere('run.run_no', 'like', $like)
                    ->orWhereExists(fn ($item) => $item->selectRaw('1')->from('erp_items as search_item')
                        ->whereRaw("JSON_CONTAINS(d.requested_lines, JSON_OBJECT('item_id', search_item.id))")
                        ->where(fn ($name) => $name->where('search_item.item_code', 'like', $like)->orWhere('search_item.item_name', 'like', $like)->orWhere('search_item.spec', 'like', $like))));
            }
            $end = 'COALESCE(d.confirmed_at,d.rejected_at,d.cancelled_at,CURRENT_TIMESTAMP)';
            $waiting = 'GREATEST(0,TIMESTAMPDIFF(SECOND,d.requested_at,'.$end.'))';
            $overdue = "CASE WHEN d.status='pending' AND $waiting>86400 THEN 1 ELSE 0 END";
            $posted = "CASE WHEN d.status='confirmed' AND EXISTS (SELECT 1 FROM erp_inventory_transactions tx WHERE tx.id=d.inventory_transaction_id AND tx.source_type='mfg_cutting_batch' AND tx.source_id=batch.id AND tx.transaction_type='mfg_cutting_remnant_inbound' AND tx.posting_status='posted') THEN 1 ELSE 0 END";
            return [$this->row($q, $kind, $done, ['number' => 'd.receipt_no', 'related_number' => 'batch.batch_no',
                'party' => "COALESCE(wo.item_name,run.output_item_name,'')", 'work_order_no' => "COALESCE(wo.work_order_no,'')", 'work_order_id' => 'wo.id',
                'cutting_batch_id' => 'batch.id', 'cutting_run_id' => 'run.id', 'status' => 'd.status', 'stage' => "'confirm'",
                'item_id' => "CAST(JSON_UNQUOTE(JSON_EXTRACT(d.requested_lines,'$[0].item_id')) AS UNSIGNED)",
                'sort_at' => 'd.requested_at', 'completed_at' => 'COALESCE(d.confirmed_at,d.rejected_at,d.cancelled_at)',
                'business_version' => 'd.business_version', 'waiting_seconds' => $waiting, 'overdue' => $overdue,
                'urgency' => '('.$overdue.')*2', 'counts_as_posting' => $posted], array_diff_key($f, ['keyword' => true]))];
        }
        if ($kind === 'inventory_transfer') {
            $q = DB::table('erp_inventory_transfers as d')->join('erp_warehouses as source_warehouse', 'source_warehouse.id', '=', 'd.source_warehouse_id')
                ->join('erp_warehouses as target_warehouse', 'target_warehouse.id', '=', 'd.target_warehouse_id');
            if (! empty($f['status'])) $q->where('d.transfer_status', strtolower($f['status']));
            else $q->whereIn('d.transfer_status', $done ? ['posted', 'cancelled'] : ['in_transit']);
            if (! $done) $q->whereNotNull('d.outbound_transaction_id')->whereNull('d.inbound_transaction_id');
            $this->transferScope($q, $user, $done, 'd.source_warehouse_id');
            $postedAt = "COALESCE(d.confirmed_at,d.posted_at,(SELECT tx.posted_at FROM erp_inventory_transactions tx WHERE tx.id=d.inventory_transaction_id))";
            return [$this->row($q, $kind, $done, ['number' => 'd.transfer_no', 'party' => 'target_warehouse.warehouse_name',
                'related_number' => "CONCAT(source_warehouse.warehouse_name,' → ',target_warehouse.warehouse_name)",
                'status' => 'd.transfer_status', 'stage' => "'confirm'", 'sort_at' => 'COALESCE(d.dispatched_at,d.created_at)',
                'completed_at' => 'COALESCE('.$postedAt.',d.cancelled_at)', 'business_version' => 'd.business_version',
                'source_warehouse_id' => 'd.source_warehouse_id', 'target_warehouse_id' => 'd.target_warehouse_id',
                'source_warehouse_name' => 'source_warehouse.warehouse_name', 'target_warehouse_name' => 'target_warehouse.warehouse_name',
                'receipt_exception' => 'd.receipt_exception', 'has_exception' => "CASE WHEN TRIM(COALESCE(d.receipt_exception,''))<>'' THEN 1 ELSE 0 END",
                'counts_as_posting' => "CASE WHEN d.transfer_status='posted' AND COALESCE(d.inbound_transaction_id,d.inventory_transaction_id) IS NOT NULL THEN 1 ELSE 0 END"],
                $f, ['erp_inventory_transfer_lines', 'transfer_id', 'item_id', 'quantity'])];
        }
        if ($kind === 'material_physical_transfer') {
            $sourceWarehouse = "COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(d.source_snapshot,'$.warehouse_id')) AS UNSIGNED),source_balance.warehouse_id)";
            $q = DB::table('erp_material_physical_transfers as d')->join('erp_inventory_balances as source_balance', 'source_balance.id', '=', 'd.source_inventory_balance_id')
                ->join('erp_material_physicals as physical', 'physical.id', '=', 'd.physical_material_id')
                ->join('erp_warehouses as target_warehouse', 'target_warehouse.id', '=', 'd.target_warehouse_id')
                ->leftJoin('erp_warehouses as source_warehouse', 'source_warehouse.id', '=', DB::raw($sourceWarehouse));
            if (! empty($f['status'])) $q->where('d.status', strtoupper($f['status']));
            else $q->where('d.status', $done ? 'POSTED' : 'IN_TRANSIT');
            if (! $done) $q->whereNotNull('d.outbound_transaction_id')->whereNull('d.inbound_transaction_id');
            $this->transferScope($q, $user, $done, $sourceWarehouse);
            return [$this->row($q, $kind, $done, ['number' => 'd.transfer_no', 'party' => 'target_warehouse.warehouse_name',
                'related_number' => "CONCAT(COALESCE(source_warehouse.warehouse_name,''),' → ',target_warehouse.warehouse_name)",
                'status' => 'd.status', 'stage' => "'confirm'", 'item_id' => 'physical.item_id', 'quantity' => '1',
                'sort_at' => 'COALESCE(d.dispatched_at,d.moved_at,d.created_at)', 'completed_at' => 'COALESCE(d.confirmed_at,d.moved_at)',
                'business_version' => 'd.business_version', 'source_warehouse_id' => $sourceWarehouse, 'target_warehouse_id' => 'd.target_warehouse_id',
                'source_warehouse_name' => 'source_warehouse.warehouse_name', 'target_warehouse_name' => 'target_warehouse.warehouse_name',
                'receipt_exception' => 'd.receipt_exception', 'has_exception' => "CASE WHEN TRIM(COALESCE(d.receipt_exception,''))<>'' THEN 1 ELSE 0 END",
                'counts_as_posting' => "CASE WHEN d.status='POSTED' AND COALESCE(d.inbound_transaction_id,d.inventory_transaction_id) IS NOT NULL THEN 1 ELSE 0 END"], $f)];
        }
        if ($kind === 'mfg_receipt') {
            $q = DB::table('erp_mfg_receipts as d')->join('erp_mfg_work_orders as wo', 'wo.id', '=', 'd.work_order_id')
                ->leftJoin('erp_warehouses as requested_warehouse', 'requested_warehouse.id', '=', 'd.requested_warehouse_id')
                ->leftJoin('erp_locations as requested_location', 'requested_location.id', '=', 'd.requested_location_id');
            if (! empty($f['status'])) $q->where('d.status', $f['status']);
            else $done ? $q->whereIn('d.status', ['confirmed', 'rejected', 'cancelled']) : $q->where('d.status', 'pending');
            if (! array_intersect(['inventory.inbound.confirm', 'production.work_order.view'], $permissions))
                $q->where('d.operator_legacy_id', (int) ($user->legacy_id ?? $user->id ?? 0));
            return [$this->row($q, $kind, $done, ['number' => 'd.receipt_no', 'work_order_no' => 'wo.work_order_no',
                'work_order_id' => 'wo.id', 'related_number' => 'wo.work_order_no', 'party' => 'wo.item_name',
                'status' => 'd.status', 'stage' => "'confirm'", 'item_id' => 'wo.item_id', 'quantity' => 'd.qty',
                'sort_at' => 'COALESCE(d.requested_at,d.created_at)', 'completed_at' => 'COALESCE(d.confirmed_at,d.rejected_at,d.cancelled_at)',
                'business_version' => 'd.business_version', 'requested_warehouse_name' => 'requested_warehouse.warehouse_name',
                'requested_location_code' => 'requested_location.location_code', 'requested_location_name' => 'requested_location.location_name',
                'counts_as_posting' => "CASE WHEN d.status='confirmed' AND d.inventory_transaction_id IS NOT NULL THEN 1 ELSE 0 END"], $f)];
        }
        if ($kind === 'material_preparation') {
            if ($done) return [];
            $keyword = trim((string) ($f['keyword'] ?? ''));
            if (! array_key_exists($keyword, $this->preparationRows)) {
                $this->preparationRows[$keyword] = $this->deliveries->pendingRows($f);
                foreach ($this->preparationRows[$keyword] as $row) $this->preparationById[$row['operation_id']] = $row;
            }
            $q = DB::table('erp_mfg_work_order_operations as d')->join('erp_mfg_work_orders as wo', 'wo.id', '=', 'd.work_order_id')
                ->whereIn('d.id', array_column($this->preparationRows[$keyword], 'operation_id') ?: [0]);
            $waiting = "(SELECT COUNT(*) FROM erp_mfg_work_order_units u WHERE u.current_operation_id=d.id AND u.status IN ('".implode("','", TaskService::SELECTABLE)."'))";
            return [$this->row($q, $kind, false, ['parent_id' => 'wo.id', 'number' => 'wo.work_order_no', 'work_order_no' => 'wo.work_order_no',
                'work_order_id' => 'wo.id', 'operation_id' => 'd.id', 'related_number' => 'd.operation_name', 'party' => 'wo.product_name',
                'stage' => "'prepare'", 'item_id' => 'wo.item_id', 'waiting_units' => $waiting, 'sort_priority' => 'wo.priority',
                'urgency' => "CASE WHEN $waiting>0 THEN 2 WHEN wo.is_urgent=1 THEN 1 ELSE 0 END", 'counts_as_posting' => '0'],
                array_diff_key($f, ['keyword' => true]))];
        }
        if ($kind === 'material_delivery') {
            $q = DB::table('erp_mfg_material_deliveries as d')->join('erp_mfg_work_orders as wo', 'wo.id', '=', 'd.work_order_id')
                ->join('erp_mfg_work_order_operations as op', 'op.id', '=', 'd.work_order_operation_id')
                ->whereIn('d.status', $done ? ['signed', 'recalled', 'cancelled'] : ['prepared', 'dispatched', 'rejected']);
            return [$this->row($q, $kind, $done, ['number' => 'd.delivery_no', 'work_order_no' => 'wo.work_order_no', 'work_order_id' => 'wo.id',
                'operation_id' => 'op.id', 'related_number' => 'op.operation_name', 'party' => 'wo.product_name', 'item_id' => 'wo.item_id',
                'quantity' => 'd.unit_qty', 'unit_name' => 'wo.unit_name', 'status' => 'd.status', 'stage' => "'deliver'", 'sort_priority' => 'wo.priority',
                'urgency' => $done ? '0' : 'CASE WHEN wo.is_urgent=1 OR d.status=\'rejected\' THEN 1 ELSE 0 END',
                'completed_at' => 'COALESCE(d.signed_at,d.closed_at)', 'counts_as_posting' => '0'], $f)];
        }
        if ($kind === 'material_return') {
            $q = DB::table('erp_mfg_material_returns as d')->join('erp_mfg_work_orders as wo', 'wo.id', '=', 'd.work_order_id')
                ->join('erp_mfg_work_order_operations as op', 'op.id', '=', 'd.work_order_operation_id')
                ->whereIn('d.status', $done ? ['approved', 'rejected', 'cancelled'] : ['pending']);
            if (! $done) $q->whereIn('d.id', MaterialReturnService::pendingReceipts(\App\Models\Erp\MfgMaterialReturn::query())->select('id'));
            if (! array_intersect(['production.material_return.approve', 'inventory.inbound.confirm'], $permissions))
                $q->where('d.requested_by_legacy_id', (int) ($user->legacy_id ?? $user->id ?? 0));
            return [$this->row($q, $kind, $done, ['number' => 'd.return_no', 'work_order_no' => 'wo.work_order_no', 'work_order_id' => 'wo.id',
                'operation_id' => 'op.id', 'related_number' => 'op.operation_name', 'party' => 'wo.product_name', 'status' => 'd.status',
                'stage' => "'receive'", 'sort_priority' => 'wo.priority', 'urgency' => $done ? '0' : 'CASE WHEN wo.is_urgent=1 THEN 1 ELSE 0 END',
                'completed_at' => 'd.reviewed_at', 'counts_as_posting' => '0'], $f, ['erp_mfg_material_return_lines', 'return_id', 'component_item_id', 'qty'])];
        }
        if ($kind === 'stocktake') {
            $q = DB::table('erp_inventory_stocktakes as d')->join('erp_warehouses as warehouse', 'warehouse.id', '=', 'd.warehouse_id')
                ->leftJoin('erp_locations as location', 'location.id', '=', 'd.location_id')
                ->whereIn('d.stocktake_status', $done ? ['completed', 'cancelled'] : ['counting']);
            $cutoff = now()->startOfDay()->subDays(StocktakeFreezeQuery::OVERDUE_DAYS)->format('Y-m-d H:i:s');
            $overdue = $done ? '0' : "CASE WHEN d.created_at<'$cutoff' THEN 1 ELSE 0 END";
            return [$this->row($q, $kind, $done, ['number' => 'd.stocktake_no', 'party' => 'warehouse.warehouse_name',
                'related_number' => "COALESCE(location.location_name,'整仓')", 'status' => 'd.stocktake_status', 'stage' => "'count'",
                'item_count' => 'd.line_count', 'urgency' => "($overdue)*2", 'overdue' => $overdue,
                'completed_at' => 'COALESCE(d.completed_at,d.cancelled_at)', 'counts_as_posting' => '0'], $f)];
        }
        if ($kind === 'purchase_receipt') {
            $q = DB::table('erp_purchase_receipts as d')->leftJoin('erp_suppliers as supplier', 'supplier.id', '=', 'd.supplier_id')
                ->where('d.receipt_status', 'confirmed')->where('d.confirm_status', 'confirmed')
                ->where('d.stock_post_status', $done ? 'posted' : 'pending')
                ->whereExists(fn ($i) => $i->selectRaw('1')->from('erp_purchase_receipt_items as l')->whereColumn('l.receipt_id', 'd.id')->where('l.is_stock_item_snapshot', 1)->where('l.final_stockable_base_qty', '>', 0));
            $posted = "(SELECT MAX(t.posted_at) FROM erp_inventory_transactions t WHERE t.source_type='purchase_receipt' AND t.source_id=d.id AND t.posting_status='posted')";
            return [$this->row($q, $kind, $done, ['number' => 'd.receipt_no', 'party' => 'supplier.supplier_name', 'completed_at' => $posted], $f,
                ['erp_purchase_receipt_items', 'receipt_id', 'item_id', 'final_stockable_base_qty'])];
        }
        if ($kind === 'purchase_return') {
            $q = DB::table('erp_purchase_returns as d')->leftJoin('erp_suppliers as supplier', 'supplier.id', '=', 'd.supplier_id');
            $done ? $q->where('d.stock_post_status', 'posted') : $q->where('d.return_status', 'pending_outbound')->where('d.audit_status', 'approved')->where('d.stock_post_status', 'pending');
            return [$this->row($q, $kind, $done, ['number' => 'd.return_no', 'party' => 'supplier.supplier_name', 'completed_at' => 'd.posted_at'], $f,
                ['erp_purchase_return_items', 'return_id', 'item_id', $done ? 'posted_base_qty' : 'approved_base_qty - l.posted_base_qty'])];
        }
        $salesOrders = SalesOrder::query()->select('id'); $this->salesScope->apply($salesOrders, $user, true);
        if ($kind === 'sales_shipment') {
            $q = DB::table('erp_sales_shipments as d')->join('erp_sales_orders as so', 'so.id', '=', 'd.sales_order_id')->whereIn('d.sales_order_id', $salesOrders);
            if ($done) $q->whereIn('d.shipment_status', ['shipped', 'completed']);
            else $q->whereIn('d.shipment_status', ['pending_outbound', 'prepared', 'partially_shipped'])
                ->whereExists(fn ($lines) => $lines->selectRaw('1')->from('erp_sales_shipment_lines as pending')
                    ->whereColumn('pending.shipment_id', 'd.id')->where('pending.line_status', 'pending_outbound'));
            return [$this->row($q, $kind, $done, ['number' => 'd.shipment_no', 'related_number' => 'so.sales_order_no', 'party' => 'so.customer_name', 'status' => 'd.shipment_status', 'completed_at' => 'd.outbound_posted_at'], $f,
                ['erp_sales_shipment_lines', 'shipment_id', 'item_id', 'base_qty'])];
        }
        $queries = [];
        if (! $done) {
            $q = DB::table('erp_sales_returns as d')->join('erp_sales_orders as so', 'so.id', '=', 'd.sales_order_id')->whereIn('d.sales_order_id', $salesOrders)->whereIn('d.return_status', ['pending_receipt', 'partial_received']);
            $queries[] = $this->row($q, $kind, false, ['number' => 'd.return_no', 'party' => 'so.customer_name', 'stage' => "'receive'", 'counts_as_posting' => '0'], $f,
                ['erp_sales_return_items', 'sales_return_id', 'item_id', 'requested_base_qty - l.received_base_qty']);
        }
        $q = DB::table('erp_sales_return_receipts as d')->join('erp_sales_returns as sr', 'sr.id', '=', 'd.sales_return_id')->join('erp_sales_orders as so', 'so.id', '=', 'sr.sales_order_id')
            ->whereIn('sr.sales_order_id', $salesOrders)->where('d.receipt_status', 'confirmed')->whereIn('d.stock_post_status', $done ? ['posted', 'not_required'] : ['pending']);
        $queries[] = $this->row($q, $kind, $done, ['number' => 'd.receipt_no', 'parent_id' => 'sr.id', 'related_number' => 'sr.return_no', 'party' => 'so.customer_name',
            'stage' => "'post'", 'completed_at' => 'COALESCE(d.posted_at,d.confirmed_at)',
            'counts_as_posting' => "CASE WHEN d.stock_post_status='posted' THEN 1 ELSE 0 END"], $f, ['erp_sales_return_receipt_items', 'receipt_id', 'item_id', $done ? 'received_base_qty' : 'restock_base_qty']);
        return $queries;
    }

    private function transferScope(Builder $query, object $actor, bool $done, string $sourceWarehouse): void
    {
        $ids = InventoryTransferService::warehouseIds($actor);
        if ($ids === null) return;
        // 接收待办只在目标仓；历史允许源/目标任一范围，确认服务仍会重验目标仓。
        $query->where(function ($scope) use ($ids, $done, $sourceWarehouse) {
            $scope->whereIn('d.target_warehouse_id', $ids);
            if ($done) $scope->orWhereIn(DB::raw($sourceWarehouse), $ids);
        });
    }

    private function row(Builder $query, string $kind, bool $done, array $fields, array $filters, ?array $lines = null): Builder
    {
        [$title, $direction] = self::TYPES[$kind];
        $columns = array_merge(['id' => 'd.id', 'parent_id' => 'd.id', 'number' => "''", 'related_number' => "''", 'work_order_no' => "''",
            'party' => "''", 'status' => $done ? "'completed'" : "'pending'", 'stage' => "'post'", 'item_id' => 'NULL', 'item_count' => '1',
            'quantity' => 'NULL', 'unit_name' => 'NULL', 'sort_at' => 'd.created_at', 'completed_at' => 'NULL', 'counts_as_posting' => '1',
            'urgency' => '0', 'sort_priority' => '3', 'overdue' => '0', 'waiting_units' => '0', 'work_order_id' => 'NULL', 'operation_id' => 'NULL',
            'cutting_batch_id' => 'NULL', 'cutting_run_id' => 'NULL', 'business_version' => 'NULL', 'waiting_seconds' => '0',
            'source_warehouse_id' => 'NULL', 'target_warehouse_id' => 'NULL', 'source_warehouse_name' => 'NULL', 'target_warehouse_name' => 'NULL',
            'requested_warehouse_name' => 'NULL', 'requested_location_code' => 'NULL', 'requested_location_name' => 'NULL',
            'receipt_exception' => 'NULL', 'has_exception' => '0'], $fields);
        if ($lines) {
            [$table, $foreign, $item, $quantity] = $lines;
            $parent = 'd.id';
            $active = $kind === 'sales_shipment' ? " AND l.line_status NOT IN ('replaced','cancelled')" : '';
            $columns['item_id'] = "(SELECT MIN(l.$item) FROM $table l WHERE l.$foreign=$parent$active)";
            $columns['item_count'] = "(SELECT COUNT(DISTINCT l.$item) FROM $table l WHERE l.$foreign=$parent$active)";
            $columns['quantity'] = "(SELECT SUM(l.$quantity) FROM $table l WHERE l.$foreign=$parent$active)";
        }
        if ($done) $columns['sort_at'] = $columns['completed_at'];
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $like = '%'.$keyword.'%';
            $query->where(function ($q) use ($columns, $lines, $like, $kind) {
                foreach (['number', 'related_number', 'work_order_no', 'party'] as $field) $q->orWhereRaw($columns[$field].' LIKE ?', [$like]);
                if ($lines) {
                    [$table, $foreign, $item] = $lines;
                    $q->orWhereExists(fn ($i) => $i->selectRaw('1')->from($table.' as search_line')->join('erp_items as search_item', 'search_item.id', '=', 'search_line.'.$item)
                        ->when($kind === 'sales_shipment', fn ($line) => $line->whereNotIn('search_line.line_status', ['replaced', 'cancelled']))
                        ->whereColumn('search_line.'.$foreign, 'd.id')->where(fn ($w) => $w->where('search_item.item_code', 'like', $like)->orWhere('search_item.item_name', 'like', $like)->orWhere('search_item.spec', 'like', $like)));
                } else $q->orWhereExists(fn ($i) => $i->selectRaw('1')->from('erp_items as search_item')->whereRaw('search_item.id = '.$columns['item_id'])
                    ->where(fn ($w) => $w->where('search_item.item_code', 'like', $like)->orWhere('search_item.item_name', 'like', $like)->orWhere('search_item.spec', 'like', $like)));
            });
        }
        $query->selectRaw('? as kind, ? as title, ? as direction, ? as status_group', [$kind, $title, $direction, $done ? 'completed' : 'pending']);
        foreach ($columns as $alias => $expression) $query->selectRaw($expression.' as '.$alias);
        return $query;
    }

    private function present(object $row): array
    {
        $remnant = null;
        if ($row->kind === 'cutting_remnant') {
            $receipt = \App\Models\Erp\MfgCuttingRemnantReceipt::query()->with('batch')->findOrFail($row->id);
            $lines = $receipt->status === 'confirmed' ? ($receipt->confirmed_lines ?? []) : ($receipt->requested_lines ?? []);
            $items = collect($lines)->pluck('item_id')->unique()->values();
            $units = collect($lines)->pluck('measure_unit')->unique()->values();
            $row->item_count = $items->count();
            $row->item_id = $items->count() === 1 ? $items->first() : null;
            $row->quantity = $items->count() === 1 && $units->count() === 1
                ? collect($lines)->reduce(fn ($sum, $line) => bcadd($sum, (string) $line['measure_qty'], 8), '0.00000000') : null;
            $row->unit_name = $units->count() === 1 ? match ($units->first()) { 'm2' => '㎡', 'm' => '米', default => $units->first() } : null;
            $remnant = app(CuttingRemnantReceiptService::class)->present($receipt, false);
        }
        $data = (array) $row;
        $data['key'] = $row->kind.':'.$row->stage.':'.$row->status_group.':'.$row->id;
        $data['id'] = (int) $row->id; $data['parent_id'] = (int) $row->parent_id;
        $data['overdue'] = (bool) $row->overdue; $data['waiting_units'] = (int) $row->waiting_units;
        $data['waiting_seconds'] = (int) $row->waiting_seconds;
        $data['waiting_hours'] = round($data['waiting_seconds'] / 3600, 1);
        $data['has_exception'] = (bool) $row->has_exception;
        foreach (['source_warehouse_id', 'target_warehouse_id', 'cutting_batch_id', 'cutting_run_id', 'business_version'] as $key)
            $data[$key] = $row->{$key} === null ? null : (int) $row->{$key};
        $data['work_order_id'] = $row->work_order_id ? (int) $row->work_order_id : null;
        $data['operation_id'] = $row->operation_id ? (int) $row->operation_id : null;
        $count = (int) $row->item_count;
        $item = $count === 1 && $row->item_id ? DB::table('erp_items as i')->leftJoin('erp_units as u', 'u.id', '=', 'i.unit_id')
            ->where('i.id', $row->item_id)->first(['i.item_name', 'i.item_code', 'i.spec', 'u.unit_name']) : null;
        $data['item_name'] = $item?->item_name ?? ($count > 1 ? '共'.$count.'项物料' : '');
        $data['item_code'] = $item?->item_code; $data['spec'] = $item?->spec;
        $data['unit_name'] = $row->unit_name ?? $item?->unit_name;
        // A sum across different items has no usable unit. Do not expose it as a quantity.
        if ($count > 1) $data['quantity'] = null;
        if ($row->kind === 'material_preparation' && isset($this->preparationById[$row->id])) {
            $preparation = $this->preparationById[$row->id];
            $data['item_name'] = $preparation['item_name']; $data['materials_summary'] = $preparation['summary'];
        }
        $data['status_label'] = $row->kind === 'mfg_receipt' ? match ($row->status) {
            'pending' => '待仓库确认', 'confirmed' => '已确认入库', 'rejected' => '已驳回', 'cancelled' => '已撤回', default => $row->status,
        } : ($row->kind === 'sales_shipment' ? match ($row->status) {
            'pending_outbound' => '待移至待发区', 'prepared' => '待发货', 'partially_shipped' => '部分已发货', 'outbound_posted' => '待发货', 'shipped' => '已发货', default => '已出库',
        } : ($row->status_group === 'completed' ? '已完成' : match ($row->kind) {
            'material_preparation' => $data['waiting_units'] > 0 ? '车间等料' : '待配料',
            'material_delivery' => MaterialDeliveryService::STATUS_LABELS[$row->status] ?? '待处理',
            'material_return' => '待接收', 'stocktake' => $data['overdue'] ? '盘点超期' : '盘点中',
            'sales_shipment', 'purchase_return' => '待出库',
            'sales_return' => $row->stage === 'receive' ? '待收货' : '待入库', default => '待办理',
        }));
        if ($remnant) {
            $data['status_label'] = ($remnant['disposition']['action'] ?? null) === 'scrap' ? '已整批报废' : $remnant['status_label'];
            $data['waiting_reason'] = $remnant['waiting_reason'];
            $data['cost_status'] = $remnant['cost_status'];
            $data['cost_status_label'] = $remnant['cost_status_label'] ?? ($row->status === 'confirmed' ? '已确认' : '待确认');
        }
        if (in_array($row->kind, ['inventory_transfer', 'material_physical_transfer'], true)) {
            $data['status_label'] = match (strtolower($row->status)) { 'in_transit' => '在途待接收', 'posted' => '已确认入库', 'cancelled' => '已取消', default => $row->status };
            if ($data['has_exception'] && strtolower($row->status) === 'in_transit') $data['status_label'] = '在途有异常';
        }
        return $data;
    }
}
