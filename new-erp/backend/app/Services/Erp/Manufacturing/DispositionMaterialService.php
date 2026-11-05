<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\{CancellationProductionDisposition as Disposition, MfgMaterialIssue, MfgMaterialReturn, MfgWorkOrder, MfgWorkOrderUnit, MfgUnitComponent};
use Illuminate\Support\Facades\DB;

/** 逐台停产份额沿真实领料来源冻结；共同投入按当时参与编号分摊，不冒充逐台实耗。 */
final class DispositionMaterialService
{
    public function freeze(Disposition $d, MfgWorkOrder $wo): void
    {
        $old = $d->material_allocation_snapshot;
        if ($old && $d->status !== 'stock') return;
        $prior = Disposition::where('work_order_id', $wo->id)->where('id', '!=', $d->id)->whereNotNull('material_allocation_snapshot')->orderBy('id')->first();
        $all = $old['unit_ids'] ?? data_get($prior?->material_allocation_snapshot, 'unit_ids') ?? MfgWorkOrderUnit::where('work_order_id', $wo->id)->where('unit_no', '<=', $wo->planned_qty)
            ->where(fn ($q) => $q->where('status', '!=', 'cancelled')->orWhereNotNull('started_at'))->orderBy('id')->pluck('id')->map(fn ($id) => (int) $id)->all();
        $unitId = (int) $d->work_order_unit_id;
        $sources = [];
        foreach (MfgMaterialIssue::where('work_order_id', $wo->id)->lockForUpdate()->get() as $issue) {
            $lines = collect($issue->issue_lines)->keyBy('material_id');
            $issued = DB::table('erp_inventory_transaction_items')->where('source_type', 'mfg_material_issue')->where('source_id', $issue->id)
                ->where('change_qty', '<', 0)->orderBy('id')->lockForUpdate()->get();
            $serials = []; $pools = [];
            foreach ($issued as $source) {
                $line = $lines->get($source->source_item_id);
                if (!$line) continue;
                $serials[$source->id] = $this->serialShare($wo, $issue, $line, $source, $all, $unitId);
                $pool = &$pools[$source->source_item_id];
                $pool ??= ['original' => 0, 'own' => 0, 'unbound' => 0];
                foreach (['original', 'own', 'unbound'] as $i => $key) $pool[$key] += count($serials[$source->id][$i]);
                unset($pool);
            }
            foreach ($issued as $source) {
                $line = $lines->get($source->source_item_id);
                if (!$line) continue;
                $cohort = isset($line['unit_ids']) ? array_map('intval', $line['unit_ids']) : $all;
                if (!in_array($unitId, $cohort, true)) continue;
                $ratio = self::unitRatio($line, [$unitId], $all);
                [$originalSerials, $ownSerials, $unboundSerials] = $serials[$source->id];
                $unserialized = bcsub(bcsub('0', (string) $source->change_qty, 8), (string) count($originalSerials), 8);
                $returnedSerials = DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
                    ->where('l.issue_id', $issue->id)->where('l.material_id', $source->source_item_id)->whereNotNull('l.inventory_serial_id')
                    ->where('r.status', 'approved')->get(['l.unit_id']);
                $returnedShare = $returnedSerials->sum(fn ($r) => $r->unit_id === null ? $ratio : ((int) $r->unit_id === $unitId ? 1 : 0));
                $pool = $pools[$source->source_item_id];
                $unboundQuota = max(0, $pool['original'] * $ratio - $pool['own'] - $returnedShare);
                $unboundQty = $pool['unbound'] ? sprintf('%.8F', $unboundQuota * count($unboundSerials) / $pool['unbound']) : '0';
                $qty = bcadd(bcadd(bcmul($unserialized, sprintf('%.12F', $ratio), 8), $unboundQty, 8), (string) count($ownSerials), 8);
                $cost = bccomp((string) $source->change_qty, '0', 8) !== 0
                    ? bcdiv(bcmul(bcsub('0', (string) $source->cost_amount, 4), $qty, 12), bcsub('0', (string) $source->change_qty, 8), 4) : '0';
                $returns = DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
                    ->where('l.source_transaction_item_id', $source->id)->whereNull('l.inventory_serial_id')->where('r.status', 'approved')->get(['l.unit_id', 'l.qty', 'l.cost_amount']);
                foreach ($returns as $returned) {
                    if ($returned->unit_id !== null && (int) $returned->unit_id !== $unitId) continue;
                    $qty = bcsub($qty, $returned->unit_id === null ? bcmul((string) $returned->qty, sprintf('%.12F', $ratio), 8) : (string) $returned->qty, 8);
                    $cost = bcsub($cost, $returned->unit_id === null ? bcmul((string) $returned->cost_amount, sprintf('%.12F', $ratio), 4) : (string) $returned->cost_amount, 4);
                }
                if (bccomp($qty, '0', 8) < 0 || bccomp($cost, '0', 4) < 0) abort(422, '原设备退料超过共同材料分摊份额，请先核对真实领退料来源。');
                $sources[] = ['kind' => 'issue', 'id' => (int) $source->id, 'issue_id' => (int) $issue->id,
                    'material_id' => (int) $source->source_item_id, 'item_id' => (int) $source->item_id,
                    'batch_no' => $source->batch_no, 'source_warehouse_id' => $source->warehouse_id, 'source_location_id' => $source->location_id,
                    'allocated_qty' => $qty, 'allocated_cost' => $cost, 'unit_ids' => $cohort, 'serial_ids' => $ownSerials,
                    'unbound_qty' => $unboundQty, 'unbound_serial_ids' => $unboundSerials,
                    'fixed_allocated_qty' => bcsub($qty, $unboundQty, 8),
                    'fixed_allocated_cost' => bcsub($cost, bcmul(bcdiv(bcsub('0', (string) $source->cost_amount, 4), bcsub('0', (string) $source->change_qty, 8), 12), $unboundQty, 4), 4),
                    'allocation_method' => isset($line['unit_ids']) ? 'recorded_unit_count' : 'legacy_work_order_unit_count'];
            }
        }
        foreach (['erp_mfg_cutting_allocations' => 'cutting_allocation', 'erp_mfg_cutting_run_allocations' => 'cutting_run_allocation'] as $table => $kind) {
            foreach (DB::table($table)->where('work_order_id', $wo->id)->lockForUpdate()->get() as $source) {
                $cohort = OrderChangeLaborService::recordedUnitIds($source) ?? $all;
                if (!in_array($unitId, $cohort, true)) continue;
                $qty = (string) ($source->material_qty ?? $source->qty ?? 0);
                $sources[] = ['kind' => $kind, 'id' => (int) $source->id, 'material_id' => (int) ($source->work_order_material_id ?? 0),
                    'allocated_qty' => self::share($qty, $cohort, $unitId, 8),
                    'allocated_cost' => self::share((string) $source->cost_amount, $cohort, $unitId, 4), 'unit_ids' => $cohort,
                    'allocation_method' => $source->unit_ids === null ? 'legacy_work_order_unit_count' : 'recorded_unit_count'];
            }
        }
        foreach (DB::table('erp_mfg_cutting_batches')->where('work_order_id', $wo->id)->where('status', '!=', 'cancelled')->lockForUpdate()->get() as $batch) {
            $inputs = json_decode((string) $batch->input_lines, true) ?: [];
            $cohort = data_get($inputs, '0.unit_ids') ?? $all;
            if (!in_array($unitId, $cohort, true)) continue;
            $cost = (string) ($batch->status === 'settled' ? $batch->net_cost_amount : $batch->input_cost_amount);
            $sources[] = ['kind' => 'cutting_batch', 'id' => (int) $batch->id, 'material_id' => 0,
                'allocated_qty' => '0', 'has_material' => !empty($inputs), 'allocated_cost' => self::share($cost, $cohort, $unitId, 4), 'unit_ids' => $cohort,
                'allocation_method' => data_get($inputs, '0.unit_ids') === null ? 'legacy_work_order_unit_count' : 'recorded_unit_count'];
        }
        $existing = collect($old['sources'] ?? [])->mapWithKeys(fn ($s) => [$s['kind'].':'.$s['id'] => $s])->all();
        foreach ($sources as $source) $existing[$source['kind'].':'.$source['id']] ??= $source;
        $d->update(['material_allocation_snapshot' => ['version' => 1, 'unit_id' => $unitId, 'unit_ids' => $all,
            'return_line_id_at_freeze' => $old['return_line_id_at_freeze'] ?? (int) DB::table('erp_mfg_material_return_lines')->max('id'),
            'frozen_at' => $old['frozen_at'] ?? $d->effective_at?->toIso8601String(), 'sources' => array_values($existing),
            'rule' => '有编号部件按实际编号退回，共同材料按冻结参与编号分摊；实际退回沿原出库批次和单价。']]);
        $this->refresh($d);
    }

