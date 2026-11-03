<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Item;
use App\Models\Erp\MfgPurchaseDemandLink;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseRequest;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\PurchaseRequestCreationApplicationService;
use App\Services\Erp\PurchaseSupplyProjectionService;
use App\Services\Erp\UnitConversionDomainService;
use Illuminate\Support\Facades\DB;

/**
 * 生产缺料：未结束工单（含计划）还没领的外购件，扣掉可用库存和采购在途后的缺口。
 * 库存按优先级、同级按工单先后分配；在途先抵本工单专属，再分无关联手工备货，不占用其他工单采购。
 * 可自制的半成品不在这里，继续走“开半成品工单”。
 */
final class ShortageService
{
    use ProductionActor;

    public const WORK_ORDER_STATUSES = ['planned', 'released', 'in_progress', 'paused'];
    private const CLOSED = ['cancelled', 'closed', 'rejected', 'void', 'voided'];
    public const REQUEST_STATUS_LABELS = ['draft' => '草稿', 'submitted' => '已提交', 'confirmed' => '已确认', 'approved' => '已审核',
        'planned' => '已转计划', 'processing' => '采购中', 'closed' => '已关闭', 'cancelled' => '已取消', 'rejected' => '已驳回'];

    public function __construct(private readonly WorkOrderPlanBuilder $plans, private readonly WorkOrderService $workOrders) {}

    /** 外购件：库存物料，且不是可以自己做的半成品/成品。 */
    public static function purchasable(Item $item): bool
    {
        if ($item->item_type === 'service' || ! $item->is_stock_item) return false;
        return ! in_array($item->item_type, ['semi_finished', 'finished_product'], true) || $item->isPurchaseFulfilledItem();
    }

