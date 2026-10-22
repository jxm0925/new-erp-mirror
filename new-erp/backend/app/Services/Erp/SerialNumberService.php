<?php

namespace App\Services\Erp;

use App\Models\Erp\DocumentNumber;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\Item;
use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderUnit;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 统一序列号规则：前缀 + 年月 + 4 位流水，如 DF2610-0001。
 * - 前缀取物料的序列号前缀（启用追溯时必填、1～10 位英文字母、各物料不重复，见 Item::booted）。
 * - 同一前缀每月一组流水，生产工单、采购到货、库存调整共用，号从生成到报废不变。
 * - 未启用追溯的自制品不占序列号，工单里用台号（工单号-001）区分第几台。
 */
final class SerialNumberService
{
    public const PREFIX_PATTERN = '/^[A-Z]{1,10}$/';

    /** @return list<string> */
    public function next(Item $item, int $count): array
    {
        if ($item->serialTrackingMode() === 'none')
            throw ValidationException::withMessages(['item_id' => "物料 {$item->item_code} 未启用序列号追溯，不能生成序列号。"]);
        $prefix = strtoupper(trim((string) $item->serial_number_prefix));
        if (! preg_match(self::PREFIX_PATTERN, $prefix))
            throw ValidationException::withMessages(['serial_number_prefix' => "物料 {$item->item_code} 未维护有效的序列号前缀（1～10 位英文字母），请先在物料资料里填写。"]);
        if ($count < 1) return [];

        return DB::transaction(function () use ($prefix, $count): array {
            $date = now()->startOfMonth()->toDateString();
            $type = 'serial:'.$prefix;
            $counter = DocumentNumber::query()->where('document_type', $type)->whereDate('number_date', $date)->lockForUpdate()->first()
                ?: DocumentNumber::create(['document_type' => $type, 'number_date' => $date, 'current_sequence' => 0]);
            $sequence = (int) $counter->current_sequence;
            $period = now()->format('ym');
            $serials = [];
            while (count($serials) < $count) {
                $sequence++;
                $serial = $prefix.$period.'-'.str_pad((string) $sequence, 4, '0', STR_PAD_LEFT);
                if ($this->taken($serial)) continue;
                $serials[] = $serial;
            }
            $counter->update(['current_sequence' => $sequence]);
            return $serials;
        }, 5);
    }

    /** 工单内的台号，仅用于未启用追溯的自制品。 */
    public function unitNo(MfgWorkOrder $wo, int $unitNo): string
    {
        return $wo->work_order_no.'-'.str_pad((string) $unitNo, 3, '0', STR_PAD_LEFT);
    }

    /** 号已被在制设备或库存档案占用（含供应商号、人工录入号）。 */
    public function taken(string $serial): bool
    {
        return MfgWorkOrderUnit::query()->where('serial_no', $serial)->exists()
            || InventorySerial::query()->where('serial_no', $serial)->exists();
    }
}
