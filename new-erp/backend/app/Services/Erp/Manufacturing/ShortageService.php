<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\Item;
use App\Models\Erp\MfgPurchaseDemandLink;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseRequest;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\PurchaseRequestCreationApplicationService;
use Illuminate\Support\Facades\DB;

/**
 * 生产缺料：未结束工单（含计划）还没领的外购件，扣掉可用库存和采购在途后的缺口。
 * 分配顺序：加急 → 计划开工日早 → 工单号小，先分库存再分在途；在途含所有来源未到货的采购需求/计划/订单。
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
                return ['work_orders' => $mine, 'need_qty' => $mine[0]['need_qty'], 'shortage_qty' => $mine[0]['shortage_qty'],
                    'earliest_date' => $mine[0]['planned_start_date'], 'is_urgent' => $mine[0]['is_urgent']] + $row;
            }, $rows)));
        }
        if (($filters['only_short'] ?? true) !== false) $rows = array_values(array_filter($rows, fn ($r) => $r['shortage_qty'] > 0.00001));
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        if ($keyword !== '') {
            $rows = array_values(array_filter($rows, fn ($r) => str_contains((string) $r['item_code'], $keyword) || str_contains((string) $r['item_name'], $keyword)
                || collect($r['work_orders'])->contains(fn ($w) => str_contains((string) $w['work_order_no'], $keyword))));
        }
        usort($rows, fn ($a, $b) => [$b['is_urgent'] ? 1 : 0, $a['earliest_date'] ?? '9999-12-31', (string) $a['item_code']]
            <=> [$a['is_urgent'] ? 1 : 0, $b['earliest_date'] ?? '9999-12-31', (string) $b['item_code']]);
        $perPage = min(100, max(1, (int) ($filters['per_page'] ?? 10)));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $short = array_filter($rows, fn ($r) => $r['shortage_qty'] > 0.00001);
        return [
            'data' => array_slice($rows, ($page - 1) * $perPage, $perPage), 'total' => count($rows), 'current_page' => $page, 'per_page' => $perPage,
            'summary' => ['short_item_count' => count($short),
                'work_order_count' => collect($short)->flatMap(fn ($r) => collect($r['work_orders'])->where('shortage_qty', '>', 0.00001)->pluck('id'))->unique()->count()],
        ];
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
                'w.work_order_no', 'w.status', 'w.item_name', 'w.is_urgent', 'w.planned_start_date', 'w.warehouse_id']);
        if ($onlyItemIds !== null) $query->whereIn('m.component_item_id', $onlyItemIds ?: [0]);
        $lines = $query->get();
        if ($lines->isEmpty()) return [];
        $items = Item::query()->whereIn('id', $lines->pluck('component_item_id')->unique()->all())->get()->keyBy('id');
        $lines = $lines->filter(fn ($l) => ($item = $items->get($l->component_item_id)) && self::purchasable($item));
        if ($lines->isEmpty()) return [];
        $ids = $lines->pluck('component_item_id')->map(fn ($id) => (int) $id)->unique()->values()->all();
        [$available] = $this->plans->availableForKitting($ids);
        $transit = $this->inTransit($ids);
        $linked = $this->openLinks($ids);

        $rows = [];
        foreach ($lines->groupBy('component_item_id') as $itemId => $group) {
            $item = $items->get($itemId);
            $demands = $group->groupBy('work_order_id')->map(function ($ls) {
                $f = $ls->first();
                return ['id' => (int) $f->work_order_id, 'work_order_no' => $f->work_order_no, 'status' => $f->status,
                    'status_label' => WorkOrderQueryService::STATUS_LABELS[$f->status] ?? $f->status, 'item_name' => $f->item_name,
                    'is_urgent' => (bool) $f->is_urgent, 'planned_start_date' => $f->planned_start_date ? substr((string) $f->planned_start_date, 0, 10) : null,
                    'warehouse_id' => $f->warehouse_id ? (int) $f->warehouse_id : null,
                    'need_qty' => round($ls->sum(fn ($l) => (float) $l->required_qty - (float) $l->issued_qty), 4)];
            })->values()->all();
            usort($demands, fn ($a, $b) => [$b['is_urgent'] ? 1 : 0, $a['planned_start_date'] ?? '9999-12-31', $a['id']]
                <=> [$a['is_urgent'] ? 1 : 0, $b['planned_start_date'] ?? '9999-12-31', $b['id']]);
            $stock = max(0, (float) ($available[$itemId] ?? 0));
            $coming = max(0, (float) ($transit[$itemId] ?? 0));
            foreach ($demands as &$d) {
                $fromStock = min($d['need_qty'], $stock); $stock -= $fromStock;
                $fromTransit = min($d['need_qty'] - $fromStock, $coming); $coming -= $fromTransit;
                $d['stock_qty'] = round($fromStock, 4);
                $d['transit_qty'] = round($fromTransit, 4);
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
                'available_qty' => round((float) ($available[$itemId] ?? 0), 4), 'in_transit_qty' => round((float) ($transit[$itemId] ?? 0), 4),
                'shortage_qty' => round(array_sum(array_column($demands, 'shortage_qty')), 4),
                'earliest_date' => $dates ? min($dates) : null, 'is_urgent' => collect($short)->contains('is_urgent', true),
                'default_warehouse_id' => $item->default_warehouse_id ? (int) $item->default_warehouse_id : null,
                'work_orders' => $demands, 'requests' => array_values($linked[(int) $itemId] ?? []),
            ];
        }
        return $rows;
    }

    /**
     * 选中的物料合并生成一张采购需求（每种物料一行），并按分配顺序记下覆盖了哪些工单的缺口。
     * @param array<int, array{item_id:int, qty?:float|null}> $lines
     */
    public function createRequest(array $lines, ?int $workOrderId, ?string $remark, object $actor): PurchaseRequest
    {
        return DB::transaction(function () use ($lines, $workOrderId, $remark, $actor) {
            $ids = collect($lines)->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->sort()->values()->all();
            if (count($ids) !== count($lines)) $this->fail('duplicate_item', '同一物料只能选一次。');
            // 锁住物料，同一物料的并发转采购排队，后一个按前一个生成后的在途重算缺口。
            $items = Item::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $wo = null;
            if ($workOrderId) {
                $wo = MfgWorkOrder::query()->lockForUpdate()->find($workOrderId);
                if (! $wo || ! in_array($wo->status, self::WORK_ORDER_STATUSES, true)) $this->fail('status_invalid', '工单已结束，不能再转采购。');
            }
            $rows = $this->compute($ids);
            $requestItems = []; $allocations = []; $urgent = false; $woNos = [];
            foreach ($lines as $line) {
                $itemId = (int) $line['item_id'];
                $item = $items->get($itemId);
                $row = $rows[$itemId] ?? null;
                $demands = collect($row['work_orders'] ?? [])->filter(fn ($d) => $d['shortage_qty'] > 0.00001 && (! $wo || $d['id'] === (int) $wo->id))->values();
                $gap = round($demands->sum('shortage_qty'), 4);
                if (! $item || $gap <= 0.00001) $this->fail('no_shortage', ($item?->item_name ?: '物料').' 已经不缺了（可能已转过采购或已到货），请刷新。');
                $qty = isset($line['qty']) && $line['qty'] !== null && $line['qty'] !== '' ? round((float) $line['qty'], 4) : $gap;
                if ($qty <= 0) $this->fail('qty_invalid', $item->item_name.' 采购数量要大于 0。');
                $left = $qty; $parts = [];
                foreach ($demands as $d) {
                    if ($left <= 0.00001) break;
                    $take = round(min($left, $d['shortage_qty']), 4); $left -= $take;
                    $parts[] = ['work_order_id' => $d['id'], 'work_order_no' => $d['work_order_no'], 'qty' => $take];
                    $urgent = $urgent || $d['is_urgent'];
                    $woNos[$d['id']] = $d['work_order_no'];
                }
                $date = collect($parts)->map(fn ($p) => $demands->firstWhere('id', $p['work_order_id'])['planned_start_date'] ?? null)->filter()->min();
                $requestItems[] = ['item_id' => $itemId, 'request_qty' => $qty,
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
     * 采购在途（基本单位）：需求未转计划 + 计划未下单 + 订单未到货，不论来源。
     * @return array<int, float>
     */
    public function inTransit(array $ids): array
    {
        if (! $ids) return [];
        $sum = [];
        $add = function ($rows) use (&$sum) { foreach ($rows as $r) $sum[(int) $r->item_id] = ($sum[(int) $r->item_id] ?? 0) + (float) $r->qty; };
        $add(DB::table('erp_purchase_request_items as i')->join('erp_purchase_requests as r', 'r.id', '=', 'i.request_id')
            ->whereNull('r.deleted_at')->whereNotIn('r.request_status', self::CLOSED)->whereNotIn('i.line_status', self::CLOSED)
            ->whereIn('i.item_id', $ids)->groupBy('i.item_id')->selectRaw('i.item_id, SUM(i.remaining_qty) AS qty')->get());
        $add(DB::table('erp_purchase_plan_items as i')->join('erp_purchase_plans as p', 'p.id', '=', 'i.plan_id')
            ->whereNotIn('p.plan_status', self::CLOSED)->whereNotIn('i.line_status', self::CLOSED)
            ->whereIn('i.item_id', $ids)->groupBy('i.item_id')->selectRaw('i.item_id, SUM(CASE WHEN i.plan_qty > i.ordered_qty THEN i.plan_qty - i.ordered_qty ELSE 0 END) AS qty')->get());
        $add(DB::table('erp_purchase_order_items as i')->join('erp_purchase_orders as o', 'o.id', '=', 'i.order_id')
            ->whereNotIn('o.purchase_status', self::CLOSED)->whereNotIn('i.line_status', [...self::CLOSED, 'received'])->where('i.remaining_qty', '>', 0)
            ->whereIn('i.item_id', $ids)->groupBy('i.item_id')
            ->selectRaw('i.item_id, SUM(i.remaining_qty * CASE WHEN i.conversion_factor_snapshot > 0 THEN i.conversion_factor_snapshot ELSE 1 END) AS qty')->get());
        return array_map(fn ($q) => round($q, 4), $sum);
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
                'request_status_label' => self::REQUEST_STATUS_LABELS[$l->request->request_status] ?? $l->request->request_status, 'qty' => round($group->sum('qty'), 4)];
        }
        return $out;
    }

    private function num(float $v): string { return rtrim(rtrim(number_format($v, 4, '.', ''), '0'), '.'); }
}
