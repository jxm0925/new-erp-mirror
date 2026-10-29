<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\Item;
use App\Models\Erp\MfgMaterialIssue;
use App\Models\Erp\MfgUnitComponent;
use App\Models\Erp\MfgUnitComponentRemoval;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderUnit;
use Illuminate\Support\Facades\DB;

/**
 * 装配谱系：每台设备装了哪些带编号的部件（自制半成品、外购件）。
 * 只有“装上”和“拆下”两个动作，记录只追加不修改：互换 = 两拆两装；退料、报废、换号同样先拆下。
 * 每次装上/拆下同时记入部件号的序列号档案事件，整机号和部件号可以互查。
 */
final class UnitComponentService
{
    use ProductionActor;

    private const EPS = 0.00001;
    private const ADJUSTABLE = ['waiting', 'in_progress', 'rework', 'completed'];

    public function __construct(private readonly WorkOrderService $workOrders) {}

    /**
     * 工序收料后装上：收到的编号按设备号顺序登记，每台按 BOM 单台用量装满再到下一台。
     * 同一种料拆在几道工序（几行用料）时，按用料行各自的单台用量计数，焊接装的和总装装的互不挤占；
     * 同时整台合计不超过各行单台用量之和。装不下的编号留在“待登记编号”，主管可手动指定装到哪台设备。
     * @param array<int, array{material: \App\Models\Erp\MfgWorkOrderMaterial, picks: array}> $plan
     */
    public function bind(MfgWorkOrder $wo, MfgMaterialIssue $issue, array $plan, array $selections, object $actor): void
    {
        $planned = (float) $wo->planned_qty;
        $units = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->whereNotIn('status', ['scrapped', 'cancelled'])->orderBy('unit_no')->lockForUpdate()->get();
        if ($units->isEmpty() || $planned <= 0) return;
        foreach ($plan as $p) {
            $m = $p['material'];
            $manual = isset($selections[$m->id]);
            $ids = $manual ? $selections[$m->id] : collect($p['picks'])->flatMap(fn ($pick) => $pick['serial_ids'] ?? [])->map(fn ($id) => (int) $id)->all();
            if (! $ids) continue;
            $serials = InventorySerial::query()->whereIn('id', $ids)->get()->keyBy('id');
            $spec = Item::query()->whereKey($m->component_item_id)->value('spec');
            [$rowCap, $itemCap, $rowCounts, $itemCounts] = $this->capacity($wo, (int) $m->component_item_id);
            $perRow = $rowCap[(int) $m->id] ?? max(1, (int) ceil((float) $m->required_qty / $planned - self::EPS));
            $unbound = [];
            foreach ($ids as $id) {
                $serial = $serials->get($id);
                if (! $serial) continue;
                $unit = $units->first(fn ($u) => ($rowCounts[$u->id][$m->id] ?? 0) < $perRow && ($itemCounts[$u->id] ?? 0) < $itemCap);
                if (! $unit) { $unbound[] = $serial->serial_no; continue; }
                $this->install($wo, $unit, (int) $m->component_item_id, (string) $m->component_item_name, $spec, $serial, $actor,
                    '收料单 '.$issue->issue_no.($manual ? '，人工指定' : '，系统分配'), ['type' => 'mfg_material_issue', 'id' => $issue->id, 'no' => $issue->issue_no]);
                $rowCounts[$unit->id][$m->id] = ($rowCounts[$unit->id][$m->id] ?? 0) + 1;
                $itemCounts[$unit->id] = ($itemCounts[$unit->id] ?? 0) + 1;
            }
            if ($unbound) $this->workOrders->log($wo, 'component_unbound', null, null, "{$m->component_item_name} 编号 ".implode('、', $unbound).' 已收料但各台设备已按单台用量装满，未登记到设备，可在工单详情“待登记编号”里手动指定', $actor, ['issue_id' => $issue->id]);
        }
    }

