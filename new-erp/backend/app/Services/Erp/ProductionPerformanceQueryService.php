<?php

namespace App\Services\Erp;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\ProductionPerformanceAssignment;
use App\Models\Erp\SalesOrder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** PC-only read projection: the entire order must actually ship before any amount is calculated. */
final class ProductionPerformanceQueryService
{
    public function __construct(
        private readonly ProductionDataScopeResolver $dataScopes,
        private readonly ProductionShipmentSourceResolver $shipmentSources,
        private readonly ProductionOutputTraceService $outputTrace,
        private readonly ProductionPerformanceScopeService $scopes,
        private readonly ProductionPerformanceApplicationService $assignments,
        private readonly ProductionPerformanceCalculator $calculator,
    ) {}

    public function policy(): array { return $this->calculator->policy(); }

    public function operations(array $filters, object $user, array $permissions): array
    {
        $this->permission($permissions);
        $owner = (int) ($user->legacy_id ?? $user->id ?? 0);
        $queries = [];
        foreach (['unit_operation' => 'erp_production_unit_operations', 'quantity_operation' => 'erp_production_quantity_operations'] as $type => $table) {
            $queries[] = DB::table($table.' as op')->where('op.status', 'COMPLETED')->where(function ($owned) use ($owner, $type): void {
                $owned->where('op.responsible_user_legacy_id', $owner)->orWhere(function ($fallback) use ($owner, $type): void {
                    $fallback->whereNull('op.responsible_user_legacy_id')->whereExists(function ($task) use ($owner, $type): void {
                        $task->selectRaw('1')->from('erp_production_task_targets as link')->join('erp_production_tasks as task', 'task.id', '=', 'link.task_id')
                            ->where('link.target_type', $type)->whereColumn('link.target_id', 'op.id')->where('task.assignee_user_legacy_id', $owner);
                    });
                });
            })->when(! empty($filters['keyword']), fn ($q) => $q->where('op.operation_name_snapshot', 'like', '%'.trim((string) $filters['keyword']).'%'))
                ->selectRaw('? as scope_type, op.id as scope_id, op.completed_at', [$type]);
        }
        $queries[] = DB::table('erp_shipment_packing_operations as op')->where('op.status', 'COMPLETED')->where('op.owner_legacy_id', $owner)
            ->when(! empty($filters['keyword']), fn ($q) => $q->where('op.operation_name_snapshot', 'like', '%'.trim((string) $filters['keyword']).'%'))
            ->selectRaw('? as scope_type, op.id as scope_id, op.completed_at', ['shipment_packing_operation']);
        $union = array_shift($queries);
        foreach ($queries as $query) $union->unionAll($query);
        $page = DB::query()->fromSub($union, 'owned_operations')->orderByDesc('completed_at')->orderByDesc('scope_id')
            ->paginate($this->size($filters), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $rows = [];
        foreach ($page->items() as $item) {
            $scope = $this->scopes->resolve($item->scope_type, (int) $item->scope_id);
            unset($scope['participants']);
            $assignment = ProductionPerformanceAssignment::query()->where('active_scope_key', $item->scope_type.':'.$item->scope_id)->first();
            $rows[] = ['scope' => $scope, 'assignment_version' => $assignment ? (int) $assignment->version_no : 0,
                'credited_share_ratio' => $assignment?->credited_share_ratio, 'noncredited_share_ratio' => $assignment?->noncredited_share_ratio,
                'confirmed_at' => $assignment?->confirmed_at, 'can_confirm' => in_array('production.performance.manage', $permissions, true)];
        }
        return ['data' => $rows, 'meta' => $this->meta($page)];
    }

    public function paginate(array $filters, object $user, array $permissions, bool $superAdmin = false): array
    {
        $query = $this->orders($user, $permissions, $superAdmin)
            ->when(trim((string) ($filters['keyword'] ?? '')) !== '', fn ($q) => $q->where(function ($search) use ($filters): void {
                $keyword = '%'.trim((string) $filters['keyword']).'%';
                $search->where('sales_order_no', 'like', $keyword)->orWhere('customer_name', 'like', $keyword);
            }));
        $lastShipped = DB::table('erp_sales_shipments as last_shipment')->selectRaw('MAX(last_shipment.shipped_at)')
            ->whereColumn('last_shipment.sales_order_id', 'erp_sales_orders.id')->whereIn('last_shipment.shipment_status', ['shipped', 'completed']);
        $query->select('erp_sales_orders.*')->selectSub($lastShipped, 'last_shipped_at');
        if (! empty($filters['shipped_from'])) $query->whereRaw('('.$lastShipped->toSql().') >= ?', array_merge($lastShipped->getBindings(), [$filters['shipped_from'].' 00:00:00']));
        if (! empty($filters['shipped_to'])) $query->whereRaw('('.$lastShipped->toSql().') <= ?', array_merge($lastShipped->getBindings(), [$filters['shipped_to'].' 23:59:59']));
        $page = $query->orderByDesc('erp_sales_orders.id')->paginate($this->size($filters), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $rows = [];
        foreach ($page->items() as $order) {
            $readiness = $this->readiness($order);
            $rows[] = ['id' => (int) $order->id, 'sales_order_no' => $order->sales_order_no, 'customer_name' => $order->customer_name,
                'business_version' => (int) $order->business_version, 'last_shipped_at' => $order->last_shipped_at,
                'readiness' => $readiness, 'statistics_status' => $readiness['entire_order_shipped'] ? 'available' : 'waiting_shipment'];
        }
        return ['data' => $rows, 'meta' => $this->meta($page), 'basis_policy' => $this->policy()];
    }

    public function show(int $id, array $filters, object $user, array $permissions, bool $superAdmin = false): array
    {
        // A consistent read transaction ties the amount, final shipment and current
        // share versions together; reading a mix of concurrent revisions could
        // otherwise report a total that no source facts actually support.
        return DB::transaction(function () use ($id, $filters, $user, $permissions, $superAdmin): array {
            $order = $this->orders($user, $permissions, $superAdmin)->with('lines')->whereKey($id)->first();
            if (! $order) $this->fail('performance_order_not_found', '订单不存在或不在当前绩效统计数据范围内。', 404);
            $readiness = $this->readiness($order);
            $eligibleLineIds = array_column($readiness['lines'], 'sales_order_line_id');
            $lines = $order->lines->keyBy('id');
            $groups = [];
            $issues = [];
            $seenSources = [];
            $sourceSalesQty = [];
            $sourceQuantityConflicts = [];
            foreach ($this->shipmentSources->forOrder($order) as $source) {
                $lineId = (int) ($source['sales_order_line_id'] ?? 0);
                if (! in_array($lineId, $eligibleLineIds, true)) continue;
                $sourceKey = (string) ($source['source_key'] ?? implode(':', [$source['shipment_line_id'], $source['output_record_id'] ?? 0,
                    $source['inventory_serial_id'] ?? 0, $source['base_qty'], $source['sales_qty']]));
                if (isset($seenSources[$sourceKey])) continue;
                $seenSources[$sourceKey] = true;
                $sourceSalesQty[$lineId] = bcadd($sourceSalesQty[$lineId] ?? '0', (string) $source['sales_qty'], 8);
                if (! $this->traceComplete($source['trace_status'] ?? null) || empty($source['output_record_id'])) {
                    $issues[] = ['code' => 'shipment_source_missing', 'sales_order_line_id' => $lineId,
                        'shipment_line_id' => (int) $source['shipment_line_id'], 'message' => '本次发货的生产来源资料不完整。'];
                    continue;
                }
                $contributions = $this->outputTrace->contributions((int) $source['output_record_id'], (string) $source['base_qty']);
                if ($contributions === []) $issues[] = ['code' => 'production_contribution_missing', 'sales_order_line_id' => $lineId,
                    'message' => '未找到可确认的前序生产参与记录。'];
                foreach ($contributions as $contribution) {
                    if (! $this->traceComplete($contribution['trace_status'] ?? null) || ! isset($contribution['basis_fraction'])) {
                        $issues[] = ['code' => 'production_trace_incomplete', 'sales_order_line_id' => $lineId,
                            'output_record_id' => (int) ($contribution['output_record_id'] ?? 0), 'message' => '前序产出来源或对应数量未核对完整。'];
                        continue;
                    }
                    $type = (string) ($contribution['target_type'] ?? '');
                    $targetId = (int) ($contribution['target_id'] ?? 0);
                    $key = $lineId.':'.$type.':'.$targetId;
                    if (! isset($groups[$key])) $groups[$key] = $this->group($key, $lineId, $type, $targetId, $lines->get($lineId), $issues);
                    if ($groups[$key] === null) continue;
                    $groups[$key]['basis_slices'][] = ['sales_qty' => (string) $source['sales_qty'], 'coverage' => (string) $contribution['basis_fraction']];
                    $groups[$key]['source_facts'][] = ['shipment_line_id' => (int) $source['shipment_line_id'],
                        'output_record_id' => (int) $contribution['output_record_id'], 'inventory_serial_id' => $source['inventory_serial_id'] ?? null,
                        'allocated_base_qty' => (string) $contribution['allocated_base_qty'], 'output_base_qty' => (string) $contribution['output_base_qty'],
                        'total_target_output_qty' => (string) $contribution['total_target_output_qty'], 'basis_fraction' => (string) $contribution['basis_fraction']];
                }
            }
            foreach ($readiness['lines'] as $goodsLine) {
                $lineId = $goodsLine['sales_order_line_id'];
                if (bccomp($sourceSalesQty[$lineId] ?? '0', $goodsLine['shipped_sales_qty'], 8) !== 0) {
                    $sourceQuantityConflicts[$lineId] = true;
                    $issues[] = ['code' => 'shipment_source_quantity_mismatch', 'sales_order_line_id' => $lineId,
                        'message' => '实际发货数量与生产来源数量未对应完整。'];
                }
            }
            $this->addPacking($order, $groups, $lines, $issues, $eligibleLineIds);
            $rows = [];
            $employees = [];
            $pool = '0.0000'; $personal = '0.0000'; $excluded = '0.0000';
            foreach (array_filter($groups) as $row) {
                $scope = $row['scope'];
                $assignment = ProductionPerformanceAssignment::query()->with('shares')->where('active_scope_key', $scope['scope_type'].':'.$scope['scope_id'])->first();
                $row['assignment'] = $assignment ? $this->assignments->present($assignment) : null;
                $row['assignment_current'] = $assignment !== null && (int) $assignment->owner_legacy_id === $scope['owner_legacy_id']
                    && (int) ($assignment->scope_snapshot['scope_version'] ?? 0) === $scope['scope_version'];
                $row['can_confirm'] = $scope['status'] === 'COMPLETED' && $scope['owner_legacy_id'] === (int) ($user->legacy_id ?? $user->id ?? 0)
                    && in_array('production.performance.manage', $permissions, true);
                $row['statistics_status'] = ! $row['assignment_current'] ? 'pending_shares'
                    : ($scope['performance_rate_snapshot'] === null ? 'pending_rate' : ($scope['status'] !== 'COMPLETED' ? 'pending_completion' : 'ready'));
                if ($scope['scope_type'] !== 'shipment_packing_operation' && isset($sourceQuantityConflicts[$row['sales_order_line_id']])) $row['statistics_status'] = 'pending_trace';
                $row['basis_amount'] = null; $row['performance_pool_amount'] = null; $row['personal_performance_amount'] = null; $row['noncredited_amount'] = null;
                if ($row['statistics_status'] !== 'ready') $issues[] = ['code' => $row['statistics_status'], 'scope_type' => $scope['scope_type'],
                    'scope_id' => $scope['scope_id'], 'message' => $this->pendingMessage($row['statistics_status'])];
                // There is no provisional monetary result for partial shipments.
                // Ratios and participation facts remain available for confirmation.
                if ($readiness['entire_order_shipped'] && $row['statistics_status'] === 'ready') {
                    $line = $lines->get($row['sales_order_line_id']);
                    $lineAmount = $line->amount_incl_tax;
                    if ($lineAmount === null || (bccomp((string) $lineAmount, '0', 4) === 0 && bccomp((string) ($line->amount ?? 0), '0', 4) > 0)) {
                        $issues[] = ['code' => 'goods_amount_missing', 'sales_order_line_id' => (int) $line->id, 'message' => '商品折后含税金额尚未核对。'];
                        $row['statistics_status'] = 'pending_amount';
                    } else {
                        $basis = '0.000000000000';
                        foreach ($row['basis_slices'] as $slice) $basis = bcadd($basis, $this->calculator->sourceBasis((string) $lineAmount,
                            $slice['sales_qty'], (string) $line->order_qty, $slice['coverage']), 12);
                        if (bccomp($basis, (string) $lineAmount, 4) > 0) {
                            $issues[] = ['code' => 'production_basis_quantity_conflict', 'scope_type' => $scope['scope_type'], 'scope_id' => $scope['scope_id'],
                                'message' => '工序来源覆盖量超过该商品数量，请核对重复或混批来源。'];
                            $row['statistics_status'] = 'pending_trace';
                        } else {
                            $amounts = $this->calculator->amounts($basis, $scope['performance_rate_snapshot'], $row['assignment']['shares']);
                            $row['basis_amount'] = $this->calculator->round($basis);
                            $row = array_replace($row, $amounts);
                            foreach ($amounts['shares'] as $share) {
                                if (! $share['eligible']) continue;
                                $employeeId = $share['employee_legacy_id'];
                                if (! isset($employees[$employeeId])) $employees[$employeeId] = ['employee_legacy_id' => $employeeId,
                                    'employee_name' => $share['employee_name'], 'performance_amount' => '0.0000', 'operations_count' => 0, '_operation_keys' => []];
                                $employees[$employeeId]['performance_amount'] = bcadd($employees[$employeeId]['performance_amount'], $share['performance_amount'], 4);
                                $employees[$employeeId]['_operation_keys'][$scope['scope_type'].':'.$scope['scope_id']] = true;
                                $employees[$employeeId]['operations_count'] = count($employees[$employeeId]['_operation_keys']);
                            }
                            $pool = bcadd($pool, $amounts['performance_pool_amount'], 4);
                            $personal = bcadd($personal, $amounts['personal_performance_amount'], 4);
                            $excluded = bcadd($excluded, $amounts['noncredited_amount'], 4);
                        }
                    }
                }
                unset($row['basis_slices']);
                $rows[] = $row;
            }
            $issues = array_values(array_unique($issues, SORT_REGULAR));
            $status = ! $readiness['entire_order_shipped'] ? 'waiting_shipment' : ($issues === [] ? 'ready' : 'pending_facts');
            // Partial fact sets must not masquerade as an order total. Keep the
            // individual trace rows visible, but withhold all order amounts until
            // every source and personal allocation has been confirmed.
            $completeStatistics = $status === 'ready';
            $employees = array_map(function ($employee) { unset($employee['_operation_keys']); return $employee; }, array_values($employees));
            $fingerprintRows = array_map(function ($row) { unset($row['can_confirm']); return $row; }, $rows);
            $fingerprint = hash('sha256', json_encode([$this->policy(), (int) $order->business_version, $readiness, $fingerprintRows], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
            return ['order' => ['id' => (int) $order->id, 'sales_order_no' => $order->sales_order_no, 'customer_name' => $order->customer_name,
                    'business_version' => (int) $order->business_version], 'readiness' => $readiness, 'basis_policy' => $this->policy(),
                'statistics_status' => $status, 'statistics_complete' => $completeStatistics,
                'performance_pool_amount' => $completeStatistics ? $pool : null, 'personal_performance_amount' => $completeStatistics ? $personal : null,
                'noncredited_amount' => $completeStatistics ? $excluded : null, 'calculation_fingerprint' => $fingerprint,
                'rows' => $this->slice($rows, $filters['page'] ?? 1, $this->size($filters)),
                'employees' => $this->slice($employees, $filters['employees_page'] ?? 1, $this->size($filters)),
                'issues' => $this->slice($issues, $filters['issues_page'] ?? 1, $this->size($filters))];
        });
    }

    public function scope(string $type, int $id, array $filters, object $user, array $permissions, bool $superAdmin = false): array
    {
        $this->permission($permissions);
        $scope = $this->scopes->resolve($type, $id);
        // The participant picker is deliberately restricted to the accountable
        // owner. Statistics viewers inspect source rows, not arbitrary identities.
        if ($scope['owner_legacy_id'] !== (int) ($user->legacy_id ?? $user->id ?? 0)) $this->fail('performance_owner_required', '只有工序接单人可以打开个人份额确认。', 403);
        $assignment = ProductionPerformanceAssignment::query()->with('shares')->where('active_scope_key', $type.':'.$id)->first();
        $participants = $scope['participants']; unset($scope['participants']);
        return ['scope' => $scope, 'participants' => $this->slice($participants, $filters['page'] ?? 1, $this->size($filters)),
            'assignment' => $assignment ? $this->assignments->present($assignment) : null];
    }

    private function group(string $key, int $lineId, string $type, int $id, ?object $line, array &$issues): ?array
    {
        try { $scope = $this->scopes->resolve($type, $id); }
        catch (WorkOrderDomainException $e) {
            $issues[] = ['code' => $e->errorCode, 'scope_type' => $type, 'scope_id' => $id, 'message' => $e->getMessage()];
            return null;
        }
        return ['row_key' => $key, 'sales_order_line_id' => $lineId, 'product_name' => $line->product_name ?? null,
            'sku_name' => $line->sku_name ?? null, 'item_name' => $line->item_name ?? null, 'scope' => $scope,
            'basis_slices' => [], 'source_facts' => []];
    }

    private function addPacking(SalesOrder $order, array &$groups, $lines, array &$issues, array $eligibleLineIds): void
    {
        $ops = DB::table('erp_shipment_packing_operations as op')->join('erp_sales_shipments as shipment', 'shipment.id', '=', 'op.shipment_id')
            ->where('shipment.sales_order_id', $order->id)->whereIn('shipment.shipment_status', ['shipped', 'completed'])
            ->whereNotNull('shipment.shipped_at')->where('op.status', '<>', 'CANCELLED')->whereExists(function ($posted): void {
                $posted->selectRaw('1')->from('erp_inventory_transactions as posted')->whereColumn('posted.source_id', 'shipment.id')
                    ->where('posted.source_type', 'sales_shipment')->where('posted.transaction_type', 'sales_shipment_outbound')->where('posted.posting_status', 'posted');
            })->get(['op.*']);
        foreach ($ops as $op) {
            $contentIds = json_decode((string) $op->packing_content_ids, true);
            if (! is_array($contentIds) || $contentIds === []) {
                $issues[] = ['code' => 'packing_content_missing', 'scope_id' => (int) $op->id, 'message' => '包装工序的产品及数量对应记录不完整。'];
                continue;
            }
            $contents = DB::table('erp_shipment_packing_contents as content')
                ->join('erp_sales_shipment_lines as line', 'line.id', '=', 'content.shipment_line_id')
                ->whereIn('content.id', $contentIds)->where('content.shipment_id', $op->shipment_id)->where('content.package_id', $op->package_id)
                ->get(['content.*', 'line.base_qty as shipment_base_qty', 'line.sales_qty as shipment_sales_qty']);
            if ($contents->count() !== count(array_unique($contentIds))) {
                $issues[] = ['code' => 'packing_content_mismatch', 'scope_id' => (int) $op->id, 'message' => '包装内容与对应发货单不一致。'];
                continue;
            }
            foreach ($contents as $content) {
                $lineId = (int) $content->sales_order_line_id;
                if (! in_array($lineId, $eligibleLineIds, true)) continue;
                if (bccomp((string) $content->shipment_base_qty, '0', 8) <= 0) {
                    $issues[] = ['code' => 'packing_quantity_invalid', 'scope_id' => (int) $op->id, 'message' => '包装产品与销售数量的换算记录不完整。']; continue;
                }
                $key = $lineId.':shipment_packing_operation:'.$op->id;
                if (! isset($groups[$key])) $groups[$key] = $this->group($key, $lineId, 'shipment_packing_operation', (int) $op->id, $lines->get($lineId), $issues);
                if ($groups[$key] === null) continue;
                $salesQty = bcdiv(bcmul((string) $content->shipment_sales_qty, (string) $content->base_qty, 12), (string) $content->shipment_base_qty, 8);
                $groups[$key]['basis_slices'][] = ['sales_qty' => $salesQty, 'coverage' => '1.00000000'];
                $groups[$key]['source_facts'][] = ['packing_content_id' => (int) $content->id, 'shipment_line_id' => (int) $content->shipment_line_id,
                    'package_id' => (int) $content->package_id, 'base_qty' => (string) $content->base_qty, 'sales_qty' => $salesQty,
                    'serial_snapshot' => json_decode((string) $content->serial_snapshot, true)];
            }
        }
    }

    private function readiness(SalesOrder $order): array
    {
        $shipped = DB::table('erp_sales_shipment_lines as line')->join('erp_sales_shipments as shipment', 'shipment.id', '=', 'line.shipment_id')
            ->where('shipment.sales_order_id', $order->id)->whereIn('shipment.shipment_status', ['shipped', 'completed'])->whereNotNull('shipment.shipped_at')
            ->whereExists(function ($posted): void {
                $posted->selectRaw('1')->from('erp_inventory_transactions as posted')->whereColumn('posted.source_id', 'shipment.id')
                    ->where('posted.source_type', 'sales_shipment')->where('posted.transaction_type', 'sales_shipment_outbound')->where('posted.posting_status', 'posted');
            })->groupBy('line.sales_order_line_id')->selectRaw('line.sales_order_line_id, SUM(line.sales_qty) as sales_qty, MAX(shipment.shipped_at) as last_shipped_at')->get()->keyBy('sales_order_line_id');
        $order->loadMissing('lines');
        $result = $this->calculator->shipmentReadiness($order->lines->map(fn ($line) => $line->only(['id', 'line_type', 'line_status', 'order_qty', 'no_delivery_qty', 'service_fulfilled_qty']))->all(),
            $shipped->map(fn ($row) => (array) $row)->all());
        $result['entire_order_shipped_at'] = $result['entire_order_shipped'] ? $shipped->max('last_shipped_at') : null;
        return $result;
    }

    private function orders(object $user, array $permissions, bool $superAdmin): Builder
    {
        $this->permission($permissions);
        $scope = $this->dataScopes->resolve($user, 'production.performance.view', $permissions, $superAdmin);
        $query = SalesOrder::query()->whereIn('order_status', ['confirmed', 'in_progress', 'completed']);
        if (($scope['mode'] ?? 'deny') === 'all') return $query;
        if (($scope['mode'] ?? 'deny') === 'deny') return $query->whereRaw('1 = 0');
        $ids = $scope['user_ids'];
        return $query->where(function ($visible) use ($ids): void {
            $visible->whereExists(function ($workOrders) use ($ids): void {
                $workOrders->selectRaw('1')->from('erp_work_orders as wo')->leftJoin('erp_sales_order_production_requirements as demand', 'demand.id', '=', 'wo.production_demand_id')
                    ->where(function ($order): void {
                        $order->whereColumn('demand.sales_order_id', 'erp_sales_orders.id')
                            ->orWhere(fn ($source) => $source->where('wo.source_type', 'sales_order')->whereColumn('wo.source_id', 'erp_sales_orders.id'));
                    })->where(function ($responsible) use ($ids): void {
                        $responsible->whereIn('wo.responsible_user_legacy_id', $ids)->orWhereExists(function ($tasks) use ($ids): void {
                            $tasks->selectRaw('1')->from('erp_production_tasks as task')->whereColumn('task.work_order_id', 'wo.id')->whereIn('task.assignee_user_legacy_id', $ids);
                        });
                    });
            })->orWhereExists(function ($packing) use ($ids): void {
                $packing->selectRaw('1')->from('erp_shipment_packing_operations as op')->join('erp_sales_shipments as shipment', 'shipment.id', '=', 'op.shipment_id')
                    ->whereColumn('shipment.sales_order_id', 'erp_sales_orders.id')->whereIn('op.owner_legacy_id', $ids);
            });
        });
    }

    private function traceComplete(mixed $status): bool { return in_array($status, ['complete', 'COMPLETE', 'traced', 'ready', '完整'], true); }
    private function pendingMessage(string $status): string
    {
        return ['pending_shares' => '个人份额或不计绩效状态尚未确认。', 'pending_rate' => '来源工序未冻结绩效比例。',
            'pending_completion' => '来源工序尚未完成。'][$status] ?? '绩效来源资料尚未完整。';
    }
    private function size(array $filters): int { return max(1, min(100, (int) ($filters['per_page'] ?? 20))); }
    private function slice(array $rows, mixed $page, int $size): array
    {
        $page = max(1, (int) $page);
        return ['data' => array_slice($rows, ($page - 1) * $size, $size), 'meta' => ['total' => count($rows), 'current_page' => $page,
            'per_page' => $size, 'last_page' => max(1, (int) ceil(count($rows) / $size))]];
    }
    private function meta($page): array { return ['total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage(), 'last_page' => $page->lastPage()]; }
    private function permission(array $permissions): void { if (! in_array('production.performance.view', $permissions, true)) $this->fail('permission_denied', '当前用户没有查看个人绩效统计的权限。', 403); }
    private function fail(string $code, string $message, int $status = 422): never { throw new WorkOrderDomainException($code, $message, $status); }
}
