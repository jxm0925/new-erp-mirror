<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Bom;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\Item;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLog;
use App\Models\Erp\MfgWorkOrderUnit;
use App\Models\Erp\ProductionRouting;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use App\Models\Erp\Warehouse;
use App\Services\Erp\BomMatcher;
use App\Services\Erp\SalesOrderFundingGateService;

/** 工单列表、详情、序列号查询、备货预览：只读。 */
final class WorkOrderQueryService
{
    public const STATUS_LABELS = ['planned' => '计划', 'released' => '已下达', 'in_progress' => '生产中', 'paused' => '暂停',
        'completed' => '已完工', 'closed' => '已关闭', 'cancelled' => '已取消'];
    public const STOCK_REASONS = ['alert' => '库存预警', 'regular' => '常规备货', 'other' => '其他'];
    public const UNIT_LABELS = ['waiting' => '未开工', 'in_progress' => '在制', 'rework' => '返工', 'completed' => '已完工',
        'stored' => '已入库', 'scrapped' => '报废', 'cancelled' => '已取消'];

    private array $funding = [];

    public function __construct(
        private readonly WorkOrderPlanBuilder $plans,
        private readonly SalesOrderFundingGateService $fundingGates,
        private readonly BomMatcher $bomMatcher,
    ) {}

    public function paginate(array $filters, bool $amounts): array
    {
        $query = $this->filtered($filters);
        $page = $query->with(['operations:id,work_order_id,operation_name,execution_context,sequence,completed_qty'])
            ->withCount(['units', 'units as stored_units_count' => fn ($q) => $q->where('status', 'stored')])
            ->orderByRaw("FIELD(status, 'paused', 'planned', 'in_progress', 'released', 'completed', 'closed', 'cancelled')")
            ->orderByDesc('is_urgent')->orderBy('planned_finish_date')->orderByDesc('id')
            ->paginate(min(100, max(1, (int) ($filters['per_page'] ?? 10))));
        $page->getCollection()->transform(fn (MfgWorkOrder $wo) => $this->row($wo, $amounts));
        return $page->toArray() + ['summary' => $this->summary()];
    }

    public function detail(int $id, bool $amounts): array
    {
        $wo = MfgWorkOrder::query()->with(['operations', 'units', 'materials'])->findOrFail($id);
        $row = $this->row($wo, $amounts);
        $operations = $wo->operations->map(fn ($op) => $this->operationArray($op))->values()->all();
        $materials = $wo->materials->map(fn ($m) => $this->materialArray($m))->values()->all();
        $kitting = $this->plans->kitting($materials, $operations);
        $row['kitting'] = $kitting;
        $row['operations'] = $operations;
        $row['units'] = $wo->units->map(fn ($u) => [
            'id' => $u->id, 'unit_no' => $u->unit_no, 'serial_no' => $u->serial_no, 'status' => $u->status,
            'status_label' => self::UNIT_LABELS[$u->status] ?? $u->status, 'rework_count' => $u->rework_count,
            'current_operation_id' => $u->current_operation_id, 'stored_at' => $u->stored_at?->toDateTimeString(),
        ])->values()->all();
        $row['logs'] = MfgWorkOrderLog::query()->where('work_order_id', $wo->id)->orderByDesc('id')->limit(30)->get()
            ->map(fn ($log) => ['id' => $log->id, 'action' => $log->action, 'content' => $log->content, 'operator_name' => $log->operator_name,
                'created_at' => $log->created_at?->toDateTimeString()])->values()->all();
        $row['bom'] = $wo->bom_id ? ['id' => $wo->bom_id, 'bom_no' => $wo->bom_no, 'version' => $wo->bom_version,
            'bom_name' => data_get($wo->bom_snapshot, 'bom_name')] : null;
        $row['routing'] = $wo->routing_id ? ['id' => $wo->routing_id, 'routing_no' => $wo->routing_no, 'routing_name' => $wo->routing_name, 'version' => $wo->routing_version] : null;
        $row['special_requirement'] = $wo->special_requirement;
        $row['remark'] = $wo->remark;
        $row['warehouse_id'] = $wo->warehouse_id;
        $row['warehouse_name'] = $wo->warehouse_id ? Warehouse::query()->whereKey($wo->warehouse_id)->value('warehouse_name') : null;
        $row['standard_minutes_per_unit'] = round(collect($operations)->where('execution_context', 'production')->sum('unit_standard_minutes'), 1);
        $row['estimated_material_cost_per_unit'] = $amounts ? $this->plans->estimateMaterialCost($materials, (float) $wo->planned_qty) : null;
        $row['sales_order'] = $this->salesOrderSummary($wo, $amounts);
        $row['attention'] = $this->attention($row, $kitting);
        $row['created_by_name'] = $wo->created_by_name;
        $row['created_at'] = $wo->created_at?->toDateTimeString();
        $row['released_at'] = $wo->released_at?->toDateTimeString();
        return $row;
    }