    /**
     * 待登记编号：本工单收料领出、仍为生产已领、最近一次领用就是本工单，但没装在本工单任何一台上的编号。
     * @return array<int, array{serial_id:int, serial_no:string, component_item_id:int, component_item_name:string, batch_no:?string, issue_no:?string}>
     */
    public function unbound(MfgWorkOrder $wo): array
    {
        $issues = MfgMaterialIssue::query()->where('work_order_id', $wo->id)->orderBy('id')->get(['id', 'issue_no', 'issue_lines']);
        $picked = [];
        foreach ($issues as $issue) foreach ((array) $issue->issue_lines as $line) foreach ((array) ($line['picks'] ?? []) as $pick)
            foreach ((array) ($pick['serial_ids'] ?? []) as $sid) $picked[(int) $sid] = ['issue_id' => (int) $issue->id, 'issue_no' => $issue->issue_no,
                'item_id' => (int) ($line['component_item_id'] ?? 0), 'item_name' => (string) ($line['component_item_name'] ?? '')];
        if (! $picked) return [];
        $serials = InventorySerial::query()->whereIn('id', array_keys($picked))->where('serial_status', 'production_consumed')->orderBy('serial_no')->get();
        if ($serials->isEmpty()) return [];
        $last = InventorySerialEvent::query()->whereIn('inventory_serial_id', $serials->pluck('id')->all())->where('event_type', 'production_material_issued')
            ->selectRaw('inventory_serial_id, MAX(id) AS id')->groupBy('inventory_serial_id')->pluck('id', 'inventory_serial_id');
        $events = InventorySerialEvent::query()->whereIn('id', $last->values()->all() ?: [0])->get(['id', 'inventory_serial_id', 'document_type', 'document_id'])->keyBy('inventory_serial_id');
        $installed = MfgUnitComponent::query()->installed()->where('work_order_id', $wo->id)->whereIn('component_serial_no', $serials->pluck('serial_no')->all())
            ->pluck('component_serial_no')->flip();
        $out = [];
        foreach ($serials as $s) {
            $e = $events->get($s->id);
            $meta = $picked[(int) $s->id];
            if (! $e || $e->document_type !== 'mfg_material_issue' || (int) $e->document_id !== $meta['issue_id'] || isset($installed[$s->serial_no])) continue;
            $out[] = ['serial_id' => (int) $s->id, 'serial_no' => $s->serial_no, 'component_item_id' => (int) $s->item_id,
                'component_item_name' => $meta['item_name'], 'batch_no' => $s->batch_no, 'issue_no' => $meta['issue_no']];
        }
        return $out;
    }

