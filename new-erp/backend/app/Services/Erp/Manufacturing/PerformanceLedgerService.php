<?php

namespace App\Services\Erp\Manufacturing;

use App\Exceptions\Erp\WorkOrderDomainException;
use App\Services\Erp\ProductionPerformanceCalculator;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * 工时/提成按“产品出库”结算的台账（只读，按事实实时推算，不改历史）。
 * - 每张工单的工时挂在它做出来的产品上；产品出库（直接发货，或被领用进别的产品、那个产品出库）时按出库件数比例结算：
 *   已结算工时 = 工单工时 × 已出库件数 ÷ 产出件数；没出库的部分一直挂着，叫“未结算”。
 * - 报废单独列：工时按“合格 + 报废”件数平摊，报废那部分在报废审批当月记为“报废工时”，不摊给合格品、不进订单。
 * - 月度滚动：上月结转（月初未结）+ 本月新做 − 本月出库结算 − 本月报废 = 结转下月。没结算的不会重复累加，只是往后结转，等出库那个月再结。
 * - 提成只在出库时产生：订单折后金额 × 出库占比 × 工序提成比例，按工序参与人份额分。
 * - 发货包装作业：随发货单发运结算。
 * - 集中开料记录：一批只开一个 BOM，按实际计时；工单下料「已备料」按件数领走，这批的工时按 领走件数 ÷ 总件数 跟着那张工单的产品出库结算，
 *   没领完的件留在这批里（结余）一直往后结转。提成：那张工单下料工序的提成池按工时占比分给这批开料的人（和在工单上计时的人一起按工时分）。
 * - 客户退货：只记录（哪个月、哪张订单、退了几件、对应哪些工单），不扣工时和提成——退货一般伴随售后，
 *   要按责任人扣，先手动处理。退回的货以后再发出去也不重复结算（每张工单最多按产出件数结算一次）。
 */
final class PerformanceLedgerService
{
    private const SOURCE_LABELS = ['sales_order' => '订单工单', 'stock' => '备货工单', 'work_order' => '半成品工单', 'packing' => '发货包装'];

    private bool $loaded = false;
    private array $wos = [];
    private array $woByKey = [];
    private array $consumers = [];
    private array $direct = [];
    private array $labor = [];
    private array $ops = [];
    private array $shares = [];
    private array $lines = [];
    private array $stock = [];
    private array $packing = [];
    private array $memo = [];
    private array $lineShipped = [];
    private array $lineReturns = [];
    private array $returnsByWo = [];
    private array $scraps = [];
    private array $scrapMemo = [];
    private array $runs = [];
    private array $runAlloc = [];
    private array $runShare = [];
    private array $opRate = [];
    private array $runMinutes = [];

    public function __construct(private readonly ProductionPerformanceCalculator $calc) {}

