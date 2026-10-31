<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\MfgCuttingBatch;
use App\Models\Erp\MfgCuttingRun;
use App\Models\Erp\MfgCuttingRunAllocation;
use App\Models\Erp\MfgCuttingRunSession;
use App\Models\Erp\MfgLaborSession;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderMaterial;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\ShipmentPackingLaborSession;
use App\Services\Erp\DocumentNumberService;
use Illuminate\Support\Facades\DB;

/**
 * 集中开料记录（按实际工时）：
 * - 一次只开一个 BOM（一种设备／半成品）的料，不能把两个设备的料混在一起开；
 * - 开料的人各自点开始／暂停，按实际时间记工时，不参考标准工时；
 * - 结束时登记每种料实际切了多少件（默认 = 每套件数 × 开的套数，可以改）；
 * - 工单下料点「已备料」时按件数领走：带走的工时 = 这批总工时 × 领走件数 ÷ 这批总件数（例如 4 小时切 160 根，领 1 根 = 4 ÷ 160 × 1）。
 *   没领完的件留在这批里（结余），以后谁领谁带走；工时跟着做出来的产品出库时结算。
 */
final class CuttingRunService
{
    use ProductionActor;

    private const OPEN_WO = ['released', 'in_progress', 'paused'];

    public function __construct(private readonly DocumentNumberService $numbers) {}

    /** 可开料的 BOM：已审核启用、有下料行的 BOM，带出还要开多少套、已开未领多少。 */
    public function boms(array $filters): array
    {
        $keyword = trim((string) ($filters['keyword'] ?? ''));
        $boms = DB::table('erp_boms as b')->join('erp_items as i', 'i.id', '=', 'b.output_item_id')
            ->whereNull('b.deleted_at')->where('b.status', 'active')->where('b.audit_status', 'approved')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_bom_items as bi')->whereColumn('bi.bom_id', 'b.id')->whereNull('bi.deleted_at')->whereNotNull('bi.cutting_quantity_snapshot'))
            ->when($keyword !== '', fn ($q) => $q->where(fn ($w) => $w->where('b.bom_no', 'like', "%{$keyword}%")->orWhere('b.bom_name', 'like', "%{$keyword}%")
                ->orWhere('i.item_name', 'like', "%{$keyword}%")->orWhere('i.item_code', 'like', "%{$keyword}%")))
            ->orderBy('i.item_name')->orderBy('b.id')->limit(100)
            ->get(['b.id', 'b.bom_no', 'b.bom_name', 'b.version', 'b.output_item_id', 'i.item_name', 'i.item_code', 'i.spec', 'i.model']);
        $demand = $this->demandByItem($boms->pluck('output_item_id')->unique()->all());
        $runs = MfgCuttingRun::query()->whereIn('output_item_id', $boms->pluck('output_item_id')->unique()->all() ?: [0])->whereIn('status', ['running', 'finished'])->get();
        $out = [];
        foreach ($boms as $b) {
            $lines = $this->bomLines((int) $b->id);
            if (! $lines) continue;
            $mine = $runs->where('output_item_id', $b->output_item_id);
            $out[] = ['bom_id' => (int) $b->id, 'bom_no' => $b->bom_no, 'bom_name' => $b->bom_name, 'version' => $b->version,
                'output_item_id' => (int) $b->output_item_id, 'item_name' => $b->item_name, 'item_code' => $b->item_code, 'specification' => $b->spec ?: $b->model,
                'lines' => array_values($lines), 'pieces_per_set' => array_sum(array_column($lines, 'per_set')),
                'need_sets' => (int) ($demand[$b->output_item_id] ?? 0),
                'stock_sets' => $this->availableSets($mine->where('status', 'finished')),
                'running' => $mine->where('status', 'running')->count()];
        }
        usort($out, fn ($a, $b) => [$b['need_sets'] > 0, $b['need_sets']] <=> [$a['need_sets'] > 0, $a['need_sets']]);
        return ['items' => $out];
    }

