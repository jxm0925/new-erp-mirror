<?php

namespace App\Services\Erp\Manufacturing;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\ProductionPerformanceCalculator;
use Illuminate\Support\Facades\DB;

/**
 * 订单提成与工时（只读统计）。按“用了多少取多少”把每张订单真正用到的工时、提成归集起来：
 * - 订单工单自己做的：工单的工序工时、提成全部算这张订单；
 * - 用了库存里备货工单做的架子、电箱（或直接发了备货整机）：按库存批次号（= 当初工单号）找到那批工单，
 *   工时 = 那批工序总工时 × 用量 ÷ 那批产出；提成 = 订单折后金额 × 那批工序的提成比例 × 这部分占比，
 *   再按那批工序的参与人份额（固定份额，或按实际工时占比）分到人；
 * - 现开的半成品工单同理，按半成品自己的路线工序统计；
 * - 期初、外购入库等没有生产记录的库存不计。
 * 订单商品全部发完货才算“可结算”，之前的数字都是预估。
 */
final class PerformanceStatsService
{
    private const SKIP_TYPES = ['service', 'no_delivery', 'fee', 'auxiliary', 'deduction'];
    private const MAX_DEPTH = 6;
    private const SOURCE_LABELS = ['sales_order' => '订单工单', 'stock' => '备货工单', 'work_order' => '半成品工单'];

    private array $woCache = [];
    private array $itemTypes = [];
    private array $shipped = [];

    public function __construct(private readonly ProductionPerformanceCalculator $calc) {}

