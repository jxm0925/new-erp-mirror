<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Bom;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Item;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgCuttingBatch;
use App\Models\Erp\MfgMaterialIssue;
use App\Models\Erp\MfgMaterialReturn;
use App\Models\Erp\MfgDrawing;
use App\Models\Erp\MfgLaborSession;
use App\Models\Erp\MfgTaskParticipant;
use App\Models\Erp\MfgUnitReport;
use App\Models\Erp\MfgWorkOrderLink;
use App\Models\Erp\MfgWorkOrderLog;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderTech;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Warehouse;
use App\Services\Erp\BomMatcher;
use Illuminate\Support\Facades\DB;
use App\Services\Erp\SalesOrderFundingGateService;

/** 工单列表、详情、序列号查询、备货预览：只读。 */
final class WorkOrderQueryService
{
    public const STATUS_LABELS = ['planned' => '计划', 'released' => '已下达', 'in_progress' => '生产中', 'paused' => '暂停',
        'completed' => '已完工', 'closed' => '已关闭', 'cancelled' => '已取消'];
    public const STOCK_REASONS = ['alert' => '库存预警', 'regular' => '常规备货', 'other' => '其他', 'order_released' => '订单取消/短交转备货'];
    public const UNIT_LABELS = ['waiting' => '未开工', 'in_progress' => '在制', 'rework' => '返工', 'completed' => '等入库',
        'pending_receipt' => '待仓库确认', 'stored' => '已入库', 'scrapped' => '报废', 'cancelled' => '已取消'];

    private array $funding = [];

    public function __construct(
        private readonly WorkOrderPlanBuilder $plans,
        private readonly SalesOrderFundingGateService $fundingGates,
        private readonly BomMatcher $bomMatcher,
    ) {}