    public function index(array $filters): array
    {
        $this->closeOverdueSessions();
        $page = MfgCuttingRun::query()
            ->when(! empty($filters['status']), fn ($q) => $q->where('status', $filters['status']), fn ($q) => $q->where('status', '!=', 'cancelled'))
            ->when(! empty($filters['output_item_id']), fn ($q) => $q->where('output_item_id', (int) $filters['output_item_id']))
            ->when(! empty($filters['only_left']), fn ($q) => $q->whereColumn('allocated_pieces', '<', 'total_pieces'))
            ->orderByRaw("status = 'running' DESC")->orderByDesc('id')
            ->paginate(min(50, max(1, (int) ($filters['per_page'] ?? 20))), ['*'], 'page', max(1, (int) ($filters['page'] ?? 1)));
        $timing = MfgCuttingRunSession::query()->whereIn('cutting_run_id', $page->getCollection()->pluck('id') ?: [0])->where('status', 'active')
            ->get(['cutting_run_id', 'employee_name'])->groupBy('cutting_run_id');
        $page->getCollection()->transform(fn ($run) => $this->summary($run) + ['timing_names' => $timing->get($run->id, collect())->pluck('employee_name')->values()->all()]);
        return $page->toArray();
    }

    public function show(int $id, ?object $actor = null): array
    {
        $this->closeOverdueSessions();
        return $this->detail(MfgCuttingRun::query()->findOrFail($id), $actor);
    }

    /** 新开一批：选 BOM、开几套，开的人同时开始计时。 */
    public function start(array $data, object $actor): array
    {
        $this->closeOverdueSessions();
        return DB::transaction(function () use ($data, $actor) {
            $me = (int) $this->actorId($actor);
            DB::table('erp_legacy_admin_users')->where('legacy_id', $me)->lockForUpdate()->first();
            $this->assertFree($me);
            $bom = DB::table('erp_boms as b')->join('erp_items as i', 'i.id', '=', 'b.output_item_id')->where('b.id', (int) $data['bom_id'])
                ->whereNull('b.deleted_at')->first(['b.id', 'b.bom_no', 'b.bom_name', 'b.status', 'b.audit_status', 'b.output_item_id', 'i.item_name']);
            if (! $bom || $bom->status !== 'active' || $bom->audit_status !== 'approved') $this->fail('bom_invalid', '请选已审核、启用的 BOM。');
            $lines = $this->bomLines((int) $bom->id);
            if (! $lines) $this->fail('bom_no_cutting', '这个 BOM 里没有要下料的板材／管材（没填下料尺寸和件数）。');
            $sets = max(1, (int) $data['set_qty']);
            foreach ($lines as &$line) $line += ['planned_pieces' => $line['per_set'] * $sets, 'actual_pieces' => $line['per_set'] * $sets, 'allocated_pieces' => 0];
            unset($line);
            $run = MfgCuttingRun::create([
                'run_no' => $this->numbers->next('mfg_cutting_run', 'MCR'), 'bom_id' => $bom->id, 'bom_no' => $bom->bom_no, 'bom_name' => $bom->bom_name,
                'output_item_id' => $bom->output_item_id, 'output_item_name' => $bom->item_name, 'status' => 'running', 'business_version' => 1,
                'set_qty' => $sets, 'lines' => array_values($lines), 'total_pieces' => 0, 'allocated_pieces' => 0,
                'started_by_legacy_id' => $me, 'started_by_name' => $this->actorName($actor), 'started_at' => now(),
                'remark' => isset($data['remark']) ? mb_substr(trim((string) $data['remark']), 0, 500) ?: null : null,
            ]);
            MfgCuttingRunSession::create(['cutting_run_id' => $run->id, 'employee_legacy_id' => $me, 'employee_name' => $this->actorName($actor),
                'status' => 'active', 'started_at' => now()]);
            return $this->detail($run->fresh(), $actor);
        }, 5);
    }

