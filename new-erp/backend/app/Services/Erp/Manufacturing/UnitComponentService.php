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
     * 工序收料后装上：收到的编号按台号顺序登记，每台按 BOM 单台用量装满再到下一台。
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
            $perUnit = max(1, (int) ceil((float) $m->required_qty / $planned - self::EPS));
            $counts = MfgUnitComponent::query()->installed()->where('work_order_id', $wo->id)->where('component_item_id', $m->component_item_id)
                ->selectRaw('unit_id, COUNT(*) AS c')->groupBy('unit_id')->pluck('c', 'unit_id')->map(fn ($c) => (int) $c)->all();
            $unbound = [];
            foreach ($ids as $id) {
                $serial = $serials->get($id);
                if (! $serial) continue;
                $unit = $units->first(fn ($u) => ($counts[$u->id] ?? 0) < $perUnit);
                if (! $unit) { $unbound[] = $serial->serial_no; continue; }
                $this->install($wo, $unit, (int) $m->component_item_id, (string) $m->component_item_name, $spec, $serial, $actor,
                    '收料单 '.$issue->issue_no.($manual ? '，人工指定' : '，系统分配'), ['type' => 'mfg_material_issue', 'id' => $issue->id, 'no' => $issue->issue_no]);
                $counts[$unit->id] = ($counts[$unit->id] ?? 0) + 1;
            }
            if ($unbound) $this->workOrders->log($wo, 'component_unbound', null, null, "{$m->component_item_name} 编号 ".implode('、', $unbound).' 已收料但各台已按单台用量装满，未登记到台', $actor, ['issue_id' => $issue->id]);
        }
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
            if ((int) $a->unit_id === (int) $b->unit_id) $this->fail('component_same_unit', '两条记录在同一台上，不需要互换。');
            $units = MfgWorkOrderUnit::query()->whereIn('id', [$a->unit_id, $b->unit_id])->lockForUpdate()->get()->keyBy('id');
            if ($units->contains(fn ($u) => ! in_array($u->status, self::ADJUSTABLE, true)))
                $this->fail('unit_locked', '已入库或报废的台不能再调整部件编号。');
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
