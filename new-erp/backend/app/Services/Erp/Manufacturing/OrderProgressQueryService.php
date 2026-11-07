<?php

namespace App\Services\Erp\Manufacturing;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Models\Erp\Item;
use App\Models\Erp\MfgLaborSession;
use App\Models\Erp\MfgMaterialDelivery;
use App\Models\Erp\MfgMaterialReturn;
use App\Models\Erp\MfgTaskParticipant;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLink;
use App\Models\Erp\MfgWorkOrderMaterial;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\ProductionOperationWorker;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * 车间公共订单进度：所有登录员工都能看，不按接单人筛选（工序列表接单后别人就看不到了）。
 * 只读视图，明确投影生产字段，避开销售详情和会同步状态的任务查询。
 */
final class OrderProgressQueryService
{
    private const WORK_ORDER_FIELDS = [
        'id', 'sales_order_line_id', 'work_order_no', 'product_name', 'sku_name', 'item_name', 'status',
        'source_type', 'started_at', 'rework_of_work_order_id', 'planned_qty', 'unit_name',
    ];

    /** 按台视为已做完：完工待入库、待仓库确认、已入库。 */
    private const DONE_UNITS = ['completed', 'pending_receipt', 'stored'];

    public function paginate(array $filters): array
    {
        return ($filters['scope'] ?? 'sales') === 'stock' ? $this->paginateStock($filters) : $this->paginateSales($filters);
    }

    private function paginateSales(array $filters): array
    {
        $keyword = trim($filters['keyword'] ?? '');
        $page = SalesOrder::query()->select(['id', 'sales_order_no'])
            ->whereExists($this->productionOrders()->whereColumn('sales_order_id', 'erp_sales_orders.id')->selectRaw('1'))
            ->when($keyword !== '', function ($q) use ($keyword) {
                $like = '%'.addcslashes($keyword, '\\%_').'%';
                $q->where(fn ($q) => $q->where('sales_order_no', 'like', $like)
                    ->orWhereExists($this->productionOrders()->whereColumn('sales_order_id', 'erp_sales_orders.id')->selectRaw('1')
                        ->where(fn ($q) => $q->where('product_name', 'like', $like)->orWhere('sku_name', 'like', $like)->orWhere('item_name', 'like', $like))));
            })
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 10);

        $roots = $this->productionOrders()->whereIn('sales_order_id', $page->getCollection()->modelKeys())->orderBy('id')
            ->get(array_merge(self::WORK_ORDER_FIELDS, ['sales_order_id']))->groupBy('sales_order_id');
        $page->through(function (SalesOrder $order) use ($roots) {
            $rows = $roots->get($order->id, collect());
            $counts = $rows->countBy('status');
            $finished = (int) (($counts['completed'] ?? 0) + ($counts['closed'] ?? 0));
            $status = match (true) {
                isset($counts['in_progress']) => 'in_progress',
                isset($counts['paused']) => 'paused',
                isset($counts['released']) => 'released',
                isset($counts['planned']) => 'planned',
                $finished > 0 => 'completed',
                default => 'cancelled',
            };

            return [
                'id' => $order->id,
                'order_no' => $order->sales_order_no,
                'products' => $rows->map(fn ($row) => $this->name($row))->unique()->sort()->implode('、'),
                'status_label' => WorkOrderQueryService::STATUS_LABELS[$status],
                'summary' => '主工单 '.$rows->count().' 张 · 已完成 '.$finished.' 张',
            ] + $this->overview($rows, (int) $order->id);
        });