    /** 开始／暂停自己的计时（几个人一起开料，各自点）。 */
    public function timing(int $id, string $action, object $actor): array
    {
        $this->closeOverdueSessions();
        return DB::transaction(function () use ($id, $action, $actor) {
            $me = (int) $this->actorId($actor);
            DB::table('erp_legacy_admin_users')->where('legacy_id', $me)->lockForUpdate()->first();
            $run = MfgCuttingRun::query()->lockForUpdate()->findOrFail($id);
            if ($run->status !== 'running') $this->fail('status_invalid', '这批开料已经结束，不能再计时。');
            $active = MfgCuttingRunSession::query()->where('cutting_run_id', $run->id)->where('employee_legacy_id', $me)->where('status', 'active')->lockForUpdate()->first();
            if ($action === 'pause') {
                if (! $active) $this->fail('not_timing', '你在这批开料没有正在计时。');
                $this->close($active, 'paused');
            } else {
                if ($active) $this->fail('already_timing', '你已经在计时了。');
                $this->assertFree($me);
                MfgCuttingRunSession::create(['cutting_run_id' => $run->id, 'employee_legacy_id' => $me, 'employee_name' => $this->actorName($actor),
                    'status' => 'active', 'started_at' => now()]);
            }
            return $this->detail($run->fresh(), $actor);
        }, 5);
    }

    /** 切完结束：停掉所有人的计时，登记每种料实际切了多少件。 */
    public function finish(int $id, array $data, object $actor): array
    {
        return DB::transaction(function () use ($id, $data, $actor) {
            $run = MfgCuttingRun::query()->lockForUpdate()->findOrFail($id);
            if ($run->status !== 'running') $this->fail('status_invalid', '这批开料已经结束。');
            if ((int) $run->business_version !== (int) $data['expected_version']) $this->fail('version_conflict', '这批开料有变化，请刷新后再确认。', 409);
            if (MfgCuttingBatch::query()->where('cutting_run_id', $run->id)->where('status', 'issued')->exists())
                $this->fail('sheet_not_settled', '这批领的板还没登记余料，请先在「领板／登记余料」里登记完再结束。');
            $counts = collect($data['lines'] ?? [])->mapWithKeys(fn ($l) => [(string) $l['line_key'] => (int) $l['actual_pieces']]);
            $lines = $run->lines;
            $total = 0;
            foreach ($lines as &$line) {
                if ($counts->has($line['line_key'])) $line['actual_pieces'] = max(0, min(100000, $counts[$line['line_key']]));
                $total += (int) $line['actual_pieces'];
            }
            unset($line);
            if ($total <= 0) $this->fail('pieces_required', '请填实际切了多少件。');
            foreach (MfgCuttingRunSession::query()->where('cutting_run_id', $run->id)->where('status', 'active')->lockForUpdate()->get() as $s) $this->close($s, 'closed');
            $minutes = round((float) MfgCuttingRunSession::query()->where('cutting_run_id', $run->id)->sum('labor_minutes'), 2);
            if ($minutes <= 0) $this->fail('labor_required', '这批开料没有计时记录，请先点开始计时。');
            $run->update(['status' => 'finished', 'lines' => $lines, 'total_pieces' => $total, 'labor_minutes' => $minutes,
                'business_version' => (int) $run->business_version + 1, 'finished_by_legacy_id' => $this->actorId($actor), 'finished_by_name' => $this->actorName($actor),
                'finished_at' => now(), 'remark' => array_key_exists('remark', $data) ? (mb_substr(trim((string) $data['remark']), 0, 500) ?: null) : $run->remark]);
            return $this->detail($run->fresh(), $actor);
        }, 5);
    }

    /** 开错了作废：还没结束、没有领板的才能作废，计时不再算。 */
    public function cancel(int $id, object $actor): array
    {
        return DB::transaction(function () use ($id, $actor) {
            $run = MfgCuttingRun::query()->lockForUpdate()->findOrFail($id);
            if ($run->status !== 'running') $this->fail('status_invalid', '只有还没结束的开料记录能作废。');
            if (MfgCuttingBatch::query()->where('cutting_run_id', $run->id)->exists()) $this->fail('has_sheet_batch', '这批已经领了板，不能作废，请登记余料后结束。');
            foreach (MfgCuttingRunSession::query()->where('cutting_run_id', $run->id)->where('status', 'active')->lockForUpdate()->get() as $s) $this->close($s, 'closed');
            $run->update(['status' => 'cancelled', 'business_version' => (int) $run->business_version + 1,
                'finished_by_legacy_id' => $this->actorId($actor), 'finished_by_name' => $this->actorName($actor), 'finished_at' => now()]);
            return $this->detail($run->fresh(), $actor);
        }, 5);
    }

