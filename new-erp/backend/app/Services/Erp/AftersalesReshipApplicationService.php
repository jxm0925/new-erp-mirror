<?php

namespace App\Services\Erp;

use App\Models\Erp\AftersalesAction;
use App\Models\Erp\AftersalesCase;
use App\Models\Erp\AftersalesReship;
use App\Models\Erp\AftersalesReshipItem;
use App\Models\Erp\InventoryBalance;
use App\Models\Erp\InventoryPostingLog;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\InventorySerialEvent;
use App\Models\Erp\InventoryTransaction;
use App\Models\Erp\Item;
use App\Models\Erp\SalesReturn;
use App\Services\Erp\Inventory\InventoryPostingKernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 售后补发：补发单只做"要发什么"，出库时才选库存来源/序列号并过账成一条
 * aftersales_reship_outbound 库存事务（负向出库、按余额当前移动平均成本），
 * 与销售发货完全隔离——不动订单 shipped_qty、不进 erp_sales_shipments。
 * 换货补发固定为换回商品；独立补发可任选物料。先退后发在退货单收齐前禁止出库。
 */
final class AftersalesReshipApplicationService
{
    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly InventoryPostingKernel $kernel,
        private readonly InventoryAvailabilityService $availability,
        private readonly AftersalesCaseApplicationService $cases,
    ) {}

    /** 在处理新增事务内创建待出库补发单；itemRows 已含快照。 */
    public function createDraft(AftersalesCase $case, AftersalesAction $action, array $itemRows, object $actor): AftersalesReship
    {
        $snap = $case->customer_snapshot ?: [];
        $reship = AftersalesReship::create([
            'reship_no' => $this->numbers->next('aftersales_reship', 'ASD'),
            'case_id' => $case->id, 'action_id' => $action->id, 'sales_order_id' => $case->sales_order_id,
            'status' => 'pending',
            'receiver_name' => $snap['name'] ?? null,
            'receiver_phone' => $snap['phone'] ?? null,
            'receiver_address' => $snap['address'] ?? null,
            'created_by_legacy_id' => (int) $actor->legacy_id,
        ]);
        foreach ($itemRows as $row) {
            AftersalesReshipItem::create($row + ['reship_id' => $reship->id]);
        }
        return $reship;
    }

    /** 订单（可限定订单行）已由补发单发出的序列号；用于拒绝补发件再次退/换。 */
    public static function reshippedSerialIds(int $orderId, ?int $salesOrderLineId = null): array
    {
        return AftersalesReshipItem::query()
            ->join('erp_aftersales_reships', 'erp_aftersales_reships.id', '=', 'erp_aftersales_reship_items.reship_id')
            ->where('erp_aftersales_reships.sales_order_id', $orderId)
            ->where('erp_aftersales_reships.status', 'shipped')
            ->when($salesOrderLineId, fn ($q) => $q->where('erp_aftersales_reship_items.sales_order_line_id', $salesOrderLineId))
            ->whereNotNull('erp_aftersales_reship_items.inventory_serial_id')
            ->pluck('erp_aftersales_reship_items.inventory_serial_id')
            ->map(fn ($id) => (int) $id)->all();
    }

    /** 该行已由补发单发出的数量（让补发件能再开售后单）。 */
    public static function reshippedQuantity(int $orderId, int $salesOrderLineId): float
    {
        return (float) AftersalesReshipItem::query()
            ->join('erp_aftersales_reships', 'erp_aftersales_reships.id', '=', 'erp_aftersales_reship_items.reship_id')
            ->where('erp_aftersales_reships.sales_order_id', $orderId)
            ->where('erp_aftersales_reships.status', 'shipped')
            ->where('erp_aftersales_reship_items.sales_order_line_id', $salesOrderLineId)
            ->sum('erp_aftersales_reship_items.qty');
    }

    /** 出库前置条件；先退后发的换货补发须等退货单收齐。 */
    public function canShip(AftersalesReship $reship): array
    {
        if ($reship->status !== 'pending' || $reship->inventory_transaction_id) return [false, '补发单已出库或已取消。'];
        $case = $reship->case ?: AftersalesCase::query()->find($reship->case_id);
        if (! $case || ! in_array($case->status, ['processing', 'ready_to_close'], true)) {
            return [false, '售后单已关闭、已取消或尚未受理，不能补发出库。'];
        }
        $action = $reship->action ?: AftersalesAction::query()->find($reship->action_id);
        if (! $action || (int) $action->case_id !== (int) $case->id || $action->status !== 'processing'
            || ! in_array($action->action_type, ['exchange', 'reship'], true)) {
            return [false, '关联处理已失效，不能补发出库。'];
        }
        if ($action->action_type === 'exchange') {
            $return = $action->salesReturn;
            if (! $return || in_array($return->return_status, ['draft', 'cancelled'], true)) {
                return [false, '关联退货单未确认或已取消，不能补发出库。'];
            }
            $received = $return->items->isNotEmpty() && $return->items->every(fn ($line) => (float) $line->received_base_qty + 0.00000001 >= (float) $line->requested_base_qty);
            if ($action->reship_timing !== 'ship_first' && ! $received) return [false, '先退后发：退货单尚未收齐，收齐后才能补发出库。'];
        }
        return [true, null];
    }

    /**
     * 补发出库：订单 → 售后 → 处理 → 退货 → 补发，按与取消相同的锁序重检状态后过账，
     * 序列号转 shipped（补发件视同已发出在客户处，可再开售后单）→ 补发单 shipped → 同步处理完成。
     */
    public function ship(int $id, array $payload, object $actor): AftersalesReship
    {
        return DB::transaction(function () use ($id, $payload, $actor): AftersalesReship {
            $reship = AftersalesReship::query()->findOrFail($id);
            $case = $this->cases->lockCase($reship->case_id);
            $action = AftersalesAction::query()->whereKey($reship->action_id)->lockForUpdate()->firstOrFail();
            if ($action->sales_return_id) {
                $action->setRelation('salesReturn', SalesReturn::query()->with([
                    'items' => fn ($query) => $query->orderBy('id')->lockForUpdate(),
                ])->lockForUpdate()->findOrFail($action->sales_return_id));
            }
            $reship = AftersalesReship::query()->whereKey($id)->lockForUpdate()->firstOrFail();
            $reship->setRelation('case', $case)->setRelation('action', $action);
            [$allowed, $reason] = $this->canShip($reship);
            if (! $allowed) throw ValidationException::withMessages(['reship' => $reason]);

            $items = $reship->items()->lockForUpdate()->get()->keyBy('id');
            if ($items->isEmpty()) throw ValidationException::withMessages(['items' => '补发单没有明细。']);
            $choices = collect($payload['items'] ?? [])->keyBy('reship_item_id');
            $postRows = [];
            $balanceTotals = [];
            $serialPicked = [];
            foreach ($items as $row) {
                $choice = $choices->get($row->id);
                if (! $choice || empty($choice['inventory_balance_id'])) {
                    throw ValidationException::withMessages(['items' => "{$row->item_name}：必须选择出库仓库/库位/批次。"]);
                }
                $balance = InventoryBalance::query()->whereKey((int) $choice['inventory_balance_id'])->lockForUpdate()->firstOrFail();
                if ((int) $balance->item_id !== (int) $row->item_id) {
                    throw ValidationException::withMessages(['items' => "{$row->item_name}：所选库存不是该物料。"]);
                }
                $qty = round((float) $row->qty, 8);
                $eligible = $this->availability->outboundBalanceQuery([(int) $row->item_id])
                    ->where('erp_inventory_balances.id', $balance->id)->lockForUpdate()->first();
                if (! $eligible || $this->availability->availableForOutbound($balance) + 0.00000001 < $qty) {
                    throw ValidationException::withMessages(['items' => "{$row->item_name}：所选批次可用库存不足（需要 {$qty}），请先备货或改为先退后发。"]);
                }
                $item = Item::query()->find($row->item_id);
                $serialIds = array_values(array_unique(array_map('intval', $choice['serial_ids'] ?? [])));
                $mode = $item?->serialTrackingMode() ?? 'none';
                if ($mode === 'required' && count($serialIds) !== (int) $qty) {
                    throw ValidationException::withMessages(['items' => "{$row->item_name}：序列号设备必须逐台选择 {$qty} 台。"]);
                }
                if ($mode === 'none' && $serialIds !== []) {
                    throw ValidationException::withMessages(['items' => "{$row->item_name}：非序列管理物料不能选序列号。"]);
                }
                $serials = $serialIds ? InventorySerial::query()->whereIn('id', $serialIds)
                    ->where('inventory_balance_id', $balance->id)->where('serial_status', 'available')->lockForUpdate()->get() : collect();
                if (count($serialIds) !== $serials->count()) {
                    throw ValidationException::withMessages(['items' => "{$row->item_name}：所选序列号已不在该批次的可发库存中。"]);
                }
                // 同一余额被多行选中时必须按合计验证，且序列号不能跨行重复。
                $balanceTotals[$balance->id] = ($balanceTotals[$balance->id] ?? 0.0) + $qty;
                $postRows[] = ['row' => $row, 'balance' => $balance, 'qty' => $qty, 'serials' => $serials];
                foreach ($serialIds as $sid) {
                    if (isset($serialPicked[$sid])) {
                        throw ValidationException::withMessages(['items' => "序列号 {$serials->firstWhere('id', $sid)?->serial_no} 被重复选择。"]);
                    }
                    $serialPicked[$sid] = true;
                }
            }
            foreach ($postRows as $post) {
                if ($this->availability->availableForOutbound($post['balance']) + 0.00000001 < ($balanceTotals[$post['balance']->id] ?? 0)) {
                    throw ValidationException::withMessages(['items' => "{$post['row']->item_name}：所选批次合计需求量超过可用库存，请拆分库存来源或改为先退后发。"]);
                }
            }

            // 承运商名称服务端快照：以 erp_sales_order_deliveries 为准，不信任客户端传值。
            $carrierName = null;
            if (! empty($payload['carrier_id'])) {
                $carrierName = DB::table('erp_sales_order_deliveries')
                    ->where('id', (int) $payload['carrier_id'])->where('enabled', true)->value('name');
                if (! $carrierName) throw ValidationException::withMessages(['carrier_id' => '承运商不存在或已停用。']);
            }
            $shippedAt = ! empty($payload['shipped_at']) ? $payload['shipped_at'] : now()->toDateTimeString();
            $first = $postRows[0]['balance'];
            $transaction = $this->kernel->begin([
                'transaction_type' => 'aftersales_reship_outbound',
                'source_type' => 'aftersales_reship', 'source_id' => $reship->id, 'source_no' => $reship->reship_no,
                'posting_status' => 'posted', 'warehouse_id' => $first->warehouse_id, 'location_id' => $first->location_id,
                'transaction_date' => substr((string) $shippedAt, 0, 10), 'posted_by' => (int) $actor->legacy_id, 'posted_at' => now(),
            ]);
            foreach ($postRows as $post) {
                $row = $post['row']; $balance = $post['balance']; $qty = $post['qty'];
                $cost = CuttingDecimal::share((string) $balance->inventory_value, (string) $balance->quantity_on_hand, (string) $qty);
                $posted = $this->kernel->apply($transaction, [
                    'item_id' => $row->item_id, 'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id,
                    'batch_no' => $balance->batch_no, 'unit_id' => $row->unit_id, 'material_lot_id' => $balance->material_lot_id,
                    'change_qty' => -$qty, 'unit_cost' => $balance->average_unit_cost,
                    'cost_amount' => bcsub('0', $cost, 4), 'cost_source_type' => 'aftersales_reship',
                    'source_type' => 'aftersales_reship', 'source_id' => $reship->id, 'source_item_id' => $row->id,
                ]);
                $row->update([
                    'warehouse_id' => $balance->warehouse_id, 'location_id' => $balance->location_id, 'batch_no' => $balance->batch_no,
                    'inventory_balance_id' => $balance->id,
                    'unit_cost' => $balance->average_unit_cost, 'cost_amount' => bcsub('0', $cost, 4),
                    'transaction_item_id' => $posted->id,
                ]);
                // 序列设备逐台拆行：一行一条序列号，便于补发件后续再开售后单时按台认领。
                $serialList = $post['serials']->values();
                if ($serialList->isNotEmpty()) {
                    $this->assignSerials($row, $serialList, $reship, $transaction, $posted, $actor);
                }
            }
            $reship->update([
                'status' => 'shipped', 'shipped_at' => $shippedAt,
                'carrier_id' => $payload['carrier_id'] ?? null, 'carrier_name' => $carrierName,
                'tracking_no' => $payload['tracking_no'] ?? null,
                'receiver_name' => $payload['receiver_name'] ?? $reship->receiver_name,
                'receiver_phone' => $payload['receiver_phone'] ?? $reship->receiver_phone,
                'receiver_address' => $payload['receiver_address'] ?? $reship->receiver_address,
                'package_count' => $payload['package_count'] ?? null, 'package_method' => $payload['package_method'] ?? null,
                'shipped_by_legacy_id' => (int) $actor->legacy_id,
                'inventory_transaction_id' => $transaction->id,
            ]);
            InventoryPostingLog::create(['source_type' => 'aftersales_reship', 'source_id' => $reship->id, 'source_no' => $reship->reship_no,
                'transaction_type' => 'aftersales_reship_outbound', 'transaction_id' => $transaction->id,
                'posting_status' => 'posted', 'posted_at' => now(), 'message' => '售后补发出库成功']);
            $this->cases->log($case, 'reship_ship', 'pending', 'shipped',
                "补发出库 {$reship->reship_no}：{$postRows[0]['row']->item_name} 等 {$reship->items()->count()} 行已发出".($reship->tracking_no ? "，{$reship->tracking_no}" : ''), $actor, $reship->action_id);
            app(AftersalesActionApplicationService::class)->syncCompletion($reship->action_id, $actor);
            return $reship->fresh('items');
        }, 5);
    }

    /** 序列行拆分：原行留第一台并记下序列，其余按台新增行；序列事件记 shipped。 */
    private function assignSerials(AftersalesReshipItem $row, $serials, AftersalesReship $reship, InventoryTransaction $transaction, object $posted, object $actor): void
    {
        $list = $serials->values();
        $row->update(['inventory_serial_id' => $list->first()->id, 'serial_no' => $list->first()->serial_no]);
        if ($list->count() > 1) {
            // 序列行拆成一行一台：成本整笔挂在原 transaction_item_id，拆行只记设备身份。
            foreach ($list->slice(1) as $serial) {
                $clone = $row->replicate(['transaction_item_id']);
                $clone->qty = 1;
                $clone->inventory_serial_id = $serial->id;
                $clone->serial_no = $serial->serial_no;
                $clone->transaction_item_id = $posted->id;
                $clone->save();
            }
            $row->update(['qty' => 1]);
        }
        foreach ($list as $serial) {
            $serial->update(['serial_status' => 'shipped', 'outbound_at' => now()]);
            InventorySerialEvent::create(['inventory_serial_id' => $serial->id, 'event_type' => 'aftersales_reship_outbound',
                'document_type' => 'aftersales_reship', 'document_id' => $reship->id, 'document_no' => $reship->reship_no,
                'from_status' => 'available', 'to_status' => 'shipped',
                'warehouse_id' => $serial->warehouse_id, 'location_id' => $serial->location_id, 'batch_no' => $serial->batch_no,
                'operator_id' => (int) $actor->legacy_id,
                'event_payload' => ['reship_item_id' => $row->id, 'transaction_item_id' => $posted->id], 'occurred_at' => now()]);
        }
    }

    /** 调用方须已按订单 → 售后 → 处理加锁；退货取消、处理取消和整单取消共用本入口。 */
    public function cancelForAction(AftersalesAction $action, string $reason): array
    {
        $reships = $action->reships()->orderBy('id')->lockForUpdate()->get();
        if ($reships->contains(fn ($reship) => $reship->status === 'shipped' || $reship->inventory_transaction_id)) {
            throw ValidationException::withMessages(['reship' => '补发已出库，不能取消关联售后单、处理或退货单。']);
        }
        $cancelled = [];
        foreach ($reships->where('status', 'pending') as $reship) {
            $reship->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $reason]);
            $cancelled[] = $reship->reship_no;
        }
        return $cancelled;
    }
}
