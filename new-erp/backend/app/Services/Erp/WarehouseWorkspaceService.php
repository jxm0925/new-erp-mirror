<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\{SalesOrder, WorkOrder};
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Discovery only: all mutations still use their existing domain application services. */
final class WarehouseWorkspaceService
{
    private const TYPES = [
        'purchase_receipt' => ['采购入库', 'inbound', 'inventory.post.view'],
        'output' => ['成品入库', 'inbound', 'production.task.view'],
        'remnant' => ['余料入库', 'inbound', 'production.cutting.view'],
        'cutting_product' => ['下料产品入库', 'inbound', 'production.cutting.view'],
        'production_return' => ['生产退料', 'inbound', 'production.task.view'],
        'sales_return' => ['销售退货', 'inbound', 'sales_return.view'],
        'picking' => ['生产领料', 'outbound', 'production.material_picking.view'],
        'sales_shipment' => ['销售出库', 'outbound', 'sales_order.shipment.view'],
        'purchase_return' => ['采购退货', 'outbound', 'purchase_return.view'],
    ];

    public function __construct(private readonly ProductionDataScopeResolver $scope,
        private readonly CuttingReadService $cutting, private readonly CuttingRemnantReceiptService $remnants,
        private readonly ProductionExecutionInboxService $inbox, private readonly SalesOrderVisibilityService $salesScope) {}

    public function types(array $permissions): array
    {
        $types = [];
        foreach (self::TYPES as $key => [$name, $direction, $permission]) {
            if (in_array($permission, $permissions, true)) $types[] = compact('key', 'name', 'direction');
        }
        return $types;
    }

    public function paginate(array $filters, object $user, array $permissions, bool $super): array
    {
        $this->assertAccess($permissions);
        $query = $this->query($filters, $user, $permissions, $super);
        $page = $query->orderByDesc('sort_at')->orderBy('kind')->orderByDesc('id')
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
        return ['today_inbound' => (int) ($done['inbound'] ?? 0), 'today_outbound' => (int) ($done['outbound'] ?? 0),
            'types' => $this->types($permissions),
            'can_delivery' => in_array('production.material_delivery.view', $permissions, true),
            'todos' => $this->paginate(['per_page' => 2], $user, $permissions, $super)];
    }

    private function assertAccess(array $permissions): void
    {
        if ($this->types($permissions) === [] && ! in_array('production.material_delivery.view', $permissions, true))
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
        if ($queries === []) return DB::query()->fromSub(DB::query()->selectRaw("0 as id, '' as kind, '' as direction, '' as number, NULL as sort_at, NULL as completed_at, 0 as counts_as_posting")->whereRaw('1=0'), 'workspace');
        $union = array_shift($queries);
        foreach ($queries as $query) $union->unionAll($query);
        $query = DB::query()->fromSub($union, 'workspace');
        if (! empty($filters['date_from'])) $query->whereDate('sort_at', '>=', $filters['date_from']);
        if (! empty($filters['date_to'])) $query->whereDate('sort_at', '<=', $filters['date_to']);
        return $query;
    }