    /**
     * 工单下料「已备料」：这次第一次过本道的 $units 套，按本道每种料的每套件数从同一产品的开料记录里领（指定的那批优先，其余先开的先领），
     * 每领一件带走 这批总工时 ÷ 这批总件数。开料记录还在开（没结束）又不够领时不让备料，避免工时漏掉。
     * 调用方须在事务内并已锁定工单和工序。返回领到的明细。
     */
    public function allocate(MfgWorkOrder $wo, MfgWorkOrderOperation $op, int $units, object $actor, ?int $preferRunId = null): array
    {
        if ($units <= 0) return [];
        $needs = [];
        foreach ($this->woLines($wo, $op) as $key => $line) $needs[$key] = ['pieces' => (int) round($line['per_set'] * $units), 'material_id' => $line['material_id'], 'name' => $line['name']];
        $needs = array_filter($needs, fn ($n) => $n['pieces'] > 0);
        if (! $needs) return [];
        $runs = MfgCuttingRun::query()->where('output_item_id', $wo->item_id)->where('status', 'finished')->whereColumn('allocated_pieces', '<', 'total_pieces')
            ->orderByRaw('id = ? DESC', [(int) $preferRunId])->orderBy('finished_at')->orderBy('id')->lockForUpdate()->get();
        if ($preferRunId && ! $runs->contains('id', $preferRunId)) $this->fail('cutting_run_invalid', '所选开料记录不是这个产品的、还没结束或已经领完，请刷新后重选。');
        $out = [];
        foreach ($runs as $run) {
            $lines = $run->lines;
            $taken = 0; $minutes = '0';
            foreach ($lines as &$line) {
                $key = $line['line_key'];
                if (empty($needs[$key]['pieces'])) continue;
                $left = (int) $line['actual_pieces'] - (int) $line['allocated_pieces'];
                $take = min($left, $needs[$key]['pieces']);
                if ($take <= 0) continue;
                $line['allocated_pieces'] = (int) $line['allocated_pieces'] + $take;
                $needs[$key]['pieces'] -= $take;
                $taken += $take;
                $out[] = ['run_id' => (int) $run->id, 'run_no' => $run->run_no, 'line_key' => $key, 'material_id' => $needs[$key]['material_id'], 'name' => $line['name'], 'pieces' => $take];
            }
            unset($line);
            if ($taken <= 0) continue;
            $after = (int) $run->allocated_pieces + $taken;
            // 一批领完时把剩下的工时全部带走，避免四舍五入留尾差。
            $runMinutes = $after >= (int) $run->total_pieces ? bcsub((string) $run->labor_minutes, (string) $run->allocated_minutes, 4)
                : bcdiv(bcmul((string) $run->labor_minutes, (string) $taken, 8), (string) $run->total_pieces, 4);
            foreach ($out as &$row) {
                if ($row['run_id'] !== (int) $run->id || isset($row['done'])) continue;
                $m = bcdiv(bcmul($runMinutes, (string) $row['pieces'], 8), (string) $taken, 4);
                MfgCuttingRunAllocation::create(['cutting_run_id' => $run->id, 'work_order_id' => $wo->id, 'work_order_operation_id' => $op->id,
                    'work_order_material_id' => $row['material_id'], 'line_key' => $row['line_key'], 'unit_qty' => $units, 'pieces' => $row['pieces'],
                    'labor_minutes' => $m, 'allocated_by_legacy_id' => $this->actorId($actor), 'allocated_by_name' => $this->actorName($actor)]);
                $row['labor_minutes'] = (float) $m;
                $row['done'] = true;
            }
            unset($row);
            $run->update(['lines' => $lines, 'allocated_pieces' => $after, 'allocated_minutes' => bcadd((string) $run->allocated_minutes, $runMinutes, 4)]);
        }
        $short = array_filter($needs, fn ($n) => $n['pieces'] > 0);
        if ($short && MfgCuttingRun::query()->where('output_item_id', $wo->item_id)->where('status', 'running')->exists())
            $this->fail('cutting_run_open', '「集中开料」里这个产品还有没结束的开料记录，'.implode('、', array_map(fn ($n) => $n['name'].' 还差 '.$n['pieces'].' 件', $short))
                .'。请开料的人先点「切完结束」登记件数，再来备料，这样开料工时才能带过来。');
        return array_map(fn ($r) => array_diff_key($r, ['done' => 1]), $out);
    }