        return $page->toArray();
    }

    /** 备货工单没有销售订单，以顶层备货工单为一行；随单补开的子工单挂在其下，不单独成行。 */
    private function paginateStock(array $filters): array
    {
        $keyword = trim($filters['keyword'] ?? '');
        $page = MfgWorkOrder::query()->where('source_type', 'stock')
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_work_order_links as l')->whereColumn('l.child_work_order_id', 'erp_mfg_work_orders.id'))
            ->when($keyword !== '', function ($q) use ($keyword) {
                $like = '%'.addcslashes($keyword, '\\%_').'%';
                $q->where(fn ($q) => $q->where('work_order_no', 'like', $like)->orWhere('product_name', 'like', $like)
                    ->orWhere('sku_name', 'like', $like)->orWhere('item_name', 'like', $like));
            })
            ->orderByDesc('id')->paginate($filters['per_page'] ?? 10, self::WORK_ORDER_FIELDS);

        $page->through(fn (MfgWorkOrder $wo) => [
            'id' => $wo->id,
            'order_no' => $wo->work_order_no,
            'products' => $this->name($wo),
            'status_label' => WorkOrderQueryService::STATUS_LABELS[$wo->status] ?? '待处理',
            'summary' => '备货工单',
        ] + $this->overview(collect([$wo]), null));

        return $page->toArray();
    }

    /**
     * 列表行不展开就能看到：按台完成情况，以及整棵工单树（含子工单）当前谁在做哪道。
     * @return array{unit_text: string, current_text: string}
     */
    private function overview(Collection $roots, ?int $salesOrderId): array
    {
        $active = $roots->where('status', '!=', 'cancelled')->keyBy('id');
        if ($active->isEmpty()) return ['unit_text' => '', 'current_text' => ''];

        $units = MfgWorkOrderUnit::query()->whereIn('work_order_id', $active->keys())->whereNotIn('status', ['scrapped', 'cancelled'])
            ->get(['status', 'started_at']);
        // 同一订单可能混有台、个等不同单位，不同时不硬套第一个单位。
        $unitNames = $active->map(fn ($wo) => $wo->unit_name ?: '台')->unique();
        $unitName = $unitNames->count() === 1 ? $unitNames->first() : '件';
        if ($units->isEmpty()) {
            $unitText = '计划 '.$this->qty($active->sum(fn ($wo) => (float) $wo->planned_qty)).' '.$unitName.' · 尚未生成设备编号';
        } else {
            $done = $units->whereIn('status', self::DONE_UNITS)->count();
            // 从未开过工的才算未开工；做完一道等下一道的仍算在制。
            $notStarted = $units->where('status', 'waiting')->whereNull('started_at')->count();
            $unitText = '共 '.$units->count().' '.$unitName.' · 已完工 '.$done.' · 在制 '.($units->count() - $done - $notStarted).' · 未开工 '.$notStarted;
        }

        [$orders] = $this->workOrderTree($active->values(), $salesOrderId);
        $current = $this->currentOperations($orders->keys());
        $parts = [];
        foreach ($orders as $id => $wo) {
            if (empty($current[$id])) continue;
            $prefix = $active->has($id) ? ($active->count() > 1 ? $this->name($wo).'：' : '') : $this->name($wo).'(子)：';
            $parts[] = $prefix.implode('；', $current[$id]);
        }

        return ['unit_text' => $unitText, 'current_text' => implode(' ｜ ', $parts)];
    }

    /**
     * 工单当前做到哪道、谁接单、谁在计时。工序列表上级卡片与订单进度共用这一份口径。
     * @return array<int, string[]> 工单 ID => 进行中工序描述
     */
    public function currentOperations(Collection $workOrderIds): array
    {
        $ops = MfgWorkOrderOperation::query()->whereIn('work_order_id', $workOrderIds)->where('execution_context', 'production')
            ->where('status', '!=', 'done')->orderBy('sequence')->orderBy('id')->get(['id', 'work_order_id', 'operation_name']);
        if ($ops->isEmpty()) return [];
        $ids = $ops->modelKeys();
        $here = MfgWorkOrderUnit::query()->whereIn('current_operation_id', $ids)->whereIn('status', TaskService::SELECTABLE)
            ->selectRaw('current_operation_id, COUNT(*) AS qty')->groupBy('current_operation_id')->pluck('qty', 'current_operation_id');
        $owners = MfgTaskParticipant::query()->whereIn('work_order_operation_id', $ids)->where('lane', 'normal')
            ->where('role', 'owner')->where('is_active', true)->pluck('employee_name', 'work_order_operation_id');
        $timing = MfgLaborSession::query()->whereIn('work_order_operation_id', $ids)->where('status', 'active')
            ->get(['work_order_operation_id', 'employee_name'])->groupBy('work_order_operation_id');

        return $ops->filter(fn ($op) => (int) ($here[$op->id] ?? 0) > 0 || $timing->has($op->id))
            ->groupBy('work_order_id')
            ->map(fn ($rows) => $rows->map(function ($op) use ($owners, $timing) {
                $names = $timing->get($op->id, collect())->pluck('employee_name')->filter()->unique()->values();
                $owner = $owners[$op->id] ?? null;
                $state = $names->isNotEmpty() ? $names->implode('、').' 计时中' : ($owner ? $owner.' 已接单' : '待接单');
                return $op->operation_name.' · '.$state;
            })->values()->all())
            ->all();
    }

    public function show(int $id, ?int $executorId, string $scope = 'sales'): array
    {
        if ($scope === 'stock') {
            $roots = MfgWorkOrder::query()->where('source_type', 'stock')->whereKey($id)->get(self::WORK_ORDER_FIELDS);
            if ($roots->isEmpty()) throw new WorkOrderDomainException('not_found', '该备货工单不存在。', 404);
            $orderNo = $roots->first()->work_order_no;
            $salesOrderId = null;
            $cancelled = false;
        } else {
            $order = SalesOrder::query()->findOrFail($id, ['id', 'sales_order_no', 'order_status']);
            $roots = $this->productionOrders()->where('sales_order_id', $id)->orderBy('id')->get(self::WORK_ORDER_FIELDS);
            if ($roots->isEmpty()) {
                throw new WorkOrderDomainException('not_found', '该订单暂无生产工单。', 404);
            }
            $orderNo = $order->sales_order_no;
            $salesOrderId = $id;
            $cancelled = $order->order_status === 'cancelled';
        }

        [$workOrders, $children] = $this->workOrderTree($roots, $salesOrderId);
        $operations = $this->operations($workOrders, $executorId, $cancelled);
        $units = $this->units($workOrders);
        $progress = $this->operationProgress($workOrders);
        $node = function (int $id, array $path = []) use (&$node, $workOrders, $children, $operations, $units, $progress): array {
            $wo = $workOrders[$id];
            $path[$id] = true;
            $childIds = array_values(array_filter($children[$id] ?? [], fn ($childId) => ! isset($path[$childId])));

            return [
                'id' => $wo->id,
                'work_order_no' => $wo->work_order_no,
                'name' => $this->name($wo),
                'status_label' => WorkOrderQueryService::STATUS_LABELS[$wo->status] ?? '待处理',
                'planned_text' => $this->qty((float) $wo->planned_qty).' '.($wo->unit_name ?: '台'),
                'operation_progress' => $progress[$id] ?? '',
                'units' => $units->get($id, []),
                'operations' => $operations->get($id, collect())->all(),
                'children' => array_map(fn ($childId) => $node($childId, $path), $childIds),
            ];
        };

        if ($scope === 'stock') {
            $result = [['id' => null, 'name' => $this->name($roots->first()), 'work_orders' => [$node((int) $roots->first()->id)]]];
            return ['id' => $id, 'order_no' => $orderNo, 'lines' => $result];
        }

        $byLine = $roots->toBase()->groupBy(fn ($wo) => (int) $wo->sales_order_line_id);
        $lines = SalesOrderLine::query()->where('sales_order_id', $id)->whereIn('id', $byLine->keys())
            ->orderBy('sort_order')->orderBy('id')->get(['id', 'product_name', 'sku_name', 'item_name']);
        $result = $lines->map(fn ($line) => [
            'id' => $line->id,
            'name' => $this->name($line),
            'work_orders' => $byLine[$line->id]->map(fn ($wo) => $node((int) $wo->id))->values()->all(),
        ])->all();

        // 历史工单可能只有订单 ID，仍保留其真实工单和生产进度。
        foreach ($byLine->except($lines->modelKeys()) as $lineId => $rows) {
            $result[] = [
                'id' => $lineId ?: null,
                'name' => '未关联商品行',
                'work_orders' => $rows->map(fn ($wo) => $node((int) $wo->id))->values()->all(),
            ];
        }

        return ['id' => $order->id, 'order_no' => $orderNo, 'lines' => $result];
    }

    /**
     * 按台进度：每台设备当前在哪道、状态、谁在计时。计时人员按计时记录里勾选的设备编号对应，
     * 不按工序推断，避免协同人只做其中几台时把所有台都显示成他在做。
     */
    private function units(Collection $orders): Collection
    {
        $units = MfgWorkOrderUnit::query()->whereIn('work_order_id', $orders->keys())->where('status', '!=', 'cancelled')
            ->orderBy('unit_no')->orderBy('id')->get(['id', 'work_order_id', 'unit_no', 'serial_no', 'status', 'current_operation_id', 'started_at']);
        if ($units->isEmpty()) return collect();
        $opNames = MfgWorkOrderOperation::query()->whereKey($units->pluck('current_operation_id')->filter()->unique()->values()->all())
            ->pluck('operation_name', 'id');
        $workers = [];
        foreach (MfgLaborSession::query()->whereIn('work_order_id', $orders->keys())->where('status', 'active')->get(['unit_ids', 'employee_name']) as $session) {
            foreach ($session->unit_ids ?: [] as $unitId) $workers[(int) $unitId][] = $session->employee_name;
        }

        return $units->groupBy('work_order_id')->map(fn ($rows) => $rows->map(function ($u) use ($opNames, $workers) {
            $names = array_values(array_unique(array_filter($workers[$u->id] ?? [])));
            $atOperation = in_array($u->status, TaskService::SELECTABLE, true);
            $status = match (true) {
                $names !== [] => '计时中',
                $u->status === 'waiting' && $u->started_at !== null => '等待本道加工',
                default => WorkOrderQueryService::UNIT_LABELS[$u->status] ?? $u->status,
            };

            return [
                'id' => $u->id,
                'serial_no' => $u->serial_no ?: '第 '.$u->unit_no.' 台',
                'operation_name' => $atOperation ? ($opNames[$u->current_operation_id] ?? '') : '',
                'status_label' => $status,
                'worker_names' => implode('、', $names),
            ];
        })->values()->all());
    }

    /** 工序完成情况，如「下料 3/3 → 焊接 2/3」。 */
    private function operationProgress(Collection $orders): array
    {
        return MfgWorkOrderOperation::query()->whereIn('work_order_id', $orders->keys())->where('execution_context', 'production')
            ->orderBy('sequence')->orderBy('id')->get(['work_order_id', 'operation_name', 'completed_qty'])
            ->groupBy('work_order_id')
            ->map(fn ($rows, $orderId) => $rows->map(fn ($op) => $op->operation_name.' '.$this->qty((float) $op->completed_qty)
                .'/'.$this->qty((float) $orders[$orderId]->planned_qty))->implode(' → '))
            ->all();
    }

    private function qty(float $value): string
    {
        return rtrim(rtrim(number_format($value, 4, '.', ''), '0'), '.') ?: '0';
    }

    private function productionOrders()
    {
        // 随单补开的子工单可能仅复制了订单号码，正式归属从工单关系表读取。
        return MfgWorkOrder::query()->where('source_type', 'sales_order');
    }

    private function workOrderTree(Collection $roots, int $salesOrderId): array
    {
        $roots = $roots->keyBy('id');
        $orders = $roots;
        $frontier = $orders->keys()->all();
        $children = [];
        while ($frontier) {
            $links = MfgWorkOrderLink::query()->whereIn('parent_work_order_id', $frontier)
                ->orderBy('id')->get(['parent_work_order_id', 'child_work_order_id']);
            $next = $links->pluck('child_work_order_id')->diff($orders->keys())->unique()->values()->all();
            $new = MfgWorkOrder::query()->whereKey($next)
                ->where(fn ($q) => $q->whereNull('sales_order_id')->orWhere('sales_order_id', $salesOrderId))
                ->get(self::WORK_ORDER_FIELDS)->keyBy('id');
            $orders = $orders->union($new);
            foreach ($links as $link) {
                if ($orders->has($link->child_work_order_id) && ! $roots->has($link->child_work_order_id)) {
                    $children[$link->parent_work_order_id][] = (int) $link->child_work_order_id;
                }
            }
            $frontier = $new->keys()->all();
        }

        return [$orders, $children];
    }

    private function operations(Collection $orders, ?int $executorId, bool $salesOrderCancelled): Collection
    {
        $ops = MfgWorkOrderOperation::query()->whereIn('work_order_id', $orders->keys())
            ->where('execution_context', 'production')->orderBy('sequence')->orderBy('id')
            ->get(['id', 'work_order_id', 'operation_id', 'operation_name', 'status', 'work_status', 'completed_qty']);
        $ids = $ops->modelKeys();
        $participants = MfgTaskParticipant::query()->whereIn('work_order_operation_id', $ids)->where('is_active', true)->orderBy('id')
            ->get(['work_order_operation_id', 'lane', 'role', 'employee_legacy_id', 'employee_name'])->groupBy('work_order_operation_id');
        $timing = MfgLaborSession::query()->whereIn('work_order_operation_id', $ids)->where('status', 'active')->orderBy('id')
            ->get(['work_order_operation_id', 'lane', 'employee_legacy_id', 'employee_name'])->groupBy('work_order_operation_id');
        $units = $this->operationUnits($ids);
        $materials = $this->materials($ids);
        $workers = $executorId === null ? collect() : ProductionOperationWorker::query()
            ->whereIn('operation_id', $ops->pluck('operation_id')->filter()->unique())
            ->get(['operation_id', 'employee_legacy_id'])->groupBy('operation_id');
        $repairing = $executorId === null ? collect() : DB::table('erp_shipment_reworks')->where('status', 'in_progress')
            ->whereIn('work_order_id', $orders->where('status', 'completed')->keys())->distinct()->pluck('work_order_id')->flip();

        return $ops->groupBy('work_order_id')->map(function ($rows, $orderId) use ($orders, $participants, $timing, $units, $materials, $workers, $repairing, $executorId, $salesOrderCancelled) {
            $wo = $orders[$orderId];
            $runnable = $executorId !== null
                && (in_array($wo->status, ['released', 'in_progress'], true) || $repairing->has($orderId))
                && ! ($salesOrderCancelled && $wo->source_type === 'sales_order' && ! $wo->started_at);

            return $rows->flatMap(function ($op) use ($wo, $participants, $timing, $units, $materials, $workers, $runnable, $executorId) {
                $rows = [];
                foreach ($wo->rework_of_work_order_id ? ['rework'] : TaskService::LANES as $lane) {
                    $people = $participants->get($op->id, collect())->where('lane', $lane);
                    $sessions = $timing->get($op->id, collect())->where('lane', $lane);
                    $here = $units->get($op->id, collect())->whereIn('status', TaskService::laneStatuses($lane));
                    if ($lane === 'rework' && ! $wo->rework_of_work_order_id && $here->isEmpty() && $sessions->isEmpty()) continue;

                    $done = $op->status === 'done' || $op->work_status === 'done';
                    $current = ! $done && ($here->isNotEmpty() || $sessions->isNotEmpty());
                    $ready = (int) $here->sum('ready') > 0;
                    $owner = $people->firstWhere('role', 'owner');
                    $eligible = $people->whereIn('role', ['owner', 'collaborator'])->contains('employee_legacy_id', $executorId)
                        || (! $owner && (! $workers->has($op->operation_id) || $workers[$op->operation_id]->contains('employee_legacy_id', $executorId)));
                    $status = match (true) {
                        $done => '已完成',
                        $current && $wo->status === 'paused' => '已暂停',
                        $sessions->isNotEmpty() => '加工中',
                        $current && ! $ready => '待处理',
                        $current && $owner !== null => '已接单',
                        $current => '待接单',
                        $lane === 'normal' && ($op->status === 'in_progress' || (float) $op->completed_qty > 0) => '等待前道交接',
                        default => '未到工序',
                    };
                    $rows[] = [
                        'id' => $op->id,
                        'name' => TaskService::laneTitle($op->operation_name, $lane),
                        'status_label' => $status,
                        'is_current' => $current,
                        'owner_name' => $owner?->employee_name,
                        'timing_names' => $sessions->unique('employee_legacy_id')->pluck('employee_name')->filter()->values()->all(),
                        'can_open' => $runnable && $current && $eligible && $ready,
                        'lane' => $lane,
                        'materials' => $lane === 'normal' ? $materials->get($op->id, []) : [],
                    ];
                }

                return $rows;
            })->values();
        });
    }

    private function operationUnits(array $operationIds): Collection
    {
        return DB::table('erp_mfg_work_order_units as u')->whereIn('u.current_operation_id', $operationIds)
            ->join('erp_mfg_work_order_operations as o', function ($join) {
                $join->on('o.id', '=', 'u.current_operation_id')->on('o.work_order_id', '=', 'u.work_order_id');
            })
            ->whereIn('u.status', TaskService::SELECTABLE)->groupBy('u.current_operation_id', 'u.status')
            // 与报工入口一致，整单或逐台变更待处置、待报废审核的编号不能办理工序。
            ->selectRaw("u.current_operation_id, u.status, SUM(CASE WHEN NOT EXISTS (
                SELECT 1 FROM erp_cancellation_production_dispositions d
                WHERE d.work_order_id = u.work_order_id AND d.resolved_at IS NULL
                    AND (d.work_order_unit_id IS NULL OR d.work_order_unit_id = u.id)
            ) AND NOT EXISTS (
                SELECT 1 FROM erp_mfg_unit_reports r WHERE r.unit_id = u.id AND r.review_status = 'pending'
            ) THEN 1 ELSE 0 END) AS ready")
            ->get()->groupBy('current_operation_id');
    }

    private function materials(array $operationIds): Collection
    {
        $materials = MfgWorkOrderMaterial::query()->whereIn('work_order_operation_id', $operationIds)
            ->where('required_qty', '>', 0)->where('participates_in_kitting', true)
            ->where(fn ($q) => $q->whereNull('cutting_mode')->orWhereNotIn('cutting_mode', ['length', 'sheet']))
            ->orderBy('id')->get(['id', 'work_order_operation_id', 'component_item_id', 'component_item_name',
                'component_item_code', 'unit_name', 'required_qty', 'issued_qty']);
        $items = Item::query()->whereKey($materials->pluck('component_item_id')->unique()->all())
            ->get(['id', 'item_type', 'manufacturing_strategy', 'management_scope', 'is_stock_item', 'is_purchase_item', 'is_production_item'])->keyBy('id');
        $materials = $materials->filter(fn ($m) => ($item = $items->get($m->component_item_id)) && WorkOrderService::isChildOrderItem($item));
        if ($materials->isEmpty()) return collect();

        // 领料行汇总已包含实际直交和核准退料。已发出但车间还没签收的配送需另扣除。
        $unsigned = $this->unsignedMaterials($materials->pluck('work_order_operation_id')->unique()->all());

        return $materials->groupBy('work_order_operation_id')->map(fn ($rows) => $rows->map(fn ($m) => [
                'name' => $m->component_item_name ?: $m->component_item_code,
                'received_qty' => max(0, round((float) $m->issued_qty - ($unsigned[$m->id] ?? 0), 4)),
                'required_qty' => (float) $m->required_qty,
                'unit_name' => $m->unit_name,
            ])->values()->all());
    }

    private function unsignedMaterials(array $operationIds): array
    {
        $deliveries = MfgMaterialDelivery::query()->whereIn('work_order_operation_id', $operationIds)->whereNotNull('material_issue_id')
            ->where(fn ($q) => $q->whereIn('status', ['dispatched', 'rejected'])
                ->orWhere(fn ($r) => $r->where('status', 'recalled')
                    ->whereIn('material_return_id', MfgMaterialReturn::query()->where('status', 'pending')->select('id'))))
            ->get(['material_issue_id', 'lines']);
        if ($deliveries->isEmpty()) return [];

        $returns = DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
            ->where('r.status', 'approved')->whereIn('l.issue_id', $deliveries->pluck('material_issue_id')->unique())
            ->groupBy('l.issue_id', 'l.material_id')->selectRaw('l.issue_id, l.material_id, SUM(l.qty) AS qty')->get()
            ->groupBy('issue_id')->map(fn ($rows) => $rows->pluck('qty', 'material_id'));
        $unsigned = [];
        foreach ($deliveries as $delivery) {
            $returned = $returns->get($delivery->material_issue_id, collect());
            $lines = collect($delivery->lines)->groupBy('material_id');
            foreach ($lines as $materialId => $rows) {
                $outstanding = max(0, (float) $rows->sum('qty') - (float) ($returned[$materialId] ?? 0));
                $unsigned[$materialId] = ($unsigned[$materialId] ?? 0) + $outstanding;
            }
        }

        return $unsigned;
    }

    private function name(object $row): string
    {
        return $row->product_name ?: ($row->sku_name ?: ($row->item_name ?: '未命名商品'));
    }
}
