<?php

namespace App\Services\Erp;

use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderAttachment;
use App\Models\Erp\SalesOrderLog;
use App\Models\Erp\SalesOrderVersion;
use Illuminate\Support\Facades\DB;

class SalesOrderDraftService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly SalesOrderSnapshotService $snapshots,
        private readonly SalesOrderLineService $lines,
        private readonly SalesOrderAttachmentService $attachments,
        private readonly SalesOrderAmountService $amounts,
        private readonly SalesOrderFundingGateService $fundingGates,
    ) {
    }

    public function create(array $payload, string $operator): SalesOrder
    {
        return DB::transaction(function () use ($payload, $operator) {
            [$header, $lines, $deletedLineIds, $draftToken] = $this->split($payload);
            $reservationToken = $header['reservation_token'] ?? null;
            unset($header['reservation_token'], $header['creation_session_id']);
            $header = $this->normalize($header);
            $header['sales_order_no'] = $header['sales_order_no'] ?: $this->numbers->next('sales_order', 'SO');
            $header['order_status'] = 'draft';
            $header['confirm_status'] = 'unconfirmed';
            $header['fulfillment_status'] = 'pending';
            $header['production_confirm_status'] = 'not_entered';
            $header['shipment_status'] = 'not_shipped';

            $order = SalesOrder::create($header);
            if ($reservationToken) {
                $this->numbers->consume(
                    $reservationToken,
                    'sales_order',
                    $order->sales_order_no,
                    $header['created_by_legacy_id'] ?? null,
                    'sales_order',
                    $order->id
                );
            }
            $this->lines->sync($order, $lines, $deletedLineIds, $draftToken, $operator);
            $this->attachments->bindOrderDraft($order->id, $draftToken);
            $order = $this->amounts->refresh($order);
            $this->fundingGates->refreshProjection($order);
            $this->recordVersion($order, 'create', null, $order->fresh('lines')->toArray(), $operator);
            $this->recordLog($order, 'create', null, 'draft', '新增销售订单草稿', $operator);

            return $order->fresh($this->relations());
        });
    }

    public function update(SalesOrder $order, array $payload, string $operator): SalesOrder
    {
        abort_if($order->order_status !== 'draft', 422, '只有草稿订单允许直接编辑');
        abort_if($order->shipment_status !== 'not_shipped', 422, '已有发货记录的订单不能编辑草稿');

        return DB::transaction(function () use ($order, $payload, $operator) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            abort_if($order->order_status !== 'draft', 422, 'Only draft orders can be edited directly.');
            abort_if($order->shipment_status !== 'not_shipped', 422, 'Orders with shipments cannot be edited as drafts.');
            $before = $order->fresh(['lines', 'attachments'])->toArray();
            [$header, $lines, $deletedLineIds, $draftToken] = $this->split($payload);
            // Number reservations belong to the create-page lifecycle. They are
            // accepted in the shared form payload, but are not sales-order columns
            // and must never be persisted while editing an existing draft.
            unset($header['reservation_token'], $header['creation_session_id']);
            $header = $this->normalize($header);
            unset($header['sales_order_no']);
            $header['order_status'] = 'draft';
            $header['confirm_status'] = 'unconfirmed';
            $header['fulfillment_status'] = 'pending';
            $header['production_confirm_status'] = 'not_entered';
            $header['shipment_status'] = 'not_shipped';

            $order->update($header);
            $this->lines->sync($order, $lines, $deletedLineIds, $draftToken, $operator);
            $this->attachments->bindOrderDraft($order->id, $draftToken);
            $order = $this->amounts->refresh($order);
            $this->fundingGates->refreshProjection($order);
            $after = $order->fresh('lines')->toArray();
            $this->recordVersion($order, 'update', $before, $after, $operator);
            $this->recordLog($order, 'update', 'draft', 'draft', '编辑销售订单草稿', $operator);

            return $order->fresh($this->relations());
        });
    }

    public function delete(SalesOrder $order, string $operator): void
    {
        abort_if($order->order_status !== 'draft', 422, '只有草稿订单允许删除');
        abort_if($order->fulfillments()->exists(), 422, '订单已产生履约记录，不能删除');
        abort_if($order->productionRequirements()->exists(), 422, '订单已产生生产需求，不能删除');
        abort_if(DB::table('erp_inventory_reservations')->where('source_order_id', $order->id)->exists(), 422, '订单已产生库存占用，不能删除');

        DB::transaction(function () use ($order, $operator) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertDraftDeletable($order);
            SalesOrderAttachment::query()
                ->where('sales_order_id', $order->id)
                ->where('status', 'active')
                ->update(['status' => 'deleted', 'deleted_by' => $operator, 'deleted_at' => now()]);
            $this->recordLog($order, 'delete_draft', 'draft', 'deleted', '删除销售订单草稿', $operator);
            $order->lines()->delete();
            $order->delete();
        });
    }

    public function submitForConfirmation(SalesOrder $order, string $operator): SalesOrder
    {
        abort_if($order->order_status !== 'draft', 422, '只有草稿订单可以提交确认');
        abort_if($order->confirm_status === 'pending_confirmation', 422, '订单已提交确认，请勿重复提交');
        $order->load(['lines.sku', 'lines.attachments']);
        abort_if($order->lines->isEmpty(), 422, '销售订单至少需要一行明细');
        abort_if(blank($order->default_carrier_id ?: $order->carrier_id), 422, '提交确认前必须选择快递');

        foreach ($order->lines as $line) {
            abort_if((float) $line->unit_price <= 0, 422, "订单行 {$line->line_no} 的销售单价必须大于 0");
            $sku = $line->sku;
            abort_if(!$sku, 422, "订单行 {$line->line_no} 的 SKU 已不存在");
            abort_if(
                $sku->electric_mode === 'required' && blank($line->electric),
                422,
                "订单行 {$line->line_no} 必须填写电压"
            );
            abort_if(
                $sku->need_pump_mode === 'required' && is_null($line->need_pump),
                422,
                "订单行 {$line->line_no} 必须选择原水泵控制"
            );
            if ($line->is_special_customized) {
                $types = $line->attachments->where('status', 'active')->pluck('attachment_type');
                abort_if(
                    $sku->special_custom_drawing_required && !$types->contains(fn ($type) => in_array($type, ['design_drawing', 'customer_drawing'], true)),
                    422,
                    "订单行 {$line->line_no} 缺少特殊定制设计图纸"
                );
                abort_if(
                    $sku->special_custom_agreement_required && !$types->contains('technical_agreement'),
                    422,
                    "订单行 {$line->line_no} 缺少特殊定制技术协议"
                );
                abort_if(
                    $sku->special_custom_description_required && blank($line->customization_description),
                    422,
                    "订单行 {$line->line_no} 缺少特殊定制说明"
                );
            }
        }

        return DB::transaction(function () use ($order, $operator) {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            $this->assertDraftConfirmationGate($order);
            $before = $order->fresh('lines')->toArray();
            $order->update(['confirm_status' => 'pending_confirmation']);
            $after = $order->fresh('lines')->toArray();
            $this->recordVersion($order, 'submit_confirmation', $before, $after, $operator);
            $this->recordLog(
                $order,
                'submit_confirmation',
                'unconfirmed',
                'pending_confirmation',
                '销售订单草稿已通过确认前校验，等待下一阶段正式确认',
                $operator
            );

            return $order->fresh($this->relations());
        });
    }

    /** 第五阶段只允许预校验，不变更订单状态或创建下游单据。 */
    public function precheckConfirmation(SalesOrder $order): array
    {
        $order->load(['lines.sku', 'lines.attachments']);
        $checks = [];
        $add = static function (string $code, bool $passed, string $passedMessage, string $blockedMessage, ?string $field = null) use (&$checks): void {
            $checks[] = array_filter([
                'code' => $code,
                'status' => $passed ? 'passed' : 'blocked',
                'message' => $passed ? $passedMessage : $blockedMessage,
                'field' => $field,
            ], static fn ($value) => $value !== null);
        };
        $shippingAddress = $order->full_address
            ?: data_get($order->shipping_address_snapshot, 'full_address')
            ?: data_get($order->shipping_address_snapshot, 'address');
        $add('DRAFT_STATUS', $order->order_status === 'draft', '订单处于草稿状态', '订单状态不是草稿，不能执行确认前检查', 'order_status');
        $add('CUSTOMER_COMPLETE', filled($order->customer_name) && filled($order->contact_phone) && filled($shippingAddress), '客户及收货信息完整', '客户名称、联系电话或收货地址不完整', 'customer');
        $add('DEFAULT_CARRIER_REQUIRED', filled($order->default_carrier_id ?: $order->carrier_id), '默认承运方式已选择', '请选择默认承运方式', 'default_carrier_id');
        $add('ORDER_LINES_REQUIRED', $order->lines->isNotEmpty(), '订单明细已填写', '订单至少需要一行明细', 'lines');
        foreach ($order->lines as $line) {
            $prefix = "LINE_{$line->line_no}_";
            $add($prefix.'UNIT_PRICE', (float) $line->unit_price > 0, "第 {$line->line_no} 行销售单价有效", "第 {$line->line_no} 行销售单价必须大于 0", "lines.{$line->line_no}.unit_price");
            $sku = $line->sku;
            $add($prefix.'SKU_EXISTS', (bool) $sku, "第 {$line->line_no} 行 SKU 有效", "第 {$line->line_no} 行 SKU 无效或已停用", "lines.{$line->line_no}.sku_id");
            if (!$sku) continue;
            $add($prefix.'ELECTRIC', $sku->electric_mode !== 'required' || filled($line->electric), "第 {$line->line_no} 行电压配置符合 SKU 要求", "第 {$line->line_no} 行必须填写电压", "lines.{$line->line_no}.electric");
            $add($prefix.'PUMP', $sku->need_pump_mode !== 'required' || !is_null($line->need_pump), "第 {$line->line_no} 行原水泵控制配置符合 SKU 要求", "第 {$line->line_no} 行必须选择原水泵控制", "lines.{$line->line_no}.need_pump");
            if (!$line->is_special_customized) continue;
            $types = $line->attachments->where('status', 'active')->pluck('attachment_type');
            $add($prefix.'DRAWING', !$sku->special_custom_drawing_required || $types->contains(fn ($type) => in_array($type, ['design_drawing', 'customer_drawing'], true)), "第 {$line->line_no} 行特殊定制设计图纸要求已满足", "第 {$line->line_no} 行缺少特殊定制设计图纸", "lines.{$line->line_no}.attachments");
            $add($prefix.'AGREEMENT', !$sku->special_custom_agreement_required || $types->contains('technical_agreement'), "第 {$line->line_no} 行特殊定制技术协议要求已满足", "第 {$line->line_no} 行缺少特殊定制技术协议", "lines.{$line->line_no}.attachments");
            $add($prefix.'DESCRIPTION', !$sku->special_custom_description_required || filled($line->customization_description), "第 {$line->line_no} 行特殊定制说明已填写", "第 {$line->line_no} 行缺少特殊定制说明", "lines.{$line->line_no}.customization_description");
        }
        return ['passed' => collect($checks)->every(fn (array $check) => $check['status'] === 'passed'), 'checks' => $checks, 'downstream_created' => false, 'message' => '当前仅进行确认前检查，不改变订单状态，不生成下游单据。'];
    }

    private function assertDraftDeletable(SalesOrder $order): void
    {
        abort_if($order->order_status !== 'draft', 422, 'Only draft orders can be deleted.');
        abort_if($order->fulfillments()->exists(), 422, 'Orders with fulfillment records cannot be deleted.');
        abort_if($order->productionRequirements()->exists(), 422, 'Orders with production requirements cannot be deleted.');
        abort_if(DB::table('erp_inventory_reservations')->where('source_order_id', $order->id)->exists(), 422, 'Orders with inventory reservations cannot be deleted.');
    }

    private function assertDraftConfirmationGate(SalesOrder $order): void
    {
        abort_if($order->order_status !== 'draft', 422, 'Only draft orders can be submitted.');
        abort_if($order->confirm_status === 'pending_confirmation', 422, 'Order has already been submitted.');
        $order->load(['lines.sku', 'lines.attachments']);
        abort_if($order->lines->isEmpty(), 422, 'At least one order line is required.');
        abort_if(blank($order->default_carrier_id ?: $order->carrier_id), 422, 'Default carrier is required before confirmation.');

        foreach ($order->lines as $line) {
            abort_if((float) $line->unit_price <= 0, 422, "Order line {$line->line_no} must have a positive unit price.");
            $sku = $line->sku;
            abort_if(!$sku, 422, "Order line {$line->line_no} SKU no longer exists.");
            abort_if($sku->electric_mode === 'required' && blank($line->electric), 422, "Order line {$line->line_no} requires electric voltage.");
            abort_if($sku->need_pump_mode === 'required' && is_null($line->need_pump), 422, "Order line {$line->line_no} requires pump-control selection.");
            if (!$line->is_special_customized) continue;
            $types = $line->attachments->where('status', 'active')->pluck('attachment_type');
            abort_if($sku->special_custom_drawing_required && !$types->contains(fn ($type) => in_array($type, ['design_drawing', 'customer_drawing'], true)), 422, "Order line {$line->line_no} is missing a special-custom drawing.");
            abort_if($sku->special_custom_agreement_required && !$types->contains('technical_agreement'), 422, "Order line {$line->line_no} is missing a special-custom technical agreement.");
            abort_if($sku->special_custom_description_required && blank($line->customization_description), 422, "Order line {$line->line_no} is missing a special-custom description.");
        }
    }

    private function split(array $payload): array
    {
        $lines = $payload['lines'] ?? [];
        $deleted = $payload['deleted_line_ids'] ?? [];
        $draftToken = $payload['draft_token'] ?? null;
        unset($payload['lines'], $payload['deleted_line_ids'], $payload['draft_token']);
        return [$payload, $lines, $deleted, $draftToken];
    }

    private function normalize(array $payload): array
    {
        foreach (['is_urgent', 'quickly', 'is_customized', 'is_special_customized', 'is_delay', 'delay', 'need_pump', 'is_share'] as $field) {
            $payload[$field] = (bool) ($payload[$field] ?? false);
        }
        if (isset($payload['share_user']) && is_array($payload['share_user'])) {
            $payload['share_user'] = implode(',', array_values(array_filter($payload['share_user'], fn ($value) => filled($value))));
        }
        if (!$payload['is_share']) $payload['share_user'] = null;
        $payload['order_source'] = $payload['order_source'] ?? 'manual';
        $payload['currency'] = $payload['currency'] ?? 'CNY';
        $payload['freight_amount'] = round((float) ($payload['freight_amount'] ?? 0), 2);
        $payload['carrier_fee'] = round((float) ($payload['carrier_fee'] ?? 0), 2);
        $payload['order_date'] = $payload['order_date'] ?? (
            !empty($payload['order_time']) ? date('Y-m-d', strtotime($payload['order_time'])) : now()->toDateString()
        );
        $payload['order_remark'] = $payload['order_remark'] ?? $payload['remark'] ?? null;
        $payload['remark'] = $payload['order_remark'];
        $payload['quickly'] = $payload['quickly'] || $payload['is_urgent'];
        $payload['is_urgent'] = $payload['quickly'];
        $payload['delay'] = $payload['delay'] || $payload['is_delay'];
        $payload['is_delay'] = $payload['delay'];

        return $this->snapshots->lock($payload);
    }

    private function recordVersion(
        SalesOrder $order,
        string $type,
        ?array $before,
        ?array $after,
        string $operator
    ): void {
        SalesOrderVersion::create([
            'sales_order_id' => $order->id,
            'version_no' => (int) SalesOrderVersion::where('sales_order_id', $order->id)->max('version_no') + 1,
            'change_type' => $type,
            'before_snapshot' => $before,
            'after_snapshot' => $after,
            'operator' => $operator,
        ]);
    }

    private function recordLog(
        SalesOrder $order,
        string $action,
        ?string $before,
        ?string $after,
        string $content,
        string $operator
    ): void {
        SalesOrderLog::create([
            'sales_order_id' => $order->id,
            'order_no_snapshot' => $order->sales_order_no,
            'action' => $action,
            'before_status' => $before,
            'after_status' => $after,
            'operator' => $operator,
            'content' => $content,
        ]);
    }

    private function relations(): array
    {
        return [
            'lines.product',
            'lines.sku.salesUnit',
            'lines.item',
            'lines.attachments' => fn ($query) => $query->where('status', 'active')->latest('version_no'),
            'attachments' => fn ($query) => $query->where('status', 'active')->where('attachment_scope', 'order')->latest('version_no'),
            'versions',
            'logs',
        ];
    }
}
