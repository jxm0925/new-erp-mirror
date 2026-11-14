<?php

namespace App\Services\Erp\Manufacturing;

use App\Services\Erp\CuttingDecimal;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/** 采购金额重算只改变成本；来源、参与设备、数量和业务时间始终沿原记录。 */
final class PurchaseCostManufacturingRecalculator
{
    private ?array $facts = null;
    private ?int $onlyWorkOrder = null;
    private array $locked = ['work_orders' => [], 'batches' => [], 'runs' => []];
    private array $purchaseSerialCosts = [];
    private array $purchaseOldSerialCosts = [];
    private array $projectedSerialCosts = [];
    private array $scopeOrders = [];
    private array $scopeBatches = [];
    private array $scopeRuns = [];
    private ?string $scopeSignature = null;

    public function setPurchaseSerialCosts(array $newCosts, array $oldCosts = []): void
    {
        $this->purchaseSerialCosts = $newCosts;
        $this->purchaseOldSerialCosts = $oldCosts;
    }

    public function deriveMovementCosts(array $movementCosts): array
    {
        return $this->projection($movementCosts)['movements'];
    }

    public function serialCosts(array $movementCosts): array
    {
        return $this->projection($movementCosts)['serial_costs'];
    }

    public function receiptSerialCosts(array $movementCosts): array
    {
        return $this->projection($movementCosts)['receipt_serial_costs'];
    }

    public function oldSerialCosts(array $movementCosts): array
    {
        return $this->projection($movementCosts)['old_serial_costs'];
    }