    public function paginate(array $filters, bool $amounts): array
    {
        $query = $this->filtered($filters);
        $tree = empty($filters['flat']);
        // 树形显示：半成品工单挂在总装工单下面；只有它的总装工单不在本次结果里时，才单独显示一行。
        // 多层时看所有上级（最多 4 层），任何一级上级在结果里就挂到它下面，避免同一张工单出现两次。
        if ($tree) for ($level = 1; $level <= 4; $level++) {
            $query->whereNotExists(function ($q) use ($level, $filters) {
                $q->selectRaw('1')->from('erp_mfg_work_order_links as l1')->whereColumn('l1.child_work_order_id', 'erp_mfg_work_orders.id');
                for ($i = 2; $i <= $level; $i++) $q->join("erp_mfg_work_order_links as l{$i}", "l{$i}.child_work_order_id", '=', 'l'.($i - 1).'.parent_work_order_id');
                $q->whereIn("l{$level}.parent_work_order_id", $this->filtered($filters)->select('id'));
            });
        }
        $page = $query->with(['operations:id,work_order_id,operation_name,execution_context,sequence,completed_qty'])
            ->withCount(['units', 'units as stored_units_count' => fn ($q) => $q->where('status', 'stored'),
                'units as pending_receipt_units_count' => fn ($q) => $q->where('status', 'pending_receipt')])
            // 没做完的在前：按优先级（特急→可延后），同级先建的先做；做完的按新到旧。
            ->orderByRaw("CASE WHEN status IN ('completed', 'closed', 'cancelled') THEN 1 ELSE 0 END")->orderBy('priority')
            ->orderByRaw("CASE WHEN status IN ('completed', 'closed', 'cancelled') THEN -id ELSE id END")
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))));
        $rows = $page->getCollection()->map(fn (MfgWorkOrder $wo) => $this->row($wo, $amounts))->all();
        $page->setCollection(collect($this->attachLinks($rows, $amounts, $tree)));
        return $page->toArray() + ['summary' => $this->summary()];
    }

    /**
     * 给列表行补上下挂关系：总装工单汇总半成品入库情况（如“架子 0/1”），树形模式下带出下挂的半成品工单（最多 4 层）；
     * 半成品工单标出是给哪张总装工单做的。
     */
    private function attachLinks(array $rows, bool $amounts, bool $tree, int $depth = 0): array
    {
        if (! $rows) return $rows;
        $ids = array_column($rows, 'id');
        $links = MfgWorkOrderLink::query()->whereIn('parent_work_order_id', $ids)->orderBy('id')->get();
        $children = $links->isEmpty() ? collect() : MfgWorkOrder::query()->with(['operations:id,work_order_id,operation_name,execution_context,sequence,completed_qty'])
            ->withCount(['units', 'units as stored_units_count' => fn ($q) => $q->where('status', 'stored'),
                'units as pending_receipt_units_count' => fn ($q) => $q->where('status', 'pending_receipt')])
            ->whereIn('id', $links->pluck('child_work_order_id'))->where('status', '!=', 'cancelled')->get()->keyBy('id');
        $childRows = [];
        if ($tree && $depth < 4 && $children->isNotEmpty()) {
            $built = $this->attachLinks($children->map(fn ($wo) => $this->row($wo, $amounts))->values()->all(), $amounts, true, $depth + 1);
            foreach ($built as $r) $childRows[$r['id']] = $r;
        }
        $parentIds = MfgWorkOrderLink::query()->whereIn('child_work_order_id', $ids)->pluck('parent_work_order_id', 'child_work_order_id');
        $parentNos = $parentIds->isEmpty() ? collect() : MfgWorkOrder::query()->whereIn('id', $parentIds->values())->pluck('work_order_no', 'id');
        foreach ($rows as &$row) {
            $pid = $parentIds[$row['id']] ?? null;
            $row['parent_work_order'] = $pid ? ['id' => (int) $pid, 'work_order_no' => $parentNos[$pid] ?? null] : null;
            $mine = $links->where('parent_work_order_id', $row['id']);
            $semi = [];
            foreach ($mine as $link) {
                $child = $children->get($link->child_work_order_id);
                if (! $child) continue;
                $key = (int) $link->component_item_id;
                $semi[$key] ??= ['item_id' => $key, 'item_name' => $child->item_name, 'planned_qty' => 0.0, 'stored_qty' => 0.0, 'unit_name' => $child->unit_name];
                $semi[$key]['planned_qty'] = round($semi[$key]['planned_qty'] + (float) $child->planned_qty, 4);
                $semi[$key]['stored_qty'] = round($semi[$key]['stored_qty'] + (float) $child->stored_qty, 4);
                if ($tree && isset($childRows[$child->id])) $row['children'][] = $childRows[$child->id];
            }
            $row['semi_summary'] = array_values($semi);
            $row['semi_ready'] = $semi ? collect($semi)->every(fn ($s) => $s['stored_qty'] + 0.00001 >= $s['planned_qty']) : null;
        }
        unset($row);
        return $rows;
    }

    public function detail(int $id, bool $amounts): array
    {
        $wo = MfgWorkOrder::query()->with(['operations', 'units.components', 'materials', 'item:id,item_type,spec,model,material_grade'])->findOrFail($id);
        $row = $this->row($wo, $amounts);
        $operations = $wo->operations->map(fn ($op) => $this->operationArray($op))->values()->all();
        $materials = $wo->materials->map(fn ($m) => $this->materialArray($m))->values()->all();
        $kitting = $this->semiKitting($wo, $this->plans->kitting($materials, $operations));
        $row['kitting'] = $kitting;
        $row['operations'] = $operations;
        [$row['parent_work_order'], $row['child_work_orders']] = $this->links($wo);
        $row['is_sub_work_order'] = $row['parent_work_order'] !== null || $wo->source_type === 'work_order' || (bool) $wo->rework_of_work_order_id;
        $opNames = $wo->operations->pluck('operation_name', 'id');
        $pendingScrap = MfgUnitReport::query()->where('work_order_id', $wo->id)->where('review_status', 'pending')->pluck('unit_id')->flip();
        $row['units'] = $wo->units->map(fn ($u) => [
            'id' => $u->id, 'unit_no' => $u->unit_no, 'serial_no' => $u->serial_no, 'status' => $u->status,
            'status_label' => isset($pendingScrap[$u->id]) ? '等报废确认' : (self::UNIT_LABELS[$u->status] ?? $u->status), 'rework_count' => $u->rework_count,
            'current_operation_id' => $u->current_operation_id, 'current_operation_name' => $u->current_operation_id ? ($opNames[$u->current_operation_id] ?? null) : null,
            'stored_at' => $u->stored_at?->toDateTimeString(), 'completed_at' => $u->completed_at?->toDateTimeString(),
            'components' => $u->components->map(fn ($c) => ['id' => $c->id, 'component_item_id' => $c->component_item_id,
                'component_item_name' => $c->component_item_name, 'component_serial_no' => $c->component_serial_no])->values()->all(),
        ])->values()->all();
        $row['operations'] = $this->operationProgress($wo, $row['operations']);
        $row['tech'] = $this->techSummary($wo);
        // 订单信息（销售员、客户、电话、地址、各种备注），取订单现在的内容，销售改过的标出来
        $row['order_info'] = app(OrderBrief::class)->forWorkOrder($wo);
        $row['shipment_repairs'] = self::shipmentRepairs($wo);
        $row['logs'] = MfgWorkOrderLog::query()->where('work_order_id', $wo->id)->orderByDesc('id')->limit(30)->get()
            ->map(fn ($log) => ['id' => $log->id, 'action' => $log->action, 'content' => $log->content, 'operator_name' => $log->operator_name,
                'created_at' => $log->created_at?->toDateTimeString()])->values()->all();
        $row['bom'] = $wo->bom_id ? ['id' => $wo->bom_id, 'bom_no' => $wo->bom_no, 'version' => $wo->bom_version,
            'bom_name' => data_get($wo->bom_snapshot, 'bom_name')] : null;
        $row['routing'] = $wo->routing_id ? ['id' => $wo->routing_id, 'routing_no' => $wo->routing_no, 'routing_name' => $wo->routing_name, 'version' => $wo->routing_version] : null;
        $row['special_requirement'] = $wo->special_requirement;
        $row['remark'] = $wo->remark;
        $row['warehouse_id'] = $wo->warehouse_id;
        $row['allowed_location_purposes'] = ReceiptService::allowedLocationPurposes($wo);
        $row['warehouse_name'] = $wo->warehouse_id ? Warehouse::query()->whereKey($wo->warehouse_id)->value('warehouse_name') : null;
        $row['standard_minutes_per_unit'] = round(collect($operations)->where('execution_context', 'production')->sum('unit_standard_minutes'), 1);
        $inventoryAmounts = app(\App\Services\Erp\InventoryAmountVisibilityService::class)->canView();
        $row['estimated_material_cost_per_unit'] = $inventoryAmounts ? $this->plans->estimateMaterialCost($materials, (float) $wo->planned_qty) : null;
        $row['actual_material_cost_amount'] = null;
        $row['actual_material_cost_per_unit'] = null;
        $row['material_cost_collection_complete'] = false;
        if ($inventoryAmounts) {
            $issued = MfgMaterialIssue::query()->where('work_order_id', $wo->id)
                ->selectRaw('COUNT(*) AS fact_count, COALESCE(SUM(cost_amount), 0) AS total_cost')->first();
            $cut = MfgCuttingBatch::query()->where('work_order_id', $wo->id)->where('status', 'settled')
                ->selectRaw('COUNT(*) AS fact_count, COALESCE(SUM(net_cost_amount), 0) AS total_cost')->first();
            $pooled = \App\Models\Erp\MfgCuttingAllocation::query()->where('work_order_id', $wo->id)
                ->selectRaw('COUNT(*) AS fact_count, COALESCE(SUM(cost_amount), 0) AS total_cost')->first();
            $runCut = \App\Models\Erp\MfgCuttingRunAllocation::query()->where('work_order_id', $wo->id)->where('cost_amount', '>', 0)
                ->selectRaw('COUNT(*) AS fact_count, COALESCE(SUM(cost_amount), 0) AS total_cost')->first();
            $returned = (string) MfgMaterialReturn::query()->where('work_order_id', $wo->id)->where('status', 'approved')->sum('cost_amount');
            if ((int) $issued->fact_count + (int) $cut->fact_count + (int) $pooled->fact_count + (int) $runCut->fact_count > 0) {
                // 已发生金额来自正式单据；库存被领空后不能再以当前余额预算替代真实归集。
                $row['actual_material_cost_amount'] = bcsub(bcadd(bcadd(bcadd((string) $issued->total_cost, (string) $cut->total_cost, 4), (string) $pooled->total_cost, 4), (string) $runCut->total_cost, 4), $returned, 4);
                $row['actual_material_cost_per_unit'] = (float) $wo->planned_qty > 0
                    ? bcdiv($row['actual_material_cost_amount'], (string) $wo->planned_qty, 4) : null;
                $row['material_cost_collection_complete'] = ! $wo->materials->contains(fn ($m) => $m->participates_in_kitting
                    && bccomp((string) $m->issued_qty, (string) $m->required_qty, 4) < 0)
                    && ! MfgCuttingBatch::query()->where('work_order_id', $wo->id)->whereIn('status', ['issued', 'awaiting_confirm'])->exists();
            }
        }
        $row['material_returns'] = app(MaterialReturnService::class)->forWorkOrder((int) $wo->id, $inventoryAmounts);
        $row['receipts'] = \App\Models\Erp\MfgReceipt::query()->where('work_order_id', $wo->id)->orderByDesc('id')->get()
            ->map(fn ($receipt) => app(ReceiptService::class)->present($receipt, $inventoryAmounts))->values()->all();
        $row['purchase_demands'] = app(ShortageService::class)->forWorkOrder((int) $wo->id);
        // 外购件按全厂排队（加急→开工日→工单号）后本单的缺口；齐套看板只看本单能否用当前库存，两者可能不同。
        $queue = in_array($wo->status, ShortageService::WORK_ORDER_STATUSES, true) ? app(ShortageService::class)->forWorkOrderQueue((int) $wo->id) : [];
        $row['purchase_shortage'] = array_values($queue);
        foreach ($row['kitting'] as &$op) foreach ($op['materials'] as &$m)
            $m['queue_shortage_qty'] = isset($queue[(int) $m['component_item_id']]) ? $queue[(int) $m['component_item_id']]['shortage_qty'] : null;
        unset($op, $m);
        $row['material_deliveries'] = app(MaterialDeliveryService::class)->forWorkOrder((int) $wo->id);
        $open = in_array($wo->status, ['released', 'in_progress', 'paused'], true);
        $row['unbound_components'] = $open ? app(UnitComponentService::class)->unbound($wo) : [];
        $unitCounts = $wo->units->pluck('status')->countBy()->all();
        $allClosed = $open && $unitCounts && ! array_diff(array_keys($unitCounts), ['stored', 'scrapped', 'cancelled']);
        $workOrders = app(WorkOrderService::class);
        $row['completion_blockers'] = $open ? $workOrders->completionBlockers($wo, $unitCounts) : [];
        $row['scrap_settle_allowed'] = $allClosed && $workOrders->canSettleScrap($wo, $unitCounts);
        $row['sales_order'] = $this->salesOrderSummary($wo, $amounts);
        $row['attention'] = $this->attention($row, $kitting);
        $row['created_by_name'] = $wo->created_by_name;
        $row['created_at'] = $wo->created_at?->toDateTimeString();
        $row['released_at'] = $wo->released_at?->toDateTimeString();
        return $row;
    }

    /** 每道工序：现在停在这道的设备、已过的设备、接单人、谁在计时。 */
    private function operationProgress(MfgWorkOrder $wo, array $operations): array
    {
        $here = $wo->units->whereIn('status', ['waiting', 'in_progress', 'rework'])->groupBy('current_operation_id')->map->count();
        $rework = $wo->units->where('status', 'rework')->groupBy('current_operation_id')->map->count();
        $owners = MfgTaskParticipant::query()->where('work_order_id', $wo->id)->where('role', 'owner')->where('is_active', true)->get()->groupBy('work_order_operation_id');
        $timing = MfgLaborSession::query()->where('work_order_id', $wo->id)->where('status', 'active')->get()->groupBy('work_order_operation_id');
        // 正常 / 返工工时分开：返工单独计时、不算提成；返工工单整张都是返工工时。
        $minutes = MfgLaborSession::query()->where('work_order_id', $wo->id)->selectRaw('work_order_operation_id, is_rework, SUM(labor_minutes) AS m')
            ->groupBy('work_order_operation_id', 'is_rework')->get()->groupBy('work_order_operation_id');
        $ops = $wo->operations->keyBy('id');
        return array_map(function ($op) use ($ops, $here, $rework, $owners, $timing, $minutes) {
            $m = $minutes->get($op['id']) ?? collect();
            $normal = (float) $m->where('is_rework', false)->sum('m');
            $redo = (float) $m->where('is_rework', true)->sum('m');
            $o = $owners->get($op['id']) ?? collect();
            $workStatus = $ops->get($op['id'])?->work_status ?: 'pending';
            return $op + [
                'status' => $ops->get($op['id'])?->status ?? 'pending', 'work_status' => $workStatus, 'work_status_label' => TaskService::WORK_STATUS_LABELS[$workStatus] ?? $workStatus,
                'here_qty' => (int) ($here[$op['id']] ?? 0), 'rework_qty' => (int) ($rework[$op['id']] ?? 0),
                'owner_name' => $o->firstWhere('lane', 'normal')?->employee_name, 'rework_owner_name' => $o->firstWhere('lane', 'rework')?->employee_name,
                'timing_names' => ($timing->get($op['id']) ?? collect())->map(fn ($s) => $s->employee_name.($s->lane === 'rework' ? '（返工）' : ''))->values()->all(),
                'labor_minutes' => round($normal + $redo, 1), 'normal_minutes' => round($normal, 1), 'rework_minutes' => round($redo, 1),
            ];
        }, $operations);
    }

    private function techSummary(MfgWorkOrder $wo): ?array
    {
        $tech = MfgWorkOrderTech::query()->where('work_order_id', $wo->id)->first();
        if (! $tech) return null;
        $drawings = MfgDrawing::query()->where('work_order_id', $wo->id)->where('status', 'active')->orderBy('id')->get();
        return ['status' => $tech->status, 'status_label' => TechService::STATUS_LABELS[$tech->status] ?? $tech->status, 'tech_owner_name' => $tech->tech_owner_name,
            'released_at' => $tech->released_at?->toDateTimeString(), 'drawings_updated_at' => $tech->drawings_updated_at?->toDateTimeString(),
            'drawings' => $drawings->map(fn ($d) => ['id' => $d->id, 'drawing_name' => $d->drawing_name, 'version_no' => $d->version_no, 'url' => $d->url,
                'mime_type' => $d->mime_type, 'uploaded_by' => $d->uploaded_by, 'uploaded_at' => $d->uploaded_at?->toDateTimeString()])->values()->all()];
    }

    /** 备货工单新建页：选好产品、数量后预览 BOM、路线、各工序料够不够、预计用时和成本。 */
    public function preview(array $data, bool $amounts): array
    {
        $item = Item::query()->with('unit')->findOrFail($data['item_id']);
        $qty = (float) ($data['planned_qty'] ?? 1);
        $plan = $this->plans->build($item, $data['product_id'] ?? null, $data['sku_id'] ?? null, null, max($qty, 0.0001), $data['bom_id'] ?? null, $data['routing_id'] ?? null);
        $operations = array_map(fn ($op) => $this->operationArray((object) $op), $plan['operations']);
        $kitting = $this->plans->kitting($plan['materials'], $operations);
        $compIds = collect($kitting)->flatMap(fn ($op) => collect($op['materials'])->pluck('component_item_id'))->unique()->values();
        $compItems = Item::query()->whereIn('id', $compIds)->get(['id', 'spec', 'model', 'material_grade'])->keyBy('id');
        $bomLines = collect(data_get($plan, 'bom.items', []))->keyBy('id');
        foreach ($kitting as &$op) {
            foreach ($op['materials'] as &$m) {
                $m = array_merge($m, self::materialSpecInfo($compItems->get($m['component_item_id']), $bomLines->get($m['bom_item_id'] ?? null)));
            }
            unset($m);
        }
        unset($op);
        $stock = InventoryBalance::query()->where('item_id', $item->id)->sum('quantity_on_hand');
        return [
            'item' => ['id' => $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name, 'unit_name' => $item->unit?->unit_name,
                'is_purchase_item' => $item->isPurchaseFulfilledItem(), 'default_warehouse_id' => $item->default_warehouse_id,
                'stock_qty' => round((float) $stock, 4)] + self::itemSpecInfo($item),
            'bom' => $plan['bom'] ? collect($plan['bom'])->except('items')->all() : null,
            'routing' => $plan['routing'] ? ['id' => $plan['routing']['routing_id'], 'routing_no' => $plan['routing']['routing_no'],
                'routing_name' => $plan['routing']['routing_name'], 'version' => $plan['routing']['version'], 'is_default' => $plan['routing']['is_default'] ?? false] : null,
            'operations' => $operations, 'kitting' => $kitting, 'block_reasons' => $plan['block_reasons'],
            'standard_minutes_per_unit' => round(collect($operations)->where('execution_context', 'production')->sum('unit_standard_minutes'), 1),
            'estimated_material_cost_per_unit' => app(\App\Services\Erp\InventoryAmountVisibilityService::class)->canView()
                ? $this->plans->estimateMaterialCost($plan['materials'], max($qty, 0.0001)) : null,
            'bom_options' => $this->bomOptions($item->id), 'routing_options' => $this->routingOptions($item->id),
        ];
    }

    public function serial(string $serialNo): ?array
    {
        $unit = MfgWorkOrderUnit::query()->with('components')->where('serial_no', $serialNo)->first();
        $matched = null;
        if (! $unit) {
            // 输入的是部件号（外购件或半成品）：查到它当前装在哪台设备，返回那台整机的追溯。
            $matched = \App\Models\Erp\MfgUnitComponent::query()->installed()->where('component_serial_no', $serialNo)->orderByDesc('id')->first();
            $unit = $matched ? MfgWorkOrderUnit::query()->with('components')->find($matched->unit_id) : null;
        }
        if (! $unit) return null;
        $installedIn = \App\Models\Erp\MfgUnitComponent::query()->installed()->where('component_serial_no', $unit->serial_no)->orderByDesc('id')->first();
        $parentSerial = $installedIn ? MfgWorkOrderUnit::query()->whereKey($installedIn->unit_id)->value('serial_no') : null;
        $history = \App\Models\Erp\MfgUnitComponent::query()->with('removal')->where('unit_id', $unit->id)->whereHas('removal')->orderBy('id')->get();
        $wo = MfgWorkOrder::query()->with(['operations', 'item:id,spec,model,material_grade'])->findOrFail($unit->work_order_id);
        $logs = MfgWorkOrderLog::query()->where('work_order_id', $wo->id)
            ->where(fn ($q) => $q->where('unit_id', $unit->id)->orWhere(fn ($w) => $w->whereNull('unit_id')->whereIn('action', ['released', 'paused', 'resumed', 'cancelled'])))
            ->orderBy('id')->get()->map(fn ($log) => ['action' => $log->action, 'content' => $log->content, 'operator_name' => $log->operator_name, 'created_at' => $log->created_at?->toDateTimeString()]);
        $current = $unit->current_operation_id ? $wo->operations->firstWhere('id', $unit->current_operation_id) : null;
        $specInfo = self::itemSpecInfo($wo->item, $wo);
        return [
            'serial_no' => $unit->serial_no, 'status' => $unit->status, 'status_label' => self::UNIT_LABELS[$unit->status] ?? $unit->status,
            'current_operation_id' => $current?->id, 'current_operation_name' => $current?->operation_name, 'rework_count' => $unit->rework_count,
            'work_order' => ['id' => $wo->id, 'work_order_no' => $wo->work_order_no, 'status' => $wo->status, 'status_label' => self::STATUS_LABELS[$wo->status] ?? $wo->status,
                'item_name' => $wo->item_name, 'item_code' => $wo->item_code, 'product_name' => $wo->product_name, 'sku_code' => $wo->sku_code, 'sku_name' => $wo->sku_name,
                'item_spec' => $specInfo['item_spec'], 'material_grade' => $specInfo['material_grade'], 'specification' => $specInfo['specification'],
                'bom_no' => $wo->bom_no, 'bom_name' => data_get($wo->bom_snapshot, 'bom_name'), 'bom_version' => $wo->bom_version,
                'routing_name' => $wo->routing_name, 'routing_version' => $wo->routing_version,
                'special_requirement' => $wo->special_requirement],
            'sales_order' => $wo->sales_order_id ? ['id' => $wo->sales_order_id, 'sales_order_no' => $wo->sales_order_no, 'customer_name' => $wo->customer_name] : null,
            'operations' => $wo->operations->map(fn ($op) => ['id' => $op->id, 'operation_name' => $op->operation_name, 'execution_context' => $op->execution_context])->values(),
            'events' => $logs->values(),
            'components' => $unit->components->map(fn ($c) => ['component_item_name' => $c->component_item_name, 'component_spec' => $c->component_spec,
                'component_serial_no' => $c->component_serial_no, 'batch_no' => $c->batch_no, 'installed_by_name' => $c->installed_by_name,
                'installed_at' => $c->installed_at?->toDateTimeString()])->values(),
            'matched_component_serial_no' => $matched?->component_serial_no,
            'installed_in_serial_no' => $parentSerial,
            'removed_components' => $history->map(fn ($c) => ['component_item_name' => $c->component_item_name, 'component_serial_no' => $c->component_serial_no,
                'installed_at' => $c->installed_at?->toDateTimeString(), 'removed_at' => $c->removal?->removed_at?->toDateTimeString(),
                'reason' => $c->removal?->reason, 'remark' => $c->removal?->remark])->values(),
        ];
    }

    public function serialSuggestions(string $keyword): array
    {
        return MfgWorkOrderUnit::query()->where('serial_no', 'like', '%'.$keyword.'%')->orderByDesc('id')->limit(10)->pluck('serial_no')->all();
    }

    public function bomOptions(int $itemId): array
    {
        return $this->bomMatcher->eligibleQuery()->where('output_item_id', $itemId)->orderByDesc('is_default')->orderByDesc('id')->limit(30)
            ->get(['id', 'bom_no', 'bom_name', 'version', 'is_default', 'product_id', 'sku_id'])->map(fn (Bom $bom) => [
                'id' => $bom->id, 'bom_no' => $bom->bom_no, 'bom_name' => $bom->bom_name, 'version' => $bom->version, 'is_default' => (bool) $bom->is_default,
                'scope' => $bom->sku_id ? 'SKU 专用' : ($bom->product_id ? '产品专用' : '通用')])->all();
    }

    public function routingOptions(int $itemId): array
    {
        return ProductionRouting::query()->where('status', 'active')->applicableToItem($itemId)->orderByDesc('is_default')->orderByDesc('version')->limit(30)
            ->get(['id', 'routing_no', 'routing_name', 'version', 'is_default'])->map(fn ($r) => ['id' => $r->id, 'routing_no' => $r->routing_no,
                'routing_name' => $r->routing_name, 'version' => $r->version, 'is_default' => (bool) $r->is_default])->all();
    }

    /** 订单详情里的“生产进度”页签。 */
    public function forSalesOrder(int $orderId, bool $amounts): array
    {
        return MfgWorkOrder::query()->with(['operations:id,work_order_id,operation_name,execution_context,sequence,completed_qty', 'item:id,item_type,spec,model,material_grade'])
            ->withCount(['units', 'units as stored_units_count' => fn ($q) => $q->where('status', 'stored'),
                'units as pending_receipt_units_count' => fn ($q) => $q->where('status', 'pending_receipt')])
            ->where('sales_order_id', $orderId)->orderBy('sales_order_line_no')->orderBy('id')->get()
            ->map(fn ($wo) => $this->row($wo, $amounts))->values()->all();
    }

    private function filtered(array $filters)
    {
        $query = MfgWorkOrder::query();
        if ($status = $filters['status'] ?? null) $query->where('status', $status);
        else $query->where('status', '!=', 'cancelled');
        if ($source = $filters['source_type'] ?? null) $query->where('source_type', $source);
        if ($owner = $filters['owner_legacy_id'] ?? null) $query->where('owner_legacy_id', $owner);
        if ($from = $filters['due_from'] ?? null) $query->where('planned_finish_date', '>=', $from);
        if ($to = $filters['due_to'] ?? null) $query->where('planned_finish_date', '<=', $to);
        if (! empty($filters['stuck_only'])) $query->where(fn ($q) => $q->whereNotNull('block_reason')->orWhere('status', 'paused'));
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $like = '%'.$keyword.'%';
            $query->where(fn ($q) => $q->where('work_order_no', 'like', $like)->orWhere('item_name', 'like', $like)->orWhere('item_code', 'like', $like)
                ->orWhere('product_name', 'like', $like)->orWhere('sku_code', 'like', $like)->orWhere('sku_name', 'like', $like)->orWhere('sales_order_no', 'like', $like)->orWhere('customer_name', 'like', $like)
                ->orWhereExists(fn ($it) => $it->selectRaw('1')->from('erp_items as it')->whereColumn('it.id', 'erp_mfg_work_orders.item_id')->where(fn ($w) => $w->where('it.spec', 'like', $like)->orWhere('it.model', 'like', $like)))
                ->orWhereExists(fn ($u) => $u->selectRaw('1')->from('erp_mfg_work_order_units as u')->whereColumn('u.work_order_id', 'erp_mfg_work_orders.id')->where('u.serial_no', 'like', $like)));
        }
        return $query;
    }

    private function summary(): array
    {
        $counts = MfgWorkOrder::query()->selectRaw('status, COUNT(*) AS c')->groupBy('status')->pluck('c', 'status')->map(fn ($v) => (int) $v)->all();
        $open = ['planned', 'released', 'in_progress', 'paused'];
        $today = now()->toDateString();
        return [
            'status_counts' => $counts, 'all' => array_sum($counts) - ($counts['cancelled'] ?? 0),
            'waiting_start' => $counts['released'] ?? 0,
            'in_progress' => $counts['in_progress'] ?? 0,
            'in_progress_units' => MfgWorkOrderUnit::query()->whereIn('status', ['in_progress', 'rework'])->count(),
            'stuck' => MfgWorkOrder::query()->whereIn('status', $open)->where(fn ($q) => $q->whereNotNull('block_reason')->orWhere('status', 'paused'))->count(),
            'stuck_paused' => $counts['paused'] ?? 0,
            'due_this_week' => MfgWorkOrder::query()->whereIn('status', $open)->whereBetween('planned_finish_date', [$today, now()->endOfWeek()->toDateString()])->count(),
            'overdue' => MfgWorkOrder::query()->whereIn('status', $open)->where('planned_finish_date', '<', $today)->count(),
        ];
    }

    private function row(MfgWorkOrder $wo, bool $amounts): array
    {
        $qty = (float) $wo->planned_qty;
        $funding = $wo->source_type === 'sales_order' && $wo->sales_order_id && ! $wo->started_at && in_array($wo->status, ['planned', 'released', 'paused'], true)
            ? $this->fundingFor((int) $wo->sales_order_id, $amounts) : null;
        $stuck = $wo->status === 'paused' ? '暂停：'.($wo->pause_reason ?: '未写原因')
            : ($wo->block_reason ?: ($funding && ! ($funding['production_funds_satisfied'] ?? true) ? '定金未到，不能开工' : null));
        $production = $wo->operations->where('execution_context', 'production')->values();
        $specInfo = self::itemSpecInfo($wo->relationLoaded('item') ? $wo->item : null, $wo);
        return [
            'id' => $wo->id, 'work_order_no' => $wo->work_order_no, 'source_type' => $wo->source_type,
            'source_label' => $wo->rework_of_work_order_id ? '返工工单' : match ($wo->source_type) { 'stock' => '备货', 'work_order' => '半成品补做', default => '销售订单' },
            'rework_of_work_order_id' => $wo->rework_of_work_order_id,
            'rework_of_work_order_no' => $wo->rework_of_work_order_id ? MfgWorkOrder::query()->whereKey($wo->rework_of_work_order_id)->value('work_order_no') : null,
            'stock_reason' => $wo->stock_reason, 'stock_reason_label' => self::STOCK_REASONS[$wo->stock_reason] ?? null,
            'sales_order_id' => $wo->sales_order_id, 'sales_order_no' => $wo->sales_order_no, 'sales_order_line_no' => $wo->sales_order_line_no,
            'customer_name' => $wo->customer_name, 'product_name' => $wo->product_name, 'sku_code' => $wo->sku_code, 'sku_name' => $wo->sku_name,
            'item_id' => $wo->item_id, 'item_code' => $wo->item_code, 'item_name' => $wo->item_name, 'unit_name' => $wo->unit_name,
            'item_spec' => $specInfo['item_spec'], 'material_grade' => $specInfo['material_grade'], 'specification' => $specInfo['specification'],
            'planned_qty' => $qty, 'completed_qty' => (float) $wo->completed_qty, 'stored_qty' => (float) $wo->stored_qty,
            'pending_receipt_qty' => (int) ($wo->pending_receipt_units_count ?? ($wo->relationLoaded('units')
                ? $wo->units->where('status', 'pending_receipt')->count() : $wo->units()->where('status', 'pending_receipt')->count())),
            'units_count' => (int) ($wo->units_count ?? $wo->units()->count()),
            'status' => $wo->status, 'status_label' => self::STATUS_LABELS[$wo->status] ?? $wo->status, 'is_urgent' => (bool) $wo->is_urgent, 'priority' => (int) ($wo->priority ?: 3), 'priority_label' => \App\Services\Erp\SalesOrderPriorityService::label((int) ($wo->priority ?: 3)),
            'owner_legacy_id' => $wo->owner_legacy_id, 'owner_name' => $wo->owner_name,
            'planned_start_date' => $wo->planned_start_date?->toDateString(), 'planned_finish_date' => $wo->planned_finish_date?->toDateString(),
            'required_date' => $wo->required_date?->toDateString(), 'block_reason' => $wo->block_reason, 'pause_reason' => $wo->pause_reason,
            'cancel_reason' => $wo->cancel_reason, 'stuck_reason' => $stuck, 'cost_status' => $wo->cost_status,
            'bom_id' => $wo->bom_id, 'routing_id' => $wo->routing_id, 'started' => (bool) $wo->started_at,
            'progress' => $production->map(fn ($op) => ['operation_name' => $op->operation_name, 'completed_qty' => (float) $op->completed_qty, 'planned_qty' => $qty])->values()->all(),
            'funding' => $funding, 'business_version' => (int) $wo->business_version,
            'overdue' => $wo->planned_finish_date && in_array($wo->status, ['planned', 'released', 'in_progress', 'paused'], true) && $wo->planned_finish_date->lt(now()->startOfDay()),
        ];
    }

    private function fundingFor(int $orderId, bool $amounts): ?array
    {
        if (array_key_exists($orderId, $this->funding)) return $this->funding[$orderId];
        $order = SalesOrder::query()->find($orderId);
        if (! $order) return $this->funding[$orderId] = null;
        try { $status = $this->fundingGates->status($order); } catch (\Throwable) { return $this->funding[$orderId] = null; }
        $keep = ['policy_name', 'production_funds_satisfied', 'shipment_funds_satisfied', 'production_block_message', 'shipment_block_message'];
        if ($amounts) $keep = array_merge($keep, ['contract_amount', 'net_received_amount', 'production_required_amount', 'outstanding_amount']);
        return $this->funding[$orderId] = array_intersect_key($status, array_flip($keep));
    }

    private function salesOrderSummary(MfgWorkOrder $wo, bool $amounts): ?array
    {
        if (! $wo->sales_order_id) return null;
        $order = SalesOrder::query()->find($wo->sales_order_id, ['id', 'sales_order_no', 'customer_name', 'customer_name_snapshot', 'required_delivery_date', 'order_status']);
        $line = $wo->sales_order_line_id ? SalesOrderLine::query()->find($wo->sales_order_line_id, ['id', 'line_no', 'order_qty', 'unit_name_snapshot', 'production_required_qty', 'inventory_fulfilled_qty']) : null;
        return [
            'id' => $wo->sales_order_id, 'sales_order_no' => $wo->sales_order_no, 'customer_name' => $wo->customer_name,
            'order_status' => $order?->order_status, 'required_delivery_date' => $order?->required_delivery_date ? substr((string) $order->required_delivery_date, 0, 10) : null,
            'line_no' => $line?->line_no, 'order_qty' => $line ? (float) $line->order_qty : null, 'unit_name' => $line?->unit_name_snapshot,
            'production_qty' => $line ? (float) $line->production_required_qty : null, 'inventory_qty' => $line ? (float) $line->inventory_fulfilled_qty : null,
            'funding' => $this->fundingFor((int) $wo->sales_order_id, $amounts),
        ];
    }

    private function attention(array $row, array $kitting): array
    {
        $items = [];
        if ($row['block_reason']) $items[] = ['type' => 'danger', 'title' => '资料不全，工单停在计划', 'text' => $row['block_reason'].'。补齐后点“下达”。'];
        if ($row['status'] === 'paused') $items[] = ['type' => 'warning', 'title' => '工单已暂停', 'text' => $row['pause_reason'] ?: '未写原因'];
        if ($row['funding'] && ! ($row['funding']['production_funds_satisfied'] ?? true)) $items[] = ['type' => 'warning', 'title' => '定金未到，不能开工', 'text' => $row['funding']['production_block_message'] ?? '请联系业务员催收定金。'];
        foreach ($kitting as $op) {
            if ($op['status'] !== 'short') continue;
            $names = collect($op['materials'])->filter(fn ($m) => $m['shortage_qty'] > 0)->map(fn ($m) => $m['component_item_name'].' 缺 '.rtrim(rtrim(number_format($m['shortage_qty'], 4, '.', ''), '0'), '.').($m['unit_name'] ?: ''))->implode('、');
            $short = collect($op['materials'])->filter(fn ($m) => $m['shortage_qty'] > 0);
            // 已开的半成品工单（含已完工待入库）按在做数量扣减，扣完仍缺才提示再开单。
            $canMake = $short->contains(fn ($m) => ! empty($m['can_make']) && ($m['make_qty'] ?? 0) > 0);
            $making = $short->filter(fn ($m) => ($m['in_production_qty'] ?? 0) > 0)
                ->map(fn ($m) => $m['component_item_name'].' 在做 '.rtrim(rtrim(number_format((float) $m['in_production_qty'], 4, '.', ''), '0'), '.').($m['unit_name'] ?: ''))->implode('、');
            $purchase = $short->contains(fn ($m) => empty($m['can_make']));
            $advice = collect([
                $making !== '' ? '已开半成品工单：'.$making.'，入库后即可配料' : null,
                $canMake ? '其余半成品可在齐套看板点“开半成品工单”交下料组做' : null,
                $purchase ? '外购件请采购跟进' : null,
            ])->filter()->implode('；');
            $items[] = ['type' => 'warning', 'title' => $op['operation_name'].'缺料', 'text' => $names.'。按当前可用库存算，'
                .($advice !== '' ? $advice : '请采购跟进').'；料不齐不能开工。'];
        }
        foreach ($row['completion_blockers'] ?? [] as $b) $items[] = ['type' => 'warning', 'title' => '工单还有事项待处理', 'text' => $b['text'].'。'];
        // 做完的设备可以分批入库；只有最后一批要求开料批次都已登记，成本才能收口。
        if ($row['completed_qty'] > $row['stored_qty'] + 0.00001) {
            $open = MfgWorkOrderUnit::query()->where('work_order_id', $row['id'])->whereNotIn('status', ['stored', 'scrapped', 'cancelled'])->count();
            $waiting = MfgWorkOrderUnit::query()->where('work_order_id', $row['id'])->where('status', 'completed')->count();
            $batch = MfgCuttingBatch::query()->where('work_order_id', $row['id'])->where('status', 'awaiting_confirm')->first();
            if (! $batch && $waiting >= $open) $batch = MfgCuttingBatch::query()->where('work_order_id', $row['id'])->where('status', 'issued')->first();
            if ($batch)
                $items[] = ['type' => 'warning', 'title' => $batch->status === 'awaiting_confirm' ? '本批设备暂不能确认入库' : '最后一批设备暂不能确认入库', 'text' => '开料批次 '.$batch->batch_no.($batch->status === 'awaiting_confirm'
                    ? ' 余料待仓库确认，确认后才能结清本批材料净耗。' : ' 尚未登记产出和余料，请先返回开料页登记。')];
        }
        if (! empty($row['unbound_components'])) $items[] = ['type' => 'info', 'title' => '有部件编号待登记', 'text' => count($row['unbound_components']).' 个已收料的部件编号还没装到设备上，请在“部件编号”处登记到具体的设备。'];
        if ($row['overdue']) $items[] = ['type' => 'danger', 'title' => '已过计划完工日', 'text' => '计划 '.$row['planned_finish_date'].' 完工，请调整计划或加急。'];
        return $items;
    }

    /**
     * 齐套看板补充：缺的是自制半成品时可以给下料组开单（can_make），已开还没入库的数量（in_production_qty），
     * 建议再开多少（make_qty）；每道工序是否还有料没收（receive_pending）。
     */
    private function semiKitting(MfgWorkOrder $wo, array $kitting): array
    {
        $ids = collect($kitting)->flatMap(fn ($op) => collect($op['materials'])->pluck('component_item_id'))->unique()->values();
        $items = Item::query()->whereIn('id', $ids)->get()->keyBy('id');
        $bomLines = collect(data_get($wo->bom_snapshot, 'items', []))->keyBy('id');
        $open = $wo->id ? MaterialIssueService::openChildQty((int) $wo->id) : [];
        $held = $wo->id ? MaterialReturnService::heldDefectiveQty((int) $wo->id) : [];
        $shortByItem = collect($kitting)->flatMap(fn ($op) => $op['materials'])->groupBy('component_item_id')->map(fn ($rows) => $rows->sum('shortage_qty'));
        $openWo = in_array($wo->status, ['planned', 'released', 'in_progress', 'paused'], true);
        foreach ($kitting as &$op) {
            $pending = false;
            $prepared = str_starts_with((string) $op['operation_key'], 'o') ? MaterialDeliveryService::preparedQty((int) substr($op['operation_key'], 1)) : [];
            foreach ($op['materials'] as &$m) {
                $item = $items->get($m['component_item_id']);
                $m = array_merge($m, self::materialSpecInfo($item, $bomLines->get($m['bom_item_id'] ?? null)));
                $m['can_make'] = $openWo && $item && in_array($item->item_type, ['semi_finished', 'finished_product'], true) && ! $item->isPurchaseFulfilledItem();
                $m['in_production_qty'] = (float) ($open[$m['component_item_id']] ?? 0);
                // 退回冻结、返工中的不良件：不算缺口，防止重复补单。
                $m['held_qty'] = (float) ($held[$m['component_item_id']] ?? 0);
                $m['purchasable'] = $item && ShortageService::purchasable($item);
                $m['make_qty'] = $m['can_make'] ? max(0, ceil(round((float) ($shortByItem[$m['component_item_id']] ?? 0) - $m['in_production_qty'] - $m['held_qty'], 4))) : 0;
                if ($m['participates_in_kitting'] && ($m['issue_mode'] ?? 'receive') === 'receive' && (float) $m['issued_qty'] + (float) ($prepared[$m['id'] ?? 0] ?? 0) + 0.00001 < (float) $m['required_qty']) $pending = true;
            }
            unset($m);
            $op['receive_pending'] = $pending && in_array($wo->status, ['released', 'in_progress', 'paused'], true);
        }
        unset($op);
        return $kitting;
    }

    /** 半成品工单显示是给哪张总装工单做的；总装工单列出给下料组开的半成品工单。 */
    /**
     * 发货试水不合格转回车间返修的记录：返修中（在哪道）、返修合格等仓库「返修合格返回」、已返回重新试水。
     * 工单已完工时靠这里看到这台还在返修，不会“单子没了”。
     */
    public static function shipmentRepairs(MfgWorkOrder $wo): array
    {
        $rows = \App\Models\Erp\ShipmentRework::query()->where('work_order_id', $wo->id)->orderByDesc('id')->limit(20)->get();
        if ($rows->isEmpty()) return [];
        $units = MfgWorkOrderUnit::query()->whereIn('id', $rows->pluck('unit_id')->filter())->get()->keyBy('id');
        $ops = MfgWorkOrderOperation::query()->where('work_order_id', $wo->id)->pluck('operation_name', 'id');
        $shipments = DB::table('erp_sales_shipments')->whereIn('id', $rows->pluck('shipment_id'))->pluck('shipment_no', 'id');
        return $rows->map(function ($r) use ($units, $ops, $shipments) {
            $unit = $r->unit_id ? $units->get($r->unit_id) : null;
            $state = $r->status === 'in_progress' ? 'repairing' : ($r->returned_at ? 'returned' : 'wait_return');
            $at = $unit && $unit->status === 'rework' && $unit->current_operation_id ? ($ops[$unit->current_operation_id] ?? null) : null;
            return ['id' => (int) $r->id, 'rework_no' => $r->rework_no, 'shipment_id' => (int) $r->shipment_id, 'shipment_no' => $shipments[$r->shipment_id] ?? null,
                'serial_no' => $unit?->serial_no, 'reason' => $r->reason, 'state' => $state,
                'state_label' => match ($state) {
                    'repairing' => '返修中'.($at ? '（在'.$at.'）' : ''),
                    'wait_return' => '返修已合格，等仓库点「返修合格返回」后重新试水',
                    default => '已返回发货，重新试水',
                },
                'return_operation_name' => $r->return_operation_id ? ($ops[$r->return_operation_id] ?? null) : null,
                'current_operation_id' => $state === 'repairing' && $unit ? $unit->current_operation_id : null,
                'created_at' => $r->created_at?->toDateTimeString(), 'completed_at' => $r->completed_at?->toDateTimeString()];
        })->values()->all();
    }

    private function links(MfgWorkOrder $wo): array
    {
        $brief = function (?MfgWorkOrder $w): ?array {
            if (! $w) return null;
            $specInfo = self::itemSpecInfo($w->relationLoaded('item') ? $w->item : null, $w);
            return ['id' => $w->id, 'work_order_no' => $w->work_order_no, 'item_code' => $w->item_code, 'item_name' => $w->item_name, 'product_name' => $w->product_name,
                'sku_code' => $w->sku_code, 'sku_name' => $w->sku_name, 'item_spec' => $specInfo['item_spec'], 'material_grade' => $specInfo['material_grade'],
                'specification' => $specInfo['specification'],
                'status' => $w->status, 'status_label' => self::STATUS_LABELS[$w->status] ?? $w->status, 'planned_qty' => (float) $w->planned_qty,
                'stored_qty' => (float) $w->stored_qty, 'completed_qty' => (float) $w->completed_qty, 'unit_name' => $w->unit_name,
                'planned_finish_date' => $w->planned_finish_date?->toDateString(), 'is_rework' => (bool) $w->rework_of_work_order_id];
        };
        $up = MfgWorkOrderLink::query()->where('child_work_order_id', $wo->id)->first();
        $parent = $up ? $brief(MfgWorkOrder::query()->with('item:id,item_type,spec,model,material_grade')->find($up->parent_work_order_id)) : null;
        $childIds = MfgWorkOrderLink::query()->where('parent_work_order_id', $wo->id)->orderBy('id')->pluck('child_work_order_id');
        $children = $childIds->isEmpty() ? [] : MfgWorkOrder::query()->with('item:id,item_type,spec,model,material_grade')->whereIn('id', $childIds)->orderBy('id')->get()->map($brief)->values()->all();
        return [$parent, $children];
    }

    private function operationArray(object $op): array
    {
        return [
            'id' => $op->id ?? null, 'key' => isset($op->id) && ! isset($op->key) ? 'o'.$op->id : ($op->key ?? null),
            'operation_name' => $op->operation_name, 'operation_no' => $op->operation_no ?? null, 'sequence' => (int) $op->sequence,
            'execution_context' => $op->execution_context ?: 'production', 'stage_name' => $op->stage_name ?? null,
            'quality_mode' => $op->quality_mode ?? 'none', 'output_mode' => $op->output_mode ?? 'flow_only',
            'unit_standard_minutes' => (float) ($op->unit_standard_minutes ?? 0), 'setup_standard_minutes' => (float) ($op->setup_standard_minutes ?? 0),
            'packaging_scheme_name' => $op->packaging_scheme_name ?? null, 'completed_qty' => (float) ($op->completed_qty ?? 0),
        ];
    }

    private function materialArray($m): array
    {
        return [
            'id' => $m->id, 'bom_item_id' => $m->bom_item_id ? (int) $m->bom_item_id : null,
            'operation_key' => $m->work_order_operation_id ? 'o'.$m->work_order_operation_id : null,
            'component_item_id' => (int) $m->component_item_id, 'component_item_code' => $m->component_item_code, 'component_item_name' => $m->component_item_name,
            'unit_name' => $m->unit_name, 'unit_qty' => (float) $m->unit_qty, 'required_qty' => (float) $m->required_qty, 'issued_qty' => (float) $m->issued_qty,
            'participates_in_kitting' => (bool) $m->participates_in_kitting, 'cutting_mode' => $m->cutting_mode, 'supply_mode' => $m->supply_mode,
            'remark' => $m->remark,
        ];
    }

    /** 统一提取工单物料/产品的规格型号与材质。 */
    public static function itemSpecInfo(?Item $item, ?MfgWorkOrder $wo = null): array
    {
        $spec = trim((string) ($item?->spec ?: $item?->model ?: ''));
        $grade = trim((string) ($item?->material_grade ?: ''));
        $skuName = trim((string) ($wo?->sku_name ?: ''));
        $parts = array_values(array_filter([$spec ?: null, $grade ?: null]));
        $combined = $parts ? implode(' · ', $parts) : ($skuName !== '' && $skuName !== trim((string) ($wo?->item_name ?: '')) ? $skuName : ($skuName ?: null));
        return [
            'item_spec' => $spec !== '' ? $spec : ($skuName !== '' ? $skuName : null),
            'material_grade' => $grade !== '' ? $grade : null,
            'specification' => $combined,
        ];
    }

    /** 从 BOM 行快照提取下料尺寸描述（板材长宽厚/件数、管材单台总米数）。 */
    public static function cutSpecFromLine(?array $line): ?string
    {
        if (! $line) return null;
        $snap = is_array($line['cutting_quantity_snapshot'] ?? null) ? $line['cutting_quantity_snapshot'] : [];
        if (($snap['mode'] ?? null) === 'sheet_dimensions') {
            $l = $snap['length_mm'] ?? null;
            $w = $snap['width_mm'] ?? null;
            $t = $snap['thickness_mm'] ?? null;
            $c = $snap['piece_count'] ?? null;
            if ($l && $w) {
                $dim = $l.'×'.$w.($t ? '×'.$t : '').'mm';
                return '下料 '.$dim.($c ? ' × '.$c.'件' : '');
            }
        }
        if (($snap['mode'] ?? null) === 'pipe_length' && ! empty($snap['total_length_mm'])) {
            $mm = (float) $snap['total_length_mm'];
            $m = rtrim(rtrim(number_format($mm / 1000, 3, '.', ''), '0'), '.');
            return '下料 '.$mm.'mm（'.$m.'m/台）';
        }
        return null;
    }

    /** 统一提取工序用料的规格型号、材质、下料尺寸。 */
    public static function materialSpecInfo(?Item $item, ?array $bomLine = null): array
    {
        $spec = trim((string) ($item?->spec ?: $item?->model ?: ($bomLine['component_spec'] ?? '')));
        $grade = trim((string) ($item?->material_grade ?: ''));
        $cutSpec = self::cutSpecFromLine($bomLine);
        $parts = array_values(array_filter([$spec ?: null, $grade ?: null, $cutSpec]));
        return [
            'component_item_spec' => $spec !== '' ? $spec : null,
            'material_grade' => $grade !== '' ? $grade : null,
            'cut_spec' => $cutSpec,
            'specification' => $parts ? implode(' · ', $parts) : null,
        ];
    }
}
