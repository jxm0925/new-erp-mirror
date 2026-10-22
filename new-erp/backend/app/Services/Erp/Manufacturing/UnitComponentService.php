<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\InventorySerial;
use App\Models\Erp\Item;
use App\Models\Erp\MfgMaterialIssue;
use App\Models\Erp\MfgUnitComponent;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderUnit;
use Illuminate\Support\Facades\DB;

/**
 * 装配记录：每台设备装了哪些带编号的部件（自制半成品、外购件）。
 * 工序收料时把收到的编号按台号顺序登记到台上（每台按 BOM 单台用量装满再到下一台）；
 * 管理员可在台入库前把同一工单两台的同种部件编号互换。
 */
final class UnitComponentService
{
    use ProductionActor;

    private const EPS = 0.00001;
    private const ADJUSTABLE = ['waiting', 'in_progress', 'rework', 'completed'];

    public function __construct(private readonly WorkOrderService $workOrders) {}

    /** @param array<int, array{material: \App\Models\Erp\MfgWorkOrderMaterial, picks: array}> $plan */
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
            $item = Item::query()->find($m->component_item_id);
            $perUnit = max(1, (int) ceil((float) $m->required_qty / $planned - self::EPS));
            $counts = MfgUnitComponent::query()->where('work_order_id', $wo->id)->where('component_item_id', $m->component_item_id)
                ->selectRaw('unit_id, COUNT(*) AS c')->groupBy('unit_id')->pluck('c', 'unit_id')->map(fn ($c) => (int) $c)->all();
            $unbound = [];
            foreach ($ids as $id) {
                $serial = $serials->get($id);
                if (! $serial) continue;
                $unit = $units->first(fn ($u) => ($counts[$u->id] ?? 0) < $perUnit);
                if (! $unit) { $unbound[] = $serial->serial_no; continue; }
                MfgUnitComponent::create([
                    'unit_id' => $unit->id, 'work_order_id' => $wo->id, 'component_item_id' => $m->component_item_id,
                    'component_item_name' => $m->component_item_name, 'component_spec' => $item?->spec, 'component_serial_no' => $serial->serial_no,
                    'batch_no' => $serial->batch_no, 'installed_by_legacy_id' => $this->actorId($actor), 'installed_by_name' => $this->actorName($actor),
                    'installed_at' => now(), 'remark' => '收料单 '.$issue->issue_no.($manual ? '，人工指定' : '，系统分配'),
                ]);
                $counts[$unit->id] = ($counts[$unit->id] ?? 0) + 1;
                $this->workOrders->log($wo, 'component_bound', null, null, "{$unit->serial_no} 装配 {$m->component_item_name} {$serial->serial_no}（收料单 {$issue->issue_no}".($manual ? '，人工指定' : '').'）',
                    $actor, ['issue_id' => $issue->id, 'inventory_serial_id' => (int) $serial->id], (int) $unit->id);
            }
            if ($unbound) $this->workOrders->log($wo, 'component_unbound', null, null, "{$m->component_item_name} 编号 ".implode('、', $unbound).' 已收料但各台已按单台用量装满，未登记到台', $actor, ['issue_id' => $issue->id]);
        }
    }

    /** 互换同一工单两台的同种部件编号；台入库、报废后不能再调整。 */
    public function swap(int $workOrderId, int $firstId, int $secondId, object $actor): void
    {
        DB::transaction(function () use ($workOrderId, $firstId, $secondId, $actor) {
            $wo = MfgWorkOrder::query()->lockForUpdate()->findOrFail($workOrderId);
            if (! in_array($wo->status, ['released', 'in_progress', 'paused'], true)) $this->fail('status_invalid', '工单不在生产中，不能调整部件编号。');
            $rows = MfgUnitComponent::query()->where('work_order_id', $wo->id)->whereIn('id', [$firstId, $secondId])->lockForUpdate()->get()->keyBy('id');
            if ($firstId === $secondId || $rows->count() !== 2) $this->fail('component_invalid', '请选择本工单两台设备上的部件记录。');
            [$a, $b] = [$rows->get($firstId), $rows->get($secondId)];
            if ((int) $a->component_item_id !== (int) $b->component_item_id) $this->fail('component_mismatch', '只能互换同一种部件的编号。');
            if ((int) $a->unit_id === (int) $b->unit_id) $this->fail('component_same_unit', '两条记录在同一台上，不需要互换。');
            $units = MfgWorkOrderUnit::query()->whereIn('id', [$a->unit_id, $b->unit_id])->lockForUpdate()->get()->keyBy('id');
            if ($units->contains(fn ($u) => ! in_array($u->status, self::ADJUSTABLE, true)))
                $this->fail('unit_locked', '已入库或报废的台不能再调整部件编号。');
            [$unitA, $unitB] = [$units->get($a->unit_id), $units->get($b->unit_id)];
            $a->update(['unit_id' => $unitB->id]);
            $b->update(['unit_id' => $unitA->id]);
            $text = "互换 {$a->component_item_name}：{$unitA->serial_no} 改装 {$b->component_serial_no}，{$unitB->serial_no} 改装 {$a->component_serial_no}";
            $this->workOrders->log($wo, 'component_swapped', null, null, $text, $actor, ['component_ids' => [$a->id, $b->id]], (int) $unitA->id);
            $this->workOrders->log($wo, 'component_swapped', null, null, $text, $actor, ['component_ids' => [$a->id, $b->id]], (int) $unitB->id);
        }, 5);
    }
}