    public function refresh(Disposition $d): void
    {
        $snapshot = $d->material_allocation_snapshot ?: [];
        $snapshot['sources'] ??= [];
        $cost = '0';
        foreach ($snapshot['sources'] as &$source) {
            if ($source['kind'] === 'cutting_batch') {
                $batch = DB::table('erp_mfg_cutting_batches')->where('id', $source['id'])->first();
                if ($batch) $source['allocated_cost'] = self::share((string) ($batch->status === 'settled' ? $batch->net_cost_amount : $batch->input_cost_amount),
                    $source['unit_ids'], (int) $d->work_order_unit_id, 4);
            }
            $cost = bcadd($cost, (string) $source['allocated_cost'], 4);
        }
        unset($source);
        $returned = (string) DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
            ->where('r.status', 'approved')->where('l.id', '>', $snapshot['return_line_id_at_freeze'] ?? 0)
            ->where(fn ($q) => $q->where('r.disposition_id', $d->id)->orWhere('l.unit_id', $d->work_order_unit_id))->sum('l.cost_amount');
        $left = bcsub($cost, $returned, 4);
        abort_if(bccomp($left, '-0.0001', 4) < 0, 422, '本台实际退料成本超过冻结份额，请先核对原领料来源，不能挤占其他设备成本。');
        $reserved = ($d->resolved_at && $d->status !== 'stock') || MfgWorkOrderUnit::whereKey($d->work_order_unit_id)->where('status', 'stored')->exists() ? '0' : (bccomp($left, '0', 4) > 0 ? $left : '0');
        $d->update(['material_allocation_snapshot' => $snapshot, 'reserved_material_cost' => $reserved]);
    }