    /** 这道下料工序能从哪些开料记录领（同一产品、已结束、还有剩），以及还在开的批次数。 */
    public function availableFor(MfgWorkOrder $wo, MfgWorkOrderOperation $op): array
    {
        $lines = $this->woLines($wo, $op);
        if (! $lines) return ['runs' => [], 'running' => 0];
        $runs = MfgCuttingRun::query()->where('output_item_id', $wo->item_id)->where('status', 'finished')->whereColumn('allocated_pieces', '<', 'total_pieces')
            ->orderBy('finished_at')->orderBy('id')->limit(20)->get();
        $rows = [];
        foreach ($runs as $run) {
            $sets = null;
            foreach ($lines as $key => $l) {
                $line = collect($run->lines)->firstWhere('line_key', $key);
                $left = $line ? (int) $line['actual_pieces'] - (int) $line['allocated_pieces'] : 0;
                $sets = min($sets ?? PHP_INT_MAX, $l['per_set'] > 0 ? (int) floor($left / $l['per_set'] + 1e-9) : PHP_INT_MAX);
            }
            $perSet = array_sum(array_column($lines, 'per_set'));
            $rows[] = ['id' => (int) $run->id, 'run_no' => $run->run_no, 'finished_at' => $run->finished_at?->toDateTimeString(),
                'left_pieces' => (int) $run->total_pieces - (int) $run->allocated_pieces, 'left_sets' => (int) ($sets ?? 0),
                'minutes_per_set' => (int) $run->total_pieces > 0 ? round((float) $run->labor_minutes * $perSet / (int) $run->total_pieces, 1) : null];
        }
        return ['runs' => $rows, 'running' => MfgCuttingRun::query()->where('output_item_id', $wo->item_id)->where('status', 'running')->count()];
    }

    /** 某人有没有正在计时的开料记录（工单计时、打包计时开始前要查）。 */
    public static function activeFor(int $employeeId): ?string
    {
        $session = MfgCuttingRunSession::query()->where('employee_legacy_id', $employeeId)->where('status', 'active')->first(['cutting_run_id']);
        return $session ? (string) MfgCuttingRun::query()->whereKey($session->cutting_run_id)->value('run_no') : null;
    }

    /** 计时超过 4 小时没停的，按开始后 4 小时自动停止。 */
    public function closeOverdueSessions(): void
    {
        $limit = now()->subHours(TaskService::AUTO_STOP_HOURS);
        foreach (MfgCuttingRunSession::query()->where('status', 'active')->where('started_at', '<=', $limit)->limit(200)->pluck('id') as $id) {
            DB::transaction(function () use ($id, $limit) {
                $s = MfgCuttingRunSession::query()->where('status', 'active')->where('started_at', '<=', $limit)->lockForUpdate()->find($id);
                if ($s) $this->close($s, 'paused', $s->started_at->copy()->addHours(TaskService::AUTO_STOP_HOURS));
            }, 5);
        }
    }