    /** 备货工单新建页：选好产品、数量后预览 BOM、路线、各工序料够不够、预计用时和成本。 */
    public function preview(array $data, bool $amounts): array
    {
        $item = Item::query()->with('unit')->findOrFail($data['item_id']);
        $qty = (float) ($data['planned_qty'] ?? 1);
        $plan = $this->plans->build($item, $data['product_id'] ?? null, $data['sku_id'] ?? null, null, max($qty, 0.0001), $data['bom_id'] ?? null, $data['routing_id'] ?? null);
        $operations = array_map(fn ($op) => $this->operationArray((object) $op), $plan['operations']);
        $kitting = $this->plans->kitting($plan['materials'], $operations);
        $stock = InventoryBalance::query()->where('item_id', $item->id)->sum('quantity_on_hand');
        return [
            'item' => ['id' => $item->id, 'item_code' => $item->item_code, 'item_name' => $item->item_name, 'unit_name' => $item->unit?->unit_name,
                'is_purchase_item' => $item->isPurchaseFulfilledItem(), 'default_warehouse_id' => $item->default_warehouse_id,
                'stock_qty' => round((float) $stock, 4)],
            'bom' => $plan['bom'] ? collect($plan['bom'])->except('items')->all() : null,
            'routing' => $plan['routing'] ? ['id' => $plan['routing']['routing_id'], 'routing_no' => $plan['routing']['routing_no'],
                'routing_name' => $plan['routing']['routing_name'], 'version' => $plan['routing']['version'], 'is_default' => $plan['routing']['is_default'] ?? false] : null,
            'operations' => $operations, 'kitting' => $kitting, 'block_reasons' => $plan['block_reasons'],
            'standard_minutes_per_unit' => round(collect($operations)->where('execution_context', 'production')->sum('unit_standard_minutes'), 1),
            'estimated_material_cost_per_unit' => $amounts ? $this->plans->estimateMaterialCost($plan['materials'], max($qty, 0.0001)) : null,
            'bom_options' => $this->bomOptions($item->id), 'routing_options' => $this->routingOptions($item->id),
        ];
    }

