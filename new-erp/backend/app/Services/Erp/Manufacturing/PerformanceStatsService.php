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
 * 返工工时（返工计时、总装退回不良开的返工工单）单独列出，不参与提成分配，也不算进正常工时。
 */
final class PerformanceStatsService
{
    private const SKIP_TYPES = ['service', 'no_delivery', 'fee', 'auxiliary', 'deduction'];
    private const MAX_DEPTH = 6;
    private const SOURCE_LABELS = ['sales_order' => '订单工单', 'stock' => '备货工单', 'work_order' => '半成品工单', 'rework' => '返工工单'];

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
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('erp_cancellation_production_dispositions as d')->whereColumn('d.sales_order_id', 'o.id'))
                    ->orWhereExists(fn ($s) => $s->selectRaw('1')->from('erp_sales_shipments as sh')->whereColumn('sh.sales_order_id', 'o.id')
                        ->where('sh.shipment_status', '!=', 'cancelled'));
            })
            ->when($keyword !== '', fn ($q) => $q->where(fn ($w) => $w->where('o.sales_order_no', 'like', "%{$keyword}%")
                ->orWhere('o.customer_name', 'like', "%{$keyword}%")))
            ->orderByDesc('o.id')
            ->paginate(min(30, max(1, (int) ($filters['per_page'] ?? 10))), ['o.id'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $rows = [];
        foreach ($page->items() as $row) {
            $d = $this->order((int) $row->id, $amounts);
            $rows[] = ['id' => $d['order']['id'], 'sales_order_no' => $d['order']['sales_order_no'], 'customer_name' => $d['order']['customer_name'],
                'order_date' => $d['order']['order_date'], 'status' => $d['status'], 'status_label' => $d['status_label'],
                'goods_amount' => $d['totals']['goods_amount'], 'performance_amount' => $d['totals']['performance_amount'],
                'labor_minutes' => $d['totals']['labor_minutes'], 'rework_minutes' => $d['totals']['rework_minutes'], 'employee_count' => count($d['employees']), 'issue_count' => count($d['issues'])];
        }
        return ['data' => $rows, 'total' => $page->total(), 'current_page' => $page->currentPage(), 'per_page' => $page->perPage()];
    }

    public function order(int $id, bool $amounts, array $laborFilters = []): array
    {
        $order = DB::table('erp_sales_orders')->where('id', $id)->first();
        if (! $order) throw new WorkOrderDomainException('order_not_found', '订单不存在。', 404);
        $issues = [];
        $foreign = strtoupper((string) ($order->currency ?: 'CNY')) !== strtoupper((string) ($order->base_currency ?: 'CNY'));
        $rate = ! $foreign ? '1' : (bccomp((string) ($order->business_exchange_rate ?? 0), '0', 10) > 0 ? (string) $order->business_exchange_rate : null);
        if ($rate === null) $issues[] = '外币订单还没有业务汇率，提成金额暂不能折算人民币。';
        $lines = DB::table('erp_sales_order_lines')->where('sales_order_id', $id)->where('line_status', '!=', 'removed')->orderBy('sort_order')->orderBy('id')->get();
        $shippedByLine = $this->shippedLines($id)->groupBy('sales_order_line_id')
            ->map(fn ($rows) => ['sales_qty' => (string) $rows->sum(fn ($r) => (float) $r->sales_qty)])->all();
        $readiness = $this->calc->shipmentReadiness($lines->map(fn ($l) => (array) $l)->all(), $shippedByLine);
        $employees = [];
        $out = [];
        $goodsAmount = '0';
        $blocks = [];
        foreach ($lines as $line) {
            if (in_array($line->line_type, self::SKIP_TYPES, true) || $line->line_status === 'cancelled') continue;
            $qty = bccomp((string) ($line->item_base_required_qty ?? 0), '0', 8) > 0 ? (string) $line->item_base_required_qty : (string) $line->order_qty;
            if (bccomp($qty, '0', 8) <= 0) continue;
            $amount = $rate === null || $line->amount_incl_tax === null ? null : bcmul((string) $line->amount_incl_tax, $rate, 4);
            if ($amount !== null) $goodsAmount = bcadd($goodsAmount, $amount, 4);
            $ctx = ['amount' => $amount, 'qty' => $qty, 'issues' => &$issues, 'employees' => &$employees, 'line' => $line, 'blocks' => &$blocks];
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
                // 根计划：半成品单独销售用发货行上冻结的计划；其余用产出工单冻结的计划。
                $ctx['root_plan'] = $src['shipment_plan'] ?? null
                    ? (is_array($src['shipment_plan']) ? $src['shipment_plan'] : json_decode((string) $src['shipment_plan'], true))
                    : (is_string($src['wo']->performance_plan ?? null) ? json_decode($src['wo']->performance_plan, true) : null);
                $sources[] = ['kind' => $src['kind'], 'kind_label' => $src['label']] + $this->node($src['wo'], $src['qty'], $fraction, $ctx, 0, [], '', $src['unit_ids'] ?? null);
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
        $reworkTotal = 0.0;
        foreach ($employeeRows as &$e) {
            $perfTotal = bcadd($perfTotal, $e['performance_amount'], 4);
            $laborTotal += $e['labor_minutes'];
            $reworkTotal += $e['rework_minutes'];
            $e['labor_minutes'] = round($e['labor_minutes'], 1);
            $e['rework_minutes'] = round($e['rework_minutes'], 1);
            $e['performance_amount'] = $amounts ? $this->calc->round($e['performance_amount']) : null;
        }
        unset($e);
        $settleable = $readiness['entire_order_shipped'] && ! $blocks;
        return [
            'order' => ['id' => (int) $order->id, 'sales_order_no' => $order->sales_order_no, 'customer_name' => $order->customer_name,
                'order_date' => $order->order_date, 'currency' => $order->currency, 'exchange_rate' => $foreign ? $rate : null],
            'status' => $settleable ? 'settleable' : 'estimate', 'status_label' => $settleable ? '已全部发货，可结算' : '未全部发货，以下为预估',
            'readiness' => ['goods_lines_total' => $readiness['goods_lines_total'], 'fully_shipped_lines' => $readiness['fully_shipped_lines']],
            'rule' => '订单折后含税金额 × 工序提成比例 × 占比；用库存半成品/备货整机的，按库存批次找到当初的工单，用了多少取多少（工时 = 那批总工时 × 用量 ÷ 那批产出）。',
            'lines' => $out, 'employees' => $employeeRows,
            'totals' => ['goods_amount' => $amounts ? $this->calc->round($goodsAmount) : null,
                'performance_amount' => $amounts ? $this->calc->round($perfTotal) : null, 'labor_minutes' => round($laborTotal, 1),
                'rework_minutes' => round($reworkTotal, 1)],
            'issues' => array_values(array_unique($issues)), 'amount_visible' => $amounts,
            'block_reasons' => array_values(array_unique($blocks)),
            'change_labor' => app(OrderChangeLaborService::class)->order($id, $laborFilters),
        ];
    }

    /** 这一行商品由哪些工单产出：已发货的按发货批次号找工单；还没发货的按订单工单预估。 */
    private function lineSources(object $line, string $qty): array
    {
        $sources = [];
        $shippedByBatch = [];
        // 同一批次的多次发货可能冻结不同方案，来源必须保留到发货行。
        foreach ($this->shippedLines((int) $line->sales_order_id)->where('sales_order_line_id', $line->id) as $shipment) {
            $q = (string) $shipment->base_qty;
            $wo = $shipment->batch_no ? $this->woByBatch((string) $shipment->batch_no, (int) $shipment->item_id) : null;
            if ($wo) $shippedByBatch[$wo->id] = bcadd($shippedByBatch[$wo->id] ?? '0', $q, 8);
            $sources[] = ['kind' => 'shipped', 'label' => $wo && $wo->source_type !== 'sales_order' ? '已发货（用库存现货）' : '已发货',
                'wo' => $wo, 'qty' => $q, 'batch_no' => $shipment->batch_no, 'shipment_plan' => $shipment->performance_plan ?? null,
                'unit_ids' => $wo ? $this->shipmentUnitIds($wo, $shipment, (int) $line->sales_order_id) : null];
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
            $sources[] = ['kind' => 'pending', 'label' => '未发货（按订单工单预估）', 'wo' => $wo, 'qty' => $remain, 'batch_no' => $wo->work_order_no,
                'unit_ids' => CancellationProductionService::salesDemandUnitIds((int) $wo->id)];
        }
        return $sources;
    }

    /** 一张工单的工序工时/提成，以及它用到的半成品来源（递归）。$positionPath 是这张工单在根 BOM 里的位置路径，空串表示根工单本身。 */
    private function node(object $wo, string $used, string $fraction, array &$ctx, int $depth, array $path, string $positionPath = '', ?array $unitIds = null): array
    {
        $isRoot = $depth === 0;
        $scrapped = (string) DB::table('erp_mfg_unit_reports')->where('work_order_id', $wo->id)->where('result', 'scrap')->where('review_status', 'approved')->distinct()->count('unit_id');
        $output = $this->output($wo);
        // 工时按“合格 + 报废”平摊，报废那部分单独列出，不摊给订单。
        $base = in_array($wo->status, ['completed', 'closed'], true) ? bcadd($output, $scrapped, 8) : (bccomp($output, $scrapped, 8) > 0 ? $output : $scrapped);
        $laborRatio = bccomp($base, '0', 8) > 0 ? min(1.0, (float) bcdiv($used, $base, 10)) : 0.0;
        $legacy = OrderChangeLaborService::legacyUnitIds((int) $wo->id);
        $scopeFraction = $unitIds === null ? 1 : min(1.0, (float) $used / max(1, count($unitIds)));
        $operations = [];
        $ops = DB::table('erp_mfg_work_order_operations')->where('work_order_id', $wo->id)->where('execution_context', 'production')->orderBy('sequence')->get();
        $sessionsOf = fn (bool $rework) => DB::table('erp_mfg_labor_sessions')->where('work_order_id', $wo->id)->where('status', '!=', 'active')
            ->where('is_rework', $rework)->get()->map(function ($session) use ($unitIds, $scopeFraction, $legacy) {
                $session->m = (float) $session->labor_minutes;
                $session->scoped_m = $unitIds === null ? null : $session->m * $this->scopeRatio($session, $unitIds, $legacy) * $scopeFraction;
                return $session;
            })->groupBy('work_order_operation_id');
        $sessions = $sessionsOf(false);
        $reworkSessions = $sessionsOf(true);
        $participants = DB::table('erp_mfg_task_participants')->where('work_order_id', $wo->id)->where('lane', 'normal')->where('is_active', true)->get()->groupBy('work_order_operation_id');
        $runAllocs = DB::table('erp_mfg_cutting_run_allocations as a')->join('erp_mfg_cutting_runs as r', 'r.id', '=', 'a.cutting_run_id')
            ->where('a.work_order_id', $wo->id)->where('r.status', 'finished')
            ->select(['a.*', 'r.run_no'])->selectRaw('a.pieces AS n, a.labor_minutes AS m')->get()->groupBy('work_order_operation_id');
        $notes = [];
        // 子工单节点比例：按 (operation_id, 第几次出现) 回到根工单整套提成计划查；查不到记未配置，不回退自身比例。
        $aloneSale = $isRoot && ($ctx['root_plan']['source'] ?? null) === 'alone_sale';
        $occMap = ! $isRoot || $aloneSale ? app(PerformancePlanRateService::class)->occurrenceMap($wo) : [];
        foreach ($ops as $op) {
            $rate = $op->performance_rate;
            if (! $isRoot || $aloneSale) {
                $identity = $occMap[(int) $op->id] ?? null;
                $resolved = ! $identity ? ['rate' => null, 'source' => 'missing'] : ($aloneSale
                    ? app(PerformancePlanRateService::class)->ownPlanRate($ctx['root_plan'], $identity['operation_id'], $identity['occurrence'])
                    : app(PerformancePlanRateService::class)->positionRate($ctx['root_plan'] ?? null, $positionPath, $identity['operation_id'], $identity['occurrence']));
                $rate = $resolved['rate'];
                if ($resolved['source'] === 'missing' || $rate === null) {
                    $location = $aloneSale ? '本次单独销售方案' : "根 BOM 位置 {$positionPath}";
                    $ctx['blocks'][] = $ctx['issues'][] = "未配置提成：{$wo->item_name} {$op->operation_name}（{$location}）";
                }
            }
            $people = [];
            foreach ($participants->get($op->id, collect()) as $p) $people[(int) $p->employee_legacy_id] = ['name' => $p->employee_name, 'minutes' => 0.0, 'fixed' => $p->share_percent];
            foreach ($sessions->get($op->id, collect()) as $s) {
                $people[(int) $s->employee_legacy_id] ??= ['name' => $s->employee_name, 'minutes' => 0.0, 'fixed' => null];
                $people[(int) $s->employee_legacy_id]['minutes'] += (float) $s->m;
                if ($unitIds !== null) $people[(int) $s->employee_legacy_id]['attributed_minutes'] = ($people[(int) $s->employee_legacy_id]['attributed_minutes'] ?? 0) + $s->scoped_m;
            }
            // 集中开料：这道下料用了开料记录的件，带过来的工时按开料的人各自实际计时占比分到人，提成和本道计时的人一起按工时分。
            foreach ($runAllocs->get($op->id, collect()) as $a) {
                $runPeople = DB::table('erp_mfg_cutting_run_sessions')->where('cutting_run_id', $a->cutting_run_id)->whereNotNull('ended_at')
                    ->groupBy('employee_legacy_id', 'employee_name')->selectRaw('employee_legacy_id, employee_name, SUM(labor_minutes) AS m')->get();
                $runTotal = (float) $runPeople->sum('m');
                foreach ($people as &$p) $p['fixed'] = null;
                unset($p);
                foreach ($runPeople as $rp) {
                    $people[(int) $rp->employee_legacy_id] ??= ['name' => $rp->employee_name, 'minutes' => 0.0, 'fixed' => null];
                    $people[(int) $rp->employee_legacy_id]['minutes'] += $runTotal > 0 ? (float) $a->m * (float) $rp->m / $runTotal : 0;
                    if ($unitIds !== null) $people[(int) $rp->employee_legacy_id]['attributed_minutes'] = ($people[(int) $rp->employee_legacy_id]['attributed_minutes'] ?? 0)
                        + ($runTotal > 0 ? (float) $a->m * (float) $rp->m / $runTotal : 0) * $this->scopeRatio($a, $unitIds, $legacy) * $scopeFraction;
                }
                $notes[] = "{$op->operation_name}用了集中开料 {$a->run_no} 的 ".(int) $a->n.' 件，按件数带过来 '.round((float) $a->m, 1).' 分钟开料工时。';
            }
            $rework = [];
            foreach ($reworkSessions->get($op->id, collect()) as $s) {
                $rework[(int) $s->employee_legacy_id] ??= ['name' => $s->employee_name, 'minutes' => 0.0];
                $rework[(int) $s->employee_legacy_id]['minutes'] += (float) $s->m;
                if ($unitIds !== null) $rework[(int) $s->employee_legacy_id]['attributed_minutes'] = ($rework[(int) $s->employee_legacy_id]['attributed_minutes'] ?? 0) + $s->scoped_m;
            }
            $operations[] = $this->operationRow($op->operation_name, $op->status, $rate, $people, $fraction, $laborRatio, $ctx, $rework);
        }
        // 总装退回不良件开的返工工单：工时记在这张工单名下，全部算返工，不算提成。
        $reworkOrders = [];
        $reworkWos = DB::table('erp_mfg_work_order_links as l')->join('erp_mfg_work_orders as c', 'c.id', '=', 'l.child_work_order_id')
            ->where('l.parent_work_order_id', $wo->id)->whereNotNull('c.rework_of_work_order_id')->where('c.status', '!=', 'cancelled')
            ->orderBy('c.id')->get(['c.id', 'c.work_order_no', 'c.item_name', 'c.planned_qty', 'c.unit_name', 'c.status']);
        foreach ($reworkWos as $rw) {
            $people = DB::table('erp_mfg_labor_sessions')->where('work_order_id', $rw->id)->where('status', '!=', 'active')
                ->groupBy('employee_legacy_id', 'employee_name')->selectRaw('employee_legacy_id, employee_name, SUM(labor_minutes) AS m')->get();
            $rows = [];
            foreach ($people as $p) {
                $minutes = (float) $p->m * $laborRatio;
                $rows[] = ['employee_legacy_id' => (int) $p->employee_legacy_id, 'employee_name' => $p->employee_name, 'rework_minutes' => round($minutes, 1)];
                $this->addRework($ctx, (int) $p->employee_legacy_id, $p->employee_name, $minutes);
            }
            $reworkOrders[] = ['work_order_id' => (int) $rw->id, 'work_order_no' => $rw->work_order_no, 'item_name' => $rw->item_name,
                'qty' => (float) $rw->planned_qty, 'unit_name' => $rw->unit_name, 'status' => $rw->status,
                'rework_minutes' => round((float) $people->sum('m') * $laborRatio, 1), 'employees' => $rows];
        }
        $children = [];
        if ((float) $scrapped > 0) $notes[] = "这批报废 {$scrapped} 件，报废那部分工时单独列出（见“工时去向”），不摊到订单。";
        if ($depth < self::MAX_DEPTH) {
            foreach ($this->consumed($wo) as $pos) {
                // 位置提成池：外购/期初批占分母但不计酬；超领/报废不放大池子。
                $total = array_reduce($pos['batches'], fn ($s, $b) => bcadd($s, $b['qty'], 8), '0');
                if (bccomp($total, '0', 8) <= 0) continue;
                // 提成池按完整位置需求封顶，分批发货不能放大池子；工时继续沿原有用量分摊。
                $denom = bccomp($total, (string) $pos['required'], 8) >= 0 ? $total : (string) $pos['required'];
                if (bccomp($denom, '0', 8) <= 0) continue;
                $fullPath = $positionPath === '' ? (string) $pos['position_path'] : $positionPath.'/'.$pos['position_path'];
                foreach ($pos['batches'] as $b) {
                    if (bccomp($b['qty'], '0', 8) <= 0) continue;
                    $share = bcdiv($b['qty'], $denom, 12);
                    $reqUsed = bccomp($output, '0', 8) > 0 ? bcmul((string) $b['material_required'], bcdiv($used, $output, 12), 8) : '0';
                    $laborDenom = bccomp($b['material_total'], $reqUsed, 8) >= 0 ? $b['material_total'] : $reqUsed;
                    $childUsed = bcmul($used, bcdiv($b['qty'], $laborDenom, 12), 8);
                    $child = $b['batch_no'] ? $this->woByBatch((string) $b['batch_no'], (int) $pos['item_id']) : null;
                    if (! $child || $child->id === $wo->id || in_array($child->id, $path, true)) {
                        if ($this->isMadeItem((int) $pos['item_id'])) $notes[] = $pos['item_name'].' '.$this->num($childUsed).' 来自期初/外购库存（批次 '.($b['batch_no'] ?: '无').'），没有生产记录，不计。';
                        continue;
                    }
                    if ($pos['position_path'] === '') $ctx['issues'][] = "半成品 {$pos['item_name']} 的用料行缺位置标识，整套提成无法定位，按未配置处理。";
                    $children[] = $this->node($child, $childUsed, bcmul($fraction, $share, 12), $ctx, $depth + 1, [...$path, $wo->id], $fullPath,
                        $this->childUnitIds($wo, $child, $unitIds));
                }
            }
        }
        return ['work_order_id' => (int) $wo->id, 'work_order_no' => $wo->work_order_no, 'source_type' => $wo->source_type,
            'source_label' => self::SOURCE_LABELS[$wo->source_type] ?? $wo->source_type, 'sales_order_no' => $wo->sales_order_no,
            'item_name' => $wo->item_name, 'unit_name' => $wo->unit_name, 'status' => $wo->status,
            'output_qty' => $this->num($output), 'used_qty' => $this->num($used), 'labor_ratio' => round($laborRatio, 6), 'scrapped_qty' => (float) $scrapped,
            'coverage' => round((float) $fraction, 6), 'operations' => $operations, 'children' => $children, 'rework_orders' => $reworkOrders, 'notes' => $notes,
            'change_labor_origins' => app(OrderChangeLaborService::class)->origins((int) $wo->id),
            'sourcing_unit_ids' => $unitIds, 'labor_allocation_method' => $unitIds === null ? 'source_quantity' : 'recorded_units_and_shared_labor',
            'labor_trace_keys' => DB::table('erp_mfg_labor_sessions')->where('work_order_id', $wo->id)->orderBy('id')->pluck('id')->map(fn ($id) => 'mfg:'.$id)->all()];
    }

    private function addRework(array &$ctx, int $id, string $name, float $minutes): void
    {
        $ctx['employees'][$id] ??= ['employee_legacy_id' => $id, 'employee_name' => $name, 'labor_minutes' => 0.0, 'rework_minutes' => 0.0, 'performance_amount' => '0', 'operations' => 0];
        $ctx['employees'][$id]['rework_minutes'] += $minutes;
    }

    /** 发货包装作业：按这行商品在包裹里的数量占比取工时和提成。 */
    private function packing(object $line, array &$ctx): array
    {
        $contents = DB::table('erp_shipment_packing_contents as c')->join('erp_sales_shipments as s', 's.id', '=', 'c.shipment_id')
            ->join('erp_sales_shipment_lines as sl', 'sl.id', '=', 'c.shipment_line_id')->whereNotIn('sl.line_status', ['cancelled', 'replaced'])
            ->where('c.sales_order_line_id', $line->id)->where('s.shipment_status', '!=', 'cancelled')
            ->get(['c.id', 'c.shipment_id', 'c.base_qty', 'c.routing_snapshot', 's.shipment_no', 'sl.batch_no', 'sl.item_id', 'sl.performance_plan']);
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
            // D4：混批包裹按包裹内容各自的产出工单冻结路线比例计酬，再合计；工时仍按数量占比分摊。
            foreach ($mine->groupBy(function ($c) use ($op) {
                $r = app(PerformancePlanRateService::class)->packingContentRate($c, $op, fn ($b, $i) => $this->woByBatch($b, $i));
                return (string) ($r['rate'] ?? 'x').'|'.$r['source'];
            }) as $key => $group) {
                [$rate] = explode('|', $key);
                $groupQty = (string) $group->sum(fn ($c) => (float) $c->base_qty);
                if ($rate === 'x' || $rate === '') {
                    $ctx['blocks'][] = $ctx['issues'][] = "未配置提成：{$op->operation_name_snapshot}（发货包裹）";
                    $rate = null;
                }
                $row = $this->operationRow($op->operation_name_snapshot, strtolower((string) $op->status), $rate === null ? null : $rate, $people,
                    bcdiv($groupQty, $ctx['qty'], 12), $laborRatio * ($allQty > 0 ? (float) $groupQty / $allQty : 0), $ctx);
                $rows[] = ['shipment_no' => $group->first()->shipment_no, 'packed_qty' => $this->num($groupQty)] + $row;
            }
        }
        return $rows;
    }

    /** 一道工序：提成池 = 金额 × 占比 × 比例，按固定份额或实际工时分到人；工时按用量占比取。$rework 返工计时单独列，不分提成。 */
    private function operationRow(string $name, ?string $status, $rate, array $people, string $fraction, float $laborRatio, array &$ctx, array $rework = []): array
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
        $attributedTotal = 0.0;
        foreach ($people as $id => $p) {
            $share = $shares[$id] ?? '0';
            $amount = bcmul($pool, $share, 12);
            $minutes = $p['attributed_minutes'] ?? ($p['minutes'] * $laborRatio);
            $attributedTotal += $minutes;
            $totalMinutes += $p['minutes'];
            $employees[] = ['employee_legacy_id' => $id, 'employee_name' => $p['name'], 'share_ratio' => (float) $share,
                'share_mode' => $fixed ? 'fixed' : 'labor', 'labor_minutes_total' => round($p['minutes'], 1),
                'labor_minutes' => round($minutes, 1), 'performance_amount' => $ctx['amount'] === null ? null : $this->calc->round($amount)];
            $ctx['employees'][$id] ??= ['employee_legacy_id' => $id, 'employee_name' => $p['name'], 'labor_minutes' => 0.0, 'rework_minutes' => 0.0, 'performance_amount' => '0', 'operations' => 0];
            $ctx['employees'][$id]['labor_minutes'] += $minutes;
            $ctx['employees'][$id]['performance_amount'] = bcadd($ctx['employees'][$id]['performance_amount'], $amount, 12);
            $ctx['employees'][$id]['operations']++;
        }
        $reworkRows = [];
        $reworkTotal = 0.0;
        $attributedRework = 0.0;
        foreach ($rework as $id => $p) {
            $reworkTotal += $p['minutes'];
            $minutes = $p['attributed_minutes'] ?? ($p['minutes'] * $laborRatio);
            $attributedRework += $minutes;
            $reworkRows[] = ['employee_legacy_id' => $id, 'employee_name' => $p['name'], 'rework_minutes_total' => round($p['minutes'], 1),
                'rework_minutes' => round($minutes, 1)];
            $this->addRework($ctx, $id, $p['name'], $minutes);
        }
        $redact = ! isset($ctx['line']) || $ctx['amount'] === null;
        return ['operation_name' => $name, 'status' => $status, 'performance_rate' => $hasRate ? (float) $rate : null,
            'basis_amount' => $redact || ! $hasRate ? null : $this->calc->round(bcmul($ctx['amount'], $fraction, 12)),
            'performance_pool_amount' => $redact || ! $hasRate ? null : $this->calc->round($pool),
            'labor_minutes_total' => round($totalMinutes, 1), 'labor_minutes' => round($attributedTotal, 1),
            'share_mode' => $fixed ? 'fixed' : 'labor', 'employees' => $employees,
            'rework_minutes_total' => round($reworkTotal, 1), 'rework_minutes' => round($attributedRework, 1), 'rework_employees' => $reworkRows];
    }

    /** 退料先按 material_id 回冲；提成按位置合池，工时仍保留每条物理用料行的原分母。 */
    private function consumed(object $wo): array
    {
        $materials = DB::table('erp_mfg_work_order_materials')->where('work_order_id', $wo->id)->get()->keyBy('id');
        $issued = DB::table('erp_inventory_transaction_items as ti')->join('erp_mfg_material_issues as i', 'i.id', '=', 'ti.source_id')
            ->where('ti.source_type', 'mfg_material_issue')->where('i.work_order_id', $wo->id)
            ->groupBy('ti.source_item_id', 'ti.item_id', 'ti.item_name', 'ti.batch_no')
            ->selectRaw('ti.source_item_id, ti.item_id, ti.item_name, ti.batch_no, SUM(-ti.change_qty) AS qty')->get();
        if ($issued->isEmpty()) return [];
        $returned = DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
            ->where('r.status', 'approved')->where('l.work_order_id', $wo->id)
            ->groupBy('l.material_id', 'l.component_item_id', 'l.batch_no')->selectRaw('l.material_id, l.component_item_id, l.batch_no, SUM(l.qty) AS qty')->get()
            ->keyBy(fn ($r) => (int) ($r->material_id ?? 0).'|'.$r->component_item_id.'|'.$r->batch_no);
        $out = [];
        $required = [];
        $materialTotals = [];
        foreach ($materials as $material) {
            $posKey = $material->position_path ?: '#'.$material->id;
            $required[$posKey] = ($required[$posKey] ?? 0) + (float) $material->required_qty;
        }
        foreach ($issued as $r) {
            if (! $this->isMadeItem((int) $r->item_id) && ! ($r->batch_no && $this->woByBatch((string) $r->batch_no, (int) $r->item_id))) continue;
            $key = (int) $r->source_item_id.'|'.$r->item_id.'|'.$r->batch_no;
            $net = bcsub((string) $r->qty, (string) ($returned[$key]->qty ?? '0'), 8);
            $mat = $materials->get((int) $r->source_item_id);
            $positionPath = (string) ($mat?->position_path ?? '');
            $posKey = $positionPath !== '' ? $positionPath : '#'.$r->source_item_id;
            $materialTotals[(int) $r->source_item_id] = bcadd($materialTotals[(int) $r->source_item_id] ?? '0', bccomp($net, '0', 8) > 0 ? $net : '0', 8);
            $out[$posKey] ??= ['item_id' => (int) $r->item_id, 'item_name' => $r->item_name,
                'position_path' => $positionPath, 'required' => $required[$posKey] ?? 0, 'batches' => []];
            $out[$posKey]['batches'][] = ['batch_no' => $r->batch_no, 'qty' => bccomp($net, '0', 8) > 0 ? $net : '0',
                'material_id' => (int) $r->source_item_id, 'material_required' => (float) ($mat?->required_qty ?? 0)];
        }
        foreach ($out as &$position) {
            foreach ($position['batches'] as &$batch) $batch['material_total'] = $materialTotals[$batch['material_id']] ?? '0';
            unset($batch);
        }
        unset($position);
        return $out;
    }

    private function shippedLines(int $orderId)
    {
        return $this->shipped[$orderId] ??= DB::table('erp_sales_shipment_lines as l')->join('erp_sales_shipments as s', 's.id', '=', 'l.shipment_id')
            ->where('s.sales_order_id', $orderId)->where('s.shipment_status', '!=', 'cancelled')
            ->whereNotIn('l.line_status', ['cancelled', 'replaced'])
            ->where(fn ($w) => $w->whereNotNull('l.dispatched_at')->orWhereIn('s.shipment_status', ['shipped', 'completed']))
            ->get(['l.sales_order_line_id', 'l.item_id', 'l.batch_no', 'l.sales_qty', 'l.base_qty', 'l.performance_plan', 'l.serial_snapshot', 'l.inventory_reservation_id']);
    }

    private function scopeRatio(object $source, array $unitIds, array $legacy): float
    {
        $cohort = OrderChangeLaborService::recordedUnitIds($source) ?? $legacy;
        return $cohort ? count(array_intersect($cohort, $unitIds)) / count($cohort) : 0;
    }

    private function shipmentUnitIds(object $wo, object $shipment, int $orderId): ?array
    {
        $serials = json_decode((string) $shipment->serial_snapshot, true) ?: [];
        $ids = DB::table('erp_mfg_work_order_units')->where('work_order_id', $wo->id)->whereIn('inventory_serial_id', $serials['inventory_serial_ids'] ?? [])->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids) return $ids;
        $reservation = $shipment->inventory_reservation_id ? DB::table('erp_inventory_reservations')->where('id', $shipment->inventory_reservation_id)->first() : null;
        $snapshot = $reservation ? json_decode((string) $reservation->reservation_snapshot, true) : [];
        if (!empty($snapshot['serial_nos'])) return DB::table('erp_mfg_work_order_units')->where('work_order_id', $wo->id)->whereIn('serial_no', $snapshot['serial_nos'])->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ((int) $wo->sales_order_id === $orderId) return CancellationProductionService::salesDemandUnitIds((int) $wo->id);
        $stock = DB::table('erp_cancellation_production_dispositions')->where('work_order_id', $wo->id)->where('status', 'stock')->whereNotNull('work_order_unit_id')->pluck('work_order_unit_id')->map(fn ($id) => (int) $id)->all();
        return $stock ?: null;
    }

    private function childUnitIds(object $parent, object $child, ?array $parentUnitIds): ?array
    {
        if ($parentUnitIds !== null) {
            $serials = \App\Models\Erp\MfgUnitComponent::installed()->where('work_order_id', $parent->id)->whereIn('unit_id', $parentUnitIds)
                ->where('component_item_id', $child->item_id)->where('batch_no', $child->work_order_no)->pluck('component_serial_no')->filter()->all();
            $ids = DB::table('erp_mfg_work_order_units')->where('work_order_id', $child->id)->whereIn('serial_no', $serials)->pluck('id')->map(fn ($id) => (int) $id)->all();
            if ($ids) return $ids;
        }
        if (DB::table('erp_cancellation_production_dispositions')->where('work_order_id', $child->id)->exists()) {
            $originalParent = DB::table('erp_mfg_work_order_links')->where('child_work_order_id', $child->id)->value('parent_work_order_id');
            return (int) $originalParent === (int) $parent->id ? CancellationProductionService::salesDemandUnitIds((int) $child->id)
                : DB::table('erp_cancellation_production_dispositions')->where('work_order_id', $child->id)->where('status', 'stock')->whereNotNull('work_order_unit_id')->pluck('work_order_unit_id')->map(fn ($id) => (int) $id)->all();
        }
        return null;
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
