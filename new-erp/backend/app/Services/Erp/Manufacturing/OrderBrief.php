<?php

namespace App\Services\Erp\Manufacturing;

use App\Models\Erp\MfgWorkOrder;
use App\Models\Erp\MfgWorkOrderLink;
use App\Services\Erp\ErpUserProjectionService;
use Illuminate\Support\Facades\DB;

/**
 * 车间看的订单信息：销售员、客户、联系人电话、收货地址、订单备注、客户备注、物流要求、本行备注。
 * 都取订单现在的内容（销售改了马上能看到）；工单第一次读到时存一份快照，之后和快照不一样的字段标「销售已修改」。
 * 半成品工单、返工工单顺着总装工单找到订单。
 */
class OrderBrief
{
    /** 比对是否被修改的字段 => 页面叫法 */
    public const WATCHED = [
        'customer_name' => '客户', 'contact_name' => '联系人', 'contact_phone' => '联系电话', 'address' => '收货地址',
        'order_remark' => '订单备注', 'customer_remark' => '客户备注', 'logistics_requirement' => '物流要求', 'line_remark' => '本行备注',
        'required_delivery_date' => '要求交期',
    ];

    public function forWorkOrder(MfgWorkOrder $wo): ?array
    {
        [$orderId, $lineId] = $this->resolve($wo);
        if (! $orderId) return null;
        $info = $this->current($orderId, $lineId);
        if (! $info) return null;
        $snapshot = $wo->order_info_snapshot;
        if (! is_array($snapshot) || (int) ($snapshot['sales_order_id'] ?? 0) !== $orderId) {
            // 第一次读到（或老工单）：以现在的内容为准存一份，之后销售再改就能比出来。
            $snapshot = $this->watched($info) + ['sales_order_id' => $orderId, 'taken_at' => now()->toDateTimeString()];
            MfgWorkOrder::query()->whereKey($wo->id)->update(['order_info_snapshot' => json_encode($snapshot, JSON_UNESCAPED_UNICODE)]);
        }
        $changed = [];
        foreach (self::WATCHED as $key => $label) {
            $before = trim((string) ($snapshot[$key] ?? ''));
            $after = trim((string) ($info[$key] ?? ''));
            if ($before !== $after) $changed[] = ['field' => $key, 'label' => $label, 'before' => $before !== '' ? $before : '（空）', 'after' => $after !== '' ? $after : '（空）'];
        }
        return $info + ['changed' => $changed, 'snapshot_at' => $snapshot['taken_at'] ?? null];
    }

    /** 发货单：按发货单关联的订单取；有工单的行用工单的快照比对。 */
    public function forSalesOrder(int $orderId, ?int $lineId = null): ?array
    {
        $info = $this->current($orderId, $lineId);
        return $info ? $info + ['changed' => [], 'snapshot_at' => null] : null;
    }

    /** @return array{0: ?int, 1: ?int} [订单 ID, 订单行 ID] */
    public function resolve(MfgWorkOrder $wo): array
    {
        $cur = $wo;
        for ($i = 0; $i < 6 && $cur; $i++) {
            if ($cur->sales_order_id) return [(int) $cur->sales_order_id, $cur->sales_order_line_id ? (int) $cur->sales_order_line_id : null];
            $parentId = MfgWorkOrderLink::query()->where('child_work_order_id', $cur->id)->value('parent_work_order_id')
                ?: $cur->rework_of_work_order_id;
            $cur = $parentId ? MfgWorkOrder::query()->find($parentId) : null;
        }
        if ($wo->sales_order_no) {
            $id = DB::table('erp_sales_orders')->where('sales_order_no', $wo->sales_order_no)->value('id');
            if ($id) return [(int) $id, null];
        }
        return [null, null];
    }

    private function current(int $orderId, ?int $lineId): ?array
    {
        $o = DB::table('erp_sales_orders')->where('id', $orderId)->first();
        if (! $o) return null;
        $line = $lineId ? DB::table('erp_sales_order_lines')->where('id', $lineId)->first(['id', 'line_no', 'remark', 'product_name', 'sku_name', 'order_qty', 'unit_name_snapshot']) : null;
        $sales = $o->sales_user_legacy_id ? app(ErpUserProjectionService::class)->one($o->sales_user_legacy_id) : null;
        $text = fn ($v) => ($v = trim((string) $v)) !== '' ? $v : null;
        return [
            'sales_order_id' => (int) $o->id, 'sales_order_no' => $o->sales_order_no,
            'salesperson_name' => $sales['display_name'] ?? null,
            'customer_name' => $text($o->customer_name_snapshot ?? null) ?? $text($o->customer_name),
            'contact_name' => $text($o->contact_name_snapshot ?? null) ?? $text($o->contact_name),
            'contact_phone' => $text($o->contact_phone_snapshot ?? null) ?? $text($o->contact_phone) ?? $text($o->customer_phone ?? null),
            'address' => $text($o->full_address) ?? $text($o->address),
            'order_remark' => $text($o->order_remark) ?? $text($o->remark),
            'customer_remark' => $text($o->customer_remark),
            'logistics_requirement' => $text($o->logistics_requirement),
            'required_delivery_date' => $o->required_delivery_date ? substr((string) $o->required_delivery_date, 0, 10) : null,
            'line_no' => $line && $line->line_no !== null ? (int) $line->line_no : null,
            'line_remark' => $line ? $text($line->remark) : null,
        ];
    }

    private function watched(array $info): array
    {
        return array_intersect_key($info, self::WATCHED);
    }
}