    private function sources(string $kind, bool $done, array $f, object $user, array $permissions, bool $super): array
    {
        if ($kind === 'purchase_receipt') {
            $q = DB::table('erp_purchase_receipts as d')->leftJoin('erp_suppliers as supplier', 'supplier.id', '=', 'd.supplier_id')
                ->where('d.receipt_status', 'confirmed')->where('d.confirm_status', 'confirmed')
                ->where('d.stock_post_status', $done ? 'posted' : 'pending')
                ->whereExists(fn ($i) => $i->selectRaw('1')->from('erp_purchase_receipt_items as l')->whereColumn('l.receipt_id', 'd.id')->where('l.is_stock_item_snapshot', 1)->where('l.final_stockable_base_qty', '>', 0));
            $posted = "(SELECT MAX(t.posted_at) FROM erp_inventory_transactions t WHERE t.source_type='purchase_receipt' AND t.source_id=d.id AND t.posting_status='posted')";
            return [$this->row($q, $kind, $done, ['number' => 'd.receipt_no', 'party' => 'supplier.supplier_name', 'completed_at' => $posted], $f,
                ['erp_purchase_receipt_items', 'receipt_id', 'item_id', 'final_stockable_base_qty'])];
        }
        if ($kind === 'output') {
            $visible = $this->inbox->visibleQuery('outputs', $user, $permissions, $super)->select('records.id');
            $q = DB::table('erp_production_output_records as d')->join('erp_work_orders as wo', 'wo.id', '=', 'd.work_order_id')
                ->whereIn('d.id', $visible);
            if ($done) $q->join('erp_production_output_warehouse_postings as posting', 'posting.output_record_id', '=', 'd.id')->where('posting.status', 'POSTED');
            else {
                $q->whereIn('d.status', ['CREATED', 'WAIT_WAREHOUSE'])->where('d.output_mode_snapshot', '<>', 'flow_only')
                    ->where(fn ($q) => $q->where('d.quality_mode_snapshot', 'none')->orWhereExists(fn ($i) => $i->selectRaw('1')
                        ->from('erp_production_quality_inspections as qi')->whereColumn('qi.output_record_id', 'd.id')->where('qi.result', 'passed')));
                // Terminal outputs become receivable only after a genuine approved completion;
                // intermediate outputs keep their existing warehouse flow.
                $q->where(function ($allowed) {
                    $allowed->whereExists(fn ($c) => $c->selectRaw('1')->from('erp_work_order_completion_lines as cl')
                        ->join('erp_work_order_completions as c', 'c.id', '=', 'cl.completion_id')
                        ->whereColumn('cl.output_record_id', 'd.id')->where('c.status', 'APPROVED')->where('cl.qualified_base_qty', '>', 0))
                        ->orWhereNotIn('d.id', app(ProductionOutputService::class)->terminalOutputIdsQuery());
                });
            }
            return [$this->row($q, $kind, $done, ['id' => $done ? 'posting.id' : 'd.id', 'parent_id' => 'd.id', 'number' => 'wo.work_order_no',
                'work_order_no' => 'wo.work_order_no', 'completed_at' => $done ? 'posting.posted_at' : 'NULL',
                'item_id' => 'd.output_item_id', 'quantity' => $done ? 'posting.posted_base_qty' : "COALESCE((SELECT cl.qualified_base_qty - COALESCE((SELECT SUM(fgr.posted_base_qty) FROM erp_work_order_finished_goods_receipts fgr WHERE fgr.completion_line_id=cl.id AND fgr.status='POSTED'),0) FROM erp_work_order_completion_lines cl JOIN erp_work_order_completions c ON c.id=cl.completion_id WHERE cl.output_record_id=d.id AND c.status='APPROVED' ORDER BY cl.id DESC LIMIT 1),d.output_base_qty)"], $f)];
        }
        if ($kind === 'production_return') {
            $visible = $this->inbox->visibleQuery('material_returns', $user, $permissions, $super)->select('records.id');
            $q = DB::table('erp_production_material_returns as d')->join('erp_work_orders as wo', 'wo.id', '=', 'd.work_order_id')->whereIn('d.id', $visible);
            $done ? $q->whereNotNull('d.warehouse_received_at') : $q->where('d.status', 'SUBMITTED');
            return [$this->row($q, $kind, $done, ['number' => 'd.return_no', 'work_order_no' => 'wo.work_order_no',
                'status' => 'd.status', 'completed_at' => 'd.warehouse_received_at'], $f,
                ['erp_production_material_return_lines', 'return_id', 'component_item_id', 'return_base_qty'])];
        }
        if ($kind === 'remnant' || $kind === 'cutting_product') {
            $visible = $this->cutting->visibleOrdersQuery($user, $permissions, $super)->select('o.id');
            if ($kind === 'remnant' && ! $done) {
                $rows = $this->remnants->rowsQuery()->whereIn('b.cutting_order_id', $visible)->whereRaw(CuttingRemnantReceiptService::PENDING_SQL);
                $keyword = trim((string) ($f['keyword'] ?? ''));
                // Search material before grouping; the count remains the number of all pending
                // pieces on that matching order, rather than only matching rows.
                $matches = clone $rows;
                if ($keyword !== '') $matches->where(fn ($w) => $w->where('i.item_name', 'like', '%'.$keyword.'%')->orWhere('i.item_code', 'like', '%'.$keyword.'%')->orWhere('i.spec', 'like', '%'.$keyword.'%'));
                $pending = $rows->selectRaw('b.cutting_order_id, COUNT(*) as piece_count, MIN(i.id) as item_id, COUNT(DISTINCT i.id) as item_count')->groupBy('b.cutting_order_id');
                $q = DB::table('erp_cutting_orders as d')->joinSub($pending, 'pending', 'pending.cutting_order_id', '=', 'd.id')->where('d.status', '<>', 'CANCELLED');
                if ($keyword !== '') $q->where(function ($w) use ($keyword, $matches): void {
                    $w->where('d.cutting_order_no', 'like', '%'.$keyword.'%')->orWhereIn('d.id', $matches->select('b.cutting_order_id'));
                    foreach (['erp_cutting_plan_allocations', 'erp_production_cutting_operations'] as $source)
                        $w->orWhereExists(fn ($s) => $s->selectRaw('1')->from($source.' as source')->join('erp_work_orders as source_wo', 'source_wo.id', '=', 'source.work_order_id')
                            ->whereColumn('source.cutting_order_id', 'd.id')->where('source_wo.work_order_no', 'like', '%'.$keyword.'%'));
                });
                return [$this->row($q, $kind, false, ['number' => 'd.cutting_order_no', 'quantity' => 'pending.piece_count', 'item_id' => 'pending.item_id', 'unit_name' => "'块余料'", 'item_count' => 'pending.item_count'], array_diff_key($f, ['keyword' => 1]))];
            }
            if ($kind === 'remnant') {
                $q = DB::table('erp_cutting_remnant_receipts as d')->join('erp_cutting_orders as o', 'o.id', '=', 'd.cutting_order_id')->whereIn('o.id', $visible)->where('d.status', 'POSTED');
                return [$this->row($q, $kind, true, ['number' => 'd.receipt_no', 'parent_id' => 'o.id', 'related_number' => 'o.cutting_order_no', 'completed_at' => 'd.posted_at'], $f,
                    ['erp_cutting_remnant_receipt_lines', 'receipt_id', 'item_id', 'posted_qty'])];
            }
            $q = DB::table('erp_cutting_result_routes as route')->join('erp_cutting_results as result', 'result.id', '=', 'route.result_id')
                ->join('erp_cutting_settlement_batches as batch', 'batch.id', '=', 'result.settlement_batch_id')
                ->join('erp_cutting_orders as o', 'o.id', '=', 'batch.cutting_order_id')->whereIn('o.id', $visible)->where('route.route_type', 'WAREHOUSE')->whereNull('route.target_material_requirement_id');
            if ($done) $q->join('erp_cutting_warehouse_receipts as d', 'd.route_id', '=', 'route.id')->where('d.status', 'POSTED');
            else $q->join('erp_cutting_orders as d', 'd.id', '=', 'o.id')->whereIn('route.status', ['WAIT_WAREHOUSE', 'PART_WAREHOUSED'])->whereColumn('route.quantity', '>', 'route.warehoused_qty');
            return [$this->row($q, $kind, $done, ['id' => $done ? 'd.id' : 'route.id', 'parent_id' => 'route.id', 'number' => $done ? 'd.receipt_no' : 'o.cutting_order_no',
                'related_number' => 'o.cutting_order_no', 'item_id' => 'result.item_id', 'quantity' => $done ? 'd.posted_qty' : '(route.quantity-route.warehoused_qty)',
                'completed_at' => $done ? 'd.created_at' : 'NULL'], $f)];
        }
        if ($kind === 'picking') {
            $visible = WorkOrder::query()->select('id');
            $this->scope->applyWorkOrderScope($visible, $this->scope->resolve($user, 'production.material_picking.view', $permissions, $super));
            $q = DB::table('erp_material_picking_tasks as d')->join('erp_work_orders as wo', 'wo.id', '=', 'd.work_order_id')
                ->leftJoin('erp_inventory_transactions as tx', 'tx.id', '=', 'd.inventory_transaction_id')->whereIn('d.work_order_id', $visible);
            $done ? $q->whereNotNull('d.inventory_transaction_id') : $q->whereIn('d.status', ['WAIT_PICK', 'PICKING']);
            return [$this->row($q, $kind, $done, ['number' => 'd.task_no', 'work_order_no' => 'wo.work_order_no', 'status' => 'd.status', 'completed_at' => 'tx.posted_at'], $f,
                ['erp_material_picking_task_lines', 'task_id', 'component_item_id', $done ? 'actual_pick_qty' : 'planned_pick_qty'])];
        }
        if ($kind === 'purchase_return') {
            $q = DB::table('erp_purchase_returns as d')->leftJoin('erp_suppliers as supplier', 'supplier.id', '=', 'd.supplier_id');
            $done ? $q->where('d.stock_post_status', 'posted') : $q->where('d.return_status', 'pending_outbound')->where('d.audit_status', 'approved')->where('d.stock_post_status', 'pending');
            return [$this->row($q, $kind, $done, ['number' => 'd.return_no', 'party' => 'supplier.supplier_name', 'completed_at' => 'd.posted_at'], $f,
                ['erp_purchase_return_items', 'return_id', 'item_id', $done ? 'posted_base_qty' : 'approved_base_qty - l.posted_base_qty'])];
        }
        $salesOrders = SalesOrder::query()->select('id'); $this->salesScope->apply($salesOrders, $user);
        if ($kind === 'sales_shipment') {
            $q = DB::table('erp_sales_shipments as d')->join('erp_sales_orders as so', 'so.id', '=', 'd.sales_order_id')->whereIn('d.sales_order_id', $salesOrders);
            $done ? $q->whereNotNull('d.outbound_posted_at') : $q->whereIn('d.shipment_status', ['pending_outbound', 'outbound_posted']);
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

    private function row(Builder $query, string $kind, bool $done, array $fields, array $filters, ?array $lines = null): Builder
    {
        [$title, $direction] = self::TYPES[$kind];
        $columns = array_merge(['id' => 'd.id', 'parent_id' => 'd.id', 'number' => "''", 'related_number' => "''", 'work_order_no' => "''",
            'party' => "''", 'status' => $done ? "'completed'" : "'pending'", 'stage' => "'post'", 'item_id' => 'NULL', 'item_count' => '1',
            'quantity' => 'NULL', 'unit_name' => 'NULL', 'sort_at' => 'd.created_at', 'completed_at' => 'NULL', 'counts_as_posting' => '1'], $fields);
        if ($lines) {
            [$table, $foreign, $item, $quantity] = $lines;
            $parent = 'd.id';
            $columns['item_id'] = "(SELECT MIN(l.$item) FROM $table l WHERE l.$foreign=$parent)";
            $columns['item_count'] = "(SELECT COUNT(DISTINCT l.$item) FROM $table l WHERE l.$foreign=$parent)";
            $columns['quantity'] = "(SELECT SUM(l.$quantity) FROM $table l WHERE l.$foreign=$parent)";
        }
        if ($done) $columns['sort_at'] = $columns['completed_at'];
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $like = '%'.$keyword.'%';
            $query->where(function ($q) use ($columns, $lines, $like) {
                foreach (['number', 'related_number', 'work_order_no', 'party'] as $field) $q->orWhereRaw($columns[$field].' LIKE ?', [$like]);
                if ($lines) {
                    [$table, $foreign, $item] = $lines;
                    $q->orWhereExists(fn ($i) => $i->selectRaw('1')->from($table.' as search_line')->join('erp_items as search_item', 'search_item.id', '=', 'search_line.'.$item)
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
        $data = (array) $row;
        $data['key'] = $row->kind.':'.$row->stage.':'.$row->status_group.':'.$row->id;
        $data['id'] = (int) $row->id; $data['parent_id'] = (int) $row->parent_id;
        $count = (int) $row->item_count;
        $item = $count === 1 && $row->item_id ? DB::table('erp_items as i')->leftJoin('erp_units as u', 'u.id', '=', 'i.unit_id')
            ->where('i.id', $row->item_id)->first(['i.item_name', 'i.item_code', 'i.spec', 'u.unit_name']) : null;
        $data['item_name'] = $item?->item_name ?? ($count > 1 ? '共'.$count.'项物料' : '');
        $data['item_code'] = $item?->item_code; $data['spec'] = $item?->spec;
        $data['unit_name'] = $row->unit_name ?? $item?->unit_name;
        if ($row->kind === 'picking') {
            $context = DB::table('erp_material_picking_tasks as task')->leftJoin('erp_warehouses as warehouse', 'warehouse.id', '=', 'task.warehouse_id')
                ->where('task.id', $row->id)->first(['task.production_location_name_snapshot', 'warehouse.warehouse_name']);
            $data['target_operation'] = $context?->production_location_name_snapshot;
            $data['warehouse_name'] = $context?->warehouse_name;
        }
        // A sum across different items has no usable unit. Do not expose it as a quantity.
        if ($count > 1 && $row->kind !== 'remnant') $data['quantity'] = null;
        $data['status_label'] = $row->kind === 'sales_shipment' ? match ($row->status) {
            'pending_outbound' => '待出库', 'outbound_posted' => '待发运', 'shipped' => '已发运', default => '已出库',
        } : ($row->status_group === 'completed' ? '已完成' : match ($row->kind) {
            'picking' => $row->status === 'PICKING' ? '拣货中' : '待拣货',
            'sales_shipment', 'purchase_return' => '待出库',
            'sales_return' => $row->stage === 'receive' ? '待收货' : '待入库',
            'production_return' => '待接收', default => '待办理',
        });
        return $data;
    }
}