    public function fingerprint(array $movementCosts): string
    {
        $this->projection($movementCosts);
        if (!$this->scopeOrders && !$this->scopeBatches && !$this->scopeRuns) return hash('sha256', '[]');
        $fields = ['mfg_work_orders' => ['id', 'item_id', 'planned_qty'],
            'mfg_material_issues' => ['id', 'work_order_id', 'inventory_transaction_id', 'issue_lines', 'cost_amount'],
            'mfg_material_returns' => ['id', 'work_order_id', 'status', 'cost_amount', 'disposition_id'],
            'mfg_material_return_lines' => ['id', 'return_id', 'source_transaction_item_id', 'unit_id', 'inventory_serial_id', 'qty', 'cost_amount'],
            'mfg_material_return_dispositions' => ['id', 'return_line_id', 'action', 'qty', 'cost_amount', 'inventory_transaction_id'],
            'mfg_cutting_batches' => ['id', 'work_order_id', 'cutting_run_id', 'status', 'input_lines', 'remnants', 'net_cost_amount', 'pool_measure', 'allocated_measure', 'allocated_cost_amount'],
            'mfg_cutting_allocations' => ['id', 'cutting_batch_id', 'work_order_id', 'unit_ids', 'qty', 'measure', 'cost_amount'],
            'mfg_cutting_runs' => ['id', 'status', 'lines', 'material_cost_amount'],
            'mfg_cutting_run_allocations' => ['id', 'cutting_run_id', 'work_order_id', 'line_key', 'unit_ids', 'pieces', 'material_qty', 'cost_amount'],
            'mfg_receipts' => ['id', 'work_order_id', 'status', 'qty', 'serial_nos', 'cost_amount', 'inventory_transaction_id'],
            'mfg_work_order_units' => ['id', 'work_order_id', 'serial_no', 'inventory_serial_id', 'status'],
            'mfg_unit_components' => ['id', 'unit_id', 'work_order_id', 'component_item_id', 'component_serial_no'],
            'mfg_unit_component_removals' => ['id', 'component_id'],
            'inventory_serials' => ['id', 'serial_no', 'source_document_type', 'source_document_id', 'source_receipt_item_id'],
            'inventory_serial_events' => ['id', 'inventory_serial_id', 'event_type', 'document_type', 'document_id', 'warehouse_id', 'location_id', 'batch_no', 'event_payload', 'occurred_at'],
            'inventory_balances' => ['id', 'item_id', 'warehouse_id', 'location_id', 'batch_no'],
            'cancellation_production_dispositions' => ['id', 'work_order_unit_id', 'status', 'material_allocation_snapshot', 'reserved_material_cost', 'loss_material_cost'],
            'material_holdings' => ['id', 'material_lot_id', 'position_type', 'position_id', 'quantity', 'total_cost', 'status'],
            'material_movements' => ['id', 'source_holding_id', 'target_holding_id', 'action', 'quantity', 'total_cost'],
            'mfg_cutting_remnant_receipts' => ['id', 'cutting_batch_id', 'status', 'requested_lines', 'confirmed_lines'],
            'movements' => ['id', 'source_type', 'source_id', 'source_item_id', 'change_qty', 'cost_amount']];
        $snapshot = [];
        foreach ($this->load() as $name => $rows) foreach ($rows as $id => $row)
            $snapshot[$name][$id] = array_intersect_key((array) $row, array_flip($fields[$name]));
        return hash('sha256', json_encode($snapshot, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    public function plannedChanges(array $movementCosts): array
    {
        $changes = [];
        foreach ($this->projection($movementCosts)['updates'] as $table => $rows) foreach ($rows as $id => $after) {
            $before = []; $different = [];
            $row = $this->load()[substr($table, 4)][$id];
            foreach ($after as $field => $value) {
                $old = is_array($value) ? $this->json($row->{$field}) : $row->{$field};
                if (is_array($value) ? $old == $value : !$this->different($old ?? '0', $value)) continue;
                $before[$field] = $old; $different[$field] = $value;
            }
            if ($different) $changes[] = ['table' => $table, 'id' => (int) $id, 'before' => $before, 'after' => $different];
        }
        return $changes;
    }

    public function apply(array $movementCosts): array
    {
        $evidence = [];
        foreach ($this->projection($movementCosts)['updates'] as $table => $rows) {
            foreach ($rows as $id => $after) {
                $row = DB::table($table)->where('id', $id)->lockForUpdate()->first();
                if (!$row) $this->fail('制造成本来源已变化，请刷新后重新核对。');
                $before = []; $changes = [];
                foreach ($after as $field => $value) {
                    $old = $row->{$field};
                    if (is_array($value)) {
                        $old = $this->json($old);
                        if ($old == $value) continue;
                        $changes[$field] = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
                    } else {
                        if ($old !== null && bccomp((string) $old, (string) $value, 8) === 0) continue;
                        $changes[$field] = $value;
                    }
                    $before[$field] = $old;
                }
                if (!$changes) continue;
                DB::table($table)->where('id', $id)->update($changes);
                $evidence[] = ['table' => $table, 'id' => (int) $id, 'before' => $before,
                    'after' => array_intersect_key($after, $changes)];
            }
        }
        return $evidence;
    }

    /** 后续入库使用相同的逐台归集，避免财务更正后又由工单平均池覆盖。 */
    public function unitMaterialCosts(int $workOrderId): array
    {
        $this->facts = null;
        $this->onlyWorkOrder = $workOrderId;
        $result = $this->project([], [$workOrderId => true]);
        return $result['unit_costs'];
    }

    private function load(): array
    {
        if ($this->facts !== null) return $this->facts;
        $facts = [];
        foreach (['mfg_work_orders', 'mfg_material_issues', 'mfg_material_returns', 'mfg_material_return_lines', 'mfg_material_return_dispositions', 'mfg_cutting_batches',
            'mfg_cutting_allocations', 'mfg_cutting_runs', 'mfg_cutting_run_allocations', 'mfg_receipts',
            'mfg_work_order_units', 'mfg_unit_components', 'mfg_unit_component_removals', 'inventory_serials', 'inventory_serial_events',
            'inventory_balances', 'cancellation_production_dispositions', 'material_holdings', 'material_movements',
            'mfg_cutting_remnant_receipts'] as $name) {
            $query = DB::table('erp_'.$name)->orderBy('id');
            if ($name === 'inventory_serial_events') $query->whereIn('event_type', ['production_receipt_in', 'production_material_issued', 'inventory_posted']);
            {
                $workOrderIds = $this->onlyWorkOrder !== null ? [$this->onlyWorkOrder] : array_keys($this->scopeOrders);
                if ($name === 'mfg_work_orders') $query->whereIn('id', $workOrderIds);
                elseif (in_array($name, ['mfg_material_issues', 'mfg_material_returns', 'mfg_material_return_dispositions', 'mfg_cutting_allocations', 'mfg_cutting_run_allocations',
                    'mfg_receipts', 'mfg_work_order_units', 'mfg_unit_components', 'cancellation_production_dispositions'], true)) $query->whereIn('work_order_id', $workOrderIds);
                elseif ($name === 'mfg_material_return_lines') $query->whereIn('return_id', array_keys($facts['mfg_material_returns']));
                elseif ($name === 'mfg_cutting_batches') $query->where(fn ($q) => $q->whereIn('work_order_id', $workOrderIds)
                    ->orWhereIn('id', array_keys($this->scopeBatches))
                    ->orWhereIn('id', DB::table('erp_mfg_cutting_allocations')->whereIn('work_order_id', $workOrderIds)->select('cutting_batch_id'))
                    ->orWhereIn('cutting_run_id', DB::table('erp_mfg_cutting_run_allocations')->whereIn('work_order_id', $workOrderIds)->select('cutting_run_id')));
                elseif ($name === 'mfg_cutting_runs') $query->where(fn ($q) => $q->whereIn('id', array_keys($this->scopeRuns))
                    ->orWhereIn('id', DB::table('erp_mfg_cutting_run_allocations')->whereIn('work_order_id', $workOrderIds)->select('cutting_run_id')));
                elseif ($name === 'mfg_unit_component_removals') $query->whereIn('component_id', array_keys($facts['mfg_unit_components']));
                elseif ($name === 'inventory_serials') $query->whereIn('id', collect($facts['mfg_material_issues'])->flatMap(fn ($i) =>
                    collect($this->json($i->issue_lines))->flatMap(fn ($l) => collect($l['picks'] ?? [])->flatMap(fn ($p) => $p['serial_ids'] ?? [])))
                    ->merge(collect($facts['mfg_work_order_units'])->pluck('inventory_serial_id')->filter())->unique()->all());
                elseif ($name === 'inventory_serial_events') $query->where(fn ($q) => $q->whereIn('inventory_serial_id', array_keys($facts['inventory_serials']))
                    ->orWhere(fn ($r) => $r->where('document_type', 'mfg_receipt')->whereIn('document_id', array_keys($facts['mfg_receipts']))));
                elseif ($name === 'inventory_balances') $query->whereIn('id', collect($facts['mfg_material_issues'])->flatMap(fn ($i) =>
                    collect($this->json($i->issue_lines))->flatMap(fn ($l) => collect($l['picks'] ?? [])->pluck('balance_id')))->unique()->all());
                elseif ($name === 'material_holdings') $query->whereIn('position_id', array_keys($facts['mfg_cutting_batches']))->whereIn('position_type', ['MFG_CUTTING', 'MFG_OUTPUT']);
                elseif ($name === 'material_movements') $query->where(fn ($q) => $q->whereIn('source_holding_id', array_keys($facts['material_holdings']))->orWhereIn('target_holding_id', array_keys($facts['material_holdings'])));
                elseif ($name === 'mfg_cutting_remnant_receipts') $query->whereIn('cutting_batch_id', array_keys($facts['mfg_cutting_batches']));
            }
            if ($this->onlyWorkOrder !== null && (str_starts_with($name, 'mfg_') || $name === 'cancellation_production_dispositions')) $query->lockForUpdate();
            $facts[$name] = $query->get()->keyBy('id')->all();
        }
        // 单工单入库也可能领过其他工单的半成品，只补读其原入库与编号事实，不加载其他工单全部制造记录。
        $originIds = [];
        foreach ($facts['inventory_serial_events'] as $event) if ($event->event_type === 'production_receipt_in' && $event->document_type === 'mfg_receipt'
            && !isset($facts['mfg_receipts'][$event->document_id])) $originIds[(int) $event->document_id] = true;
        if ($originIds) {
            $origins = DB::table('erp_mfg_receipts')->whereIn('id', array_keys($originIds))->orderBy('id')->get()->keyBy('id')->all();
            $facts['mfg_receipts'] += $origins;
            $serialNos = collect($origins)->flatMap(fn ($r) => $this->json($r->serial_nos))->all();
            $facts['mfg_work_order_units'] += DB::table('erp_mfg_work_order_units')->whereIn('serial_no', $serialNos)->orderBy('id')->get()->keyBy('id')->all();
        }
        $query = DB::table('erp_inventory_transaction_items')->whereIn('source_type', ['mfg_material_issue', 'mfg_material_return', 'mfg_material_return_disposition', 'mfg_cutting_batch', 'mfg_cutting_run', 'mfg_receipt']);
        $query->where(function ($q) use ($facts) {
            foreach (['mfg_material_issue' => 'mfg_material_issues', 'mfg_material_return' => 'mfg_material_returns', 'mfg_material_return_disposition' => 'mfg_material_return_dispositions',
                'mfg_cutting_batch' => 'mfg_cutting_batches', 'mfg_cutting_run' => 'mfg_cutting_runs', 'mfg_receipt' => 'mfg_receipts'] as $type => $name)
                $q->orWhere(fn ($where) => $where->where('source_type', $type)->whereIn('source_id', array_keys($facts[$name])));
        });
        $facts['movements'] = $query->orderBy('id')->get()->keyBy('id')->all();
        return $this->facts = $facts;
    }

    private function project(array $costs, ?array $collectWorkOrders = null): array
    {
        $f = $this->load();
        $updates = []; $derived = []; $unitDeltas = []; $unitCosts = [];
        $cost = fn ($m) => (string) ($costs[$m->id] ?? $m->cost_amount);
        $put = function (string $name, int $id, array $values) use (&$updates): void {
            $updates['erp_'.$name][$id] = array_replace($updates['erp_'.$name][$id] ?? [], $values);
        };
        $bySource = [];
        foreach ($f['movements'] as $m) $bySource[$m->source_type][(int) $m->source_id][] = $m;
        $removed = array_fill_keys(array_map(fn ($r) => (int) $r->component_id, $f['mfg_unit_component_removals']), true);
        $owners = [];
        foreach ($f['mfg_unit_components'] as $component) if (!isset($removed[$component->id]))
            $owners['work_order:'.$component->work_order_id.'|'.$component->component_item_id.'|'.$component->component_serial_no] = (int) $component->unit_id;
        $returnCosts = []; $returnsBySource = []; $activeReturnLines = [];
        foreach ($f['mfg_material_return_lines'] as $line) {
            if (($f['mfg_material_returns'][$line->return_id]->status ?? null) !== 'approved') continue;
            $returnsBySource[(int) $line->source_transaction_item_id][] = $line;
            if ($line->inventory_serial_id && $line->unit_id) $owners['issue:'.$line->issue_id.'|'.$line->component_item_id.'|'.$line->serial_no] = (int) $line->unit_id;
        }
        $serialSources = [];
        foreach ($f['mfg_material_issues'] as $issue) {
            $lines = collect($this->json($issue->issue_lines))->keyBy('material_id');
            foreach ($bySource['mfg_material_issue'][$issue->id] ?? [] as $m) if (bccomp((string) $m->change_qty, '0', 8) < 0 && ($line = $lines->get($m->source_item_id))) {
                $parts = $this->sourceSerialParts($line, $m, $f, $this->different($cost($m), $m->cost_amount) || isset($collectWorkOrders[(int) $issue->work_order_id]));
                if ($parts) $serialSources[$m->id] = $parts;
            }
        }
        foreach ($returnsBySource as $sourceId => $lines) {
            $source = $f['movements'][$sourceId] ?? null;
            if (!$source) continue;
            $sourceOrder = (int) ($f['mfg_material_issues'][$source->source_id]->work_order_id ?? 0);
            $collectSerial = isset($collectWorkOrders[$sourceOrder], $serialSources[$sourceId]);
            if (!$this->different($cost($source), $source->cost_amount) && !($serialSources[$sourceId]['changed'] ?? false) && !$collectSerial) {
                foreach ($lines as $line) $returnCosts[$line->id] = (string) $line->cost_amount;
                continue;
            }
            $leftQty = $this->abs($source->change_qty); $leftCost = isset($serialSources[$sourceId])
                ? $this->sum($serialSources[$sourceId]['new']) : $this->abs($cost($source));
            foreach ($lines as $line) {
                $activeReturnLines[$line->id] = true;
                $amount = $line->inventory_serial_id && isset($serialSources[$sourceId]['new'][$line->inventory_serial_id])
                    ? $serialSources[$sourceId]['new'][$line->inventory_serial_id] : $this->share($leftCost, $leftQty, (string) $line->qty);
                $returnCosts[$line->id] = $amount;
                $leftCost = bcsub($leftCost, $amount, 4); $leftQty = bcsub($leftQty, (string) $line->qty, 8);
                $found = 0;
                foreach ($bySource['mfg_material_return'][$line->return_id] ?? [] as $m)
                    if ((int) $m->source_item_id === (int) $line->id) { $derived[$m->id] = $amount; ++$found; }
                if ($found !== 1) $this->fail('已确认退料缺少唯一的原入库流水，不能自动追溯成本。');
                if ($this->different($amount, $line->cost_amount)) {
                    $put('mfg_material_return_lines', (int) $line->id, ['cost_amount' => $amount, 'unit_cost' => bcdiv($amount, (string) $line->qty, 4)]);
                }
            }
        }
        foreach ($f['mfg_material_returns'] as $return) {
            if ($return->status !== 'approved') continue;
            $total = '0'; $changed = false;
            foreach ($f['mfg_material_return_lines'] as $line) if ((int) $line->return_id === (int) $return->id)
            { $total = bcadd($total, $returnCosts[$line->id] ?? (string) $line->cost_amount, 4); $changed = $changed || $this->different($returnCosts[$line->id] ?? $line->cost_amount, $line->cost_amount); }
            if ($changed && $this->different($total, $return->cost_amount)) $put('mfg_material_returns', (int) $return->id, ['cost_amount' => $total]);
        }
        foreach ($f['mfg_material_return_dispositions'] as $d) {
            $line = $f['mfg_material_return_lines'][$d->return_line_id] ?? null;
            if (!$line || !$d->inventory_transaction_id || !isset($activeReturnLines[$line->id])) continue;
            $amount = $this->share($returnCosts[$line->id], (string) $line->qty, (string) $d->qty);
            $movements = $bySource['mfg_material_return_disposition'][$d->id] ?? [];
            if (count($movements) !== 1) $this->fail('不良退料处置缺少唯一的原出库流水，不能自动追溯成本。');
            $derived[$movements[0]->id] = bcsub('0', $amount, 4);
            if ($this->different($amount, $d->cost_amount)) $put('mfg_material_return_dispositions', (int) $d->id, ['cost_amount' => $amount]);
        }
        foreach ($f['mfg_material_issues'] as $issue) {
            $lines = collect($this->json($issue->issue_lines))->keyBy('material_id'); $total = '0'; $issueChanged = false;
            foreach ($bySource['mfg_material_issue'][$issue->id] ?? [] as $m) {
                if (bccomp((string) $m->change_qty, '0', 8) >= 0) continue;
                $amount = $this->abs($cost($m)); $old = $this->abs($m->cost_amount); $total = bcadd($total, $amount, 4);
                $line = $lines->get($m->source_item_id);
                $serialChanged = $serialSources[$m->id]['changed'] ?? false;
                $collect = isset($collectWorkOrders[(int) $issue->work_order_id]);
                if (!$this->different($amount, $old) && !$serialChanged && !$collect) continue;
                if (!$line) $this->fail('原领料出库缺少对应的材料行，不能猜测设备去向。');
                $weights = $this->issueWeights($line, $m, $owners, $f);
                if (isset($serialSources[$m->id])) {
                    $parts = $serialSources[$m->id]; $specificAmount = $this->sum($parts['new']);
                    $total = bcadd(bcsub($total, $amount, 4), $specificAmount, 4); $amount = $specificAmount;
                    $newShares = $this->serialUnitShares($parts['new'], $weights, $m, $owners, $f);
                    $oldShares = $this->serialUnitShares($parts['old'], $weights, $m, $owners, $f);
                    $derived[$m->id] = bcsub('0', $amount, 4);
                    foreach ($parts['events'] as $serialId => $event) {
                        $payload = $this->json($event->event_payload);
                        $payload['actual_material_cost'] = $parts['new'][$serialId]; $payload['transaction_item_id'] = (int) $m->id;
                        $put('inventory_serial_events', (int) $event->id, ['event_payload' => $payload]);
                    }
                } else { $newShares = $this->split($amount, $weights); $oldShares = $this->split($old, $weights); }
                $issueChanged = $issueChanged || $this->different($amount, $old);
                foreach ($returnsBySource[$m->id] ?? [] as $returned) {
                    $returnWeights = $returned->unit_id ? [(int) $returned->unit_id => '1'] : $weights;
                    foreach ($this->split($returnCosts[$returned->id] ?? (string) $returned->cost_amount, $returnWeights) as $unitId => $part)
                        $newShares[$unitId] = bcsub($newShares[$unitId] ?? '0', $part, 4);
                    foreach ($this->split((string) $returned->cost_amount, $returnWeights) as $unitId => $part)
                        $oldShares[$unitId] = bcsub($oldShares[$unitId] ?? '0', $part, 4);
                }
                foreach ($newShares as $unitId => $part) {
                    if (bccomp($part, '0', 4) < 0) $this->fail('设备实际退料超过该批次领用份额，请核对原领退料来源。');
                    $unitCosts[$unitId] = bcadd($unitCosts[$unitId] ?? '0', $part, 4);
                    $unitDeltas[$unitId] = bcadd($unitDeltas[$unitId] ?? '0', bcsub($part, $oldShares[$unitId] ?? '0', 4), 4);
                }
            }
            if ($issueChanged && $this->different($total, $issue->cost_amount)) $put('mfg_material_issues', (int) $issue->id, ['cost_amount' => $total]);
        }
        $batchCosts = []; $changedBatches = [];
        foreach ($f['mfg_cutting_batches'] as $batch) {
            $inputs = $this->json($batch->input_lines); $remnants = $this->json($batch->remnants); $inputTotal = '0'; $remnantTotal = '0';
            $sourceChanged = false;
            foreach ($inputs as $input) {
                $m = $f['movements'][$input['transaction_item_id'] ?? 0] ?? null;
                if ($m && $this->different($cost($m), $m->cost_amount)) $sourceChanged = true;
            }
            if (!$sourceChanged) {
                $net = (string) ($batch->status === 'settled' ? $batch->net_cost_amount : $batch->input_cost_amount);
                $batchCosts[$batch->id] = $net;
                if (isset($collectWorkOrders[(int) $batch->work_order_id]) && $batch->status !== 'cancelled')
                    $this->addUnitShares($net, $net, $this->cohort(data_get($inputs, '0.unit_ids')), $unitCosts, $unitDeltas);
                continue;
            }
            foreach ($inputs as &$input) {
                $m = $f['movements'][$input['transaction_item_id'] ?? 0] ?? null;
                if ($m) $input['cost_amount'] = $this->abs($cost($m));
                $inputTotal = bcadd($inputTotal, (string) $input['cost_amount'], 4);
                foreach ($remnants as &$remnant) if ($remnant['source_key'] === $input['source_key']) {
                    $remnant['cost_amount'] = $this->share((string) $input['cost_amount'], (string) $input['input_measure'], (string) $remnant['measure_qty']);
                    $remnant['source_cost_amount'] = $input['cost_amount'];
                    $remnantTotal = bcadd($remnantTotal, $remnant['cost_amount'], 4);
                    if (!empty($remnant['transaction_item_id'])) $derived[$remnant['transaction_item_id']] = $remnant['cost_amount'];
                    elseif ($batch->status === 'settled' && bccomp((string) $remnant['measure_qty'], '0', 8) > 0) $this->fail('已结算余料缺少原入库流水，不能自动追溯成本。');
                }
                unset($remnant);
            }
            unset($input);
            $net = $batch->status === 'settled' ? bcsub($inputTotal, $remnantTotal, 4) : $inputTotal;
            $batchCosts[$batch->id] = $net;
            $changed = $sourceChanged;
            if ($changed) {
                $changedBatches[$batch->id] = true;
                $values = ['input_lines' => $inputs, 'input_cost_amount' => $inputTotal];
                if ($batch->status === 'settled') $values += ['remnants' => $remnants, 'remnant_cost_amount' => $remnantTotal, 'net_cost_amount' => $net];
                $put('mfg_cutting_batches', (int) $batch->id, $values);
                $this->physicalCutUpdates($batch, $inputs, $remnants, $f, $put);
                foreach ($f['mfg_cutting_remnant_receipts'] as $r) if ((int) $r->cutting_batch_id === (int) $batch->id) {
                    $fields = [];
                    foreach (['requested_lines', 'confirmed_lines'] as $field) if ($r->{$field}) {
                        $rows = $this->json($r->{$field});
                        foreach ($rows as &$row) foreach ($remnants as $remnant) if (($row['source_key'] ?? null) === $remnant['source_key']) {
                            $row['cost_amount'] = $remnant['cost_amount']; $row['source_cost_amount'] = $remnant['source_cost_amount'];
                        }
                        unset($row); $fields[$field] = $rows;
                    }
                    $put('mfg_cutting_remnant_receipts', (int) $r->id, $fields);
                }
            }
            if ($batch->work_order_id && $batch->status !== 'cancelled' && ($changed || isset($collectWorkOrders[(int) $batch->work_order_id]))) {
                $old = (string) ($batch->status === 'settled' ? $batch->net_cost_amount : $batch->input_cost_amount);
                $this->addUnitShares($net, $old, $this->cohort(data_get($inputs, '0.unit_ids')), $unitCosts, $unitDeltas);
            }
        }
        $allocationCosts = [];
        foreach ($f['mfg_cutting_batches'] as $batch) {
            if ($batch->batch_scope !== 'shared') continue;
            $leftCost = $batchCosts[$batch->id]; $leftMeasure = (string) $batch->pool_measure; $allocated = '0';
            foreach ($f['mfg_cutting_allocations'] as $a) if ((int) $a->cutting_batch_id === (int) $batch->id) {
                $amount = isset($changedBatches[$batch->id]) ? $this->share($leftCost, $leftMeasure, (string) $a->measure) : (string) $a->cost_amount;
                $allocationCosts['cutting_allocation'][$a->id] = $amount;
                $leftCost = bcsub($leftCost, $amount, 4); $leftMeasure = bcsub($leftMeasure, (string) $a->measure, 8); $allocated = bcadd($allocated, $amount, 4);
                if ($this->different($amount, $a->cost_amount)) $put('mfg_cutting_allocations', (int) $a->id, ['cost_amount' => $amount]);
                if ($this->different($amount, $a->cost_amount) || isset($collectWorkOrders[(int) $a->work_order_id]))
                    $this->addUnitShares($amount, (string) $a->cost_amount, $this->cohort($a->unit_ids), $unitCosts, $unitDeltas);
            }
            if (isset($changedBatches[$batch->id])) $put('mfg_cutting_batches', (int) $batch->id, ['allocated_cost_amount' => $allocated]);
        }
        foreach ($f['mfg_cutting_runs'] as $run) {
            $lines = $this->json($run->lines); $totals = []; $changed = false;
            foreach ($f['mfg_cutting_batches'] as $batch) if ((int) $batch->cutting_run_id === (int) $run->id
                && !(!empty(data_get($this->json($batch->input_lines), '0.physical_material_id')) && data_get($this->json($batch->input_lines), '0.mode') === 'sheet')) {
                $totals[$batch->item_id] = bcadd($totals[$batch->item_id] ?? '0', $batchCosts[$batch->id], 4);
                $changed = $changed || isset($changedBatches[$batch->id]);
            }
            // 早期集中开料直接扣库，仍按当时每种料的实际产出度量分配，不能套用最新 BOM。
            foreach ($bySource['mfg_cutting_run'][$run->id] ?? [] as $m) if (bccomp((string) $m->change_qty, '0', 8) < 0) {
                $totals[$m->item_id] = bcadd($totals[$m->item_id] ?? '0', $this->abs($cost($m)), 4);
                $changed = $changed || $this->different($cost($m), $m->cost_amount);
            }
            $measures = [];
            if ($changed) foreach ($lines as $line) if (isset($totals[$line['component_item_id']]))
                $measures[$line['component_item_id']] = bcadd($measures[$line['component_item_id']] ?? '0', CuttingRunService::lineMeasure($line), 8);
            $runTotal = '0';
            foreach ($lines as &$line) {
                $itemId = $line['component_item_id'];
                if ($changed && isset($totals[$itemId])) {
                    $measure = CuttingRunService::lineMeasure($line);
                    $line['material_cost'] = $this->share($totals[$itemId], $measures[$itemId], $measure);
                    $totals[$itemId] = bcsub($totals[$itemId], $line['material_cost'], 4); $measures[$itemId] = bcsub($measures[$itemId], $measure, 8);
                }
                $leftCost = (string) ($line['material_cost'] ?? '0'); $leftPieces = (string) $line['actual_pieces']; $allocated = '0';
                foreach ($f['mfg_cutting_run_allocations'] as $a) if ((int) $a->cutting_run_id === (int) $run->id && $a->line_key === $line['line_key']) {
                    $amount = $changed ? $this->share($leftCost, $leftPieces, (string) $a->pieces) : (string) $a->cost_amount;
                    $allocationCosts['cutting_run_allocation'][$a->id] = $amount;
                    $leftCost = bcsub($leftCost, $amount, 4); $leftPieces = bcsub($leftPieces, (string) $a->pieces, 8); $allocated = bcadd($allocated, $amount, 4);
                    if ($this->different($amount, $a->cost_amount)) $put('mfg_cutting_run_allocations', (int) $a->id, ['cost_amount' => $amount]);
                    if ($this->different($amount, $a->cost_amount) || isset($collectWorkOrders[(int) $a->work_order_id]))
                        $this->addUnitShares($amount, (string) $a->cost_amount, $this->cohort($a->unit_ids), $unitCosts, $unitDeltas);
                }
                if ($changed) $line['allocated_material_cost'] = $allocated;
                $runTotal = bcadd($runTotal, (string) ($line['material_cost'] ?? '0'), 4);
            }
            unset($line);
            if ($changed) $put('mfg_cutting_runs', (int) $run->id, ['lines' => $lines, 'material_cost_amount' => $runTotal]);
        }
        // 旧早批曾按工单池暂估；受影响工单沿原 SN 重归集全部材料，才能让后续入库与更正后的来源守恒。
        $affectedOrders = [];
        foreach ($unitDeltas as $unitId => $delta) if ($this->different($delta, '0')) {
            $unit = $f['mfg_work_order_units'][$unitId] ?? null;
            if (!$unit) $this->fail('原材料分配记录中的设备已不存在，不能追溯成本。');
            $affectedOrders[(int) $unit->work_order_id] = true;
        }
        $completeCosts = [];
        if ($collectWorkOrders === null && $affectedOrders) {
            $collected = $this->project($costs, $affectedOrders); $completeCosts = $collected['unit_costs'];
            $derived = array_replace($derived, $collected['movements']);
        }
        $serialCosts = []; $receiptSerialCosts = []; $oldSerialCosts = [];
        foreach ($f['mfg_receipts'] as $receipt) {
            if ($receipt->status !== 'confirmed' || !$receipt->inventory_transaction_id || $collectWorkOrders !== null || !isset($affectedOrders[(int) $receipt->work_order_id])) continue;
            $sns = $this->json($receipt->serial_nos); $amount = '0'; $count = 0; $receiptUnits = [];
            foreach ($f['mfg_work_order_units'] as $unit) if ((int) $unit->work_order_id === (int) $receipt->work_order_id && in_array($unit->serial_no, $sns, true)) {
                $receiptUnits[$unit->id] = $unit; ++$count;
            }
            $parts = $completeCosts;
            $oldParts = $this->originalReceiptParts($receipt, $receiptUnits);
            foreach ($receiptUnits as $unitId => $unit) {
                $amount = bcadd($amount, $parts[$unitId] ?? '0', 4);
                if ($unit->inventory_serial_id) {
                    $serialCosts[(int) $unit->inventory_serial_id] = $parts[$unitId] ?? '0';
                    $receiptSerialCosts[(int) $receipt->id][(int) $unit->inventory_serial_id] = $parts[$unitId] ?? '0';
                    $events = array_filter($f['inventory_serial_events'], fn ($event) => (int) $event->inventory_serial_id === (int) $unit->inventory_serial_id
                        && $event->event_type === 'production_receipt_in' && $event->document_type === 'mfg_receipt' && (int) $event->document_id === (int) $receipt->id);
                    if (count($events) !== 1) $this->fail('本台生产入库来源事件不完整，不能保存逐台实际材料成本。');
                    $event = reset($events); $payload = $this->json($event->event_payload);
                    $oldSerialCosts[(int) $unit->inventory_serial_id] = isset($payload['actual_material_cost']) ? (string) $payload['actual_material_cost'] : $oldParts[$unitId];
                    $payload['actual_material_cost'] = $parts[$unitId] ?? '0';
                    $put('inventory_serial_events', (int) $event->id, ['event_payload' => $payload]);
                }
            }
            if ($count !== (int) $receipt->qty || $count !== count($sns)) $this->fail('生产入库设备编号与数量不一致，不能推算本台材料成本。');
            $movements = $bySource['mfg_receipt'][$receipt->id] ?? [];
            if (count($movements) !== 1) $this->fail('生产入库流水不完整，不能推算本台材料成本。');
            $derived[$movements[0]->id] = $amount;
            if ($this->different($amount, $receipt->cost_amount)) $put('mfg_receipts', (int) $receipt->id, ['cost_amount' => $amount, 'unit_cost' => bcdiv($amount, (string) $receipt->qty, 4)]);
        }
        foreach ($receiptSerialCosts as $receiptId => $parts) $this->projectedSerialCosts[$receiptId] = $parts;
        if ($collectWorkOrders === null) foreach ($f['movements'] as $m) if (bccomp((string) $m->change_qty, '0', 8) > 0
            && isset($costs[$m->id]) && $this->different($costs[$m->id], $m->cost_amount) && !isset($derived[$m->id])) $derived[$m->id] = (string) $m->cost_amount;
        $this->dispositionUpdates($f, $costs, $returnCosts, $batchCosts, $allocationCosts, $serialSources, $put);
        return ['movements' => $derived, 'updates' => $updates, 'unit_costs' => $unitCosts,
            'serial_costs' => $serialCosts, 'receipt_serial_costs' => $receiptSerialCosts, 'old_serial_costs' => $oldSerialCosts];
    }

    private function projection(array $costs): array
    {
        $this->projectedSerialCosts = []; $derived = [];
        if (!$this->configureScope($costs)) return ['movements' => [], 'updates' => [], 'unit_costs' => [], 'serial_costs' => [], 'receipt_serial_costs' => [], 'old_serial_costs' => []];
        $limit = count($this->load()['mfg_work_orders']) + 2;
        for ($i = 0; $i < $limit; ++$i) {
            $before = $this->projectedSerialCosts; $result = $this->project($costs);
            $next = array_replace($derived, $result['movements']);
            if ($before == $this->projectedSerialCosts && $next == $derived) { $result['movements'] = $next; return $result; }
            $derived = $next; $costs = array_replace($costs, $derived);
        }
        $this->fail('制造来源成本存在循环或来源不完整，不能自动保存不收敛的金额。');
    }

    private function configureScope(array $costs): bool
    {
        $signature = hash('sha256', json_encode([$costs, $this->purchaseSerialCosts, $this->purchaseOldSerialCosts], JSON_THROW_ON_ERROR));
        if ($signature === $this->scopeSignature) return (bool) ($this->scopeOrders || $this->scopeBatches || $this->scopeRuns);
        $orders = []; $batches = []; $runs = []; $issues = [];
        foreach (array_chunk(array_keys($costs), 1000) as $ids) {
            $rows = DB::table('erp_inventory_transaction_items')->whereIn('id', $ids)->whereIn('source_type',
                ['mfg_material_issue', 'mfg_material_return', 'mfg_material_return_disposition', 'mfg_cutting_batch', 'mfg_cutting_run', 'mfg_receipt'])->get();
            foreach ($rows as $m) {
                if (!$this->different($costs[$m->id], $m->cost_amount)) continue;
                if ($m->source_type === 'mfg_cutting_batch') $batches[(int) $m->source_id] = true;
                elseif ($m->source_type === 'mfg_cutting_run') $runs[(int) $m->source_id] = true;
                else {
                    $table = ['mfg_material_issue' => 'erp_mfg_material_issues', 'mfg_material_return' => 'erp_mfg_material_returns',
                        'mfg_material_return_disposition' => 'erp_mfg_material_return_dispositions', 'mfg_receipt' => 'erp_mfg_receipts'][$m->source_type];
                    $id = DB::table($table)->where('id', $m->source_id)->value('work_order_id');
                    if ($id) $orders[(int) $id] = true;
                }
            }
        }
        $changedSerials = array_keys(array_filter($this->purchaseSerialCosts, fn ($value, $id) => !isset($this->purchaseOldSerialCosts[$id])
            || $this->different($value, $this->purchaseOldSerialCosts[$id]), ARRAY_FILTER_USE_BOTH));
        if ($changedSerials) foreach (DB::table('erp_inventory_serial_events')->whereIn('inventory_serial_id', $changedSerials)
            ->where('event_type', 'production_material_issued')->where('document_type', 'mfg_material_issue')->pluck('document_id') as $id) $issues[(int) $id] = true;
        if ($issues) foreach (DB::table('erp_mfg_material_issues')->whereIn('id', array_keys($issues))->pluck('work_order_id') as $id) $orders[(int) $id] = true;
        if (!$orders && !$batches && !$runs) { $this->scopeOrders = $this->scopeBatches = $this->scopeRuns = []; $this->facts = null; $this->scopeSignature = $signature; return false; }
        // 只沿实际领用、开料分配和生产编号展开，不按产品或计划父子关系扩到其他工单。
        do {
            $before = [count($orders), count($batches), count($runs)];
            $batchRows = DB::table('erp_mfg_cutting_batches')->where(fn ($q) => $q->whereIn('id', array_keys($batches))->orWhereIn('work_order_id', array_keys($orders))->orWhereIn('cutting_run_id', array_keys($runs)))->get(['id', 'work_order_id', 'cutting_run_id']);
            foreach ($batchRows as $b) { $batches[(int) $b->id] = true; if ($b->work_order_id) $orders[(int) $b->work_order_id] = true; if ($b->cutting_run_id) $runs[(int) $b->cutting_run_id] = true; }
            foreach (DB::table('erp_mfg_cutting_allocations')->where(fn ($q) => $q->whereIn('cutting_batch_id', array_keys($batches))->orWhereIn('work_order_id', array_keys($orders)))->get(['cutting_batch_id', 'work_order_id']) as $a) {
                $batches[(int) $a->cutting_batch_id] = true; $orders[(int) $a->work_order_id] = true;
            }
            foreach (DB::table('erp_mfg_cutting_run_allocations')->where(fn ($q) => $q->whereIn('cutting_run_id', array_keys($runs))->orWhereIn('work_order_id', array_keys($orders)))->get(['cutting_run_id', 'work_order_id']) as $a) {
                $runs[(int) $a->cutting_run_id] = true; $orders[(int) $a->work_order_id] = true;
            }
            $serialIds = DB::table('erp_mfg_work_order_units')->whereIn('work_order_id', array_keys($orders))->whereNotNull('inventory_serial_id')->pluck('inventory_serial_id')->all();
            $issueRows = DB::table('erp_mfg_material_issues')->whereIn('work_order_id', array_keys($orders))->get(['issue_lines']);
            foreach ($issueRows as $i) foreach ($this->json($i->issue_lines) as $line) foreach ($line['picks'] ?? [] as $p) $serialIds = [...$serialIds, ...($p['serial_ids'] ?? [])];
            if ($serialIds) {
                $events = DB::table('erp_inventory_serial_events')->whereIn('inventory_serial_id', array_values(array_unique($serialIds)))
                    ->whereIn('event_type', ['production_receipt_in', 'production_material_issued'])->get(['event_type', 'document_id']);
                $receiptIds = []; $issueIds = [];
                foreach ($events as $event) { if ($event->event_type === 'production_receipt_in') $receiptIds[] = $event->document_id; else $issueIds[] = $event->document_id; }
                foreach (DB::table('erp_mfg_receipts')->whereIn('id', $receiptIds)->pluck('work_order_id') as $id) $orders[(int) $id] = true;
                foreach (DB::table('erp_mfg_material_issues')->whereIn('id', $issueIds)->pluck('work_order_id') as $id) $orders[(int) $id] = true;
            }
        } while ($before !== [count($orders), count($batches), count($runs)]);
        if ($orders != $this->scopeOrders || $batches != $this->scopeBatches || $runs != $this->scopeRuns) $this->facts = null;
        $this->scopeOrders = $orders; $this->scopeBatches = $batches; $this->scopeRuns = $runs;
        foreach (['work_orders' => $orders, 'batches' => $batches, 'runs' => $runs] as $kind => $ids) {
            $new = array_diff_key($ids, $this->locked[$kind]);
            if (!$new) continue;
            $table = ['work_orders' => 'erp_mfg_work_orders', 'batches' => 'erp_mfg_cutting_batches', 'runs' => 'erp_mfg_cutting_runs'][$kind];
            DB::table($table)->whereIn('id', array_keys($new))->orderBy('id')->lockForUpdate()->get();
            if ($kind === 'work_orders') DB::table('erp_mfg_work_order_units')->whereIn('work_order_id', array_keys($new))->orderBy('id')->lockForUpdate()->get();
            $this->locked[$kind] += $new; $this->facts = null;
        }
        $this->scopeSignature = $signature;
        return true;
    }

    private function issueWeights(array $line, object $movement, array $owners, array $f): array
    {
        $weights = [];
        foreach ($line['unit_allocations'] ?? [] as $a) $weights[(int) $a['unit_id']] = bcadd($weights[(int) $a['unit_id']] ?? '0', (string) $a['qty'], 8);
        if (!$weights) $weights = $this->cohort($line['unit_ids'] ?? null);
        if (!$weights) $this->fail('历史领用未记录参与设备，不能按整张工单猜测材料去向。');
        $serialIds = []; $allSerialIds = [];
        foreach ($line['picks'] ?? [] as $pick) {
            $allSerialIds = [...$allSerialIds, ...($pick['serial_ids'] ?? [])];
            $balance = $f['inventory_balances'][$pick['balance_id']] ?? null;
            if ($balance && (int) $balance->warehouse_id === (int) $movement->warehouse_id && (int) $balance->location_id === (int) $movement->location_id && $balance->batch_no === $movement->batch_no)
                $serialIds = [...$serialIds, ...($pick['serial_ids'] ?? [])];
        }
        $own = []; $rowOwn = []; $bound = 0;
        $workOrderId = $f['mfg_material_issues'][$movement->source_id]->work_order_id;
        $owner = function ($id) use ($f, $movement, $workOrderId, $owners) {
            $serial = $f['inventory_serials'][$id] ?? null;
            return $serial ? ($owners['issue:'.$movement->source_id.'|'.$movement->item_id.'|'.$serial->serial_no]
                ?? $owners['work_order:'.$workOrderId.'|'.$movement->item_id.'|'.$serial->serial_no] ?? null) : null;
        };
        foreach (array_unique($allSerialIds) as $id) {
            $unitId = $owner($id);
            if ($unitId) { $weights[$unitId] ??= '0'; $rowOwn[$unitId] = bcadd($rowOwn[$unitId] ?? '0', '1', 8); }
        }
        foreach ($serialIds as $id) {
            $unitId = $owner($id);
            if ($unitId) { $own[$unitId] = bcadd($own[$unitId] ?? '0', '1', 8); ++$bound; }
        }
        $common = bcsub($this->abs($movement->change_qty), (string) $bound, 8);
        if (bccomp($common, '0', 8) < 0) $this->fail('领用编号数量超过原批次实际领料数量。');
        $commonWeights = [];
        foreach ($weights as $unitId => $weight) {
            $left = bcsub($weight, $rowOwn[$unitId] ?? '0', 8);
            $commonWeights[$unitId] = bccomp($left, '0', 8) > 0 ? $left : '0';
        }
        $sum = array_reduce($commonWeights, fn ($sum, $v) => bcadd($sum, $v, 8), '0');
        if (bccomp($common, '0', 8) > 0 && bccomp($sum, '0', 8) <= 0) $this->fail('原设备领料分配数量不完整，不能推算成本。');
        foreach ($weights as $unitId => $weight) $weights[$unitId] = bcadd($own[$unitId] ?? '0', bccomp($common, '0', 8) > 0
            ? bcdiv(bcmul($common, $commonWeights[$unitId], 16), $sum, 8) : '0', 8);
        return $weights;
    }

    private function pickedSerialIds(array $line, object $movement, array $f): array
    {
        $ids = [];
        foreach ($line['picks'] ?? [] as $pick) {
            $balance = $f['inventory_balances'][$pick['balance_id']] ?? null;
            if ($balance && (int) $balance->warehouse_id === (int) $movement->warehouse_id && (int) $balance->location_id === (int) $movement->location_id
                && $balance->batch_no === $movement->batch_no) $ids = [...$ids, ...($pick['serial_ids'] ?? [])];
        }
        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function sourceSerialParts(array $line, object $movement, array $f, bool $required): ?array
    {
        $ids = $this->pickedSerialIds($line, $movement, $f);
        if (!$ids) return null;
        $specific = array_intersect_key($this->purchaseSerialCosts, array_flip($ids));
        foreach ($this->projectedSerialCosts as $parts) $specific += array_intersect_key($parts, array_flip($ids));
        if (!$specific && !$required) return null;
        if (bccomp((string) count($ids), $this->abs($movement->change_qty), 8) !== 0) {
            if ($specific) $this->fail('本次领用编号与原批次数量不一致，不能猜测逐台成本。');
            return null;
        }
        $fallback = $this->split($this->abs($movement->cost_amount), array_fill_keys($ids, '1'));
        $old = []; $new = []; $issued = []; $changed = false;
        foreach ($ids as $id) {
            $events = array_filter($f['inventory_serial_events'], fn ($e) => $e->event_type === 'production_material_issued' && $e->document_type === 'mfg_material_issue'
                && (int) $e->document_id === (int) $movement->source_id && (int) $e->inventory_serial_id === $id
                && (int) $e->warehouse_id === (int) $movement->warehouse_id && (int) $e->location_id === (int) $movement->location_id && $e->batch_no === $movement->batch_no);
            if (count($events) !== 1) {
                if ($specific || $required) $this->fail('原领用编号事件与实际批次不唯一，不能猜测逐台成本。');
                return null;
            }
            $event = reset($events); $issued[$id] = $event; $payload = $this->json($event->event_payload);
            $origin = array_filter($f['inventory_serial_events'], fn ($e) => (int) $e->inventory_serial_id === $id && $e->occurred_at <= $movement->created_at
                && in_array($e->event_type, ['production_receipt_in', 'inventory_posted'], true));
            $origin = $origin ? end($origin) : null;
            if (isset($payload['actual_material_cost'])) $old[$id] = (string) $payload['actual_material_cost'];
            elseif (isset($this->purchaseOldSerialCosts[$id])) $old[$id] = (string) $this->purchaseOldSerialCosts[$id];
            elseif ($origin?->event_type === 'production_receipt_in') $old[$id] = $this->issuedSerialCost($id, $movement, [], $f);
            else {
                $originPayload = $origin ? $this->json($origin->event_payload) : [];
                $old[$id] = isset($originPayload['actual_material_cost']) ? (string) $originPayload['actual_material_cost'] : $fallback[$id];
            }
            $new[$id] = $old[$id];
            if (isset($this->purchaseSerialCosts[$id])) $new[$id] = (string) $this->purchaseSerialCosts[$id];
            elseif ($origin?->event_type === 'production_receipt_in' && isset($this->projectedSerialCosts[(int) $origin->document_id][$id]))
                $new[$id] = $this->projectedSerialCosts[(int) $origin->document_id][$id];
            $changed = $changed || $this->different($new[$id], $old[$id]);
        }
        return ['old' => $old, 'new' => $new, 'events' => $issued, 'changed' => $changed];
    }

    private function serialUnitShares(array $parts, array $weights, object $movement, array $owners, array $f): array
    {
        $shares = []; $own = []; $common = '0';
        $workOrderId = $f['mfg_material_issues'][$movement->source_id]->work_order_id;
        foreach ($parts as $serialId => $amount) {
            $serial = $f['inventory_serials'][$serialId] ?? null;
            if (!$serial) $this->fail('原领用编号记录已缺失，不能推算设备成本。');
            $unitId = $owners['issue:'.$movement->source_id.'|'.$movement->item_id.'|'.$serial->serial_no]
                ?? $owners['work_order:'.$workOrderId.'|'.$movement->item_id.'|'.$serial->serial_no] ?? null;
            if ($unitId) { $shares[$unitId] = bcadd($shares[$unitId] ?? '0', $amount, 4); $own[$unitId] = bcadd($own[$unitId] ?? '0', '1', 8); }
            else $common = bcadd($common, $amount, 4);
        }
        foreach ($weights as $unitId => &$weight) $weight = bcsub($weight, $own[$unitId] ?? '0', 8);
        unset($weight);
        foreach ($this->split($common, $weights) as $unitId => $amount) $shares[$unitId] = bcadd($shares[$unitId] ?? '0', $amount, 4);
        return $shares;
    }

    private function issuedSerialCost(int $serialId, object $movement, array $receiptCosts, array $f): string
    {
        $events = array_filter($f['inventory_serial_events'], fn ($event) => (int) $event->inventory_serial_id === $serialId
            && $event->event_type === 'production_receipt_in' && $event->document_type === 'mfg_receipt' && $event->occurred_at <= $movement->created_at);
        $event = $events ? end($events) : null;
        if (!$event) $this->fail('半成品编号缺少领用之前的正式生产入库事实，不能套用后来成本。');
        if (isset($receiptCosts[(int) $event->document_id][$serialId])) return $receiptCosts[(int) $event->document_id][$serialId];
        $payload = $this->json($event->event_payload);
        if (isset($payload['actual_material_cost'])) return (string) $payload['actual_material_cost'];
        $receipt = $f['mfg_receipts'][$event->document_id] ?? null;
        if (!$receipt || $receipt->status !== 'confirmed') $this->fail('半成品原生产入库成本来源缺失。');
        $units = array_filter($f['mfg_work_order_units'], fn ($unit) => (int) $unit->work_order_id === (int) $receipt->work_order_id
            && in_array($unit->serial_no, $this->json($receipt->serial_nos), true));
        if (count($units) !== (int) $receipt->qty) $this->fail('半成品原生产入库设备数量不完整。');
        $parts = $this->originalReceiptParts($receipt, $units);
        foreach ($units as $unit) if ((int) $unit->inventory_serial_id === $serialId) return $parts[$unit->id];
        $this->fail('半成品编号不属于原生产入库设备。');
    }

    private function addUnitShares(string $amount, string $old, array $weights, array &$totals, array &$deltas): void
    {
        if (!$weights && ($this->different($amount, $old) || bccomp($amount, '0', 4) > 0)) $this->fail('历史开料分配未记录参与设备，不能按整张工单猜测材料去向。');
        $before = $this->split($old, $weights);
        foreach ($this->split($amount, $weights) as $id => $part) {
            $totals[$id] = bcadd($totals[$id] ?? '0', $part, 4);
            $deltas[$id] = bcadd($deltas[$id] ?? '0', bcsub($part, $before[$id] ?? '0', 4), 4);
        }
    }

    private function originalReceiptParts(object $receipt, array $units): array
    {
        uasort($units, fn ($a, $b) => strcmp($a->serial_no, $b->serial_no));
        $amount = (string) $receipt->cost_amount; $qty = count($units); $parts = [];
        $unitCost = $qty ? bcdiv($amount, (string) $qty, 4) : '0';
        foreach ($units as $id => $unit) {
            $parts[$id] = $qty === 1 ? $amount : $unitCost;
            $amount = bcsub($amount, $parts[$id], 4); --$qty;
        }
        return $parts;
    }

    private function dispositionUpdates(array $f, array $costs, array $returnCosts, array $batchCosts, array $allocationCosts, array $serialSources, callable $put): void
    {
        $claimed = []; $pools = [];
        foreach ($f['cancellation_production_dispositions'] as $d) foreach ($this->json($d->material_allocation_snapshot)['sources'] ?? [] as $source)
            if ($source['kind'] === 'issue') foreach ($source['claimed_serial_ids'] ?? [] as $id) $claimed[$source['id']][(int) $id] = true;
        foreach ($f['cancellation_production_dispositions'] as $d) {
            $snapshot = $this->json($d->material_allocation_snapshot); $total = '0'; $changed = false;
            foreach ($snapshot['sources'] ?? [] as $index => $source) {
                $amount = null;
                if ($source['kind'] === 'issue' && isset($f['movements'][$source['id']])) {
                    $m = $f['movements'][$source['id']];
                    if (!$this->different($costs[$m->id] ?? $m->cost_amount, $m->cost_amount) && !($serialSources[$m->id]['changed'] ?? false)) { $total = bcadd($total, (string) $source['allocated_cost'], 4); continue; }
                    if (isset($serialSources[$m->id])) {
                        $parts = $serialSources[$m->id]['new'];
                        $fixedIds = array_values(array_unique(array_map('intval', $source['serial_ids'] ?? [])));
                        $ownIds = array_values(array_unique(array_map('intval', $source['claimed_serial_ids'] ?? [])));
                        $poolIds = array_values(array_unique(array_map('intval', $source['unbound_serial_ids'] ?? [])));
                        if (array_diff([...$fixedIds, ...$ownIds, ...$poolIds], array_keys($parts))) $this->fail('冻结设备的具体部件编号成本来源不完整。');
                        $fixedQty = (string) ($source['fixed_allocated_qty'] ?? bcsub((string) $source['allocated_qty'], (string) ($source['unbound_qty'] ?? '0'), 8));
                        if (bccomp($fixedQty, (string) count($fixedIds), 8) !== 0) $this->fail('冻结设备的具体部件数量与原来源不一致。');
                        $source['fixed_allocated_cost'] = $this->sum(array_intersect_key($parts, array_flip($fixedIds)));
                        $amount = bcadd($source['fixed_allocated_cost'], $this->sum(array_intersect_key($parts, array_flip($ownIds))), 4);
                        $commonQty = bcsub((string) ($source['unbound_qty'] ?? '0'), (string) count($ownIds), 8);
                        if (bccomp($commonQty, '0', 8) < 0) $this->fail('冻结设备已指定的部件超过原共同数量份额。');
                        if (!isset($pools[$m->id])) {
                            $available = array_diff_key(array_intersect_key($parts, array_flip($poolIds)), $claimed[$m->id] ?? []);
                            $pools[$m->id] = ['cost' => $this->sum($available), 'qty' => (string) count($available)];
                        }
                        $common = $this->share($pools[$m->id]['cost'], $pools[$m->id]['qty'], $commonQty);
                        $pools[$m->id]['cost'] = bcsub($pools[$m->id]['cost'], $common, 4); $pools[$m->id]['qty'] = bcsub($pools[$m->id]['qty'], $commonQty, 8);
                        $amount = bcadd($amount, $common, 4);
                    } else {
                        $amount = $this->share($this->abs($costs[$m->id] ?? $m->cost_amount), $this->abs($m->change_qty), (string) $source['allocated_qty']);
                        if (isset($source['fixed_allocated_qty'])) $source['fixed_allocated_cost'] = $this->share($this->abs($costs[$m->id] ?? $m->cost_amount), $this->abs($m->change_qty), (string) $source['fixed_allocated_qty']);
                    }
                } elseif ($source['kind'] === 'cutting_batch' && isset($batchCosts[$source['id']])) {
                    $batch = $f['mfg_cutting_batches'][$source['id']];
                    $old = (string) ($batch->status === 'settled' ? $batch->net_cost_amount : $batch->input_cost_amount);
                    if (!$this->different($batchCosts[$source['id']], $old)) { $total = bcadd($total, (string) $source['allocated_cost'], 4); continue; }
                    $amount = $this->split($batchCosts[$source['id']], $this->cohort($source['unit_ids'] ?? null))[$d->work_order_unit_id] ?? '0';
                } elseif (isset($allocationCosts[$source['kind']][$source['id']])) {
                    $name = ['cutting_allocation' => 'mfg_cutting_allocations', 'cutting_run_allocation' => 'mfg_cutting_run_allocations'][$source['kind']];
                    if (!$this->different($allocationCosts[$source['kind']][$source['id']], $f[$name][$source['id']]->cost_amount)) { $total = bcadd($total, (string) $source['allocated_cost'], 4); continue; }
                    $amount = $this->split($allocationCosts[$source['kind']][$source['id']], $this->cohort($source['unit_ids'] ?? null))[$d->work_order_unit_id] ?? '0';
                }
                if ($amount !== null) { $changed = $changed || $this->different($amount, $source['allocated_cost']); $source['allocated_cost'] = $amount; }
                $changed = $changed || $source != ($snapshot['sources'][$index] ?? []);
                $snapshot['sources'][$index] = $source; $total = bcadd($total, (string) $source['allocated_cost'], 4);
            }
            foreach ($f['mfg_material_return_lines'] as $line) if (isset($returnCosts[$line->id]) && (int) $line->id > (int) ($snapshot['return_line_id_at_freeze'] ?? 0)
                && ((int) ($f['mfg_material_returns'][$line->return_id]->disposition_id ?? 0) === (int) $d->id || (int) $line->unit_id === (int) $d->work_order_unit_id)) {
                $total = bcsub($total, $returnCosts[$line->id], 4); $changed = $changed || $this->different($returnCosts[$line->id], $line->cost_amount);
            }
            if (!$changed) continue;
            if (bccomp($total, '0', 4) < 0) $this->fail('停产设备更正后退料成本超过真实领用份额。');
            $stored = ($f['mfg_work_order_units'][$d->work_order_unit_id]->status ?? null) === 'stored';
            $put('cancellation_production_dispositions', (int) $d->id, ['material_allocation_snapshot' => $snapshot,
                'reserved_material_cost' => ($d->resolved_at && $d->status !== 'stock') || $stored ? '0' : $total,
                'loss_material_cost' => $d->status === 'cancelled' ? $total : (string) $d->loss_material_cost]);
        }
    }

    private function physicalCutUpdates(object $batch, array $inputs, array $remnants, array $f, callable $put): void
    {
        foreach ($inputs as $input) {
            if (empty($input['physical_material_id'])) continue;
            $returned = collect($remnants)->firstWhere('source_key', $input['source_key']);
            $net = bcsub((string) $input['cost_amount'], (string) ($returned['cost_amount'] ?? '0'), 4);
            $holdingId = (int) ($input['cutting_holding_id'] ?? 0);
            $holding = $f['material_holdings'][$holdingId] ?? null;
            if (!$holding) $this->fail('原实物开料持有记录缺失，不能追溯成本。');
            if ($holding->status === 'ACTIVE') $put('material_holdings', $holdingId, ['total_cost' => $input['cost_amount']]);
            foreach ($f['material_movements'] as $m) {
                if ((int) $m->target_holding_id === $holdingId) $put('material_movements', (int) $m->id, ['total_cost' => $input['cost_amount']]);
                if ((int) $m->source_holding_id !== $holdingId) continue;
                if (in_array($m->action, ['MFG_CUT_SETTLED', 'MFG_REMNANT_SCRAP'], true)) {
                    $put('material_movements', (int) $m->id, ['total_cost' => $net]);
                    $put('material_holdings', (int) $m->target_holding_id, ['total_cost' => $net]);
                }
                if ($m->action === 'MFG_REMNANT_RETURN' && $returned) $put('material_movements', (int) $m->id, ['total_cost' => $returned['cost_amount']]);
            }
        }
    }

    private function cohort($ids): array { return array_fill_keys(array_filter(array_map('intval', $this->json($ids)), fn ($id) => $id > 0), '1'); }
    private function json($value): array { return is_array($value) ? $value : (json_decode((string) $value, true) ?: []); }
    private function different($a, $b): bool { return bccomp((string) $a, (string) $b, 4) !== 0; }
    private function sum(array $values): string { return array_reduce($values, fn ($sum, $value) => bcadd($sum, (string) $value, 4), '0.0000'); }
    private function abs($value): string { return bccomp((string) $value, '0', 8) < 0 ? bcsub('0', (string) $value, 8) : (string) $value; }
    private function share(string $amount, string $quantity, string $take): string
    {
        if (bccomp($take, '0', 8) === 0) return '0.0000';
        if (bccomp($quantity, '0', 8) <= 0 || bccomp($take, $quantity, 8) > 0) $this->fail('制造成本分配数量超过原实际投入，请核对来源。');
        return bcadd(CuttingDecimal::share($amount, $quantity, $take), '0', 4);
    }
    private function split(string $amount, array $weights): array
    {
        $weights = array_filter($weights, fn ($v) => bccomp((string) $v, '0', 8) > 0); ksort($weights);
        $total = array_reduce($weights, fn ($sum, $v) => bcadd($sum, (string) $v, 8), '0'); $parts = [];
        foreach ($weights as $id => $weight) {
            $parts[$id] = $this->share($amount, $total, (string) $weight);
            $amount = bcsub($amount, $parts[$id], 4); $total = bcsub($total, (string) $weight, 8);
        }
        return $parts;
    }
    private function fail(string $message): never { throw ValidationException::withMessages(['cost_amount' => $message]); }
}
