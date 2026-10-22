<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\DocumentNumber;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\Item;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderOperation;
use App\Models\Erp\MfgWorkOrderUnit;
use Illuminate\Support\Collection;

/**
 * 工单台号与设备序列号。
 * 下达时每台先用台号（工单号-001）占位；启用单件追溯的物料按物料策略的生成时机换成正式序列号：
 * production_unit_created 下达时、routing_operation_completed 指定工序合格时、before_finished_goods_posting 入库前。
 * 指定工序不在本工单路线上时，入库前兜底生成，保证入库的追溯件都有正式序列号；未启用追溯的物料一直使用台号。
 */
final class UnitSerialService
{
    public const EVENT_RELEASED = 'released';
    public const EVENT_OPERATION_PASSED = 'operation_passed';
    public const EVENT_BEFORE_RECEIPT = 'before_receipt';

    public const STAGE_LABELS = [
        'production_unit_created' => '工单下达时',
        'routing_operation_completed' => '指定工序完成时',
        'before_finished_goods_posting' => '入库前',
    ];

    public function placeholder(MfgWorkOrder $wo, int $unitNo): string
    {
        return $wo->work_order_no.'-'.str_pad((string) $unitNo, 3, '0', STR_PAD_LEFT);
    }

    public function isPlaceholder(MfgWorkOrder $wo, MfgWorkOrderUnit $unit): bool
    {
        return $unit->serial_no === $this->placeholder($wo, (int) $unit->unit_no);
    }

    /** 未启用追溯返回 null，否则返回生成时机。 */
    public function stage(?Item $item): ?string
    {
        if (! $item || $item->serialTrackingMode() === 'none') return null;
        $stage = (string) ($item->serial_generation_stage ?: 'before_finished_goods_posting');
        return isset(self::STAGE_LABELS[$stage]) ? $stage : 'before_finished_goods_posting';
    }

    /**
     * 到了生成时机就给占位的台换成正式序列号，返回 [unit_id => [台号, 序列号]]。
     * @param Collection<int, MfgWorkOrderUnit> $units
     */
    public function assignIfDue(MfgWorkOrder $wo, Collection $units, string $event, ?MfgWorkOrderOperation $operation = null): array
    {
        $item = Item::query()->find($wo->item_id);
        $stage = $this->stage($item);
        if (! $stage || ! $this->due($item, $stage, $event, $operation)) return [];
        $pending = $units->filter(fn (MfgWorkOrderUnit $unit) => $this->isPlaceholder($wo, $unit))->sortBy('unit_no')->values();
        return $pending->isEmpty() ? [] : $this->assign($item, $pending);
    }

    private function due(Item $item, string $stage, string $event, ?MfgWorkOrderOperation $operation): bool
    {
        if ($event === self::EVENT_BEFORE_RECEIPT) return true;
        if ($stage === 'production_unit_created') return true;
        return $stage === 'routing_operation_completed' && $event === self::EVENT_OPERATION_PASSED && $operation
            && $item->serial_generation_routing_operation_id
            && (int) $operation->routing_operation_id === (int) $item->serial_generation_routing_operation_id;
    }

    /** 格式：前缀+年月-流水，如 SN2610-0015；前缀取物料序列号前缀，未设置用 SN，按前缀每月一组流水。 */
    private function assign(Item $item, Collection $units): array
    {
        $prefix = strtoupper((string) ($item->serial_number_prefix ?: 'SN'));
        $prefix = substr(trim((string) preg_replace('/[^A-Z0-9_-]+/', '-', $prefix), '-_') ?: 'SN', 0, 30);
        $period = now()->format('ym');
        $type = substr('mfg_sn:'.$prefix, 0, 40);
        $date = now()->startOfMonth()->toDateString();
        $counter = DocumentNumber::query()->where('document_type', $type)->whereDate('number_date', $date)->lockForUpdate()->first()
            ?: DocumentNumber::create(['document_type' => $type, 'number_date' => $date, 'current_sequence' => 0]);
        $sequence = (int) $counter->current_sequence;
        $changes = [];
        foreach ($units as $unit) {
            do {
                $sequence++;
                $serial = $prefix.$period.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
            } while (MfgWorkOrderUnit::query()->where('serial_no', $serial)->exists() || InventorySerial::query()->where('serial_no', $serial)->exists());
            $changes[(int) $unit->id] = [$unit->serial_no, $serial];
            $unit->update(['serial_no' => $serial]);
        }
        $counter->update(['current_sequence' => $sequence]);
        return $changes;
    }

    public static function rangeText(array $serials): string
    {
        $serials = array_values($serials);
        if (! $serials) return '';
        return count($serials) > 1 ? $serials[0].' ~ '.end($serials) : $serials[0];
    }
}