    /** 手动登记：把待登记编号装到本工单指定的一台设备上，整台合计不超过该物料各行单台用量之和。 */
    public function assign(int $workOrderId, int $serialId, int $unitId, object $actor): void
    {
        DB::transaction(function () use ($workOrderId, $serialId, $unitId, $actor) {
            $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($workOrderId);
            if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true)) $this->fail('status_invalid', '工单不在生产中，不能登记部件编号。');
            $row = collect($this->unbound($wo))->firstWhere('serial_id', $serialId);
            if (! $row) $this->fail('component_invalid', '这个编号不是本工单待登记的编号（可能已登记或已退料），请刷新。');
            $unit = MfgWorkOrderUnit::query()->where('work_order_id', $wo->id)->lockForUpdate()->find($unitId);
            if (! $unit || ! in_array($unit->status, self::ADJUSTABLE, true)) $this->fail('unit_locked', '请选择本工单还在生产中的一台设备（已入库或报废的设备不能登记）。');
            [, $itemCap, , $itemCounts] = $this->capacity($wo, (int) $row['component_item_id']);
            if (($itemCounts[$unit->id] ?? 0) >= $itemCap) $this->fail('unit_full', "{$unit->serial_no} 的 {$row['component_item_name']} 已按单台用量装满，请先互换或退料拆下。");
            $serial = InventorySerial::query()->lockForUpdate()->findOrFail($serialId);
            $spec = Item::query()->whereKey($serial->item_id)->value('spec');
            $this->install($wo, $unit, (int) $serial->item_id, $row['component_item_name'], $spec, $serial, $actor, '人工登记（收料单 '.$row['issue_no'].'）',
                ['type' => 'mfg_work_order', 'id' => $wo->id, 'no' => $wo->work_order_no]);
        }, 5);
    }

    /**
     * 单台装配上限和已装计数。按用料行：编号属于哪一行看它是哪张收料单的哪一行领出的；
     * 找不到来源行的（历史数据、手工录入）只计入整台合计。
     * @return array{0: array<int,int>, 1: int, 2: array<int, array<int,int>>, 3: array<int,int>} 行上限、整台上限、[台][行]已装、[台]已装
     */
    private function capacity(MfgWorkOrder $wo, int $itemId): array
    {
        $planned = max(1, (float) $wo->planned_qty);
        $rows = \App\Models\Erp\MfgWorkOrderMaterial::query()->where('work_order_id', $wo->id)->where('component_item_id', $itemId)->where('required_qty', '>', 0)->get(['id', 'required_qty']);
        $rowCap = $rows->mapWithKeys(fn ($r) => [(int) $r->id => max(1, (int) ceil((float) $r->required_qty / $planned - self::EPS))])->all();
        $itemCap = max(1, (int) ceil($rows->sum(fn ($r) => (float) $r->required_qty) / $planned - self::EPS));
        $rowOf = [];
        foreach (MfgMaterialIssue::query()->where('work_order_id', $wo->id)->orderBy('id')->get(['id', 'issue_lines']) as $issue) {
            foreach ((array) $issue->issue_lines as $line) {
                if ((int) ($line['component_item_id'] ?? 0) !== $itemId) continue;
                foreach ((array) ($line['picks'] ?? []) as $pick) foreach ((array) ($pick['serial_ids'] ?? []) as $sid) $rowOf[(int) $sid] = (int) $line['material_id'];
            }
        }
        $components = MfgUnitComponent::query()->installed()->where('work_order_id', $wo->id)->where('component_item_id', $itemId)->get(['unit_id', 'component_serial_no']);
        $serialIds = InventorySerial::query()->where('item_id', $itemId)->whereIn('serial_no', $components->pluck('component_serial_no')->filter()->all() ?: [''])->pluck('id', 'serial_no');
        $rowCounts = [];
        $itemCounts = [];
        foreach ($components as $c) {
            $itemCounts[$c->unit_id] = ($itemCounts[$c->unit_id] ?? 0) + 1;
            $row = $rowOf[(int) ($serialIds[$c->component_serial_no] ?? 0)] ?? null;
            if ($row) $rowCounts[$c->unit_id][$row] = ($rowCounts[$c->unit_id][$row] ?? 0) + 1;
        }
        return [$rowCap, $itemCap, $rowCounts, $itemCounts];
    }

    /** 互换同一工单两台的同种部件编号：两条原记录拆下，再按对方的编号各装上一条；台入库、报废后不能再调整。 */
    public function swap(int $workOrderId, int $firstId, int $secondId, object $actor): void
    {
        DB::transaction(function () use ($workOrderId, $firstId, $secondId, $actor) {
            $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($workOrderId);
            if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true)) $this->fail('status_invalid', '工单不在生产中，不能调整部件编号。');
            $rows = MfgUnitComponent::query()->installed()->where('work_order_id', $wo->id)->whereIn('id', [$firstId, $secondId])->lockForUpdate()->get()->keyBy('id');
            if ($firstId === $secondId || $rows->count() !== 2) $this->fail('component_invalid', '请选择本工单两台设备上当前装着的部件，请刷新后重试。');
            [$a, $b] = [$rows->get($firstId), $rows->get($secondId)];
            if ((int) $a->component_item_id !== (int) $b->component_item_id) $this->fail('component_mismatch', '只能互换同一种部件的编号。');
            if ((int) $a->unit_id === (int) $b->unit_id) $this->fail('component_same_unit', '两条记录在同一台设备上，不需要互换。');
            $units = MfgWorkOrderUnit::query()->whereIn('id', [$a->unit_id, $b->unit_id])->lockForUpdate()->get()->keyBy('id');
            if ($units->contains(fn ($u) => ! in_array($u->status, self::ADJUSTABLE, true)))
                $this->fail('unit_locked', '已入库或报废的设备不能再调整部件编号。');
            [$unitA, $unitB] = [$units->get($a->unit_id), $units->get($b->unit_id)];
            $remark = "互换 {$a->component_item_name}：{$unitA->serial_no} ↔ {$unitB->serial_no}";
            $this->remove($wo, $a, $unitA, 'swap', $actor, $remark);
            $this->remove($wo, $b, $unitB, 'swap', $actor, $remark);
            $this->install($wo, $unitA, (int) $b->component_item_id, (string) $b->component_item_name, $b->component_spec, $this->serialOf($b), $actor, $remark, [], $b);
            $this->install($wo, $unitB, (int) $a->component_item_id, (string) $a->component_item_name, $a->component_spec, $this->serialOf($a), $actor, $remark, [], $a);
        }, 5);
    }

    /** 退料拆下：退料单审核通过时把部件从台上拆下（原装配记录不改，登记拆下记录）。 */
    public function detachForReturn(MfgWorkOrder $wo, MfgUnitComponent $component, object $actor, string $remark, array $document): void
    {
        $unit = MfgWorkOrderUnit::query()->lockForUpdate()->findOrFail($component->unit_id);
        $this->remove($wo, $component, $unit, 'return', $actor, $remark, $document);
    }

    /** 装上：新增装配记录，记工单日志和部件号档案事件。$from 为互换时的原记录（取批次）。 */
    private function install(MfgWorkOrder $wo, MfgWorkOrderUnit $unit, int $itemId, string $itemName, ?string $spec, InventorySerial|string $serial,
        object $actor, string $remark, array $document = [], ?MfgUnitComponent $from = null): MfgUnitComponent
    {
        $serialNo = $serial instanceof InventorySerial ? $serial->serial_no : $serial;
        $component = MfgUnitComponent::create([
            'unit_id' => $unit->id, 'work_order_id' => $wo->id, 'component_item_id' => $itemId, 'component_item_name' => $itemName,
            'component_spec' => $spec, 'component_serial_no' => $serialNo,
            'batch_no' => $serial instanceof InventorySerial ? $serial->batch_no : $from?->batch_no,
            'installed_by_legacy_id' => $this->actorId($actor), 'installed_by_name' => $this->actorName($actor), 'installed_at' => now(), 'remark' => mb_substr($remark, 0, 500),
        ]);
        $this->workOrders->log($wo, 'component_installed', null, null, "{$unit->serial_no} 装上 {$itemName} {$serialNo}（{$remark}）", $actor, ['component_id' => $component->id], (int) $unit->id);
        $this->serialEvent($serial, 'unit_installed', $wo, $unit, $actor, $document, ['component_id' => $component->id, 'remark' => $remark]);
        return $component;
    }

    /** 拆下：登记拆下记录，原装配记录不改。 */
    private function remove(MfgWorkOrder $wo, MfgUnitComponent $component, MfgWorkOrderUnit $unit, string $reason, object $actor, string $remark, array $document = []): void
    {
        MfgUnitComponentRemoval::create([
            'component_id' => $component->id, 'work_order_id' => $wo->id, 'unit_id' => $unit->id, 'reason' => $reason,
            'document_type' => $document['type'] ?? null, 'document_id' => $document['id'] ?? null, 'document_no' => $document['no'] ?? null,
            'remark' => mb_substr($remark, 0, 500), 'removed_by_legacy_id' => $this->actorId($actor), 'removed_by_name' => $this->actorName($actor), 'removed_at' => now(),
        ]);
        $this->workOrders->log($wo, 'component_removed', null, null, "{$unit->serial_no} 拆下 {$component->component_item_name} {$component->component_serial_no}（{$remark}）", $actor, ['component_id' => $component->id, 'reason' => $reason], (int) $unit->id);
        $this->serialEvent($this->serialOf($component), 'unit_removed', $wo, $unit, $actor, $document, ['component_id' => $component->id, 'reason' => $reason, 'remark' => $remark]);
    }

    private function serialOf(MfgUnitComponent $component): InventorySerial|string
    {
        return InventorySerial::query()->where('serial_no', $component->component_serial_no)->first() ?: (string) $component->component_serial_no;
    }

    /** 部件号档案事件：没有库存档案的号（历史手工录入）只记工单日志。 */
    private function serialEvent(InventorySerial|string $serial, string $type, MfgWorkOrder $wo, MfgWorkOrderUnit $unit, object $actor, array $document, array $payload): void
    {
        if (! $serial instanceof InventorySerial) return;
        InventorySerialEvent::create([
            'inventory_serial_id' => $serial->id, 'event_type' => $type,
            'document_type' => $document['type'] ?? 'mfg_work_order', 'document_id' => $document['id'] ?? $wo->id, 'document_no' => $document['no'] ?? $wo->work_order_no,
            'from_status' => $serial->serial_status, 'to_status' => $serial->serial_status,
            'warehouse_id' => $serial->warehouse_id, 'location_id' => $serial->location_id, 'batch_no' => $serial->batch_no,
            'operator_id' => $this->actorId($actor), 'operator_name' => mb_substr($this->actorName($actor), 0, 80),
            'event_payload' => $payload + ['work_order_no' => $wo->work_order_no, 'unit_serial_no' => $unit->serial_no], 'occurred_at' => now(),
        ]);
    }
}