    public function index(array $filters, bool $amounts): array
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $page = DB::table('erp_sales_orders as o')
            ->where(function ($w) {
                $w->whereExists(fn ($s) => $s->selectRaw('1')->from('erp_mfg_work_orders as wo')->whereColumn('wo.sales_order_id', 'o.id'))
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('erp_sales_shipments as sh')->whereColumn('sh.sales_order_id', 'o.id')
                        ->where('sh.shipment_status', '!=', 'cancelled'));
            })
            ->when($keyword !== '', fn ($q) => $q->where(fn ($w) => $w->where('o.sales_order_no', 'like', "%{$keyword}%")
                ->orWhere('o.customer_name', 'like', "%{$keyword}%")))
            ->orderByDesc('o.id')
            ->paginate(min(30, max(1, (int) ($filters['per_page'] ?? 15))), ['o.id'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $rows = [];
        foreach ($page->items() as $row) {
            $d = $this->order((int) $row->id, $amounts);
            $rows[] = ['id' => $d['order']['id'], 'sales_order_no' => $d['order']['sales_order_no'], 'customer_name' => $d['order']['customer_name'],
                'order_date' => $d['order']['order_date'], 'status' => $d['status'], 'status_label' => $d['status_label'],
                'goods_amount' => $d['totals']['goods_amount'], 'performance_amount' => $d['totals']['performance_amount'],
                'labor_minutes' => $d['totals']['labor_minutes'], 'employee_count' => count($d['employees']), 'issue_count' => count($d['issues'])];
        }
        return ['data' => $rows, 'total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()];
    }

    public function order(int $id, bool $amounts): array
    {
        $order = DB::table('erp_sales_orders')->where('id', $id)->first();
        if (! $order) throw new WorkOrderDomainException('order_not_found', '订单不存在。', 404);
        $issues = [];
        $foreign = strtoupper((string) ($order->currency ?: 'CNY')) !== strtoupper((string) ($order->base_currency ?: 'CNY'));
        $rate = ! $foreign ? '1' : (bccomp((string) ($order->business_exchange_rate ?? 0), '0', 10) > 0 ? (string) $order->business_exchange_rate : null);
        if ($rate === null) $issues[] = '外币订单还没有业务汇率，提成金额暂不能折算人民币。';
        $lines = DB::table('erp_sales_order_lines')->where('sales_order_id', $id)->orderBy('sort_order')->orderBy('id')->get();
        $shippedByLine = $this->shippedLines($id)->groupBy('sales_order_line_id')
            ->map(fn ($rows) => ['sales_qty' => (string) $rows->sum(fn ($r) => (float) $r->sales_qty)])->all();
        $readiness = $this->calc->shipmentReadiness($lines->map(fn ($l) => (array) $l)->all(), $shippedByLine);
        $employees = [];
        $out = [];
        $goodsAmount = '0';
        foreach ($lines as $line) {
            if (in_array($line->line_type, self::SKIP_TYPES, true) || $line->line_status === 'cancelled') continue;
            $qty = bccomp((string) ($line->item_base_required_qty ?? 0), '0', 8) > 0 ? (string) $line->item_base_required_qty : (string) $line->order_qty;
            if (bccomp($qty, '0', 8) <= 0) continue;
            $amount = $rate === null || $line->amount_incl_tax === null ? null : bcmul((string) $line->amount_incl_tax, $rate, 4);
            if ($amount !== null) $goodsAmount = bcadd($goodsAmount, $amount, 4);
            $ctx = ['amount' => $amount, 'qty' => $qty, 'issues' => &$issues, 'employees' => &$employees, 'line' => $line];
            $sources = [];
            $covered = '0';
            foreach ($this->lineSources($line, $qty) as $src) {
                $covered = bcadd($covered, $src['qty'], 8);
                $fraction = bcdiv($src['qty'], $qty, 12);
                if (! $src['wo']) {
                    $sources[] = ['kind' => $src['kind'], 'kind_label' => $src['label'], 'work_order_id' => null, 'batch_no' => $src['batch_no'],
                        'used_qty' => $this->num($src['qty']), 'note' => '这部分是期初/外购等库存，没有生产记录，不计工时和提成。', 'operations' => [], 'children' => []];
                    continue;
                }
                $sources[] = ['kind' => $src['kind'], 'kind_label' => $src['label']] + $this->node($src['wo'], $src['qty'], $fraction, $ctx, 0, []);
            }
            $packing = $this->packing($line, $ctx);
            $returned = (float) DB::table('erp_sales_return_receipt_items as ri')->join('erp_sales_return_items as i', 'i.id', '=', 'ri.sales_return_item_id')
                ->join('erp_sales_returns as sr', 'sr.id', '=', 'i.sales_return_id')->where('i.sales_order_line_id', $line->id)->where('sr.return_status', '!=', 'cancelled')
                ->whereNotIn('ri.id', DB::table('erp_sales_return_dispositions')->whereColumn('target_receipt_item_id', '!=', 'source_receipt_item_id')->whereNotNull('target_receipt_item_id')->select('target_receipt_item_id'))
                ->sum(DB::raw('ri.received_base_qty - ri.rejected_base_qty'));
            if ($returned > 0.0001) $issues[] = "{$line->item_name}：客户退货 ".$this->num((string) $returned).'（只记录，不扣工时和提成；售后按责任手动处理）。';
            if (bccomp($covered, $qty, 8) < 0) $issues[] = "{$line->item_name}：还有 ".$this->num(bcsub($qty, $covered, 8)).' 没有生产或发货来源（还没开工单或还没发货），这部分暂不统计。';
            $out[] = ['sales_order_line_id' => (int) $line->id, 'line_no' => $line->line_no, 'product_name' => $line->product_name, 'sku_name' => $line->sku_name,
                'item_name' => $line->item_name, 'qty' => $this->num($qty), 'amount' => $amounts ? $amount : null,
                'shipped_qty' => $this->num($shippedQty = (string) array_sum(array_map(fn ($s) => $s['kind'] === 'shipped' ? $s['used_qty'] : 0, $sources))),
                'pending_qty' => $this->num(bccomp($qty, $shippedQty, 8) > 0 ? bcsub($qty, $shippedQty, 8) : '0'), 'returned_qty' => round($returned, 4),
                'sources' => $sources, 'packing' => $packing];
        }
        $employeeRows = array_values($employees);
        usort($employeeRows, fn ($a, $b) => $b['labor_minutes'] <=> $a['labor_minutes']);
        $perfTotal = '0';
        $laborTotal = 0.0;
        foreach ($employeeRows as &$e) {
            $perfTotal = bcadd($perfTotal, $e['performance_amount'], 4);
            $laborTotal += $e['labor_minutes'];
            $e['labor_minutes'] = round($e['labor_minutes'], 1);
            $e['performance_amount'] = $amounts ? $this->calc->round($e['performance_amount']) : null;
        }
        unset($e);
        $settleable = $readiness['entire_order_shipped'];
        return [
            'order' => ['id' => (int) $order->id, 'sales_order_no' => $order->sales_order_no, 'customer_name' => $order->customer_name,
                'order_date' => $order->order_date, 'currency' => $order->currency, 'exchange_rate' => $foreign ? $rate : null],
            'status' => $settleable ? 'settleable' : 'estimate', 'status_label' => $settleable ? '已全部发货，可结算' : '未全部发货，以下为预估',
            'readiness' => ['goods_lines_total' => $readiness['goods_lines_total'], 'fully_shipped_lines' => $readiness['fully_shipped_lines']],
            'rule' => '订单折后含税金额 × 工序提成比例 × 占比；用库存半成品/备货整机的，按库存批次找到当初的工单，用了多少取多少（工时 = 那批总工时 × 用量 ÷ 那批产出）。',
            'lines' => $out, 'employees' => $employeeRows,
            'totals' => ['goods_amount' => $amounts ? $this->calc->round($goodsAmount) : null,
                'performance_amount' => $amounts ? $this->calc->round($perfTotal) : null, 'labor_minutes' => round($laborTotal, 1)],
            'issues' => array_values(array_unique($issues)), 'amount_visible' => $amounts,
        ];
    }

    /** 这一行商品由哪些工单产出：已发货的按发货批次号找工单；还没发货的按订单工单预估。 */
    private function lineSources(object $line, string $qty): array
    {
        $sources = [];
        $shippedByBatch = [];
        foreach ($this->shippedLines((int) $line->sales_order_id)->where('sales_order_line_id', $line->id)->groupBy(fn ($r) => (int) $r->item_id.'|'.$r->batch_no) as $rows) {
            $first = $rows->first();
            $q = (string) $rows->sum(fn ($r) => (float) $r->base_qty);
            $wo = $first->batch_no ? $this->woByBatch((string) $first->batch_no, (int) $first->item_id) : null;
            if ($wo) $shippedByBatch[$wo->id] = bcadd($shippedByBatch[$wo->id] ?? '0', $q, 8);
            $sources[] = ['kind' => 'shipped', 'label' => $wo && $wo->source_type !== 'sales_order' ? '已发货（用库存现货）' : '已发货',
                'wo' => $wo, 'qty' => $q, 'batch_no' => $first->batch_no];
        }
        $left = bcsub($qty, array_reduce($sources, fn ($s, $r) => bcadd($s, $r['qty'], 8), '0'), 8);
        $orderWos = DB::table('erp_mfg_work_orders')->where('sales_order_line_id', $line->id)->where('source_type', 'sales_order')
            ->where('status', '!=', 'cancelled')->orderBy('id')->get();
        foreach ($orderWos as $wo) {
            if (bccomp($left, '0', 8) <= 0) break;
            $this->woCache[$wo->id] = $wo;
            $remain = bcsub($this->output($wo), $shippedByBatch[$wo->id] ?? '0', 8);
            if (bccomp($remain, '0', 8) <= 0) continue;
            if (bccomp($remain, $left, 8) > 0) $remain = $left;
            $left = bcsub($left, $remain, 8);
            $sources[] = ['kind' => 'pending', 'label' => '未发货（按订单工单预估）', 'wo' => $wo, 'qty' => $remain, 'batch_no' => $wo->work_order_no];
        }
        return $sources;
    }

    /** 一张工单的工序工时/提成，以及它用到的半成品来源（递归）。 */
    private function node(object $wo, string $used, string $fraction, array &$ctx, int $depth, array $path): array
    {
        $scrapped = (string) DB::table('erp_mfg_unit_reports')->where('work_order_id', $wo->id)->where('result', 'scrap')->where('review_status', 'approved')->distinct()->count('unit_id');
        $output = $this->output($wo);
        // 工时按“合格 + 报废”平摊，报废那部分单独列出，不摊给订单。
        $base = in_array($wo->status, ['completed', 'closed'], true) ? bcadd($output, $scrapped, 8) : (bccomp($output, $scrapped, 8) > 0 ? $output : $scrapped);
        $laborRatio = bccomp($base, '0', 8) > 0 ? min(1.0, (float) bcdiv($used, $base, 10)) : 0.0;
        $operations = [];
        $ops = DB::table('erp_mfg_work_order_operations')->where('work_order_id', $wo->id)->where('execution_context', 'production')->orderBy('sequence')->get();
        $sessions = DB::table('erp_mfg_labor_sessions')->where('work_order_id', $wo->id)->where('status', '!=', 'active')
            ->groupBy('work_order_operation_id', 'employee_legacy_id', 'employee_name')
            ->selectRaw('work_order_operation_id, employee_legacy_id, employee_name, SUM(labor_minutes) AS m')->get()->groupBy('work_order_operation_id');
        $participants = DB::table('erp_mfg_task_participants')->where('work_order_id', $wo->id)->where('is_active', true)->get()->groupBy('work_order_operation_id');
        foreach ($ops as $op) {
            $people = [];
            foreach ($participants->get($op->id, collect()) as $p) $people[(int) $p->employee_legacy_id] = ['name' => $p->employee_name, 'minutes' => 0.0, 'fixed' => $p->share_percent];
            foreach ($sessions->get($op->id, collect()) as $s) {
                $people[(int) $s->employee_legacy_id] ??= ['name' => $s->employee_name, 'minutes' => 0.0, 'fixed' => null];
                $people[(int) $s->employee_legacy_id]['minutes'] += (float) $s->m;
            }
            $operations[] = $this->operationRow($op->operation_name, $op->status, $op->performance_rate, $people, $fraction, $laborRatio, $ctx);
        }
        $children = [];
        $notes = [];
        if ((float) $scrapped > 0) $notes[] = "这批报废 {$scrapped} 件，报废那部分工时单独列出（见“工时去向”），不摊到订单。";
        if ($depth < self::MAX_DEPTH) {
            foreach ($this->consumed($wo) as $itemId => $batches) {
                $total = array_reduce($batches, fn ($s, $b) => bcadd($s, $b['qty'], 8), '0');
                if (bccomp($total, '0', 8) <= 0) continue;
                foreach ($batches as $b) {
                    if (bccomp($b['qty'], '0', 8) <= 0) continue;
                    $childUsed = bcmul($b['qty'], number_format($laborRatio, 10, '.', ''), 8);
                    $child = $b['batch_no'] ? $this->woByBatch($b['batch_no'], (int) $itemId) : null;
                    if (! $child || $child->id === $wo->id || in_array($child->id, $path, true)) {
                        if ($this->isMadeItem((int) $itemId)) $notes[] = $b['item_name'].' '.$this->num($childUsed).' 来自期初/外购库存（批次 '.($b['batch_no'] ?: '无').'），没有生产记录，不计。';
                        continue;
                    }
                    $children[] = $this->node($child, $childUsed, bcmul($fraction, bcdiv($b['qty'], $total, 12), 12), $ctx, $depth + 1, [...$path, $wo->id]);
                }
            }
        }
        return ['work_order_id' => (int) $wo->id, 'work_order_no' => $wo->work_order_no, 'source_type' => $wo->source_type,
            'source_label' => self::SOURCE_LABELS[$wo->source_type] ?? $wo->source_type, 'sales_order_no' => $wo->sales_order_no,
            'item_name' => $wo->item_name, 'unit_name' => $wo->unit_name, 'status' => $wo->status,
            'output_qty' => $this->num($output), 'used_qty' => $this->num($used), 'labor_ratio' => round($laborRatio, 6), 'scrapped_qty' => (float) $scrapped,
            'coverage' => round((float) $fraction, 6), 'operations' => $operations, 'children' => $children, 'notes' => $notes];
    }

    /** 发货包装作业：按这行商品在包裹里的数量占比取工时和提成。 */
    private function packing(object $line, array &$ctx): array
    {
        $contents = DB::table('erp_shipment_packing_contents as c')->join('erp_sales_shipments as s', 's.id', '=', 'c.shipment_id')
            ->where('c.sales_order_line_id', $line->id)->where('s.shipment_status', '!=', 'cancelled')->get(['c.id', 'c.shipment_id', 'c.base_qty', 's.shipment_no']);
        if ($contents->isEmpty()) return [];
        $rows = [];
        $ops = DB::table('erp_shipment_packing_operations')->whereIn('shipment_id', $contents->pluck('shipment_id')->unique())->where('status', '!=', 'CANCELLED')->orderBy('sequence')->get();
        foreach ($ops as $op) {
            $ids = array_map('intval', json_decode((string) $op->packing_content_ids, true) ?: []);
            $mine = $contents->whereIn('id', $ids);
            if ($mine->isEmpty()) continue;
            $mineQty = (string) $mine->sum(fn ($c) => (float) $c->base_qty);
            $allQty = (float) DB::table('erp_shipment_packing_contents')->whereIn('id', $ids ?: [0])->sum('base_qty');
            $laborRatio = $allQty > 0 ? min(1.0, (float) $mineQty / $allQty) : 0.0;
            $people = [];
            foreach (DB::table('erp_shipment_packing_participants')->where('operation_id', $op->id)->where('is_active', true)->get() as $p)
                $people[(int) $p->employee_legacy_id] = ['name' => $p->employee_name_snapshot, 'minutes' => 0.0, 'fixed' => null];
            foreach (DB::table('erp_shipment_packing_labor_sessions')->where('operation_id', $op->id)->whereNotNull('ended_at')
                ->groupBy('employee_legacy_id')->selectRaw('employee_legacy_id, SUM(actual_labor_minutes) AS m')->get() as $s) {
                $people[(int) $s->employee_legacy_id] ??= ['name' => '员工#'.$s->employee_legacy_id, 'minutes' => 0.0, 'fixed' => null];
                $people[(int) $s->employee_legacy_id]['minutes'] += (float) $s->m;
            }
            $row = $this->operationRow($op->operation_name_snapshot, strtolower((string) $op->status), $op->performance_rate_snapshot, $people,
                bcdiv($mineQty, $ctx['qty'], 12), $laborRatio, $ctx);
            $rows[] = ['shipment_no' => $mine->first()->shipment_no, 'packed_qty' => $this->num($mineQty)] + $row;
        }
        return $rows;
    }

    /** 一道工序：提成池 = 金额 × 占比 × 比例，按固定份额或实际工时分到人；工时按用量占比取。 */
    private function operationRow(string $name, ?string $status, $rate, array $people, string $fraction, float $laborRatio, array &$ctx): array
    {
        $fixed = $people !== [] && collect($people)->every(fn ($p) => $p['fixed'] !== null) && abs(collect($people)->sum(fn ($p) => (float) $p['fixed']) - 100) < 0.01;
        $shares = [];
        if ($fixed) {
            foreach ($people as $id => $p) $shares[$id] = bcdiv((string) $p['fixed'], '100', 8);
        } else {
            foreach ($this->calc->laborShares(array_map(fn ($id, $p) => ['employee_legacy_id' => $id, 'actual_labor_minutes' => $p['minutes']], array_keys($people), $people)) as $s)
                $shares[(int) $s['employee_legacy_id']] = $s['share_ratio'];
        }
        $hasRate = $rate !== null && bccomp((string) $rate, '0', 8) > 0;
        $pool = $hasRate && $ctx['amount'] !== null ? bcmul(bcmul($ctx['amount'], $fraction, 12), (string) $rate, 12) : '0';
        $employees = [];
        $totalMinutes = 0.0;
        foreach ($people as $id => $p) {
            $share = $shares[$id] ?? '0';
            $amount = bcmul($pool, $share, 12);
            $minutes = $p['minutes'] * $laborRatio;
            $totalMinutes += $p['minutes'];
            $employees[] = ['employee_legacy_id' => $id, 'employee_name' => $p['name'], 'share_ratio' => (float) $share,
                'share_mode' => $fixed ? 'fixed' : 'labor', 'labor_minutes_total' => round($p['minutes'], 1),
                'labor_minutes' => round($minutes, 1), 'performance_amount' => $ctx['amount'] === null ? null : $this->calc->round($amount)];
            $ctx['employees'][$id] ??= ['employee_legacy_id' => $id, 'employee_name' => $p['name'], 'labor_minutes' => 0.0, 'performance_amount' => '0', 'operations' => 0];
            $ctx['employees'][$id]['labor_minutes'] += $minutes;
            $ctx['employees'][$id]['performance_amount'] = bcadd($ctx['employees'][$id]['performance_amount'], $amount, 12);
            $ctx['employees'][$id]['operations']++;
        }
        $redact = ! isset($ctx['line']) || $ctx['amount'] === null;
        return ['operation_name' => $name, 'status' => $status, 'performance_rate' => $hasRate ? (float) $rate : null,
            'basis_amount' => $redact || ! $hasRate ? null : $this->calc->round(bcmul($ctx['amount'], $fraction, 12)),
            'performance_pool_amount' => $redact || ! $hasRate ? null : $this->calc->round($pool),
            'labor_minutes_total' => round($totalMinutes, 1), 'labor_minutes' => round($totalMinutes * $laborRatio, 1),
            'share_mode' => $fixed ? 'fixed' : 'labor', 'employees' => $employees];
    }

    /** 工单实际领用（扣掉退料）的物料，按物料、库存批次汇总。 */
    private function consumed(object $wo): array
    {
        $issued = DB::table('erp_inventory_transaction_items as ti')->join('erp_mfg_material_issues as i', 'i.id', '=', 'ti.source_id')
            ->where('ti.source_type', 'mfg_material_issue')->where('i.work_order_id', $wo->id)
            ->groupBy('ti.item_id', 'ti.item_name', 'ti.batch_no')->selectRaw('ti.item_id, ti.item_name, ti.batch_no, SUM(-ti.change_qty) AS qty')->get();
        if ($issued->isEmpty()) return [];
        $returned = DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
            ->where('r.status', 'approved')->where('l.work_order_id', $wo->id)
            ->groupBy('l.component_item_id', 'l.batch_no')->selectRaw('l.component_item_id, l.batch_no, SUM(l.qty) AS qty')->get()
            ->keyBy(fn ($r) => $r->component_item_id.'|'.$r->batch_no);
        $out = [];
        foreach ($issued as $r) {
            if (! $this->isMadeItem((int) $r->item_id) && ! ($r->batch_no && $this->woByBatch((string) $r->batch_no, (int) $r->item_id))) continue;
            $net = bcsub((string) $r->qty, (string) ($returned[$r->item_id.'|'.$r->batch_no]->qty ?? '0'), 8);
            $out[(int) $r->item_id][] = ['item_name' => $r->item_name, 'batch_no' => $r->batch_no, 'qty' => $net];
        }
        return $out;
    }

    private function shippedLines(int $orderId)
    {
        return $this->shipped[$orderId] ??= DB::table('erp_sales_shipment_lines as l')->join('erp_sales_shipments as s', 's.id', '=', 'l.shipment_id')
            ->where('s.sales_order_id', $orderId)->where('s.shipment_status', '!=', 'cancelled')
            ->where(fn ($w) => $w->whereNotNull('l.dispatched_at')->orWhereIn('s.shipment_status', ['shipped', 'completed']))
            ->get(['l.sales_order_line_id', 'l.item_id', 'l.batch_no', 'l.sales_qty', 'l.base_qty']);
    }

    private function woByBatch(string $batchNo, int $itemId): ?object
    {
        $key = $itemId.'|'.$batchNo;
        if (! array_key_exists($key, $this->woCache)) {
            $this->woCache[$key] = DB::table('erp_mfg_work_orders')->where('work_order_no', $batchNo)->where('item_id', $itemId)->first();
        }
        return $this->woCache[$key];
    }

    private function isMadeItem(int $itemId): bool
    {
        $this->itemTypes[$itemId] ??= (string) DB::table('erp_items')->where('id', $itemId)->value('item_type');
        return in_array($this->itemTypes[$itemId], ['semi_finished', 'finished_product'], true);
    }

    /** 工单产出：已完工按实际入库数，未完工按计划数。 */
    private function output(object $wo): string
    {
        $stored = (string) ($wo->stored_qty ?? '0');
        if (in_array($wo->status, ['completed', 'closed'], true) && bccomp($stored, '0', 8) > 0) return $stored;
        return bccomp((string) $wo->planned_qty, $stored, 8) >= 0 ? (string) $wo->planned_qty : $stored;
    }

    private function num(string $value): float
    {
        return round((float) $value, 4);
    }
}