    /** 列表：按物料一行；work_order_id 只看这张工单分到的缺口。 */
    public function paginate(array $filters): array
    {
        $rows = array_values($this->compute());
        $woId = (int) ($filters['work_order_id'] ?? 0);
        if ($woId) {
            $rows = array_values(array_filter(array_map(function ($row) use ($woId) {
                $mine = array_values(array_filter($row['work_orders'], fn ($w) => $w['id'] === $woId));
                if (! $mine) return null;
                $requests = array_values(array_filter($row['requests'], fn ($r) => isset($r['work_order_qty'][$woId])));
                foreach ($requests as &$request) $request['qty'] = $request['work_order_qty'][$woId];
                unset($request);
                return ['work_orders' => $mine, 'need_qty' => $mine[0]['need_qty'], 'shortage_qty' => $mine[0]['shortage_qty'],
                    'in_transit_qty' => $mine[0]['transit_qty'], 'in_transit_detail' => $mine[0]['in_transit_detail'], 'requests' => $requests,
                    'earliest_date' => $mine[0]['planned_start_date'], 'is_urgent' => $mine[0]['is_urgent'], 'priority' => $mine[0]['priority'] ?? 3] + $row;
            }, $rows)));
        }
        if (($filters['only_short'] ?? true) !== false) $rows = array_values(array_filter($rows, fn ($r) => $r['shortage_qty'] > 0.00001));
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => str_contains((string) $r['item_code'], $keyword) || str_contains((string) $r['item_name'], $keyword)
                || collect($r['work_orders'])->contains(fn ($w) => str_contains((string) $w['work_order_no'], $keyword))));
        }
        usort($rows, fn ($a, $b) => [$a['priority'] ?? 3, $a['earliest_date'] ?? '9999-12-31', (string) $a['item_code']]
            <=> [$b['priority'] ?? 3, $b['earliest_date'] ?? '9999-12-31', (string) $b['item_code']]);
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 10)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $short = array_filter($rows, fn ($r) => $r['shortage_qty'] > 0.00001);
        $slice = array_slice($rows, ($page - 1) * $perPage, $perPage);
        $units = $this->purchaseUnits(array_column($slice, 'item_id'));
        foreach ($slice as &$r) $r['purchase_units'] = $units[$r['item_id']] ?? [];
        unset($r);
        return [
            'data' => $slice, 'total' => count($rows), 'current_page' => $page, 'per_page' => $perPage,
            'summary' => ['short_item_count' => count($short),
                'work_order_count' => collect($short)->flatMap(fn ($r) => collect($r['work_orders'])->where('shortage_qty', '>', 0.00001)->pluck('id'))->unique()->count()],
        ];
    }

    /**
     * 转采购时可选的采购单位：库存单位（就是工单里的单位）+ 物料档案里维护的有效采购换算（如 1 卷 = 300 米）。
     * @return array<int, array> item_id => 单位列表，默认采购单位排第一
     */
    public function purchaseUnits(array $itemIds): array
    {
        return app(UnitConversionDomainService::class)->itemUnitOptions($itemIds);
    }

    /**
     * 按物料算缺口。
     * @return array<int, array> item_id => 行
     */
    public function compute(?array $onlyItemIds = null): array
    {
        $query = DB::table('erp_mfg_work_order_materials as m')->join('erp_mfg_work_orders as w', 'w.id', '=', 'm.work_order_id')
            ->whereIn('w.status', self::WORK_ORDER_STATUSES)->where('m.participates_in_kitting', true)->whereColumn('m.required_qty', '>', 'm.issued_qty')
            ->select(['m.work_order_id', 'm.component_item_id', 'm.component_item_code', 'm.component_item_name', 'm.unit_name', 'm.required_qty', 'm.issued_qty',
                'w.work_order_no', 'w.status', 'w.source_type', 'w.item_name', 'w.is_urgent', 'w.priority', 'w.planned_start_date', 'w.warehouse_id', 'w.sales_order_id', 'w.sales_order_line_id']);
        if ($onlyItemIds !== null) $query->whereIn('m.component_item_id', $onlyItemIds ?: [0]);
        $lines = $query->get();
        if ($lines->isEmpty()) return [];
        $items = Item::query()->whereIn('id', $lines->pluck('component_item_id')->unique()->all())->get()->keyBy('id');
        $lines = $lines->filter(fn ($l) => ($item = $items->get($l->component_item_id)) && self::purchasable($item));
        if ($lines->isEmpty()) return [];
        $ids = $lines->pluck('component_item_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        [$available] = $this->plans->availableForKitting($ids);
        $projection = app(PurchaseSupplyProjectionService::class)->project($ids);
        $linked = $this->openLinks($ids);
        $workOrderSales = $this->workOrderSalesOrders($lines);

        $rows = [];
        foreach ($lines->groupBy('component_item_id') as $itemId => $group) {
            $item = $items->get($itemId);
            $demands = $group->groupBy('work_order_id')->map(function ($ls) use ($workOrderSales) {
                $f = $ls->first();
                return ['id' => (int) $f->work_order_id, 'work_order_no' => $f->work_order_no, 'status' => $f->status,
                    'sales_order_item_id' => $f->sales_order_line_id ? (int) $f->sales_order_line_id : null,
                    'sales_order_id' => $workOrderSales[(int) $f->work_order_id] ?? null,
                    'status_label' => WorkOrderQueryService::STATUS_LABELS[$f->status] ?? $f->status, 'item_name' => $f->item_name,
                    'is_urgent' => (bool) $f->is_urgent, 'priority' => (int) ($f->priority ?: 3), 'planned_start_date' => $f->planned_start_date ? substr((string) $f->planned_start_date, 0, 10) : null,
                    'warehouse_id' => $f->warehouse_id ? (int) $f->warehouse_id : null,
                    'need_qty' => round($ls->sum(fn ($l) => (float) $l->required_qty - (float) $l->issued_qty), 4)];
            })->values()->all();
            // 库存先分给优先级高的工单，同级先建的先分。
            usort($demands, fn ($a, $b) => [$a['priority'], $a['id']] <=> [$b['priority'], $b['id']]);
            $stock = max(0, (float) ($available[$itemId] ?? 0));
            $shared = $projection[$itemId]['shared']['documents'] ?? [];
            $sales = array_map(fn ($p) => $p['documents'], $projection[$itemId]['sales_order_items'] ?? []);
            $salesOrders = array_map(fn ($p) => array_values(array_filter($p['documents'],
                fn ($document) => empty($document['sales_allocations'][0]['sales_order_item_id']))), $projection[$itemId]['sales_orders'] ?? []);
            $ahead = [];
            foreach ($demands as &$d) {
                // 排在前面、已分走库存的工单，用来解释“库存够但本单还缺”。
                $d['ahead'] = $ahead;
                $fromStock = min($d['need_qty'], $stock); $stock -= $fromStock;
                if ($fromStock > 0.00001) $ahead[] = ['id' => $d['id'], 'work_order_no' => $d['work_order_no'], 'stock_qty' => round($fromStock, 4)];
                $dedicated = $projection[$itemId]['work_orders'][$d['id']]['documents'] ?? [];
                $mine = $this->takeTransit($dedicated, $d['need_qty'] - $fromStock);
                // 必须同时匹配销售商品行和当前材料；同一商品行的多张工单共用可消耗的原料额度。
                $salesLineId = $d['sales_order_item_id'];
                $salesPurchase = isset($sales[$salesLineId]) ? $this->takeTransit($sales[$salesLineId], $d['need_qty'] - $fromStock - $mine['total']) : $this->transitDetail([]);
                // 整单采购在同一销售订单各工单间共用材料池，历史商品行额度已单独分配，不再从订单汇总重复领取。
                $salesOrderId = $d['sales_order_id'];
                $orderPurchase = isset($salesOrders[$salesOrderId]) ? $this->takeTransit($salesOrders[$salesOrderId], $d['need_qty'] - $fromStock - $mine['total'] - $salesPurchase['total']) : $this->transitDetail([]);
                $stockPurchase = $this->takeTransit($shared, $d['need_qty'] - $fromStock - $mine['total'] - $salesPurchase['total'] - $orderPurchase['total']);
                $fromTransit = $mine['total'] + $salesPurchase['total'] + $orderPurchase['total'] + $stockPurchase['total'];
                $d['stock_qty'] = round($fromStock, 4);
                $d['transit_qty'] = round($fromTransit, 4);
                $d['dedicated_transit_qty'] = round($mine['total'] + $salesPurchase['total'] + $orderPurchase['total'], 4);
                $d['shared_transit_qty'] = $stockPurchase['total'];
                $d['in_transit_detail'] = $fromTransit > 0.00001 ? $this->transitDetail(array_merge($mine['documents'], $salesPurchase['documents'], $orderPurchase['documents'], $stockPurchase['documents'])) : null;
                $d['shortage_qty'] = round(max(0, $d['need_qty'] - $fromStock - $fromTransit), 4);
            }
            unset($d);
            $short = array_values(array_filter($demands, fn ($d) => $d['shortage_qty'] > 0.00001));
            $dates = array_filter(array_column($short ?: $demands, 'planned_start_date'));
            $first = $group->first();
            $rows[(int) $itemId] = [
                'item_id' => (int) $itemId, 'item_code' => $item->item_code ?: $first->component_item_code, 'item_name' => $item->item_name ?: $first->component_item_name,
                'spec' => $item->spec ?: ($item->model ?: null), 'unit_name' => $first->unit_name,
                'need_qty' => round(array_sum(array_column($demands, 'need_qty')), 4),
                'available_qty' => round((float) ($available[$itemId] ?? 0), 4), 'in_transit_qty' => round(array_sum(array_column($demands, 'transit_qty')), 4),
                'in_transit_detail' => collect($demands)->sum('transit_qty') > 0.00001
                    ? $this->transitDetail(collect($demands)->flatMap(fn ($d) => $d['in_transit_detail']['documents'] ?? [])->all()) : null,
                'shortage_qty' => round(array_sum(array_column($demands, 'shortage_qty')), 4),
                'earliest_date' => $dates ? min($dates) : null, 'is_urgent' => collect($short)->contains('is_urgent', true), 'priority' => $short ? (int) collect($short)->min('priority') : 3,
                'default_warehouse_id' => $item->default_warehouse_id ? (int) $item->default_warehouse_id : null,
                'work_orders' => $demands, 'requests' => array_values($linked[(int) $itemId] ?? []),
            ];
        }
        return $rows;
    }

    /** 半成品工单沿正式父子关系继承归属；多销售订单共用的子单不能独占任一订单的采购。 */
    private function workOrderSalesOrders(\Illuminate\Support\Collection $lines): array
    {
        $sales = $lines->pluck('sales_order_id', 'work_order_id')->all();
        $sources = $lines->pluck('source_type', 'work_order_id')->all();
        $parents = []; $visited = [];
        $pending = array_keys(array_filter($sources, fn ($source, $id) => ! $sales[$id] && $source === 'work_order', ARRAY_FILTER_USE_BOTH));
        while ($pending) {
            $links = DB::table('erp_mfg_work_order_links as l')->join('erp_mfg_work_orders as w', 'w.id', '=', 'l.parent_work_order_id')
                ->whereIn('l.child_work_order_id', $pending)->get(['l.child_work_order_id', 'l.parent_work_order_id', 'w.sales_order_id', 'w.source_type']);
            $visited += array_fill_keys($pending, true);
            $pending = [];
            foreach ($links as $link) {
                $parents[$link->child_work_order_id][] = (int) $link->parent_work_order_id;
                $sales[$link->parent_work_order_id] = $link->sales_order_id;
                $sources[$link->parent_work_order_id] = $link->source_type;
                if (! $link->sales_order_id && $link->source_type === 'work_order' && ! isset($visited[$link->parent_work_order_id])) $pending[] = (int) $link->parent_work_order_id;
            }
            $pending = array_values(array_unique($pending));
        }
        $owners = function (int $id, array $path = []) use (&$owners, $sales, $sources, $parents): array {
            if (isset($path[$id])) return [0];
            if ($sales[$id] ?? null) return [(int) $sales[$id]];
            if (($sources[$id] ?? null) !== 'work_order' || empty($parents[$id])) return [0];
            $path[$id] = true; $ids = [];
            foreach ($parents[$id] ?? [] as $parentId) $ids = array_merge($ids, $owners($parentId, $path));
            return array_values(array_unique($ids));
        };
        $out = [];
        foreach ($lines->pluck('work_order_id')->unique() as $id) {
            $ids = $owners((int) $id);
            if (count($ids) === 1 && $ids[0] > 0) $out[(int) $id] = $ids[0];
        }
        return $out;
    }

    /**
     * 选中的物料合并生成一张采购需求（每种物料一行），并按分配顺序记下覆盖了哪些工单的缺口。
     * @param array<int, array{item_id:int, qty?:float|null}> $lines
     */
    public function createRequest(array $lines, ?int $workOrderId, ?string $remark, object $actor, ?array $deliveryScope = null): PurchaseRequest
    {
        return DB::transaction(function () use ($lines, $workOrderId, $remark, $actor, $deliveryScope) {
            $ids = collect($lines)->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            if (count($ids) !== count($lines)) $this->fail('duplicate_item', '同一物料只能选一次。');
            // 锁住物料，同一物料的并发转采购排队，后一个按前一个生成后的在途重算缺口。
            $items = Item::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $wo = null;
            if ($workOrderId) {
                $wo = MfgWorkOrder::query()->lockForUpdate()->find($workOrderId);
                if (! $wo || ! in_array($wo->status, self::WORK_ORDER_STATUSES, true)) $this->fail('status_invalid', '工单已结束，不能再转采购。');
            }
            // 仓库路径在物料锁后才读配料与在途，避免等待并发申请时提前建立旧快照而重复采购。
            $operationLines = $deliveryScope === null ? null : collect(app(MaterialDeliveryService::class)->shortagePurchasePreview(
                (int) $wo->id, (int) $deliveryScope['operation_id'], $deliveryScope['units'], $deliveryScope['selections'], true)['lines'])->keyBy('item_id');
            $rows = $this->compute($ids);
            $requestItems = []; $allocations = []; $urgent = false; $woNos = [];
            foreach ($lines as $line) {
                $itemId = (int) $line['item_id'];
                $item = $items->get($itemId);
                $row = $rows[$itemId] ?? null;
                $demands = collect($row['work_orders'] ?? [])->filter(fn ($d) => $d['shortage_qty'] > 0.00001 && (! $wo || $d['id'] === (int) $wo->id))->values();
                $gap = round($demands->sum('shortage_qty'), 4);
                if ($operationLines !== null) $gap = min($gap, (float) ($operationLines->get($itemId)['qty'] ?? 0));
                if (! $item || $gap <= 0.00001) $this->fail('no_shortage', ($item?->item_name ?: '物料').' 已经不缺了（可能已转过采购或已到货），请刷新。');
                $qty = isset($line['qty']) && $line['qty'] !== null && $line['qty'] !== '' ? round((float) $line['qty'], 4) : $gap;
                // 选了采购单位（如卷）就按采购单位下数量，折成库存单位（米）去冲缺口；多出来的到货后入库。
                $unitId = (int) ($line['purchase_unit_id'] ?? 0);
                if ($operationLines !== null && $unitId !== (int) $operationLines->get($itemId)['purchase_unit_id'])
                    $this->fail('unit_invalid', $item->item_name.' 请按本次缺料的库存单位申请采购。');
                $unitLine = [];
                if ($unitId) {
                    $unit = collect($this->purchaseUnits([$itemId])[$itemId] ?? [])->firstWhere('unit_id', $unitId);
                    if (! $unit) $this->fail('unit_invalid', $item->item_name.' 没有这个采购单位的换算，请先在物料档案维护“采购换算”。');
                    if (! $unit['is_base']) {
                        $purchaseQty = round((float) ($line['purchase_qty'] ?? 0), 4);
                        if ($purchaseQty <= 0) $this->fail('qty_invalid', $item->item_name.' 采购数量要大于 0。');
                        $qty = round($purchaseQty * $unit['factor'], 4);
                        $unitLine = ['purchase_unit_id' => $unitId, 'purchase_quantity' => $purchaseQty];
                    } else {
                        $unitLine = ['purchase_unit_id' => $unitId];
                    }
                }
                if ($qty <= 0) $this->fail('qty_invalid', $item->item_name.' 采购数量要大于 0。');
                if ($operationLines !== null && $qty > $gap + 0.00001)
                    $this->fail('qty_exceeds_gap', $item->item_name.' 的采购数量超过本道工序当前缺口，请刷新后核对。', 409);
                $left = $qty; $parts = [];
                foreach ($demands as $d) {
                    if ($left <= 0.00001) break;
                    $take = round(min($left, $d['shortage_qty']), 4); $left -= $take;
                    $parts[] = ['work_order_id' => $d['id'], 'work_order_no' => $d['work_order_no'], 'qty' => $take];
                    $urgent = $urgent || $d['is_urgent'];
                    $woNos[$d['id']] = $d['work_order_no'];
                }
                $date = collect($parts)->map(fn ($p) => $demands->firstWhere('id', $p['work_order_id'])['planned_start_date'] ?? null)->filter()->min();
                $requestItems[] = $unitLine + ['item_id' => $itemId, 'request_qty' => $qty,
                    'expected_date' => $date && $date > now()->toDateString() ? $date : now()->toDateString(),
                    'warehouse_id' => $item->default_warehouse_id ?: null, 'priority' => collect($parts)->contains(fn ($p) => $demands->firstWhere('id', $p['work_order_id'])['is_urgent'] ?? false) ? 'urgent' : 'high',
                    'remark' => '生产缺料：'.collect($parts)->map(fn ($p) => $p['work_order_no'].' 缺 '.$this->num($p['qty']))->implode('、')];
                $allocations[$itemId] = $parts;
            }
            $name = $this->actorName($actor);
            $no = app(DocumentNumberService::class)->next('purchase_request', 'PRQ');
            $request = app(PurchaseRequestCreationApplicationService::class)->create([
                'request_no' => $no, 'request_date' => now()->toDateString(), 'source_type' => 'production_shortage',
                'source_id' => $wo ? (string) $wo->id : null, 'source_no' => $wo?->work_order_no, 'data_source' => 'manual',
                'priority' => $urgent ? 'urgent' : 'high', 'created_by' => $name, 'requester' => $name,
                'remark' => $remark ?: '由生产缺料生成，涉及工单：'.implode('、', array_values($woNos)),
            ], $requestItems);
            $byItem = $request->items->keyBy('item_id');
            $touched = [];
            foreach ($allocations as $itemId => $parts) {
                $requestItem = $byItem->get($itemId);
                foreach ($parts as $p) {
                    MfgPurchaseDemandLink::create(['purchase_request_id' => $request->id, 'purchase_request_item_id' => $requestItem->id,
                        'work_order_id' => $p['work_order_id'], 'item_id' => $itemId, 'qty' => $p['qty'],
                        'created_by_legacy_id' => $this->actorId($actor), 'created_by_name' => $name]);
                    $touched[$p['work_order_id']][] = $items->get($itemId)->item_name.' '.$this->num($p['qty']);
                }
            }
            PurchaseLog::create(['target_type' => 'purchase_request', 'target_id' => $request->id, 'action' => 'create', 'operator' => $name,
                'content' => '由生产缺料生成，涉及工单：'.implode('、', array_values($woNos))]);
            foreach (MfgWorkOrder::query()->whereIn('id', array_keys($touched))->get() as $w) {
                $this->workOrders->log($w, 'purchase_requested', $w->status, $w->status, "缺料转采购需求 {$no}：".implode('、', $touched[$w->id]), $actor, ['purchase_request_id' => $request->id]);
            }
            return $request;
        }, 5);
    }

    /** 工单详情：这张工单转过的采购需求。 */
    public function forWorkOrder(int $workOrderId): array
    {
        return MfgPurchaseDemandLink::query()->where('work_order_id', $workOrderId)->with(['request', 'item:id,item_name'])->orderByDesc('id')->get()
            ->map(fn (MfgPurchaseDemandLink $l) => [
                'id' => $l->id, 'purchase_request_id' => $l->purchase_request_id, 'request_no' => $l->request?->request_no,
                'item_id' => $l->item_id, 'item_name' => $l->item?->item_name, 'qty' => (float) $l->qty,
                'request_status' => $l->request?->trashed() ? 'deleted' : $l->request?->request_status,
                'request_status_label' => $l->request?->trashed() ? '已删除' : (self::REQUEST_STATUS_LABELS[$l->request?->request_status] ?? $l->request?->request_status),
                'created_by_name' => $l->created_by_name, 'created_at' => $l->created_at?->toDateTimeString(),
            ])->values()->all();
    }

    /**
     * 单张工单的外购件排队结果：每种还没领够的外购件，按全厂分配顺序本单能分到的库存、在途和缺口，以及排在前面分走库存的工单。
     * @return array<int, array> item_id => 行
     */
    public function forWorkOrderQueue(int $workOrderId): array
    {
        $ids = DB::table('erp_mfg_work_order_materials')->where('work_order_id', $workOrderId)->where('participates_in_kitting', true)
            ->whereColumn('required_qty', '>', 'issued_qty')->distinct()->pluck('component_item_id')->map(fn ($id) => (int) $id)->all();
        if (! $ids) return [];
        $out = [];
        foreach ($this->compute($ids) as $itemId => $row) {
            $mine = collect($row['work_orders'])->firstWhere('id', $workOrderId);
            if (! $mine) continue;
            $out[$itemId] = ['item_id' => $itemId, 'item_name' => $row['item_name'], 'unit_name' => $row['unit_name'],
                'need_qty' => $mine['need_qty'], 'stock_qty' => $mine['stock_qty'], 'transit_qty' => $mine['transit_qty'],
                'shortage_qty' => $mine['shortage_qty'], 'available_qty' => $row['available_qty'], 'ahead' => array_slice($mine['ahead'], 0, 5),
                'in_transit_detail' => $mine['in_transit_detail']];
        }
        return $out;
    }

    /**
     * 未指定工单时只返回共享备货在途（基本单位），专属数量必须经 compute 按工单分配。
     * @return array<int, float>
     */
    public function inTransit(array $ids): array
    {
        return array_map(fn ($p) => (float) ($p['shared']['total'] ?? 0), app(PurchaseSupplyProjectionService::class)->project($ids));
    }

    /** 分配记录保留到具体供给行，同一份备货被前单取走后不能再抵后单。 */
    private function takeTransit(array &$documents, float $wanted): array
    {
        $taken = [];
        foreach ($documents as &$document) {
            if ($wanted <= 0.00001) break;
            $qty = round(min(max(0, $wanted), $document['qty']), 4);
            if ($qty <= 0.00001) continue;
            $taken[] = ['qty' => $qty] + $document;
            $document['qty'] = round($document['qty'] - $qty, 4);
            $wanted -= $qty;
        }
        return $this->transitDetail($taken);
    }

    private function transitDetail(array $documents): array
    {
        $detail = ['total' => 0.0, 'stages' => [], 'documents' => []];
        foreach ($documents as $document) {
            $detail['total'] += $document['qty'];
            $detail['stages'][$document['stage']] = ($detail['stages'][$document['stage']] ?? 0) + $document['qty'];
            $key = $document['stage'].'|'.$document['document_no'].'|'.$document['line_id'];
            $detail['documents'][$key] = ['qty' => ($detail['documents'][$key]['qty'] ?? 0) + $document['qty']] + $document;
        }
        $detail['total'] = round($detail['total'], 4);
        $detail['stages'] = array_map(fn ($q) => round($q, 4), $detail['stages']);
        $detail['documents'] = array_values($detail['documents']);
        return $detail;
    }

    /** 每种物料从生产缺料生成、还没关闭的采购需求。 */
    private function openLinks(array $ids): array
    {
        $out = [];
        $links = MfgPurchaseDemandLink::query()->whereIn('item_id', $ids)
            ->whereHas('request', fn ($q) => $q->whereNull('deleted_at')->whereNotIn('request_status', self::CLOSED))
            ->with('request')->get();
        foreach ($links->groupBy(fn ($l) => $l->item_id.'-'.$l->purchase_request_id) as $group) {
            $l = $group->first();
            $out[(int) $l->item_id][] = ['purchase_request_id' => $l->purchase_request_id, 'request_no' => $l->request->request_no,
                'request_status_label' => self::REQUEST_STATUS_LABELS[$l->request->request_status] ?? $l->request->request_status, 'qty' => round($group->sum('qty'), 4),
                'work_order_qty' => $group->groupBy('work_order_id')->map(fn ($ls) => round($ls->sum('qty'), 4))->all()];
        }
        return $out;
    }

    private function num(float $v): string { return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.'); }
}