    public function settle(Disposition $d, string $action): void
    {
        abort_if(MfgMaterialReturn::where('disposition_id', $d->id)->where('status', 'pending')->exists(), 422, '本台还有待确认退料，请先办结。');
        $this->refresh($d);
        if ($action === 'cancelled') {
            $batchIds = collect(data_get($d->material_allocation_snapshot, 'sources', []))->where('kind', 'cutting_batch')->pluck('id');
            abort_if(DB::table('erp_mfg_cutting_batches')->whereIn('id', $batchIds)->whereNotIn('status', ['settled', 'cancelled'])->exists(), 422, '本台分得的开料批次还未结算实际余料，请先办结。');
        }
        $d->update(['loss_material_cost' => $action === 'cancelled' ? $d->reserved_material_cost : '0',
            'reserved_material_cost' => $action === 'cancelled' ? '0' : $d->reserved_material_cost]);
    }

    public function refreshWorkOrder(MfgWorkOrder $wo): void
    {
        foreach (Disposition::where('work_order_id', $wo->id)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('status', 'stock'))->lockForUpdate()->get() as $d) {
            if ($d->status === 'stock') $this->freeze($d, $wo);
            else $this->refresh($d);
        }
    }

    public function sourceLimit(Disposition $d, int $sourceId, ?int $exceptReturnId = null): float
    {
        $source = collect(data_get($d->material_allocation_snapshot, 'sources', []))->first(fn ($s) => $s['kind'] === 'issue' && $s['id'] === $sourceId);
        if (!$source) return 0;
        $used = DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
            ->where('r.disposition_id', $d->id)->where('l.source_transaction_item_id', $sourceId)->whereIn('r.status', ['pending', 'approved'])
            ->when($exceptReturnId, fn ($q) => $q->where('r.id', '!=', $exceptReturnId))->sum('l.qty');
        return max(0, round((float) $source['allocated_qty'] - (float) $used, 4));
    }

    /** 未装配编号先只有共同数量份额；申请时才按实际实物锁定来源，不能用分数份额挡住整件退回。 */
    public function allocateReturnSerials(MfgWorkOrder $wo, ?Disposition $target = null, array $proposed = [], ?int $exceptReturnId = null): void
    {
        $dispositions = Disposition::where('work_order_id', $wo->id)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('status', 'stock'))
            ->whereNotIn('work_order_unit_id', MfgWorkOrderUnit::where('work_order_id', $wo->id)->where('status', 'stored')->select('id'))
            ->orderBy('id')->lockForUpdate()->get();
        $snapshots = []; $groups = []; $unitOwners = [];
        foreach ($dispositions as $d) {
            $unitOwners[(int) $d->work_order_unit_id] = (int) $d->id;
            $snapshots[$d->id] = $d->material_allocation_snapshot ?: [];
            foreach ($snapshots[$d->id]['sources'] ?? [] as $index => $source) {
                if ($source['kind'] !== 'issue' || empty($source['unbound_serial_ids'])) continue;
                $group = &$groups[(int) $source['material_id']];
                $group ??= ['sources' => [], 'units' => []];
                $group['sources'][(int) $source['id']] = array_values(array_unique([
                    ...($group['sources'][(int) $source['id']] ?? []), ...array_map('intval', $source['unbound_serial_ids']),
                ]));
                $group['units'][(int) $d->id][$index] = $source;
                unset($group);
            }
        }
        if (!$groups) return;
        $serialIds = collect($groups)->flatMap(fn ($g) => collect($g['sources'])->flatten())->unique()->values()->all();
        $claimedRows = DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
            ->where('r.work_order_id', $wo->id)->whereIn('r.status', ['pending', 'approved'])->whereIn('l.inventory_serial_id', $serialIds)
            ->when($exceptReturnId, fn ($q) => $q->where('r.id', '!=', $exceptReturnId))
            ->orderBy('l.id')->lockForUpdate()->get(['l.inventory_serial_id', 'l.source_transaction_item_id', 'r.disposition_id', 'r.status']);
        $claims = $claimedRows->mapWithKeys(fn ($r) => [$r->source_transaction_item_id.':'.$r->inventory_serial_id => (int) $r->disposition_id])->all();
        $pendingSerials = $claimedRows->where('status', 'pending')->pluck('inventory_serial_id')->map(fn ($id) => (int) $id)->all();
        $seen = [];
        foreach ($proposed as $row) if (!empty($row['inventory_serial_id'])) {
            $serialId = (int) $row['inventory_serial_id'];
            $key = $row['source_transaction_item_id'].':'.$serialId;
            abort_if(isset($seen[$serialId]) || in_array($serialId, $pendingSerials, true) || array_key_exists($key, $claims), 422, '所选编号已经被其他退料占用，请刷新后重新核对实物。');
            $seen[$serialId] = true;
            $claims[$key] = (int) $target?->id;
        }
        $serials = DB::table('erp_inventory_serials')->whereIn('id', $serialIds)->lockForUpdate()->get()->keyBy('id');
        $sourceItems = DB::table('erp_inventory_transaction_items')->whereIn('id', collect($groups)->flatMap(fn ($g) => array_keys($g['sources'])))->lockForUpdate()->get()->keyBy('id');
        $lastIssued = DB::table('erp_inventory_serial_events')->whereIn('inventory_serial_id', $serialIds)->where('event_type', 'production_material_issued')
            ->groupBy('inventory_serial_id')->selectRaw('MAX(id) AS id')->pluck('id');
        $issuedEvents = DB::table('erp_inventory_serial_events')->whereIn('id', $lastIssued)->lockForUpdate()->get()->keyBy('inventory_serial_id');
        $currentSource = function (int $sourceId, int $serialId) use ($sourceItems, $issuedEvents): bool {
            $item = $sourceItems->get($sourceId); $event = $issuedEvents->get($serialId);
            return $item && $event && $event->document_type === 'mfg_material_issue' && (int) $event->document_id === (int) $item->source_id
                && (int) $event->warehouse_id === (int) $item->warehouse_id && (int) $event->location_id === (int) $item->location_id && (string) $event->batch_no === (string) $item->batch_no;
        };
        $bindings = MfgUnitComponent::installed()->where('work_order_id', $wo->id)->whereIn('component_serial_no', $serials->pluck('serial_no'))
            ->lockForUpdate()->get()->keyBy(fn ($c) => $c->component_item_id.'|'.$c->component_serial_no);
        foreach ($groups as $group) foreach ($group['sources'] as $sourceId => $ids) foreach ($ids as $serialId) {
            $serial = $serials->get($serialId);
            if (!$serial || !$currentSource($sourceId, $serialId)) continue;
            $binding = $bindings->get($serial->item_id.'|'.$serial->serial_no);
            $key = $sourceId.':'.$serialId;
            if ($binding && !array_key_exists($key, $claims)) $claims[$key] = $unitOwners[(int) $binding->unit_id] ?? 0;
        }
        foreach ($groups as $group) {
            foreach ($group['units'] as $dispositionId => $sources) {
                $quota = array_sum(array_map(fn ($s) => (float) $s['unbound_qty'], $sources));
                $allowed = [];
                foreach ($sources as $source) foreach ($source['unbound_serial_ids'] as $serialId) $allowed[$source['id'].':'.$serialId] = (int) $serialId;
                $chosen = array_filter($allowed, fn ($id, $key) => ($claims[$key] ?? null) === (int) $dispositionId, ARRAY_FILTER_USE_BOTH);
                abort_if(count($chosen) > $quota + 0.00001, 422, '所选实际编号超过本台共同材料数量份额，请核对原领料与装配记录。');
                $remaining = max(0, $quota - count($chosen));
                $available = array_filter($allowed, fn ($id, $key) => !array_key_exists($key, $claims) && $currentSource((int) explode(':', $key)[0], $id)
                    && ($serials->get($id)?->serial_status === 'production_consumed'), ARRAY_FILTER_USE_BOTH);
                abort_if($remaining > count($available) + 0.00001, 422, '本台共同材料对应的未装配实物已不足，请核对其他设备装配及原领退料来源。');
                foreach ($sources as $index => $source) {
                    $sourceId = (int) $source['id'];
                    $sourceKeys = array_fill_keys(array_map(fn ($id) => $sourceId.':'.$id, $source['unbound_serial_ids']), true);
                    $own = array_values(array_intersect_key($chosen, $sourceKeys));
                    $shared = count($available) ? $remaining * count(array_intersect_key($available, $sourceKeys)) / count($available) : 0;
                    $allocated = sprintf('%.8F', count($own) + $shared);
                    $item = $sourceItems->get($sourceId);
                    abort_unless($item && bccomp((string) $item->change_qty, '0', 8) < 0, 422, '原领料成本来源缺失，请先核对材料流水。');
                    $unitCost = bcdiv(bcsub('0', (string) $item->cost_amount, 4), bcsub('0', (string) $item->change_qty, 8), 12);
                    $source['fixed_allocated_qty'] ??= bcsub((string) $source['allocated_qty'], (string) $source['unbound_qty'], 8);
                    $source['fixed_allocated_cost'] ??= bcsub((string) $source['allocated_cost'], bcmul($unitCost, (string) $source['unbound_qty'], 4), 4);
                    $source['allocated_qty'] = bcadd((string) $source['fixed_allocated_qty'], $allocated, 8);
                    $source['allocated_cost'] = bcadd((string) $source['fixed_allocated_cost'], bcmul($unitCost, $allocated, 4), 4);
                    $source['claimed_serial_ids'] = $own;
                    $snapshots[$dispositionId]['sources'][$index] = $source;
                }
            }
        }
        foreach ($dispositions as $d) {
            $d->update(['material_allocation_snapshot' => $snapshots[$d->id]]);
            $this->refresh($d);
        }
        // 早批入库已经形成正式成本；实物来源重定后有差额必须走正式核对，不能倒挤已入库设备。
        $actual = (string) MfgMaterialIssue::where('work_order_id', $wo->id)->sum('cost_amount');
        $actual = bcsub($actual, (string) MfgMaterialReturn::where('work_order_id', $wo->id)->where('status', 'approved')->sum('cost_amount'), 4);
        foreach (DB::table('erp_mfg_cutting_batches')->where('work_order_id', $wo->id)->where('status', '!=', 'cancelled')->get() as $batch)
            $actual = bcadd($actual, (string) ($batch->status === 'settled' ? $batch->net_cost_amount : $batch->input_cost_amount), 4);
        foreach (['erp_mfg_cutting_allocations', 'erp_mfg_cutting_run_allocations'] as $table)
            $actual = bcadd($actual, (string) DB::table($table)->where('work_order_id', $wo->id)->sum('cost_amount'), 4);
        $posted = (string) DB::table('erp_mfg_receipts')->where('work_order_id', $wo->id)->where('status', 'confirmed')->whereNotNull('inventory_transaction_id')->sum('cost_amount');
        $held = Disposition::where('work_order_id', $wo->id)->selectRaw('COALESCE(SUM(reserved_material_cost + loss_material_cost), 0) AS cost')->value('cost');
        abort_if(bccomp(bcsub(bcsub($actual, $posted, 4), (string) $held, 4), '-0.0001', 4) < 0, 422,
            '本次实际编号的原领料成本与已入库金额存在差额，请先办理正式成本核对，不能挪用其他已入库设备成本。');
        $target?->refresh();
    }

    private function serialShare(MfgWorkOrder $wo, MfgMaterialIssue $issue, array $line, object $source, array $cohort, int $unitId): array
    {
        $balances = DB::table('erp_inventory_balances')->whereIn('id', collect($line['picks'] ?? [])->pluck('balance_id'))
            ->where('warehouse_id', $source->warehouse_id)->where('location_id', $source->location_id)->where('batch_no', $source->batch_no)->pluck('id')->all();
        $original = collect($line['picks'] ?? [])->filter(fn ($p) => in_array((int) $p['balance_id'], array_map('intval', $balances), true))
            ->flatMap(fn ($p) => $p['serial_ids'] ?? [])->map(fn ($id) => (int) $id)->unique()->values()->all();
        if (!$original) return [[], [], []];
        $last = DB::table('erp_inventory_serial_events')->whereIn('inventory_serial_id', $original)->where('event_type', 'production_material_issued')
            ->groupBy('inventory_serial_id')->selectRaw('MAX(id) AS id')->pluck('id');
        $current = DB::table('erp_inventory_serials as s')->join('erp_inventory_serial_events as e', 'e.inventory_serial_id', '=', 's.id')
            ->whereIn('e.id', $last)->where('e.document_type', 'mfg_material_issue')->where('e.document_id', $issue->id)
            ->where('s.serial_status', 'production_consumed')->get(['s.id', 's.serial_no']);
        $bindings = MfgUnitComponent::installed()->where('work_order_id', $wo->id)->where('component_item_id', $source->item_id)
            ->whereIn('component_serial_no', $current->pluck('serial_no'))->get()->keyBy('component_serial_no');
        $own = []; $unbound = [];
        foreach ($current as $serial) {
            $binding = $bindings->get($serial->serial_no);
            if ($binding && (int) $binding->unit_id === $unitId) $own[] = (int) $serial->id;
            elseif (!$binding) $unbound[] = (int) $serial->id;
        }
        return [$original, $own, $unbound];
    }

    public function outstanding(Disposition $d): bool
    {
        foreach (data_get($d->material_allocation_snapshot, 'sources', []) as $source) {
            if ($source['kind'] !== 'issue') {
                if (!empty($source['has_material']) || bccomp((string) $source['allocated_qty'], '0', 8) > 0 || bccomp((string) $source['allocated_cost'], '0', 4) > 0) return true;
                continue;
            }
            $returned = DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
                ->where('r.disposition_id', $d->id)->where('r.status', 'approved')->where('l.source_transaction_item_id', $source['id'])->sum('l.qty');
            if (bccomp((string) $source['allocated_qty'], (string) $returned, 8) > 0) return true;
        }
        return false;
    }

    public function quantityLimit(Disposition $d, int $materialId, ?int $exceptReturnId = null): float
    {
        $allocated = collect(data_get($d->material_allocation_snapshot, 'sources', []))->where('kind', 'issue')->where('material_id', $materialId)->sum(fn ($s) => (float) $s['allocated_qty']);
        $used = DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
            ->where('r.disposition_id', $d->id)->where('l.material_id', $materialId)->whereIn('r.status', ['pending', 'approved'])
            ->when($exceptReturnId, fn ($q) => $q->where('r.id', '!=', $exceptReturnId))->sum('l.qty');
        return max(0, round($allocated - (float) $used, 4));
    }

    public static function unavailableQuantity(int $workOrderId, int $materialId): float
    {
        $total = 0.0;
        foreach (Disposition::where('work_order_id', $workOrderId)->where(fn ($q) => $q->whereNull('resolved_at')->orWhere('status', 'cancelled'))->get() as $d) {
            $allocated = collect(data_get($d->material_allocation_snapshot, 'sources', []))->where('material_id', $materialId)->sum(fn ($s) => (float) $s['allocated_qty']);
            $returned = DB::table('erp_mfg_material_return_lines as l')->join('erp_mfg_material_returns as r', 'r.id', '=', 'l.return_id')
                ->where('r.disposition_id', $d->id)->where('l.material_id', $materialId)->where('r.status', 'approved')->sum('l.qty');
            $total += max(0, $allocated - (float) $returned);
        }
        return round($total, 4);
    }

    public static function reservedSerialIds(int $workOrderId): array
    {
        return Disposition::where('work_order_id', $workOrderId)->whereNull('resolved_at')->get()->flatMap(fn ($d) =>
            collect(data_get($d->material_allocation_snapshot, 'sources', []))->flatMap(fn ($source) => [...($source['serial_ids'] ?? []), ...($source['claimed_serial_ids'] ?? [])]))->map(fn ($id) => (int) $id)->unique()->values()->all();
    }

    public static function unitRatio(array $line, array $unitIds, array $legacy = []): float
    {
        if (!empty($line['unit_allocations'])) {
            $total = collect($line['unit_allocations'])->sum('qty');
            return $total > 0 ? min(1, collect($line['unit_allocations'])->whereIn('unit_id', $unitIds)->sum('qty') / $total) : 0;
        }
        $cohort = array_map('intval', $line['unit_ids'] ?? $legacy);
        return $cohort ? count(array_intersect($cohort, $unitIds)) / count($cohort) : 0;
    }

    private static function share(string $total, array $cohort, int $unitId, int $scale): string
    {
        $cohort = array_values(array_unique(array_map('intval', $cohort))); sort($cohort);
        $count = count($cohort);
        if (!$count || !in_array($unitId, $cohort, true)) return '0';
        $part = bcdiv($total, (string) $count, $scale);
        return $unitId === end($cohort) ? bcsub($total, bcmul($part, (string) ($count - 1), $scale), $scale) : $part;
    }
}