    /** 月度结算：每人的 上月结转 / 本月新做 / 本月出库结算 / 结转下月 工时，和本月结算提成；可展开到工单。 */
    public function month(string $month, bool $amounts, ?int $employeeId = null): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) throw new WorkOrderDomainException('month_invalid', '月份格式应为 2026-11。', 422);
        $this->load();
        $start = Carbon::parse($month.'-01')->toDateString();
        $end = Carbon::parse($month.'-01')->endOfMonth()->toDateString();
        $people = [];
        foreach ($this->units() as $unit) {
            foreach ($unit['ops'] as $opId => $op) {
                $fb = $this->fraction($unit, $start, false);
                $fe = $this->fraction($unit, $end, true);
                $sb = $this->scrapFraction($unit, $start, false);
                $se = $this->scrapFraction($unit, $end, true);
                $events = array_filter($this->events($unit), fn ($e) => $e['date'] >= $start && $e['date'] <= $end);
                foreach ($this->labor[$unit['key']][$opId] ?? [] as $emp => $rows) {
                    if ($employeeId && $emp !== $employeeId) continue;
                    $lb = $this->sumLabor($rows, $start, false);
                    $le = $this->sumLabor($rows, $end, true);
                    if ($le <= 0) continue;
                    $opening = $lb * (1 - $fb - $sb);
                    $closing = $le * (1 - $fe - $se);
                    $new = $le - $lb;
                    $settled = $le * $fe - $lb * $fb;
                    $scrap = $le * $se - $lb * $sb;
                    $commission = '0';
                    $share = $this->shares[$unit['key']][$opId][$emp] ?? '0';
                    if ($op['rate'] !== null) {
                        foreach ($events as $e) {
                            $amount = $this->lines[$e['line_id']]['amount'] ?? null;
                            // 集中开料：每个事件带自己的比例（领用工单下料工序的提成比例 × 这批工时在那道工序里的占比）。
                            $factor = $op['rate'] === 'event' ? ($e['rf'] ?? '0') : $op['rate'];
                            if ($amount === null || bccomp($factor, '0', 12) <= 0) continue;
                            $commission = bcadd($commission, bcmul(bcmul(bcmul($amount, $e['lf'], 12), $factor, 12), $share, 12), 12);
                        }
                    }
                    if (abs($opening) < 0.05 && abs($new) < 0.05 && abs($settled) < 0.05 && abs($scrap) < 0.05 && bccomp($commission, '0', 4) === 0) continue;
                    $people[$emp] ??= ['employee_legacy_id' => $emp, 'employee_name' => $this->name($unit['key'], $opId, $emp),
                        'opening' => 0.0, 'new' => 0.0, 'settled' => 0.0, 'scrap' => 0.0, 'closing' => 0.0, 'commission' => '0', 'rows' => []];
                    $p = &$people[$emp];
                    foreach (['opening' => $opening, 'new' => $new, 'settled' => $settled, 'scrap' => $scrap, 'closing' => $closing] as $k => $v) $p[$k] += $v;
                    $p['commission'] = bcadd($p['commission'], $commission, 12);
                    $rk = $unit['key'];
                    $p['rows'][$rk] ??= $this->unitInfo($unit, $end) + ['operations' => [], 'opening' => 0.0, 'new' => 0.0, 'settled' => 0.0, 'scrap' => 0.0, 'closing' => 0.0,
                        'commission' => '0', 'shipped_in_month' => $this->shippedTo($events)];
                    $r = &$p['rows'][$rk];
                    foreach (['opening' => $opening, 'new' => $new, 'settled' => $settled, 'scrap' => $scrap, 'closing' => $closing] as $k => $v) $r[$k] += $v;
                    $r['commission'] = bcadd($r['commission'], $commission, 12);
                    if (! in_array($op['name'], $r['operations'], true)) $r['operations'][] = $op['name'];
                    unset($p, $r);
                }
            }
        }
        $totals = ['opening' => 0.0, 'new' => 0.0, 'settled' => 0.0, 'scrap' => 0.0, 'closing' => 0.0, 'commission' => '0'];
        $out = [];
        foreach ($people as $p) {
            foreach (['opening', 'new', 'settled', 'scrap', 'closing'] as $k) $totals[$k] += $p[$k];
            $totals['commission'] = bcadd($totals['commission'], $p['commission'], 12);
            $rows = array_values(array_map(fn ($r) => $this->roundRow($r, $amounts), $p['rows']));
            usort($rows, fn ($a, $b) => [$b['settled'], $b['closing']] <=> [$a['settled'], $a['closing']]);
            $out[] = ['rows' => $rows] + $this->roundRow($p, $amounts);
        }
        usort($out, fn ($a, $b) => $b['settled'] + $b['closing'] <=> $a['settled'] + $a['closing']);
        $returns = [];
        foreach ($this->lineReturns as $list) foreach ($list as $r) if ($r['date'] >= $start && $r['date'] <= $end) $returns[] = $r + ['item_name' => $this->lines[$r['line_id']]['item_name'] ?? '',
            'work_orders' => array_map(fn ($id, $q) => ['work_order_id' => $id, 'work_order_no' => $this->wos[$id]->work_order_no ?? '', 'qty' => round($q, 4)],
                array_keys($this->lineShipped[$r['line_id']] ?? []), $this->lineShipped[$r['line_id']] ?? []),
            // 预留：以后按售后责任算出的工时 / 提成调整接在这里，现在为空、不参与结算。
            'labor_adjustment' => null, 'commission_adjustment' => null, 'adjustment_status' => '待定（售后按责任手动处理）'];
        usort($returns, fn ($a, $b) => $a['date'] <=> $b['date']);
        return ['month' => $month, 'start' => $start, 'end' => $end, 'employees' => $out, 'returns' => $returns, 'totals' => $this->roundRow($totals, $amounts), 'amount_visible' => $amounts,
            'rule' => '月初未结（上月结转）+ 本月新做 − 本月出库结算 − 本月报废 = 结转下月。报废的工时单独列出，不摊给合格品。工时挂在做出来的产品上，产品出库（或被装进的整机出库）那个月才结算，没出库的往后结转，不会重复累加。集中开料按实际计时，工单领几件带走几件的工时（这批总工时 ÷ 总件数 × 件数），没领的留在开料记录里往后结转。'];
    }

    /**
     * 本月生产明细（按人、按干活时间，不看出库）：谁这个月在哪些工单上干了活、这些工单本月完工几台、每台用了多少工时。
     * - 只要在工单上做过工序，就算参与了这张工单本月完工的台数（多人一起做，每人都算这几台，再各自列工时）。
     * - 本人每台工时 = 本人在这张工单上累计工时 ÷ 工单投入台数；工单每台工时 = 工单全部人累计工时 ÷ 投入台数。
     *   工单还没做完时累计工时会继续增加，每台工时以完工后为准。
     * - 成品工单只算它自己的工时；架子、电箱等半成品和集中开料在各自的行里单独列。
     * $kind：finished 只看成品，semi 只看半成品（含集中开料），packing 只看发货包装，空为全部。
     */
    public function production(string $month, ?string $kind = null, ?int $employeeId = null): array
    {
        if (! preg_match('/^\d{4}-\d{2}$/', $month)) throw new WorkOrderDomainException('month_invalid', '月份格式应为 2026-11。', 422);
        $this->load();
        $start = Carbon::parse($month.'-01')->toDateString();
        $end = Carbon::parse($month.'-01')->endOfMonth()->toDateString();
        $done = ['month' => [], 'total' => []];
        foreach (DB::table('erp_mfg_work_order_units')->whereNotNull('completed_at')->where('status', '!=', 'scrapped')->whereDate('completed_at', '<=', $end)
            ->groupBy('work_order_id')->selectRaw('work_order_id, COUNT(*) AS n, SUM(DATE(completed_at) >= ?) AS m', [$start])->get() as $r) {
            $done['total']['W'.$r->work_order_id] = (int) $r->n;
            $done['month']['W'.$r->work_order_id] = (int) $r->m;
        }
        foreach ($this->runs as $r) {
            $d = $r->finished_at ? substr((string) $r->finished_at, 0, 10) : null;
            if (! $d || $d > $end) continue;
            $done['total']['C'.$r->id] = (float) $r->total_pieces;
            $done['month']['C'.$r->id] = $d >= $start ? (float) $r->total_pieces : 0;
        }
        $itemIds = array_values(array_unique(array_map(fn ($wo) => (int) $wo->item_id, $this->wos)));
        $itemTypes = DB::table('erp_items')->whereIn('id', $itemIds ?: [0])->pluck('item_type', 'id')->all();
        $people = [];
        foreach ($this->units() as $unit) {
            $key = $unit['key'];
            $class = match ($unit['kind']) {
                'packing' => 'packing',
                'cut' => 'semi',
                default => $unit['wo']->source_type === 'work_order' || ($itemTypes[(int) $unit['wo']->item_id] ?? null) === 'semi_finished' ? 'semi' : 'finished',
            };
            if ($kind && $kind !== $class) continue;
            $unitTotal = 0.0;
            $mine = [];
            foreach ($unit['ops'] as $opId => $op) {
                foreach ($this->labor[$key][$opId] ?? [] as $emp => $rows) {
                    $total = $this->sumLabor($rows, $end, true);
                    $unitTotal += $total;
                    if ($employeeId && $emp !== $employeeId) continue;
                    $inMonth = $total - $this->sumLabor($rows, $start, false);
                    $mine[$emp] ??= ['month' => 0.0, 'total' => 0.0, 'ops' => [], 'name' => $this->name($key, $opId, $emp)];
                    $mine[$emp]['month'] += $inMonth;
                    $mine[$emp]['total'] += $total;
                    if ($inMonth > 0.0001 && ! in_array($op['name'], $mine[$emp]['ops'], true)) $mine[$emp]['ops'][] = $op['name'];
                }
            }
            $doneMonth = $class === 'packing' ? (($unit['events'][0]['date'] ?? '9999') >= $start && ($unit['events'][0]['date'] ?? '9999') <= $end ? 1 : 0) : ($done['month'][$key] ?? 0);
            $doneTotal = $class === 'packing' ? (($unit['events'][0]['date'] ?? '9999') <= $end ? 1 : 0) : ($done['total'][$key] ?? 0);
            $base = $unit['kind'] === 'work_order' ? (float) $unit['base'] : (float) $unit['output'];
            foreach ($mine as $emp => $m) {
                if ($m['month'] < 0.05 && ! ($doneMonth > 0 && $m['total'] > 0.05)) continue;
                if (! $m['ops']) foreach ($unit['ops'] as $opId => $op) if (isset($this->labor[$key][$opId][$emp])) $m['ops'][] = $op['name'];
                $info = $this->unitInfo($unit, $end);
                $people[$emp] ??= ['employee_legacy_id' => $emp, 'employee_name' => $m['name'], 'finished_units' => 0.0, 'semi_units' => 0.0, 'cut_pieces' => 0.0, 'packing_count' => 0,
                    'month_minutes' => 0.0, 'work_order_count' => 0, 'rows' => []];
                $p = &$people[$emp];
                $p['month_minutes'] += $m['month'];
                $p['work_order_count']++;
                if ($class === 'finished') $p['finished_units'] += $doneMonth;
                elseif ($unit['kind'] === 'cut') $p['cut_pieces'] += $doneMonth;
                elseif ($class === 'semi') $p['semi_units'] += $doneMonth;
                else $p['packing_count'] += $doneMonth;
                $p['rows'][] = [
                    'key' => $key, 'class' => $class, 'class_label' => $unit['kind'] === 'cut' ? '集中开料' : ['finished' => '成品', 'semi' => '半成品', 'packing' => '发货包装'][$class],
                    'work_order_id' => $info['work_order_id'] ?? null, 'cutting_run_id' => $info['cutting_run_id'] ?? null,
                    'work_order_no' => $info['work_order_no'], 'source_label' => $info['source_label'], 'item_name' => $info['item_name'],
                    'sales_order_no' => $info['sales_order_no'], 'unit_name' => $info['unit_name'] ?: ($class === 'packing' ? '单' : '台'),
                    'operations' => $m['ops'], 'base_qty' => round($base, 4), 'completed_in_month' => round($doneMonth, 4), 'completed_total' => round($doneTotal, 4),
                    'finished' => $base > 0 && $doneTotal + ($info['scrapped_qty'] ?? 0) >= $base - 0.0001,
                    'my_month_minutes' => round($m['month'], 1), 'my_total_minutes' => round($m['total'], 1),
                    'my_minutes_per_unit' => $base > 0 ? round($m['total'] / $base, 1) : null,
                    'wo_total_minutes' => round($unitTotal, 1), 'wo_minutes_per_unit' => $base > 0 ? round($unitTotal / $base, 1) : null,
                ];
                unset($p);
            }
        }
        $out = array_values($people);
        foreach ($out as &$p) {
            usort($p['rows'], fn ($a, $b) => [$b['completed_in_month'], $b['my_month_minutes']] <=> [$a['completed_in_month'], $a['my_month_minutes']]);
            foreach (['finished_units', 'semi_units', 'cut_pieces', 'month_minutes'] as $k) $p[$k] = round($p[$k], $k === 'month_minutes' ? 1 : 4);
        }
        unset($p);
        usort($out, fn ($a, $b) => [$b['finished_units'], $b['month_minutes']] <=> [$a['finished_units'], $a['month_minutes']]);
        return ['month' => $month, 'start' => $start, 'end' => $end, 'kind' => $kind, 'employees' => $out,
            'rule' => '按干活时间统计，不看出库。只要在工单上做过工序就算参与了这张工单本月完工的台数（多人一起做每人都算）；本人每台工时 = 本人在这张工单上累计工时 ÷ 工单投入台数，工单每台工时 = 全部人累计工时 ÷ 投入台数，工单没做完时还会增加。成品只算整机工单自己的工时，架子、电箱等半成品和集中开料单独列。'];
    }

    /** 按工单看工时对应的产品去向：已出库多少、已领用待出库多少、在库多少；已结算 / 未结算工时。 */
    public function workOrders(array $filters): array
    {
        $this->load();
        $today = now()->toDateString();
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $rows = [];
        foreach ($this->units() as $unit) {
            if (! in_array($unit['kind'], ['work_order', 'cut'], true)) continue;
            $total = 0.0;
            foreach ($this->labor[$unit['key']] ?? [] as $emps) foreach ($emps as $r) $total += $this->sumLabor($r, $today, true);
            if ($total <= 0) continue;
            $info = $this->unitInfo($unit, $today);
            if ($keyword !== '' && ! str_contains($info['work_order_no'].$info['item_name'].($info['sales_order_no'] ?? ''), $keyword)) continue;
            $f = $this->fraction($unit, $today, true);
            $sf = $this->scrapFraction($unit, $today, true);
            $row = $info + ['labor_minutes' => round($total, 1), 'settled_minutes' => round($total * $f, 1), 'scrap_minutes' => round($total * $sf, 1),
                'unsettled_minutes' => round(max(0, $total * (1 - $f - $sf)), 1)];
            if (! empty($filters['only_unsettled']) && $row['unsettled_minutes'] < 0.05) continue;
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => $b['unsettled_minutes'] <=> $a['unsettled_minutes'] ?: ($b['work_order_id'] ?? 0) <=> ($a['work_order_id'] ?? 0));
        $page = max(1, (int) ($filters['page'] ?? 1));
        $per = min(50, max(1, (int) ($filters['per_page'] ?? 20)));
        $sum = fn ($k) => round(array_sum(array_column($rows, $k)), 1);
        return ['data' => array_slice($rows, ($page - 1) * $per, $per), 'total' => count($rows), 'current_page' => $page, 'per_page' => $per,
            'summary' => ['labor_minutes' => $sum('labor_minutes'), 'settled_minutes' => $sum('settled_minutes'), 'scrap_minutes' => $sum('scrap_minutes'), 'unsettled_minutes' => $sum('unsettled_minutes')]];
    }

    /** 一批（一张工单）的产出去向，每部分是否已出库、摊到多少工时。 */
    public function batch(?int $id, ?string $no): array
    {
        $this->load();
        $wo = $id ? ($this->wos[$id] ?? null) : collect($this->wos)->first(fn ($w) => $w->work_order_no === trim((string) $no));
        if (! $wo && ! $id && ($run = collect($this->runs)->first(fn ($r) => $r->run_no === trim((string) $no)))) return $this->runBatch($run);
        if (! $wo) throw new WorkOrderDomainException('work_order_not_found', '工单或开料记录不存在。', 404);
        $unit = $this->woUnit($wo);
        $today = now()->toDateString();
        $output = $unit['output'];
        $total = 0.0;
        foreach ($this->labor[$unit['key']] ?? [] as $emps) foreach ($emps as $r) $total += $this->sumLabor($r, $today, true);
        $base = $unit['base'] ?? $output;
        $per = $base > 0 ? $total / $base : 0.0;
        $usage = [];
        foreach ($this->direct[$wo->id] ?? [] as $e) {
            $usage[] = ['type' => 'shipped', 'type_label' => '直接发货', 'status_label' => '已出库', 'shipped' => true, 'target' => $e['shipment_no'],
                'sales_order_no' => $e['order_no'], 'qty' => round($e['qty'], 4), 'shipped_qty' => round($e['qty'], 4), 'pending_qty' => 0, 'date' => $e['date'],
                'labor_minutes' => round($per * $e['qty'], 1)];
        }
        foreach ($this->returnsByWo[$wo->id] ?? [] as $r) {
            $usage[] = ['type' => 'return', 'type_label' => '客户退货', 'status_label' => '仅记录，不扣工时提成（售后按责任手动处理）', 'shipped' => false,
                'target' => $r['no'], 'sales_order_no' => $r['order_no'], 'qty' => round($r['qty'], 4), 'shipped_qty' => 0, 'pending_qty' => 0, 'date' => $r['date'], 'labor_minutes' => null];
        }
        foreach ($this->consumers[$wo->id] ?? [] as $c) {
            $cu = $this->woUnit($this->wos[$c['consumer']]);
            $shipped = $cu['base'] > 0 ? max(0, min($c['n'], $c['n'] * $this->shippedQty($cu, $today) / $cu['base'])) : 0.0;
            $lost = 0.0;
            foreach ($this->scrapList($c['consumer']) as [$d, $n]) $lost += $cu['base'] > 0 ? $c['n'] * $n / $cu['base'] : 0;
            $pending = max(0, $c['n'] - $shipped - $lost);
            $cw = $this->wos[$c['consumer']];
            $usage[] = ['type' => 'consumed', 'type_label' => '装进 '.$cw->item_name, 'status_label' => ($pending < 0.0001 ? '已出库' : ($shipped > 0.0001 ? '部分出库' : '已领用，未出库')).($lost > 0.0001 ? '（其中随整机报废 '.round($lost, 2).'）' : ''),
                'shipped' => $pending < 0.0001, 'target' => $cw->work_order_no, 'target_work_order_id' => (int) $cw->id, 'sales_order_no' => $cw->sales_order_no,
                'qty' => round($c['n'], 4), 'shipped_qty' => round($shipped, 4), 'pending_qty' => round($pending, 4), 'scrapped_qty' => round($lost, 4), 'date' => null,
                'labor_minutes' => round($per * $c['n'], 1)];
        }
        $info = $this->unitInfo($unit, $today);
        if ($info['in_stock_qty'] > 0) $usage[] = ['type' => 'stock', 'type_label' => '仍在库', 'status_label' => '未出库', 'shipped' => false, 'target' => '库存',
            'sales_order_no' => null, 'qty' => $info['in_stock_qty'], 'shipped_qty' => 0, 'pending_qty' => $info['in_stock_qty'], 'date' => null, 'labor_minutes' => round($per * $info['in_stock_qty'], 1)];
        if ($info['scrapped_qty'] > 0) $usage[] = ['type' => 'scrap', 'type_label' => '报废', 'status_label' => '报废（工时单独列出，不摊给合格品）', 'shipped' => false, 'target' => '-',
            'sales_order_no' => null, 'qty' => $info['scrapped_qty'], 'shipped_qty' => 0, 'pending_qty' => 0, 'date' => null, 'labor_minutes' => round($per * $info['scrapped_qty'], 1)];
        if ($info['other_qty'] > 0) $usage[] = ['type' => 'other', 'type_label' => '未完工 / 报废 / 其他', 'status_label' => '未出库', 'shipped' => false, 'target' => '-',
            'sales_order_no' => null, 'qty' => $info['other_qty'], 'shipped_qty' => 0, 'pending_qty' => $info['other_qty'], 'date' => null, 'labor_minutes' => round($per * $info['other_qty'], 1)];
        $f = $this->fraction($unit, $today, true);
        $sf = $this->scrapFraction($unit, $today, true);
        return ['work_order' => $info + ['labor_minutes' => round($total, 1), 'labor_minutes_per_unit' => $base > 0 ? round($per, 1) : null,
            'settled_minutes' => round($total * $f, 1), 'scrap_minutes' => round($total * $sf, 1), 'unsettled_minutes' => round(max(0, $total * (1 - $f - $sf)), 1)], 'usage' => $usage];
    }

    // ---------- 内部 ----------

    private function units(): array
    {
        $units = [];
        foreach ($this->wos as $wo) if (isset($this->labor['W'.$wo->id])) $units[] = $this->woUnit($wo);
        foreach ($this->packing as $p) $units[] = $p;
        foreach ($this->runs as $r) if (isset($this->labor['C'.$r->id])) $units[] = $this->runUnit($r);
        return $units;
    }

    private function runUnit(object $r): array
    {
        $n = (float) $r->total_pieces;
        return ['key' => 'C'.$r->id, 'kind' => 'cut', 'run' => $r, 'output' => $n, 'base' => $n, 'ops' => [0 => ['name' => '集中开料', 'rate' => 'event']]];
    }

    /** 集中开料记录的产出去向：被哪些工单领走了多少件、出库没有；没领完的结余。 */
    private function runBatch(object $r): array
    {
        $unit = $this->runUnit($r);
        $today = now()->toDateString();
        $total = 0.0;
        foreach ($this->labor[$unit['key']] ?? [] as $emps) foreach ($emps as $rows) $total += $this->sumLabor($rows, $today, true);
        $per = $unit['base'] > 0 ? $total / $unit['base'] : 0.0;
        $usage = [];
        foreach ($this->runAlloc[(int) $r->id] ?? [] as $c) {
            if (! isset($this->wos[$c['consumer']])) continue;
            $cu = $this->woUnit($this->wos[$c['consumer']]);
            $shipped = $cu['base'] > 0 ? max(0, min($c['n'], $c['n'] * $this->shippedQty($cu, $today) / $cu['base'])) : 0.0;
            $lost = 0.0;
            foreach ($this->scrapList($c['consumer']) as [$d, $q]) $lost += $cu['base'] > 0 ? $c['n'] * $q / $cu['base'] : 0;
            $pending = max(0, $c['n'] - $shipped - $lost);
            $cw = $this->wos[$c['consumer']];
            $usage[] = ['type' => 'consumed', 'type_label' => '领去做 '.$cw->item_name, 'status_label' => ($pending < 0.0001 ? '已出库' : ($shipped > 0.0001 ? '部分出库' : '已领用，未出库')).($lost > 0.0001 ? '（其中随产品报废 '.round($lost, 2).'）' : ''),
                'shipped' => $pending < 0.0001, 'target' => $cw->work_order_no, 'target_work_order_id' => (int) $cw->id, 'sales_order_no' => $cw->sales_order_no,
                'qty' => round($c['n'], 4), 'shipped_qty' => round($shipped, 4), 'pending_qty' => round($pending, 4), 'scrapped_qty' => round($lost, 4), 'date' => $c['date'],
                'labor_minutes' => round($per * $c['n'], 1)];
        }
        $info = $this->unitInfo($unit, $today);
        if ($info['other_qty'] > 0) $usage[] = ['type' => 'left', 'type_label' => '开了还没领（结余）', 'status_label' => '未领用，工时往后结转', 'shipped' => false, 'target' => '-',
            'sales_order_no' => null, 'qty' => $info['other_qty'], 'shipped_qty' => 0, 'pending_qty' => $info['other_qty'], 'date' => null, 'labor_minutes' => round($per * $info['other_qty'], 1)];
        $f = $this->fraction($unit, $today, true);
        $sf = $this->scrapFraction($unit, $today, true);
        return ['work_order' => $info + ['labor_minutes' => round($total, 1), 'labor_minutes_per_unit' => $unit['base'] > 0 ? round($per, 2) : null,
            'settled_minutes' => round($total * $f, 1), 'scrap_minutes' => round($total * $sf, 1), 'unsettled_minutes' => round(max(0, $total * (1 - $f - $sf)), 1)], 'usage' => $usage];
    }

    private function woUnit(object $wo): array
    {
        $stored = (float) $wo->stored_qty;
        $scrapped = array_sum(array_column($this->scraps[$wo->id] ?? [], 1));
        // 投入件数 = 合格 + 报废：完工按实际入库 + 报废，未完工按计划数（计划里已含后来报废的件）。
        $base = in_array($wo->status, ['completed', 'closed'], true) && $stored > 0 ? $stored + $scrapped : max((float) $wo->planned_qty, $stored + $scrapped);
        return ['key' => 'W'.$wo->id, 'kind' => 'work_order', 'wo' => $wo, 'output' => max(0, $base - $scrapped), 'base' => $base, 'ops' => $this->ops['W'.$wo->id] ?? []];
    }

    /** 产品最终出库事件（日期、订单行、占这张工单产出的件数 qty、占订单行的比例 lf）。 */
    private function events(array $unit): array
    {
        if ($unit['kind'] === 'packing') return $unit['events'];
        if ($unit['kind'] === 'cut') return $this->runEvents($unit['run']);
        $id = (int) $unit['wo']->id;
        if (isset($this->memo[$id])) return $this->memo[$id];
        $this->memo[$id] = [];
        $events = $this->direct[$id] ?? [];
        usort($events, fn ($a, $b) => $a['date'] <=> $b['date']);
        $events = $this->cap($events, $unit['output']);
        foreach ($this->consumers[$id] ?? [] as $c) {
            $cu = $this->woUnit($this->wos[$c['consumer']]);
            if ($cu['base'] <= 0 || $c['N'] <= 0) continue;
            foreach ($this->events($cu) as $e) {
                $events[] = ['qty' => $c['n'] * $e['qty'] / $cu['base'], 'lf' => bcmul($e['lf'], bcdiv((string) $c['n'], (string) $c['N'], 12), 12)] + $e;
            }
        }
        return $this->memo[$id] = $events;
    }

    /** 集中开料：领用工单的产品出库时，按领走件数 ÷ 那张工单投入件数 结算这批的件数；提成比例随事件带上。 */
    private function runEvents(object $r): array
    {
        $key = 'C'.$r->id;
        if (isset($this->memo[$key])) return $this->memo[$key];
        $events = [];
        foreach ($this->runAlloc[(int) $r->id] ?? [] as $c) {
            if (! isset($this->wos[$c['consumer']])) continue;
            $cu = $this->woUnit($this->wos[$c['consumer']]);
            if ($cu['base'] <= 0) continue;
            $rate = $this->opRate[$c['op']] ?? null;
            $rf = $rate === null ? '0' : bcmul($rate, $this->runShare[(int) $r->id][$c['consumer']][$c['op']] ?? '0', 12);
            foreach ($this->events($cu) as $e) $events[] = ['qty' => $c['n'] * $e['qty'] / $cu['base'], 'rf' => $rf] + $e;
        }
        return $this->memo[$key] = $events;
    }

    /** 同一批产品最多按产出件数结算一次：退回后再发出去的，超出部分不再计工时和提成。 */
    private function cap(array $events, float $output): array
    {
        $left = $output;
        $out = [];
        foreach ($events as $e) {
            if ($left <= 0.00000001) break;
            if ($e['qty'] > $left) {
                $e['lf'] = bcmul($e['lf'], bcdiv(number_format($left, 8, '.', ''), number_format($e['qty'], 8, '.', ''), 12), 12);
                $e['qty'] = $left;
            }
            $left -= $e['qty'];
            $out[] = $e;
        }
        return $out;
    }

    private function shippedQty(array $unit, string $date): float
    {
        return array_sum(array_map(fn ($e) => $e['date'] <= $date ? $e['qty'] : 0, $this->events($unit)));
    }

    /** 已结算比例：截至某天已出库件数 ÷ 产出（inclusive=false 表示该日之前）。 */
    private function fraction(array $unit, string $date, bool $inclusive): float
    {
        $base = $unit['base'] ?? $unit['output'];
        if ($base <= 0) return 0.0;
        $q = array_sum(array_map(fn ($e) => ($inclusive ? $e['date'] <= $date : $e['date'] < $date) ? $e['qty'] : 0, $this->events($unit)));
        return max(0.0, min(1.0, $q / $base));
    }

    /** 报废比例：截至某天审批通过的报废件数 ÷ 投入件数。 */
    private function scrapFraction(array $unit, string $date, bool $inclusive): float
    {
        if (! in_array($unit['kind'], ['work_order', 'cut'], true) || ($unit['base'] ?? 0) <= 0) return 0.0;
        $q = 0.0;
        foreach ($unit['kind'] === 'cut' ? $this->runScrapList((int) $unit['run']->id) : $this->scrapList((int) $unit['wo']->id) as [$d, $n]) if ($inclusive ? $d <= $date : $d < $date) $q += $n;
        return min(1.0, $q / $unit['base']);
    }

    /** 报废件数（按日期）：本工单报废的，加上装进去的整机/上级件报废时连带的。 */
    private function scrapList(int $id, array $path = []): array
    {
        if (isset($this->scrapMemo[$id])) return $this->scrapMemo[$id];
        $list = $this->scraps[$id] ?? [];
        foreach ($this->consumers[$id] ?? [] as $c) {
            if (in_array($c['consumer'], $path, true)) continue;
            $cu = $this->woUnit($this->wos[$c['consumer']]);
            if ($cu['base'] <= 0) continue;
            foreach ($this->scrapList($c['consumer'], [...$path, $id]) as [$d, $n]) $list[] = [$d, $c['n'] * $n / $cu['base']];
        }
        return $this->scrapMemo[$id] = $list;
    }

    /** 集中开料的件随领用工单的产品报废的件数。 */
    private function runScrapList(int $runId): array
    {
        $list = [];
        foreach ($this->runAlloc[$runId] ?? [] as $c) {
            if (! isset($this->wos[$c['consumer']])) continue;
            $cu = $this->woUnit($this->wos[$c['consumer']]);
            if ($cu['base'] <= 0) continue;
            foreach ($this->scrapList($c['consumer']) as [$d, $n]) $list[] = [$d, $c['n'] * $n / $cu['base']];
        }
        return $list;
    }

    private function sumLabor(array $rows, string $date, bool $inclusive): float
    {
        $sum = 0.0;
        foreach ($rows as [$d, $m]) if ($inclusive ? $d <= $date : $d < $date) $sum += $m;
        return $sum;
    }

    private function unitInfo(array $unit, string $date): array
    {
        if ($unit['kind'] === 'packing') {
            $shipped = $unit['events'] && $unit['events'][0]['date'] <= $date;
            return ['key' => $unit['key'], 'kind' => 'packing', 'work_order_id' => null, 'work_order_no' => $unit['shipment_no'], 'source_label' => '发货包装',
                'item_name' => $unit['name'], 'sales_order_no' => $unit['order_no'], 'unit_name' => '', 'output_qty' => 1,
                'shipped_qty' => $shipped ? 1 : 0, 'consumed_pending_qty' => 0, 'in_stock_qty' => 0, 'other_qty' => 0, 'pending_qty' => $shipped ? 0 : 1, 'scrapped_qty' => 0,
                'status_label' => $shipped ? '已发运' : '未发运'];
        }
        if ($unit['kind'] === 'cut') {
            $r = $unit['run'];
            $taken = 0.0;
            foreach ($this->runAlloc[(int) $r->id] ?? [] as $c) foreach ($c['dated'] as [$d, $q]) if ($d <= $date) $taken += $q;
            $shipped = max(0, min($unit['output'], $this->shippedQty($unit, $date)));
            $lost = 0.0;
            foreach ($this->runScrapList((int) $r->id) as [$d, $n]) if ($d <= $date) $lost += $n;
            $left = max(0, $unit['output'] - $taken);
            return ['key' => $unit['key'], 'kind' => 'cut', 'work_order_id' => null, 'cutting_run_id' => (int) $r->id, 'work_order_no' => $r->run_no, 'source_label' => '集中开料',
                'item_name' => $r->output_item_name.'（开料）', 'sales_order_no' => null, 'unit_name' => '件', 'output_qty' => round($unit['output'], 4),
                'shipped_qty' => round($shipped, 4), 'consumed_pending_qty' => round(max(0, $taken - $shipped - $lost), 4), 'scrapped_inside_qty' => round($lost, 4),
                'in_stock_qty' => 0, 'other_qty' => round($left, 4), 'left_qty' => round($left, 4), 'pending_qty' => round(max(0, $unit['output'] - $shipped), 4),
                'returned_qty' => 0, 'scrapped_qty' => 0, 'status_label' => $unit['output'] - $shipped < 0.0001 ? '已全部出库' : ($shipped > 0.0001 ? '部分出库' : '未出库')];
        }
        $wo = $unit['wo'];
        $direct = array_sum(array_map(fn ($e) => $e['date'] <= $date && ($e['type'] ?? 'ship') === 'ship' ? $e['qty'] : 0, $this->direct[$wo->id] ?? []));
        $consumed = 0.0; $consumedShipped = 0.0;
        foreach ($this->consumers[$wo->id] ?? [] as $c) {
            $consumed += max(0, $this->sumLabor($c['dated'], $date, true));
            $cu = $this->woUnit($this->wos[$c['consumer']]);
            if ($cu['base'] > 0) $consumedShipped += max(0, min($c['n'], $c['n'] * $this->shippedQty($cu, $date) / $cu['base']));
        }
        $returned = array_sum(array_map(fn ($r) => $r['date'] <= $date ? $r['qty'] : 0, $this->returnsByWo[$wo->id] ?? []));
        $stock = $this->stock[$wo->id] ?? 0.0;
        $shipped = max(0, min($unit['output'], $this->shippedQty($unit, $date)));
        $scrapped = 0.0;
        $scrapInside = 0.0;
        foreach ($this->scraps[$wo->id] ?? [] as [$d, $n]) if ($d <= $date) $scrapped += $n;
        foreach ($this->scrapList((int) $wo->id) as [$d, $n]) if ($d <= $date) $scrapInside += $n;
        $scrapInside -= $scrapped;
        $other = max(0, $unit['output'] - $direct - $consumed - $stock);
        return ['key' => $unit['key'], 'kind' => 'work_order', 'work_order_id' => (int) $wo->id, 'work_order_no' => $wo->work_order_no,
            'source_label' => self::SOURCE_LABELS[$wo->source_type] ?? $wo->source_type, 'item_name' => $wo->item_name, 'sales_order_no' => $wo->sales_order_no,
            'unit_name' => $wo->unit_name, 'output_qty' => round($unit['output'], 4), 'shipped_qty' => round($shipped, 4),
            'consumed_pending_qty' => round(max(0, $consumed - $consumedShipped - $scrapInside), 4), 'scrapped_inside_qty' => round(max(0, $scrapInside), 4), 'in_stock_qty' => round($stock, 4), 'other_qty' => round($other, 4),
            'pending_qty' => round(max(0, $unit['output'] - $shipped), 4), 'returned_qty' => round(max(0, $returned), 4), 'scrapped_qty' => round($scrapped, 4),
            'status_label' => $unit['output'] - $shipped < 0.0001 ? '已全部出库' : ($shipped > 0.0001 ? '部分出库' : '未出库')];
    }

    private function shippedTo(array $events): array
    {
        $out = [];
        foreach ($events as $e) {
            $type = $e['type'] ?? 'ship';
            $k = $type.'|'.$e['order_no'].'|'.$e['date'];
            $out[$k] ??= ['type' => $type, 'type_label' => '出库',
                'sales_order_no' => $e['order_no'], 'date' => $e['date'], 'qty' => 0.0];
            $out[$k]['qty'] += $e['qty'];
        }
        return array_values(array_map(fn ($r) => ['qty' => round($r['qty'], 4)] + $r, $out));
    }

    private function roundRow(array $r, bool $amounts): array
    {
        foreach (['opening', 'new', 'closing', 'scrap'] as $k) $r[$k] = round(max(0, $r[$k] ?? 0), 1);
        $r['settled'] = round(max(0, $r['settled']), 1);
        $r['commission'] = $amounts ? $this->money($r['commission']) : null;
        return $r;
    }

    /** 四舍五入到 4 位，负数（退货扣回）按绝对值舍入。 */
    private function money(string $v): string
    {
        return bccomp($v, '0', 12) < 0 ? '-'.$this->calc->round(ltrim($v, '-')) : $this->calc->round($v);
    }

    private function name(string $key, int $opId, int $emp): string
    {
        return $this->shares[$key.'#names'][$emp] ?? ('员工#'.$emp);
    }

    private function load(): void
    {
        if ($this->loaded) return;
        $this->loaded = true;
        foreach (DB::table('erp_mfg_work_orders')->where('status', '!=', 'cancelled')->get() as $wo) {
            $this->wos[(int) $wo->id] = $wo;
            $this->woByKey[$wo->item_id.'|'.$wo->work_order_no] = (int) $wo->id;
        }
        foreach (DB::table('erp_mfg_unit_reports')->where('result', 'scrap')->where('review_status', 'approved')
            ->groupBy('work_order_id', DB::raw('DATE(reviewed_at)'))->selectRaw('work_order_id, DATE(reviewed_at) AS d, COUNT(DISTINCT unit_id) AS n')->get() as $r)
            $this->scraps[(int) $r->work_order_id][] = [(string) $r->d, (float) $r->n];
        // 订单行金额（折人民币）和数量
        $orders = DB::table('erp_sales_orders')->whereIn('id', DB::table('erp_sales_shipments')->select('sales_order_id'))->get()->keyBy('id');
        foreach (DB::table('erp_sales_order_lines')->whereIn('sales_order_id', $orders->keys()->all() ?: [0])->get() as $l) {
            $o = $orders[$l->sales_order_id];
            $foreign = strtoupper((string) ($o->currency ?: 'CNY')) !== strtoupper((string) ($o->base_currency ?: 'CNY'));
            $rate = ! $foreign ? '1' : (bccomp((string) ($o->business_exchange_rate ?? 0), '0', 10) > 0 ? (string) $o->business_exchange_rate : null);
            $q = (float) $l->item_base_required_qty > 0 ? (string) $l->item_base_required_qty : (string) $l->order_qty;
            $this->lines[(int) $l->id] = ['amount' => $rate === null || $l->amount_incl_tax === null ? null : bcmul((string) $l->amount_incl_tax, $rate, 4),
                'qty' => $q, 'order_no' => $o->sales_order_no, 'item_name' => $l->product_name ?: $l->item_name];
        }
        // 出库（发运）事实
        $shipDates = [];
        $ships = DB::table('erp_sales_shipment_lines as l')->join('erp_sales_shipments as s', 's.id', '=', 'l.shipment_id')
            ->where('s.shipment_status', '!=', 'cancelled')->where(fn ($w) => $w->whereNotNull('l.dispatched_at')->orWhereIn('s.shipment_status', ['shipped', 'completed']))
            ->get(['l.shipment_id', 's.shipment_no', 'l.sales_order_line_id', 'l.item_id', 'l.batch_no', 'l.base_qty', DB::raw('DATE(COALESCE(l.dispatched_at, s.shipped_at, s.updated_at)) AS d')]);
        foreach ($ships as $s) {
            $shipDates[(int) $s->shipment_id] = min($shipDates[(int) $s->shipment_id] ?? $s->d, $s->d);
            $woId = $this->woByKey[$s->item_id.'|'.$s->batch_no] ?? null;
            $line = $this->lines[(int) $s->sales_order_line_id] ?? null;
            if (! $woId || ! $line || bccomp($line['qty'], '0', 8) <= 0) continue;
            $this->direct[$woId][] = ['type' => 'ship', 'date' => $s->d, 'line_id' => (int) $s->sales_order_line_id, 'order_no' => $line['order_no'], 'shipment_no' => $s->shipment_no,
                'qty' => (float) $s->base_qty, 'lf' => bcdiv((string) $s->base_qty, $line['qty'], 12)];
            $this->lineShipped[(int) $s->sales_order_line_id][$woId] = ($this->lineShipped[(int) $s->sales_order_line_id][$woId] ?? 0) + (float) $s->base_qty;
        }
        $this->loadReturns();
        // 领用（扣退料）：谁领了哪批
        $net = [];
        $dated = [];
        foreach (DB::table('erp_inventory_transaction_items as ti')->join('erp_mfg_material_issues as i', 'i.id', '=', 'ti.source_id')->where('ti.source_type', 'mfg_material_issue')
            ->groupBy('i.work_order_id', 'ti.item_id', 'ti.batch_no', DB::raw('DATE(ti.created_at)'))->selectRaw('i.work_order_id, ti.item_id, ti.batch_no, DATE(ti.created_at) AS d, SUM(-ti.change_qty) AS q')->get() as $r) {
            $net[$r->work_order_id][$r->item_id][(string) $r->batch_no] = ($net[$r->work_order_id][$r->item_id][(string) $r->batch_no] ?? 0) + (float) $r->q;
            $dated[$r->work_order_id][$r->item_id][(string) $r->batch_no][] = [$r->d, (float) $r->q];
        }
        foreach (DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')->where('r.status', 'approved')
            ->groupBy('l.work_order_id', 'l.component_item_id', 'l.batch_no', DB::raw('DATE(COALESCE(r.reviewed_at, r.updated_at))'))
            ->selectRaw('l.work_order_id, l.component_item_id, l.batch_no, DATE(COALESCE(r.reviewed_at, r.updated_at)) AS d, SUM(l.qty) AS q')->get() as $r)
            if (isset($net[$r->work_order_id][$r->component_item_id][(string) $r->batch_no])) {
                $net[$r->work_order_id][$r->component_item_id][(string) $r->batch_no] -= (float) $r->q;
                $dated[$r->work_order_id][$r->component_item_id][(string) $r->batch_no][] = [$r->d, -(float) $r->q];
            }
        foreach ($net as $consumer => $items) {
            if (! isset($this->wos[$consumer])) continue;
            foreach ($items as $itemId => $batches) {
                $total = array_sum(array_map(fn ($q) => max(0, $q), $batches));
                foreach ($batches as $batch => $q) {
                    $producer = $this->woByKey[$itemId.'|'.$batch] ?? null;
                    if ($producer && $producer !== $consumer && $q > 0.00000001)
                        $this->consumers[$producer][] = ['consumer' => $consumer, 'n' => $q, 'N' => $total, 'dated' => $dated[$consumer][$itemId][$batch] ?? []];
                }
            }
        }
        // 工时、工序、份额
        foreach (DB::table('erp_mfg_labor_sessions')->where('status', '!=', 'active')->whereNotNull('ended_at')
            ->groupBy('work_order_id', 'work_order_operation_id', 'employee_legacy_id', 'employee_name', DB::raw('DATE(ended_at)'))
            ->selectRaw('work_order_id, work_order_operation_id, employee_legacy_id, employee_name, DATE(ended_at) AS d, SUM(labor_minutes) AS m')->get() as $s) {
            $this->labor['W'.$s->work_order_id][(int) $s->work_order_operation_id][(int) $s->employee_legacy_id][] = [$s->d, (float) $s->m];
            $this->shares['W'.$s->work_order_id.'#names'][(int) $s->employee_legacy_id] = $s->employee_name;
        }
        $this->loadRuns();
        $woIds = array_map(fn ($k) => (int) substr($k, 1), array_filter(array_keys($this->labor), fn ($k) => $k[0] === 'W'));
        foreach (DB::table('erp_mfg_work_order_operations')->whereIn('work_order_id', $woIds ?: [0])->get() as $op)
            $this->ops['W'.$op->work_order_id][(int) $op->id] = ['name' => $op->operation_name, 'rate' => $op->performance_rate !== null && (float) $op->performance_rate > 0 ? (string) $op->performance_rate : null];
        $fixed = DB::table('erp_mfg_task_participants')->whereIn('work_order_id', $woIds ?: [0])->where('is_active', true)->get()->groupBy('work_order_operation_id');
        foreach ($this->labor as $key => $opsLabor) {
            if ($key[0] !== 'W') continue;
            foreach ($opsLabor as $opId => $emps) $this->shares[$key][$opId] = $this->opShares($emps, $fixed->get($opId, collect())->all(), $this->runMinutes[(int) substr($key, 1)][$opId] ?? []);
        }
        // 集中开料在领用工序里的工时占比（这道工序自己计时的人 + 各批开料按领走工时，一起按工时分提成）。
        foreach ($this->runMinutes as $woId => $opsRuns) foreach ($opsRuns as $opId => $runs) {
            $own = 0.0;
            foreach ($this->labor['W'.$woId][$opId] ?? [] as $list) $own += array_sum(array_column($list, 1));
            $all = $own + array_sum($runs);
            foreach ($runs as $runId => $m) $this->runShare[$runId][$woId][$opId] = $all > 0 ? bcdiv(number_format($m, 6, '.', ''), number_format($all, 6, '.', ''), 12) : '0';
        }
        foreach ($this->runs as $r) if (isset($this->labor['C'.$r->id])) $this->shares['C'.$r->id][0] = $this->opShares($this->labor['C'.$r->id][0], []);
        $this->loadPacking($shipDates);
        foreach (DB::table('erp_inventory_balances')->where('quantity_on_hand', '>', 0)->groupBy('item_id', 'batch_no')->selectRaw('item_id, batch_no, SUM(quantity_on_hand) AS q')->get() as $b) {
            $woId = $this->woByKey[$b->item_id.'|'.$b->batch_no] ?? null;
            if ($woId) $this->stock[$woId] = (float) $b->q;
        }
    }

    /** 集中开料记录：已结束的批次、各人计时、被哪些工单领走多少件和多少工时。 */
    private function loadRuns(): void
    {
        foreach (DB::table('erp_mfg_cutting_runs')->where('status', 'finished')->where('total_pieces', '>', 0)->get() as $r) $this->runs[(int) $r->id] = $r;
        if (! $this->runs) return;
        $ids = array_keys($this->runs);
        foreach (DB::table('erp_mfg_cutting_run_sessions')->whereIn('cutting_run_id', $ids)->where('status', '!=', 'active')->whereNotNull('ended_at')
            ->groupBy('cutting_run_id', 'employee_legacy_id', 'employee_name', DB::raw('DATE(ended_at)'))
            ->selectRaw('cutting_run_id, employee_legacy_id, employee_name, DATE(ended_at) AS d, SUM(labor_minutes) AS m')->get() as $s) {
            $this->labor['C'.$s->cutting_run_id][0][(int) $s->employee_legacy_id][] = [$s->d, (float) $s->m];
            $this->shares['C'.$s->cutting_run_id.'#names'][(int) $s->employee_legacy_id] = $s->employee_name;
        }
        $rows = DB::table('erp_mfg_cutting_run_allocations')->whereIn('cutting_run_id', $ids)
            ->groupBy('cutting_run_id', 'work_order_id', 'work_order_operation_id', DB::raw('DATE(created_at)'))
            ->selectRaw('cutting_run_id, work_order_id, work_order_operation_id, DATE(created_at) AS d, SUM(pieces) AS n, SUM(labor_minutes) AS m')->orderBy('d')->get();
        $group = [];
        foreach ($rows as $a) {
            $k = $a->cutting_run_id.'|'.$a->work_order_id.'|'.$a->work_order_operation_id;
            $group[$k] ??= ['run' => (int) $a->cutting_run_id, 'consumer' => (int) $a->work_order_id, 'op' => (int) $a->work_order_operation_id, 'n' => 0.0, 'm' => 0.0, 'date' => $a->d, 'dated' => []];
            $group[$k]['n'] += (float) $a->n;
            $group[$k]['m'] += (float) $a->m;
            $group[$k]['dated'][] = [$a->d, (float) $a->n];
        }
        foreach ($group as $g) {
            $this->runAlloc[$g['run']][] = $g;
            $this->runMinutes[$g['consumer']][$g['op']][$g['run']] = ($this->runMinutes[$g['consumer']][$g['op']][$g['run']] ?? 0) + $g['m'];
        }
        foreach (DB::table('erp_mfg_work_order_operations')->whereIn('id', array_unique(array_column($group, 'op')) ?: [0])->get(['id', 'performance_rate']) as $op)
            $this->opRate[(int) $op->id] = $op->performance_rate !== null && (float) $op->performance_rate > 0 ? (string) $op->performance_rate : null;
    }

    /**
     * 客户退货（只记录）：按退货到货日记，到货时就拒收退回客户的不算；待处理的后来退回客户，在处置那天记回。
     * 数量按到货当时的口径还原，后来的处置不改到货那天的记录。
     */
    private function loadReturns(): void
    {
        $disp = DB::table('erp_sales_return_dispositions')->get();
        $targets = $disp->filter(fn ($d) => $d->target_receipt_item_id && $d->target_receipt_item_id !== $d->source_receipt_item_id)->pluck('target_receipt_item_id')->map(fn ($v) => (int) $v)->all();
        $bySource = $disp->groupBy('source_receipt_item_id');
        $rows = DB::table('erp_sales_return_receipt_items as ri')->join('erp_sales_return_receipts as r', 'r.id', '=', 'ri.receipt_id')
            ->join('erp_sales_return_items as i', 'i.id', '=', 'ri.sales_return_item_id')->join('erp_sales_returns as sr', 'sr.id', '=', 'i.sales_return_id')
            ->where('r.receipt_status', '!=', 'cancelled')->where('sr.return_status', '!=', 'cancelled')
            ->get(['ri.id', 'ri.item_id', 'ri.batch_no', 'ri.received_base_qty', 'ri.rejected_base_qty', 'i.sales_order_line_id', 'r.receipt_date', 'r.receipt_no', 'sr.return_no']);
        foreach ($rows as $r) {
            if (in_array((int) $r->id, $targets, true)) continue;
            $mine = $bySource->get($r->id, collect());
            $movedOut = (float) $mine->filter(fn ($d) => $d->disposition === 'restock' && $d->target_receipt_item_id && $d->target_receipt_item_id !== $d->source_receipt_item_id)->sum('base_qty');
            $laterRejected = $mine->where('disposition', 'rejected');
            $qty = (float) $r->received_base_qty + $movedOut - (float) $r->rejected_base_qty + (float) $laterRejected->sum('base_qty');
            $this->addReturn((int) $r->sales_order_line_id, -$qty, (string) $r->receipt_date, (string) $r->return_no, (int) $r->item_id, (string) $r->batch_no);
            foreach ($laterRejected as $d) $this->addReturn((int) $r->sales_order_line_id, (float) $d->base_qty, substr((string) $d->created_at, 0, 10), (string) $r->return_no.'（退回客户）', 0, '');
        }
    }

    /**
     * 退货只记录：摊到这行商品发货用的各批工单（按发货数量比例），用于展示，不影响结算。
     * 预留口子：以后要按售后责任扣工时 / 提成，在这里按 lineReturns / returnsByWo 生成调整事件即可，月度结算会自动在退货当月体现。
     */
    private function addReturn(int $lineId, float $qty, string $date, string $no, int $itemId, string $batch): void
    {
        $line = $this->lines[$lineId] ?? null;
        $shipped = $this->lineShipped[$lineId] ?? [];
        $total = array_sum($shipped);
        if (abs($qty) < 0.00000001 || ! $line) return;
        $this->lineReturns[$lineId][] = ['date' => $date, 'qty' => -$qty, 'no' => $no, 'order_no' => $line['order_no'], 'line_id' => $lineId];
        if ($total <= 0) return;
        foreach ($shipped as $woId => $q) $this->returnsByWo[$woId][] = ['date' => $date, 'qty' => -$qty * $q / $total, 'no' => $no, 'order_no' => $line['order_no']];
    }

    /** 发货包装作业：一道包装作业是一个结算单元，随发货单发运结算，提成按包裹里各订单行数量占比。 */
    private function loadPacking(array $shipDates): void
    {
        $ops = DB::table('erp_shipment_packing_operations as op')->join('erp_sales_shipments as s', 's.id', '=', 'op.shipment_id')->leftJoin('erp_sales_orders as o', 'o.id', '=', 's.sales_order_id')
            ->where('op.status', '!=', 'CANCELLED')->where('s.shipment_status', '!=', 'cancelled')
            ->get(['op.id', 'op.shipment_id', 'op.operation_name_snapshot', 'op.performance_rate_snapshot', 'op.packing_content_ids', 's.shipment_no', 'o.sales_order_no']);
        if ($ops->isEmpty()) return;
        $sessions = DB::table('erp_shipment_packing_labor_sessions')->whereIn('operation_id', $ops->pluck('id'))->whereNotNull('ended_at')
            ->groupBy('operation_id', 'employee_legacy_id', DB::raw('DATE(ended_at)'))->selectRaw('operation_id, employee_legacy_id, DATE(ended_at) AS d, SUM(actual_labor_minutes) AS m')->get()->groupBy('operation_id');
        $names = DB::table('erp_shipment_packing_participants')->whereIn('operation_id', $ops->pluck('id'))->get()->groupBy('operation_id');
        foreach ($ops as $op) {
            if (! $sessions->has($op->id)) continue;
            $key = 'P'.$op->id;
            foreach ($sessions[$op->id] as $s) $this->labor[$key][0][(int) $s->employee_legacy_id][] = [$s->d, (float) $s->m];
            foreach ($names->get($op->id, collect()) as $p) $this->shares[$key.'#names'][(int) $p->employee_legacy_id] = $p->employee_name_snapshot;
            $this->shares[$key][0] = $this->opShares($this->labor[$key][0], []);
            $events = [];
            $date = $shipDates[(int) $op->shipment_id] ?? null;
            if ($date) {
                $ids = array_map('intval', json_decode((string) $op->packing_content_ids, true) ?: []);
                foreach (DB::table('erp_shipment_packing_contents')->whereIn('id', $ids ?: [0])->get() as $c) {
                    $line = $this->lines[(int) $c->sales_order_line_id] ?? null;
                    if (! $line || bccomp($line['qty'], '0', 8) <= 0) continue;
                    $events[] = ['date' => $date, 'line_id' => (int) $c->sales_order_line_id, 'order_no' => $line['order_no'], 'shipment_no' => $op->shipment_no,
                        'qty' => 0.0, 'lf' => bcdiv((string) $c->base_qty, $line['qty'], 12)];
                }
                if ($events) $events[0]['qty'] = 1.0;
            }
            $rate = $op->performance_rate_snapshot !== null && (float) $op->performance_rate_snapshot > 0 ? (string) $op->performance_rate_snapshot : null;
            $this->packing[] = ['key' => $key, 'kind' => 'packing', 'output' => 1.0, 'events' => $events, 'shipment_no' => $op->shipment_no, 'order_no' => $op->sales_order_no,
                'name' => $op->operation_name_snapshot, 'ops' => [0 => ['name' => $op->operation_name_snapshot, 'rate' => $rate]]];
        }
    }

    /**
     * 份额：每人都填了固定份额且合计 100% 按固定份额，否则按实际工时占比。
     * 这道工序还用了集中开料（$runs：批次 => 领走工时）时一律按工时分，开料那部分算给开料的人（runShare），这里只返回本道计时的人的份额。
     */
    private function opShares(array $emps, array $participants, array $runs = []): array
    {
        if ($runs) {
            $own = [];
            foreach ($emps as $id => $list) $own[$id] = array_sum(array_column($list, 1));
            $all = array_sum($own) + array_sum($runs);
            return array_map(fn ($m) => $all > 0 ? bcdiv(number_format($m, 6, '.', ''), number_format($all, 6, '.', ''), 12) : '0', $own);
        }
        $fixed = $participants !== [] && collect($participants)->every(fn ($p) => $p->share_percent !== null) && abs(collect($participants)->sum(fn ($p) => (float) $p->share_percent) - 100) < 0.01;
        if ($fixed) return collect($participants)->mapWithKeys(fn ($p) => [(int) $p->employee_legacy_id => bcdiv((string) $p->share_percent, '100', 8)])->all();
        $rows = [];
        foreach ($emps as $id => $list) $rows[] = ['employee_legacy_id' => $id, 'actual_labor_minutes' => array_sum(array_column($list, 1))];
        return collect($this->calc->laborShares($rows))->mapWithKeys(fn ($s) => [(int) $s['employee_legacy_id'] => $s['share_ratio']])->all();
    }
}
