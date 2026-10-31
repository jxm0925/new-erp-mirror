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
 * - 月度滚动：上月结转（月初未结）+ 本月新做 − 本月出库结算 = 结转下月。没结算的不会重复累加，只是往后结转，等出库那个月再结。
 * - 提成只在出库时产生：订单折后金额 × 出库占比 × 工序提成比例，按工序参与人份额分。
 * - 发货包装作业：随发货单发运结算。
 * - 客户退货：只记在退货到货的那个月（负数，扣回工时和提成），出库那个月的数字不动；退回客户（拒收）的在处置当月加回。
 *   退回的货重新入库、以后再发出去，在再发出的月份重新结算。
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
                $events = array_filter($this->events($unit), fn ($e) => $e['date'] >= $start && $e['date'] <= $end);
                foreach ($this->labor[$unit['key']][$opId] ?? [] as $emp => $rows) {
                    if ($employeeId && $emp !== $employeeId) continue;
                    $lb = $this->sumLabor($rows, $start, false);
                    $le = $this->sumLabor($rows, $end, true);
                    if ($le <= 0) continue;
                    $opening = $lb * (1 - $fb);
                    $closing = $le * (1 - $fe);
                    $new = $le - $lb;
                    $settled = $le * $fe - $lb * $fb;
                    $commission = '0';
                    $share = $this->shares[$unit['key']][$opId][$emp] ?? '0';
                    if ($op['rate'] !== null) {
                        foreach ($events as $e) {
                            $amount = $this->lines[$e['line_id']]['amount'] ?? null;
                            if ($amount === null) continue;
                            $commission = bcadd($commission, bcmul(bcmul(bcmul($amount, $e['lf'], 12), $op['rate'], 12), $share, 12), 12);
                        }
                    }
                    if (abs($opening) < 0.05 && abs($new) < 0.05 && abs($settled) < 0.05 && bccomp($commission, '0', 4) === 0) continue;
                    $people[$emp] ??= ['employee_legacy_id' => $emp, 'employee_name' => $this->name($unit['key'], $opId, $emp),
                        'opening' => 0.0, 'new' => 0.0, 'settled' => 0.0, 'closing' => 0.0, 'commission' => '0', 'rows' => []];
                    $p = &$people[$emp];
                    foreach (['opening' => $opening, 'new' => $new, 'settled' => $settled, 'closing' => $closing] as $k => $v) $p[$k] += $v;
                    $p['commission'] = bcadd($p['commission'], $commission, 12);
                    $rk = $unit['key'];
                    $p['rows'][$rk] ??= $this->unitInfo($unit, $end) + ['operations' => [], 'opening' => 0.0, 'new' => 0.0, 'settled' => 0.0, 'closing' => 0.0,
                        'commission' => '0', 'shipped_in_month' => $this->shippedTo($events)];
                    $r = &$p['rows'][$rk];
                    foreach (['opening' => $opening, 'new' => $new, 'settled' => $settled, 'closing' => $closing] as $k => $v) $r[$k] += $v;
                    $r['commission'] = bcadd($r['commission'], $commission, 12);
                    if (! in_array($op['name'], $r['operations'], true)) $r['operations'][] = $op['name'];
                    unset($p, $r);
                }
            }
        }
        $totals = ['opening' => 0.0, 'new' => 0.0, 'settled' => 0.0, 'closing' => 0.0, 'commission' => '0'];
        $out = [];
        foreach ($people as $p) {
            foreach (['opening', 'new', 'settled', 'closing'] as $k) $totals[$k] += $p[$k];
            $totals['commission'] = bcadd($totals['commission'], $p['commission'], 12);
            $rows = array_values(array_map(fn ($r) => $this->roundRow($r, $amounts), $p['rows']));
            usort($rows, fn ($a, $b) => [$b['settled'], $b['closing']] <=> [$a['settled'], $a['closing']]);
            $out[] = ['rows' => $rows] + $this->roundRow($p, $amounts);
        }
        usort($out, fn ($a, $b) => $b['settled'] + $b['closing'] <=> $a['settled'] + $a['closing']);
        return ['month' => $month, 'start' => $start, 'end' => $end, 'employees' => $out, 'totals' => $this->roundRow($totals, $amounts), 'amount_visible' => $amounts,
            'rule' => '月初未结（上月结转）+ 本月新做 − 本月出库结算 = 结转下月。工时挂在做出来的产品上，产品出库（或被装进的整机出库）那个月才结算，没出库的往后结转，不会重复累加。'];
    }

    /** 按工单看工时对应的产品去向：已出库多少、已领用待出库多少、在库多少；已结算 / 未结算工时。 */
    public function workOrders(array $filters): array
    {
        $this->load();
        $today = now()->toDateString();
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $rows = [];
        foreach ($this->units() as $unit) {
            if ($unit['kind'] !== 'work_order') continue;
            $total = 0.0;
            foreach ($this->labor[$unit['key']] ?? [] as $emps) foreach ($emps as $r) $total += $this->sumLabor($r, $today, true);
            if ($total <= 0) continue;
            $info = $this->unitInfo($unit, $today);
            if ($keyword !== '' && ! str_contains($info['work_order_no'].$info['item_name'].($info['sales_order_no'] ?? ''), $keyword)) continue;
            $f = $this->fraction($unit, $today, true);
            $row = $info + ['labor_minutes' => round($total, 1), 'settled_minutes' => round($total * $f, 1), 'unsettled_minutes' => round($total * (1 - $f), 1)];
            if (! empty($filters['only_unsettled']) && $row['unsettled_minutes'] < 0.05) continue;
            $rows[] = $row;
        }
        usort($rows, fn ($a, $b) => $b['unsettled_minutes'] <=> $a['unsettled_minutes'] ?: $b['work_order_id'] <=> $a['work_order_id']);
        $page = max(1, (int) ($filters['page'] ?? 1));
        $per = min(50, max(1, (int) ($filters['per_page'] ?? 20)));
        $sum = fn ($k) => round(array_sum(array_column($rows, $k)), 1);
        return ['data' => array_slice($rows, ($page - 1) * $per, $per), 'total' => count($rows), 'current_page' => $page, 'per_page' => $per,
            'summary' => ['labor_minutes' => $sum('labor_minutes'), 'settled_minutes' => $sum('settled_minutes'), 'unsettled_minutes' => $sum('unsettled_minutes')]];
    }

    /** 一批（一张工单）的产出去向，每部分是否已出库、摊到多少工时。 */
    public function batch(?int $id, ?string $no): array
    {
        $this->load();
        $wo = $id ? ($this->wos[$id] ?? null) : collect($this->wos)->first(fn ($w) => $w->work_order_no === trim((string) $no));
        if (! $wo) throw new WorkOrderDomainException('work_order_not_found', '工单不存在。', 404);
        $unit = $this->woUnit($wo);
        $today = now()->toDateString();
        $output = $unit['output'];
        $total = 0.0;
        foreach ($this->labor[$unit['key']] ?? [] as $emps) foreach ($emps as $r) $total += $this->sumLabor($r, $today, true);
        $per = $output > 0 ? $total / $output : 0.0;
        $usage = [];
        foreach ($this->direct[$wo->id] ?? [] as $e) {
            if (($e['type'] ?? 'ship') === 'return') {
                $usage[] = ['type' => 'return', 'type_label' => $e['qty'] < 0 ? '客户退货' : '退回客户（加回）', 'status_label' => $e['qty'] < 0 ? '退货（当月扣回）' : '已出库', 'shipped' => $e['qty'] > 0,
                    'target' => $e['shipment_no'], 'sales_order_no' => $e['order_no'], 'qty' => round($e['qty'], 4), 'shipped_qty' => round($e['qty'], 4), 'pending_qty' => 0,
                    'date' => $e['date'], 'labor_minutes' => round($per * $e['qty'], 1)];
                continue;
            }
            $usage[] = ['type' => 'shipped', 'type_label' => '直接发货', 'status_label' => '已出库', 'shipped' => true, 'target' => $e['shipment_no'],
                'sales_order_no' => $e['order_no'], 'qty' => round($e['qty'], 4), 'shipped_qty' => round($e['qty'], 4), 'pending_qty' => 0, 'date' => $e['date'],
                'labor_minutes' => round($per * $e['qty'], 1)];
        }
        foreach ($this->consumers[$wo->id] ?? [] as $c) {
            $cu = $this->woUnit($this->wos[$c['consumer']]);
            $shipped = $cu['output'] > 0 ? max(0, min($c['n'], $c['n'] * $this->shippedQty($cu, $today) / $cu['output'])) : 0.0;
            $pending = max(0, $c['n'] - $shipped);
            $cw = $this->wos[$c['consumer']];
            $usage[] = ['type' => 'consumed', 'type_label' => '装进 '.$cw->item_name, 'status_label' => $pending < 0.0001 ? '已出库' : ($shipped > 0.0001 ? '部分出库' : '已领用，未出库'),
                'shipped' => $pending < 0.0001, 'target' => $cw->work_order_no, 'target_work_order_id' => (int) $cw->id, 'sales_order_no' => $cw->sales_order_no,
                'qty' => round($c['n'], 4), 'shipped_qty' => round($shipped, 4), 'pending_qty' => round($pending, 4), 'date' => null,
                'labor_minutes' => round($per * $c['n'], 1)];
        }
        $info = $this->unitInfo($unit, $today);
        if ($info['in_stock_qty'] > 0) $usage[] = ['type' => 'stock', 'type_label' => '仍在库', 'status_label' => '未出库', 'shipped' => false, 'target' => '库存',
            'sales_order_no' => null, 'qty' => $info['in_stock_qty'], 'shipped_qty' => 0, 'pending_qty' => $info['in_stock_qty'], 'date' => null, 'labor_minutes' => round($per * $info['in_stock_qty'], 1)];
        if ($info['other_qty'] > 0) $usage[] = ['type' => 'other', 'type_label' => '未完工 / 报废 / 其他', 'status_label' => '未出库', 'shipped' => false, 'target' => '-',
            'sales_order_no' => null, 'qty' => $info['other_qty'], 'shipped_qty' => 0, 'pending_qty' => $info['other_qty'], 'date' => null, 'labor_minutes' => round($per * $info['other_qty'], 1)];
        $f = $this->fraction($unit, $today, true);
        return ['work_order' => $info + ['labor_minutes' => round($total, 1), 'labor_minutes_per_unit' => $output > 0 ? round($per, 1) : null,
            'settled_minutes' => round($total * $f, 1), 'unsettled_minutes' => round($total * (1 - $f), 1)], 'usage' => $usage];
    }

    // ---------- 内部 ----------

    private function units(): array
    {
        $units = [];
        foreach ($this->wos as $wo) if (isset($this->labor['W'.$wo->id])) $units[] = $this->woUnit($wo);
        foreach ($this->packing as $p) $units[] = $p;
        return $units;
    }

    private function woUnit(object $wo): array
    {
        $stored = (float) $wo->stored_qty;
        $output = in_array($wo->status, ['completed', 'closed'], true) && $stored > 0 ? $stored : max((float) $wo->planned_qty, $stored);
        return ['key' => 'W'.$wo->id, 'kind' => 'work_order', 'wo' => $wo, 'output' => $output, 'ops' => $this->ops['W'.$wo->id] ?? []];
    }

    /** 产品最终出库事件（日期、订单行、占这张工单产出的件数 qty、占订单行的比例 lf）。 */
    private function events(array $unit): array
    {
        if ($unit['kind'] === 'packing') return $unit['events'];
        $id = (int) $unit['wo']->id;
        if (isset($this->memo[$id])) return $this->memo[$id];
        $this->memo[$id] = [];
        $events = $this->direct[$id] ?? [];
        foreach ($this->consumers[$id] ?? [] as $c) {
            $cu = $this->woUnit($this->wos[$c['consumer']]);
            if ($cu['output'] <= 0 || $c['N'] <= 0) continue;
            foreach ($this->events($cu) as $e) {
                $events[] = ['qty' => $c['n'] * $e['qty'] / $cu['output'], 'lf' => bcmul($e['lf'], bcdiv((string) $c['n'], (string) $c['N'], 12), 12)] + $e;
            }
        }
        return $this->memo[$id] = $events;
    }

    private function shippedQty(array $unit, string $date): float
    {
        return array_sum(array_map(fn ($e) => $e['date'] <= $date ? $e['qty'] : 0, $this->events($unit)));
    }

    /** 已结算比例：截至某天已出库件数 ÷ 产出（inclusive=false 表示该日之前）。 */
    private function fraction(array $unit, string $date, bool $inclusive): float
    {
        if ($unit['output'] <= 0) return 0.0;
        $q = array_sum(array_map(fn ($e) => ($inclusive ? $e['date'] <= $date : $e['date'] < $date) ? $e['qty'] : 0, $this->events($unit)));
        return max(0.0, min(1.0, $q / $unit['output']));
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
                'shipped_qty' => $shipped ? 1 : 0, 'consumed_pending_qty' => 0, 'in_stock_qty' => 0, 'other_qty' => 0, 'pending_qty' => $shipped ? 0 : 1,
                'status_label' => $shipped ? '已发运' : '未发运'];
        }
        $wo = $unit['wo'];
        $direct = array_sum(array_map(fn ($e) => $e['date'] <= $date && ($e['type'] ?? 'ship') === 'ship' ? $e['qty'] : 0, $this->direct[$wo->id] ?? []));
        $consumed = 0.0; $consumedShipped = 0.0;
        foreach ($this->consumers[$wo->id] ?? [] as $c) {
            $consumed += max(0, $this->sumLabor($c['dated'], $date, true));
            $cu = $this->woUnit($this->wos[$c['consumer']]);
            if ($cu['output'] > 0) $consumedShipped += max(0, min($c['n'], $c['n'] * $this->shippedQty($cu, $date) / $cu['output']));
        }
        $returned = -array_sum(array_map(fn ($e) => ($e['type'] ?? '') === 'return' && $e['date'] <= $date ? $e['qty'] : 0, $this->events($unit)));
        $stock = $this->stock[$wo->id] ?? 0.0;
        $shipped = max(0, min($unit['output'], $this->shippedQty($unit, $date)));
        $other = max(0, $unit['output'] - $direct - $consumed - $stock);
        return ['key' => $unit['key'], 'kind' => 'work_order', 'work_order_id' => (int) $wo->id, 'work_order_no' => $wo->work_order_no,
            'source_label' => self::SOURCE_LABELS[$wo->source_type] ?? $wo->source_type, 'item_name' => $wo->item_name, 'sales_order_no' => $wo->sales_order_no,
            'unit_name' => $wo->unit_name, 'output_qty' => round($unit['output'], 4), 'shipped_qty' => round($shipped, 4),
            'consumed_pending_qty' => round(max(0, $consumed - $consumedShipped), 4), 'in_stock_qty' => round($stock, 4), 'other_qty' => round($other, 4),
            'pending_qty' => round(max(0, $unit['output'] - $shipped), 4), 'returned_qty' => round(max(0, $returned), 4),
            'status_label' => $unit['output'] - $shipped < 0.0001 ? '已全部出库' : ($shipped > 0.0001 ? '部分出库' : '未出库')];
    }

    private function shippedTo(array $events): array
    {
        $out = [];
        foreach ($events as $e) {
            $type = $e['type'] ?? 'ship';
            $k = $type.'|'.$e['order_no'].'|'.$e['date'];
            $out[$k] ??= ['type' => $type, 'type_label' => $type === 'return' ? ($e['qty'] > 0 || bccomp($e['lf'], '0', 12) > 0 ? '退回客户（加回）' : '客户退货（扣回）') : '出库',
                'sales_order_no' => $e['order_no'], 'date' => $e['date'], 'qty' => 0.0];
            $out[$k]['qty'] += $e['qty'];
        }
        return array_values(array_map(fn ($r) => ['qty' => round($r['qty'], 4)] + $r, $out));
    }

    private function roundRow(array $r, bool $amounts): array
    {
        foreach (['opening', 'new', 'closing'] as $k) $r[$k] = round(max(0, $r[$k]), 1);
        $r['settled'] = round($r['settled'], 1); // 退货当月可能为负（扣回）
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
        // 订单行金额（折人民币）和数量
        $orders = DB::table('erp_sales_orders')->whereIn('id', DB::table('erp_sales_shipments')->select('sales_order_id'))->get()->keyBy('id');
        foreach (DB::table('erp_sales_order_lines')->whereIn('sales_order_id', $orders->keys()->all() ?: [0])->get() as $l) {
            $o = $orders[$l->sales_order_id];
            $foreign = strtoupper((string) ($o->currency ?: 'CNY')) !== strtoupper((string) ($o->base_currency ?: 'CNY'));
            $rate = ! $foreign ? '1' : (bccomp((string) ($o->business_exchange_rate ?? 0), '0', 10) > 0 ? (string) $o->business_exchange_rate : null);
            $q = (float) $l->item_base_required_qty > 0 ? (string) $l->item_base_required_qty : (string) $l->order_qty;
            $this->lines[(int) $l->id] = ['amount' => $rate === null || $l->amount_incl_tax === null ? null : bcmul((string) $l->amount_incl_tax, $rate, 4),
                'qty' => $q, 'order_no' => $o->sales_order_no];
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
        $woIds = array_map(fn ($k) => (int) substr($k, 1), array_keys($this->labor));
        foreach (DB::table('erp_mfg_work_order_operations')->whereIn('work_order_id', $woIds ?: [0])->get() as $op)
            $this->ops['W'.$op->work_order_id][(int) $op->id] = ['name' => $op->operation_name, 'rate' => $op->performance_rate !== null && (float) $op->performance_rate > 0 ? (string) $op->performance_rate : null];
        $fixed = DB::table('erp_mfg_task_participants')->whereIn('work_order_id', $woIds ?: [0])->where('is_active', true)->get()->groupBy('work_order_operation_id');
        foreach ($this->labor as $key => $opsLabor) {
            if ($key[0] !== 'W') continue;
            foreach ($opsLabor as $opId => $emps) $this->shares[$key][$opId] = $this->opShares($emps, $fixed->get($opId, collect())->all());
        }
        $this->loadPacking($shipDates);
        foreach (DB::table('erp_inventory_balances')->where('quantity_on_hand', '>', 0)->groupBy('item_id', 'batch_no')->selectRaw('item_id, batch_no, SUM(quantity_on_hand) AS q')->get() as $b) {
            $woId = $this->woByKey[$b->item_id.'|'.$b->batch_no] ?? null;
            if ($woId) $this->stock[$woId] = (float) $b->q;
        }
    }

    /**
     * 客户退货：按退货到货日记负数事件，摊到这行商品发货时用的各批工单（按发货数量比例）。
     * 到货时就退回客户（拒收）的不算；待处理的后来退回客户，在处置那天加回。数量按到货当时的口径还原，后来的处置不改到货那天的数字。
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

    private function addReturn(int $lineId, float $qty, string $date, string $no, int $itemId, string $batch): void
    {
        $line = $this->lines[$lineId] ?? null;
        $shipped = $this->lineShipped[$lineId] ?? [];
        $total = array_sum($shipped);
        if (abs($qty) < 0.00000001 || ! $line || $total <= 0 || bccomp($line['qty'], '0', 8) <= 0) return;
        arsort($shipped);
        foreach ($shipped as $woId => $q) {
            $part = $qty * $q / $total;
            $this->direct[$woId][] = ['type' => 'return', 'date' => $date, 'line_id' => $lineId, 'order_no' => $line['order_no'], 'shipment_no' => $no,
                'qty' => $part, 'lf' => bcdiv(number_format($part, 8, '.', ''), $line['qty'], 12)];
        }
        // 退回入库用了新批次号的，归到这行的主要工单，以后再发出去还能接着结算。
        if ($batch !== '' && $itemId && ! isset($this->woByKey[$itemId.'|'.$batch])) $this->woByKey[$itemId.'|'.$batch] = array_key_first($shipped);
        $this->lineReturns[$lineId][] = ['date' => $date, 'qty' => $qty, 'no' => $no];
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
                foreach ($events as $e) {
                    $shippedLine = array_sum($this->lineShipped[$e['line_id']] ?? []);
                    $packedQty = (float) bcmul($e['lf'], $this->lines[$e['line_id']]['qty'], 8);
                    if ($shippedLine <= 0) continue;
                    foreach ($this->lineReturns[$e['line_id']] ?? [] as $ret) {
                        $events[] = ['type' => 'return', 'date' => $ret['date'], 'line_id' => $e['line_id'], 'order_no' => $e['order_no'], 'shipment_no' => $ret['no'], 'qty' => 0.0,
                            'lf' => bcdiv(number_format($ret['qty'] * $packedQty / $shippedLine, 8, '.', ''), $this->lines[$e['line_id']]['qty'], 12)];
                    }
                }
            }
            $rate = $op->performance_rate_snapshot !== null && (float) $op->performance_rate_snapshot > 0 ? (string) $op->performance_rate_snapshot : null;
            $this->packing[] = ['key' => $key, 'kind' => 'packing', 'output' => 1.0, 'events' => $events, 'shipment_no' => $op->shipment_no, 'order_no' => $op->sales_order_no,
                'name' => $op->operation_name_snapshot, 'ops' => [0 => ['name' => $op->operation_name_snapshot, 'rate' => $rate]]];
        }
    }

    /** 份额：每人都填了固定份额且合计 100% 按固定份额，否则按实际工时占比。 */
    private function opShares(array $emps, array $participants): array
    {
        $fixed = $participants !== [] && collect($participants)->every(fn ($p) => $p->share_percent !== null) && abs(collect($participants)->sum(fn ($p) => (float) $p->share_percent) - 100) < 0.01;
        if ($fixed) return collect($participants)->mapWithKeys(fn ($p) => [(int) $p->employee_legacy_id => bcdiv((string) $p->share_percent, '100', 8)])->all();
        $rows = [];
        foreach ($emps as $id => $list) $rows[] = ['employee_legacy_id' => $id, 'actual_labor_minutes' => array_sum(array_column($list, 1))];
        return collect($this->calc->laborShares($rows))->mapWithKeys(fn ($s) => [(int) $s['employee_legacy_id'] => $s['share_ratio']])->all();
    }
}