    /** 本工单这道工序要切的料（按物料 + 尺寸），每套几件。 */
    public function woLines(MfgWorkOrder $wo, MfgWorkOrderOperation $op): array
    {
        $snap = collect(data_get($wo->bom_snapshot, 'items', []))->keyBy('id');
        $out = [];
        foreach (MfgWorkOrderMaterial::query()->where('work_order_operation_id', $op->id)->whereIn('cutting_mode', ['sheet', 'length'])->where('required_qty', '>', 0)->orderBy('id')->get() as $m) {
            $line = $snap->get($m->bom_item_id) ?? [];
            $snapshot = $line['cutting_quantity_snapshot'] ?? null;
            if (! is_array($snapshot)) continue;
            $key = self::lineKey((int) $m->component_item_id, $snapshot);
            $per = self::perSet($snapshot) * (float) ($m->qty_ratio ?: 1);
            if ($per <= 0) continue;
            $out[$key] ??= ['per_set' => 0.0, 'material_id' => (int) $m->id, 'name' => $m->component_item_name.' '.self::sizeText($snapshot)];
            $out[$key]['per_set'] += $per;
        }
        return $out;
    }

    public static function lineKey(int $itemId, array $snapshot): string
    {
        $n = fn ($v) => $v === null ? '' : rtrim(rtrim(sprintf('%.2F', (float) $v), '0'), '.');
        if (($snapshot['quantity_basis'] ?? null) === 'total_length') return $itemId.'|T'.$n($snapshot['total_length_mm'] ?? null);
        $d = $snapshot['dimensions'] ?? [];
        return $itemId.'|'.$n($d['length_mm'] ?? null).'x'.$n($d['width_mm'] ?? null).'x'.$n($d['thickness_mm'] ?? null);
    }

    public static function perSet(array $snapshot): int
    {
        if (($snapshot['quantity_basis'] ?? null) === 'total_length') return 1;
        return max(0, (int) ($snapshot['dimensions']['piece_qty'] ?? 0));
    }

    private static function sizeText(array $snapshot): string
    {
        $n = fn ($v) => rtrim(rtrim(sprintf('%.2F', (float) $v), '0'), '.');
        if (($snapshot['quantity_basis'] ?? null) === 'total_length') return '总长 '.$n($snapshot['total_length_mm'] ?? 0).'mm';
        $d = $snapshot['dimensions'] ?? [];
        return implode('×', array_map($n, array_filter([$d['length_mm'] ?? null, $d['width_mm'] ?? null, $d['thickness_mm'] ?? null], fn ($v) => $v !== null))).'mm';
    }

    /** BOM 里要下料的行（按物料 + 尺寸合并）。 */
    private function bomLines(int $bomId): array
    {
        $rows = DB::table('erp_bom_items as bi')->join('erp_items as i', 'i.id', '=', 'bi.component_item_id')->where('bi.bom_id', $bomId)->whereNull('bi.deleted_at')
            ->whereNotNull('bi.cutting_quantity_snapshot')->orderBy('bi.line_no')->orderBy('bi.id')
            ->get(['bi.id', 'bi.line_no', 'bi.component_item_id', 'bi.component_item_name', 'bi.remark', 'bi.cutting_quantity_snapshot', 'i.material_management_mode', 'i.spec', 'i.model']);
        $out = [];
        foreach ($rows as $r) {
            $snapshot = json_decode((string) $r->cutting_quantity_snapshot, true);
            if (! is_array($snapshot) || ! in_array($snapshot['mode'] ?? null, ['sheet', 'length'], true)) continue;
            $per = self::perSet($snapshot);
            if ($per <= 0) continue;
            $key = self::lineKey((int) $r->component_item_id, $snapshot);
            $d = $snapshot['dimensions'] ?? [];
            $out[$key] ??= ['line_key' => $key, 'bom_item_id' => (int) $r->id, 'component_item_id' => (int) $r->component_item_id,
                'component_item_name' => $r->component_item_name, 'specification' => $r->spec ?: $r->model, 'mode' => $snapshot['mode'],
                'physical' => $r->material_management_mode === 'physical', 'part_name' => $r->remark ?: null,
                'length_mm' => $d['length_mm'] ?? null, 'width_mm' => $d['width_mm'] ?? null, 'thickness_mm' => $d['thickness_mm'] ?? null,
                'total_length_mm' => $snapshot['total_length_mm'] ?? null, 'name' => $r->component_item_name.' '.self::sizeText($snapshot), 'per_set' => 0];
            $out[$key]['per_set'] += $per;
        }
        return $out;
    }