    public function serial(string $serialNo): ?array
    {
        $unit = MfgWorkOrderUnit::query()->with('components')->where('serial_no', $serialNo)->first();
        if (! $unit) return null;
        $wo = MfgWorkOrder::query()->with('operations')->findOrFail($unit->work_order_id);
        $logs = MfgWorkOrderLog::query()->where('work_order_id', $wo->id)
            ->where(fn ($q) => $q->where('unit_id', $unit->id)->orWhere(fn ($w) => $w->whereNull('unit_id')->whereIn('action', ['released', 'paused', 'resumed', 'cancelled'])))
            ->orderBy('id')->get()->map(fn ($log) => ['action' => $log->action, 'content' => $log->content, 'operator_name' => $log->operator_name, 'created_at' => $log->created_at?->toDateTimeString()]);
        $current = $unit->current_operation_id ? $wo->operations->firstWhere('id', $unit->current_operation_id) : null;
        return [
            'serial_no' => $unit->serial_no, 'status' => $unit->status, 'status_label' => self::UNIT_LABELS[$unit->status] ?? $unit->status,
            'current_operation_name' => $current?->operation_name, 'rework_count' => $unit->rework_count,
            'work_order' => ['id' => $wo->id, 'work_order_no' => $wo->work_order_no, 'status' => $wo->status, 'status_label' => self::STATUS_LABELS[$wo->status] ?? $wo->status,
                'item_name' => $wo->item_name, 'item_code' => $wo->item_code, 'product_name' => $wo->product_name, 'sku_code' => $wo->sku_code, 'sku_name' => $wo->sku_name,
                'bom_no' => $wo->bom_no, 'bom_version' => $wo->bom_version, 'routing_name' => $wo->routing_name, 'routing_version' => $wo->routing_version,
                'special_requirement' => $wo->special_requirement],
            'sales_order' => $wo->sales_order_id ? ['id' => $wo->sales_order_id, 'sales_order_no' => $wo->sales_order_no, 'customer_name' => $wo->customer_name] : null,
            'operations' => $wo->operations->map(fn ($op) => ['id' => $op->id, 'operation_name' => $op->operation_name, 'execution_context' => $op->execution_context])->values(),
            'events' => $logs->values(),
            'components' => $unit->components->map(fn ($c) => ['component_item_name' => $c->component_item_name, 'component_spec' => $c->component_spec,
                'component_serial_no' => $c->component_serial_no, 'batch_no' => $c->batch_no, 'installed_by_name' => $c->installed_by_name,
                'installed_at' => $c->installed_at?->toDateTimeString()])->values(),
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
        return ProductionRouting::query()->where('status', 'active')->where('output_item_id', $itemId)->orderByDesc('is_default')->orderByDesc('version')->limit(30)
            ->get(['id', 'routing_no', 'routing_name', 'version', 'is_default'])->map(fn ($r) => ['id' => $r->id, 'routing_no' => $r->routing_no,
                'routing_name' => $r->routing_name, 'version' => $r->version, 'is_default' => (bool) $r->is_default])->all();
    }

    /** 订单详情里的“生产进度”页签。 */
    public function forSalesOrder(int $orderId, bool $amounts): array
    {
        return MfgWorkOrder::query()->with(['operations:id,work_order_id,operation_name,execution_context,sequence,completed_qty'])
            ->withCount(['units', 'units as stored_units_count' => fn ($q) => $q->where('status', 'stored')])
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
                ->orWhere('product_name', 'like', $like)->orWhere('sku_code', 'like', $like)->orWhere('sales_order_no', 'like', $like)->orWhere('customer_name', 'like', $like)
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
        return [
            'id' => $wo->id, 'work_order_no' => $wo->work_order_no, 'source_type' => $wo->source_type,
            'source_label' => $wo->source_type === 'stock' ? '备货' : '销售订单',
            'stock_reason' => $wo->stock_reason, 'stock_reason_label' => self::STOCK_REASONS[$wo->stock_reason] ?? null,
            'sales_order_id' => $wo->sales_order_id, 'sales_order_no' => $wo->sales_order_no, 'sales_order_line_no' => $wo->sales_order_line_no,
            'customer_name' => $wo->customer_name, 'product_name' => $wo->product_name, 'sku_code' => $wo->sku_code, 'sku_name' => $wo->sku_name,
            'item_id' => $wo->item_id, 'item_code' => $wo->item_code, 'item_name' => $wo->item_name, 'unit_name' => $wo->unit_name,
            'planned_qty' => $qty, 'completed_qty' => (float) $wo->completed_qty, 'stored_qty' => (float) $wo->stored_qty,
            'units_count' => (int) ($wo->units_count ?? $wo->units()->count()),
            'status' => $wo->status, 'status_label' => self::STATUS_LABELS[$wo->status] ?? $wo->status, 'is_urgent' => (bool) $wo->is_urgent,
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
            $items[] = ['type' => 'warning', 'title' => $op['operation_name'].'缺料', 'text' => $names.'。按当前可用库存算，请采购跟进。'];
        }
        if ($row['overdue']) $items[] = ['type' => 'danger', 'title' => '已过计划完工日', 'text' => '计划 '.$row['planned_finish_date'].' 完工，请调整计划或加急。'];
        return $items;
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
            'id' => $m->id, 'operation_key' => $m->work_order_operation_id ? 'o'.$m->work_order_operation_id : null,
            'component_item_id' => (int) $m->component_item_id, 'component_item_code' => $m->component_item_code, 'component_item_name' => $m->component_item_name,
            'unit_name' => $m->unit_name, 'unit_qty' => (float) $m->unit_qty, 'required_qty' => (float) $m->required_qty, 'issued_qty' => (float) $m->issued_qty,
            'participates_in_kitting' => (bool) $m->participates_in_kitting, 'cutting_mode' => $m->cutting_mode, 'supply_mode' => $m->supply_mode,
        ];
    }
}