    /** 还在生产的工单里，各产品的下料工序还要做多少套。 */
    private function demandByItem(array $itemIds): array
    {
        if (! $itemIds) return [];
        $rows = DB::table('erp_mfg_work_order_operations as o')->join('erp_mfg_work_orders as w', 'w.id', '=', 'o.work_order_id')
            ->whereIn('w.item_id', $itemIds)->whereIn('w.status', self::OPEN_WO)->where('o.execution_context', 'production')->where('o.status', '!=', 'done')
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('erp_mfg_work_order_materials as m')->whereColumn('m.work_order_operation_id', 'o.id')
                ->whereIn('m.cutting_mode', ['sheet', 'length'])->where('m.required_qty', '>', 0))
            ->get(['w.item_id', 'w.planned_qty', 'o.completed_qty']);
        $out = [];
        foreach ($rows as $r) $out[$r->item_id] = ($out[$r->item_id] ?? 0) + max(0, (int) round((float) $r->planned_qty - (float) $r->completed_qty));
        return $out;
    }

    private function availableSets($runs): int
    {
        $sets = 0;
        foreach ($runs as $run) {
            $min = null;
            foreach ($run->lines as $l) if ((int) $l['per_set'] > 0) $min = min($min ?? PHP_INT_MAX, intdiv(max(0, (int) $l['actual_pieces'] - (int) $l['allocated_pieces']), (int) $l['per_set']));
            $sets += (int) ($min ?? 0);
        }
        return $sets;
    }

    private function assertFree(int $me): void
    {
        if ($no = self::activeFor($me)) $this->fail('already_timing', "你在开料记录 {$no} 还在计时，请先暂停那一批。");
        if (MfgLaborSession::query()->where('employee_legacy_id', $me)->where('status', 'active')->exists()) $this->fail('already_timing', '你还有工单工序在计时，请先暂停那一道。');
        if (ShipmentPackingLaborSession::query()->where('employee_legacy_id', $me)->where('status', 'ACTIVE')->exists()) $this->fail('already_timing', '你有打包作业正在计时，请先暂停。');
    }

    private function close(MfgCuttingRunSession $s, string $status, $endedAt = null): void
    {
        $ended = $endedAt ?? now();
        $s->update(['status' => $status, 'ended_at' => $ended, 'labor_minutes' => round(max(0, $s->started_at->diffInSeconds($ended, false)) / 60, 2)]);
    }

    private function summary(MfgCuttingRun $run): array
    {
        $minutes = $run->status === 'running' ? $this->liveMinutes($run) : (float) $run->labor_minutes;
        return ['id' => (int) $run->id, 'run_no' => $run->run_no, 'bom_id' => (int) $run->bom_id, 'bom_no' => $run->bom_no, 'bom_name' => $run->bom_name,
            'output_item_id' => (int) $run->output_item_id, 'output_item_name' => $run->output_item_name, 'status' => $run->status,
            'status_label' => ['running' => '开料中', 'finished' => '已结束', 'cancelled' => '已作废'][$run->status] ?? $run->status,
            'business_version' => (int) $run->business_version, 'set_qty' => (int) $run->set_qty, 'total_pieces' => (int) $run->total_pieces,
            'allocated_pieces' => (int) $run->allocated_pieces, 'left_pieces' => max(0, (int) $run->total_pieces - (int) $run->allocated_pieces),
            'labor_minutes' => round($minutes, 1), 'allocated_minutes' => round((float) $run->allocated_minutes, 1),
            'minutes_per_piece' => (int) $run->total_pieces > 0 ? round((float) $run->labor_minutes / (int) $run->total_pieces, 3) : null,
            'started_by_name' => $run->started_by_name, 'started_at' => $run->started_at?->toDateTimeString(),
            'finished_by_name' => $run->finished_by_name, 'finished_at' => $run->finished_at?->toDateTimeString(), 'remark' => $run->remark];
    }

    private function liveMinutes(MfgCuttingRun $run): float
    {
        $sum = 0.0;
        foreach (MfgCuttingRunSession::query()->where('cutting_run_id', $run->id)->get() as $s)
            $sum += $s->status === 'active' ? max(0, $s->started_at->diffInSeconds(now(), false)) / 60 : (float) $s->labor_minutes;
        return $sum;
    }

    private function detail(MfgCuttingRun $run, ?object $actor): array
    {
        $me = $actor ? (int) $this->actorId($actor) : 0;
        $row = $this->summary($run);
        $row['lines'] = array_map(fn ($l) => $l + ['left_pieces' => max(0, (int) $l['actual_pieces'] - (int) $l['allocated_pieces'])], $run->lines ?? []);
        $people = [];
        foreach (MfgCuttingRunSession::query()->where('cutting_run_id', $run->id)->orderBy('id')->get() as $s) {
            $p = &$people[(int) $s->employee_legacy_id];
            $p ??= ['employee_legacy_id' => (int) $s->employee_legacy_id, 'employee_name' => $s->employee_name, 'labor_minutes' => 0.0, 'timing' => false, 'started_at' => null];
            if ($s->status === 'active') {
                $p['timing'] = true; $p['started_at'] = $s->started_at?->toIso8601String();
                $p['labor_minutes'] += max(0, $s->started_at->diffInSeconds(now(), false)) / 60;
            } else $p['labor_minutes'] += (float) $s->labor_minutes;
            unset($p);
        }
        $row['people'] = array_values(array_map(fn ($p) => ['labor_minutes' => round($p['labor_minutes'], 1)] + $p, $people));
        $row['my'] = ['timing' => (bool) ($people[$me]['timing'] ?? false), 'joined' => isset($people[$me])];
        $row['allocations'] = MfgCuttingRunAllocation::query()->from('erp_mfg_cutting_run_allocations as a')->join('erp_mfg_work_orders as w', 'w.id', '=', 'a.work_order_id')
            ->leftJoin('erp_mfg_work_order_operations as o', 'o.id', '=', 'a.work_order_operation_id')->where('a.cutting_run_id', $run->id)
            ->groupBy('a.work_order_id', 'w.work_order_no', 'w.sales_order_no', 'w.item_name', 'o.operation_name', 'a.allocated_by_name', DB::raw('DATE(a.created_at)'))
            ->orderByRaw('MIN(a.id)')
            ->selectRaw('a.work_order_id, w.work_order_no, w.sales_order_no, w.item_name, o.operation_name, a.allocated_by_name, DATE(a.created_at) AS date, MAX(a.unit_qty) AS unit_qty, SUM(a.pieces) AS pieces, SUM(a.labor_minutes) AS labor_minutes')
            ->get()->map(fn ($a) => ['work_order_id' => (int) $a->work_order_id, 'work_order_no' => $a->work_order_no, 'sales_order_no' => $a->sales_order_no,
                'item_name' => $a->item_name, 'operation_name' => $a->operation_name, 'allocated_by_name' => $a->allocated_by_name, 'date' => $a->date,
                'unit_qty' => (int) $a->unit_qty, 'pieces' => (int) $a->pieces, 'labor_minutes' => round((float) $a->labor_minutes, 1)])->values()->all();
        $row['sheet_batches'] = MfgCuttingBatch::query()->where('cutting_run_id', $run->id)->orderBy('id')->get(['id', 'batch_no', 'item_id', 'status'])
            ->map(fn ($b) => ['id' => (int) $b->id, 'batch_no' => $b->batch_no, 'item_id' => (int) $b->item_id, 'status' => $b->status,
                'status_label' => $b->status === 'issued' ? '切割中，待登记余料' : '已登记'])->values()->all();
        $row['sheet_items'] = array_values(array_map(fn ($l) => ['item_id' => $l['component_item_id'], 'item_name' => $l['component_item_name']],
            collect($run->lines)->filter(fn ($l) => $l['mode'] === 'sheet' && ! empty($l['physical']))->unique('component_item_id')->all()));
        return $row;
    }
}
