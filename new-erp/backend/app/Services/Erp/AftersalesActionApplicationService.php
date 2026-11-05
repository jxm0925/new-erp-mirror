<?php

namespace App\Services\Erp;

use App\Models\Erp\AftersalesAction;
use App\Models\Erp\AftersalesActionItem;
use App\Models\Erp\AftersalesCase;
use App\Models\Erp\AftersalesCaseItem;
use App\Models\Erp\SalesReturn;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 售后处理：退货退款/换货的退回半身在同一事务里生成销售退货单并确认（draft → pending_receipt），
 * 单据上回写 aftersales_case_id / aftersales_action_id；仅记录登记即完成。
 * 处理完成/取消由 syncFromSalesReturn 随销售退货单状态变化同步。
 */
final class AftersalesActionApplicationService
{
    private const ACTION_TYPES = ['refund', 'exchange', 'record', 'reship', 'compensation'];
    private const RETURN_TYPES = ['refund', 'exchange'];
    private const COMPENSATION_METHODS = ['cash', 'wechat', 'alipay', 'bank_transfer', 'other'];
    private const RETURN_METHODS = ['customer_ship', 'pickup', 'with_reship'];
    private const FREIGHT_PAYERS = ['company', 'customer', 'carrier'];
    private const DISPOSITIONS = ['defective', 'restock', 'scrap'];
    private const RESHIP_TIMINGS = ['return_first', 'ship_first'];

    public function __construct(
        private readonly SalesReturnApplicationService $returns,
        private readonly AftersalesCaseApplicationService $cases,
    ) {}

    public function add(int $caseId, array $payload, object $actor): AftersalesAction
    {
        return DB::transaction(function () use ($caseId, $payload, $actor): AftersalesAction {
            $case = $this->cases->lockCase($caseId);
            if ($case->status === 'pending') {
                $case->update(['status' => 'processing', 'accepted_by_legacy_id' => (int) $actor->legacy_id, 'accepted_at' => now()]);
                $this->cases->log($case, 'accept', 'pending', 'processing', '受理售后单', $actor);
            }
            if (! in_array($case->status, ['processing', 'ready_to_close'], true)) {
                throw ValidationException::withMessages(['status' => '已关闭或已取消的售后单不能再添加处理。']);
            }
            $type = (string) ($payload['action_type'] ?? '');
            if (! in_array($type, self::ACTION_TYPES, true)) {
                throw ValidationException::withMessages(['action_type' => '本期处理方式只支持退货退款、换货、仅记录、补发和赔付记录。']);
            }
            if ($type === 'compensation') {
                $amount = (float) ($payload['compensation_amount'] ?? 0);
                if ($amount <= 0) throw ValidationException::withMessages(['compensation_amount' => '赔付金额必须大于 0。']);
                if (! in_array($payload['compensation_method'] ?? '', self::COMPENSATION_METHODS, true)) {
                    throw ValidationException::withMessages(['compensation_method' => '赔付方式不正确。']);
                }
                if (empty($payload['compensated_at'])) throw ValidationException::withMessages(['compensated_at' => '付款时间必填。']);
                if (trim((string) ($payload['record_content'] ?? '')) === '') {
                    throw ValidationException::withMessages(['record_content' => '赔付记录必须填写说明。']);
                }
            }
            $reshipTiming = (string) ($payload['reship_timing'] ?? 'return_first');
            if (! in_array($reshipTiming, self::RESHIP_TIMINGS, true)) {
                throw ValidationException::withMessages(['reship_timing' => '补发时机不正确。']);
            }
            if ($reshipTiming === 'ship_first' && trim((string) ($payload['ship_first_reason'] ?? '')) === '') {
                throw ValidationException::withMessages(['ship_first_reason' => '先发后退必须填写原因。']);
            }
            if (isset($payload['return_method']) && ! in_array($payload['return_method'], self::RETURN_METHODS, true)) {
                throw ValidationException::withMessages(['return_method' => '退回方式不正确。']);
            }
            if (isset($payload['freight_payer']) && ! in_array($payload['freight_payer'], self::FREIGHT_PAYERS, true)) {
                throw ValidationException::withMessages(['freight_payer' => '运费承担不正确。']);
            }
            $disposition = $payload['planned_disposition'] ?? 'defective';
            if (! in_array($disposition, self::DISPOSITIONS, true)) {
                throw ValidationException::withMessages(['planned_disposition' => '退回件计划处置不正确。']);
            }

            $action = AftersalesAction::create([
                'case_id' => $case->id, 'action_type' => $type, 'status' => 'processing',
                'return_method' => $payload['return_method'] ?? null,
                'freight_payer' => $payload['freight_payer'] ?? null,
                'freight_amount' => $payload['freight_amount'] ?? null,
                'planned_disposition' => in_array($type, ['refund', 'exchange'], true) ? $disposition : null,
                'reship_timing' => $type === 'exchange' ? $reshipTiming : null,
                'ship_first_reason' => $type === 'exchange' && $reshipTiming === 'ship_first' ? trim((string) $payload['ship_first_reason']) : null,
                'remark' => $payload['remark'] ?? null,
                'created_by_legacy_id' => (int) $actor->legacy_id,
            ]);

            if ($type === 'record' || $type === 'compensation') {
                $content = trim((string) ($payload['record_content'] ?? ''));
                if ($content === '') throw ValidationException::withMessages(['record_content' => '处理说明必填。']);
                $action->update(['record_content' => $content, 'status' => 'completed', 'completed_at' => now()]);
                // 赔付是公司直接付给客户的账外记录：不冲应收、不出财务单据、不进统计核算。
                if ($type === 'compensation') {
                    $amount = number_format((float) $payload['compensation_amount'], 2, '.', '');
                    $action->update([
                        'compensation_amount' => $payload['compensation_amount'],
                        'compensation_method' => $payload['compensation_method'],
                        'compensated_at' => $payload['compensated_at'],
                        'compensation_payee' => trim((string) ($payload['compensation_payee'] ?? '')) ?: null,
                    ]);
                    $this->cases->log($case, 'action_add', null, 'completed',
                        "赔付记录：¥{$amount}（{$this->compensationMethodLabel($payload['compensation_method'])}）".($action->compensation_payee ? "，收款人 {$action->compensation_payee}" : ''), $actor, $action->id);
                } else {
                    $this->cases->log($case, 'action_add', null, 'completed', "添加处理：仅记录，{$content}", $actor, $action->id);
                }
            } elseif ($type === 'reship') {
                $rows = $this->assertReshipItems($case, $payload['reship_items'] ?? []);
                $reship = app(AftersalesReshipApplicationService::class)->createDraft($case, $action, $rows, $actor);
                $this->cases->log($case, 'action_add', null, 'processing',
                    "添加处理：补发，生成补发单 {$reship->reship_no}（{$reship->items()->count()} 行）", $actor, $action->id);
            } else {
                $items = $this->assertActionItems($case, $payload['items'] ?? []);
                $actionItemRows = [];
                foreach ($items['action_items'] as $row) {
                    $actionItemRows[$row['case_item_id']] = AftersalesActionItem::create($row + ['action_id' => $action->id]);
                }
                $salesReturn = $this->returns->create([
                    'sales_order_id' => $case->sales_order_id,
                    'aftersales_case_id' => $case->id,
                    'aftersales_action_id' => $action->id,
                    'return_type' => $type === 'exchange' ? 'exchange' : 'refund',
                    'return_reason' => $this->cases->issueTypeLabel($case->issue_type),
                    'remark' => "售后单 {$case->case_no}".(! empty($payload['remark']) ? '；'.$payload['remark'] : ''),
                    'items' => $items['return_items'],
                ], (int) $actor->legacy_id, (string) $actor->name);
                $action->update(['sales_return_id' => $salesReturn->id]);
                // 确认前先保存逐台关联，退货正式收货只能接收本次处理选中的设备。
                $salesReturn = $this->returns->confirm($salesReturn->id, (int) $actor->legacy_id, (string) $actor->name);
                // 换货在同一事务里生成待出库补发单：换回商品 = 退回商品（同物料同数量）。
                if ($type === 'exchange') {
                    $reshipRows = $items['case_item_map'] ? collect($items['action_items'])->map(fn ($r) => $items['case_item_map'][$r['case_item_id']] + [
                        'action_item_id' => $actionItemRows[$r['case_item_id']]->id, 'qty' => $r['return_qty'],
                    ])->all() : [];
                    if ($reshipRows) {
                        $reship = app(AftersalesReshipApplicationService::class)->createDraft($case, $action, $reshipRows, $actor);
                    }
                }
                $this->cases->log($case, 'action_add', null, 'processing',
                    "添加处理：{$this->cases->actionLabel($action)}，生成销售退货单 {$salesReturn->return_no}"
                    .($type === 'exchange' && isset($reship) ? "，补发单 {$reship->reship_no}（".($reshipTiming === 'ship_first' ? '先发后退' : '先退后发')."）" : ''), $actor, $action->id);
            }

            $this->cases->refreshStatus($case->fresh('actions'), $actor);
            return $action->fresh(['items', 'salesReturn']);
        }, 5);
    }

    /** 取消处理：仅当关联退货单仍是草稿/待收货且无任何到货时可取消，退货单一并作废。 */
    public function cancel(int $actionId, array $payload, object $actor): AftersalesAction
    {
        return DB::transaction(function () use ($actionId, $payload, $actor): AftersalesAction {
            $action = AftersalesAction::query()->findOrFail($actionId);
            $case = $this->cases->lockCase($action->case_id);
            $action = AftersalesAction::query()->with([
                'salesReturn' => fn ($query) => $query->lockForUpdate(),
                'salesReturn.items' => fn ($query) => $query->orderBy('id')->lockForUpdate(),
                'salesReturn.receipts' => fn ($query) => $query->orderBy('id')->lockForUpdate(),
            ])->lockForUpdate()->findOrFail($actionId);
            if ($action->status !== 'processing') {
                throw ValidationException::withMessages(['status' => '只有进行中的处理可以取消。']);
            }
            if (empty($payload['cancel_reason'])) {
                throw ValidationException::withMessages(['cancel_reason' => '取消处理必须填写原因。']);
            }
            $return = $action->salesReturn;
            if ($return && (! in_array($return->return_status, ['draft', 'pending_receipt'], true)
                || $return->receipts->isNotEmpty()
                || (float) $return->items->sum('received_base_qty') > 0)) {
                throw ValidationException::withMessages(['status' => '关联退货单已有到货或已过账，不能取消处理；请按退货单流程处置完成后关闭售后单。']);
            }
            $cancelledReships = app(AftersalesReshipApplicationService::class)->cancelForAction($action, $payload['cancel_reason']);
            // 先落地处理取消，退货单作废触发的 syncFromSalesReturn 就不会再改写本处理。
            $action->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $payload['cancel_reason']]);
            if ($return) {
                $this->returns->cancel($return->id, (int) $actor->legacy_id, (string) $actor->name);
            }
            $this->cases->log($case, 'action_cancel', 'processing', 'cancelled',
                "取消处理：{$this->cases->actionLabel($action)}".($return ? "，退货单 {$return->return_no} 一并作废" : '')
                .($cancelledReships ? '，补发单 '.implode('、', $cancelledReships).' 一并作废' : ''), $actor, $action->id);
            $this->cases->refreshStatus($case->fresh('actions'), $actor);
            return $action->fresh(['salesReturn']);
        }, 5);
    }

    /**
     * 销售退货单状态变化后同步处理与售后单：completed/closed → 处理完成；
     * cancelled → 处理取消。单点入口，SalesReturnApplicationService 在各状态出口调用。
     */
    public function syncFromSalesReturn(SalesReturn $return): void
    {
        $action = AftersalesAction::query()->where('sales_return_id', $return->id)->first();
        if (! $action) return;
        $case = $this->cases->lockCase($action->case_id);
        $action = AftersalesAction::query()->whereKey($action->id)->lockForUpdate()->firstOrFail();
        $synced = false;
        if (in_array($return->return_status, ['completed', 'closed'], true) && $action->status === 'processing') {
            // 完成与否由 syncCompletion 统一判定：换货还要等补发出库。
            $this->syncCompletion($action->id, (object) ['legacy_id' => $return->updated_by ?? $return->created_by ?? 0, 'name' => '系统']);
            $synced = true;
        } elseif ($return->return_status === 'cancelled') {
            // 与退货取消同一事务：已出库则抛错回滚退货取消，未出库则连同处理一起作废。
            $cancelledReships = app(AftersalesReshipApplicationService::class)->cancelForAction($action, '退货单作废');
            if ($action->status === 'processing') {
                $action->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => '退货单作废']);
                $this->cases->log($case, 'action_cancel', 'processing', 'cancelled',
                    "处理取消：{$this->cases->actionLabel($action)}，退货单 {$return->return_no} 已作废"
                    .($cancelledReships ? '，补发单 '.implode('、', $cancelledReships).' 一并作废' : ''),
                    (object) ['legacy_id' => $return->cancelled_by ?? $return->updated_by ?? $return->created_by ?? 0, 'name' => '系统'], $action->id);
                $synced = true;
            }
        }
        if ($synced) {
            $this->cases->refreshStatus($case->fresh('actions'), (object) ['legacy_id' => 0, 'name' => '系统']);
        }
    }

    /**
     * 处理完成判定的唯一入口：退货退款=退货单 completed/closed；换货=退货单完成且补发全部出库；
     * 补发=补发单全部出库；仅记录在添加时即完成。退货状态同步与补发出库都汇聚到这里。
     */
    public function syncCompletion(int $actionId, object $actor): void
    {
        $action = AftersalesAction::query()->find($actionId);
        if (! $action) return;
        $case = $this->cases->lockCase($action->case_id);
        $action = AftersalesAction::query()->with([
            'salesReturn' => fn ($query) => $query->lockForUpdate(),
            'reships' => fn ($query) => $query->orderBy('id')->lockForUpdate(),
        ])->lockForUpdate()->findOrFail($actionId);
        $action->setRelation('case', $case);
        if ($action->status !== 'processing') return;
        $returnDone = in_array($action->salesReturn?->return_status, ['completed', 'closed'], true);
        $reshipsDone = $action->reships->isNotEmpty() && $action->reships->every(fn ($r) => $r->status === 'shipped');
        $done = match ($action->action_type) {
            'refund' => $returnDone,
            'exchange' => $returnDone && $reshipsDone,
            'reship' => $reshipsDone,
            default => false,
        };
        if (! $done) return;
        $action->update(['status' => 'completed', 'completed_at' => now()]);
        $this->cases->log($action->case, 'action_done', 'processing', 'completed',
            "处理完成：{$this->cases->actionLabel($action)}", $actor, $action->id);
        $this->cases->refreshStatus($action->case->fresh('actions'), $actor);
    }

    /**
     * 本次处理的退回明细：按售后商品行取数，数量不超过该行剩余未处理数量；
     * 销售退货单按订单行汇总（一行售后商品行 qty=1/台，普通行合并成一行）。
     */
    private function assertActionItems(AftersalesCase $case, array $rows): array
    {
        $caseItems = $case->items()->lockForUpdate()->get()->keyBy('id');
        $existing = AftersalesActionItem::query()->join('erp_aftersales_actions', 'erp_aftersales_actions.id', '=', 'erp_aftersales_action_items.action_id')
            ->where('erp_aftersales_actions.case_id', $case->id)->where('erp_aftersales_actions.status', '!=', 'cancelled')
            ->groupBy('erp_aftersales_action_items.case_item_id')->pluck(DB::raw('SUM(erp_aftersales_action_items.return_qty)'), 'erp_aftersales_action_items.case_item_id');
        $returnItems = [];
        $actionItems = [];
        $caseItemMap = [];
        $reshipped = AftersalesReshipApplicationService::reshippedSerialIds($case->sales_order_id);
        foreach ($rows as $row) {
            $caseItem = $caseItems->get((int) ($row['case_item_id'] ?? 0));
            if (! $caseItem) throw ValidationException::withMessages(['items' => '处理明细必须来自本售后单的涉及商品。']);
            // 补发回来的设备成本链与原销售出库不同，退货单不会算——只允许仅记录/补发。
            if ($caseItem->inventory_serial_id && in_array((int) $caseItem->inventory_serial_id, $reshipped, true)) {
                throw ValidationException::withMessages(['items' => "序列号 {$caseItem->serial_no} 是换货补发的设备，暂不支持再次退货或换货，可登记仅记录或补发。"]);
            }
            $qty = round((float) ($row['return_qty'] ?? 0), 8);
            $left = (float) $caseItem->qty - (float) ($existing[$caseItem->id] ?? 0);
            if ($qty <= 0 || $qty > $left + 0.00000001) {
                throw ValidationException::withMessages(['items' => "{$caseItem->item_name}".($caseItem->serial_no ? "（{$caseItem->serial_no}）" : '')."：退回数量 {$qty} 超过剩余可处理数量 {$left}。"]);
            }
            if ($caseItem->inventory_serial_id && abs($qty - (float) $caseItem->qty) > 0.00000001) {
                throw ValidationException::withMessages(['items' => "序列号设备 {$caseItem->serial_no} 必须整台退回。"]);
            }
            $existing[$caseItem->id] = (float) ($existing[$caseItem->id] ?? 0) + $qty;
            $actionItems[] = ['case_item_id' => $caseItem->id, 'return_qty' => $qty];
            $caseItemMap[$caseItem->id] = [
                'sales_order_line_id' => $caseItem->sales_order_line_id, 'item_id' => $caseItem->item_id,
                'item_name' => $caseItem->item_name, 'sku_name' => $caseItem->sku_name,
            ];
            $returnItems[$caseItem->sales_order_line_id] = ($returnItems[$caseItem->sales_order_line_id] ?? 0) + $qty;
        }
        if (! $returnItems) throw ValidationException::withMessages(['items' => '退货退款/换货至少需要一件退回商品。']);
        return [
            'return_items' => collect($returnItems)->map(fn ($qty, $lineId) => ['sales_order_line_id' => (int) $lineId, 'requested_sales_qty' => $qty])->values()->all(),
            'action_items' => $actionItems,
            'case_item_map' => $caseItemMap,
        ];
    }

    private function compensationMethodLabel(string $method): string
    {
        return match ($method) {
            'cash' => '现金', 'wechat' => '微信', 'alipay' => '支付宝',
            'bank_transfer' => '银行转账', default => '其他',
        };
    }

    /** 独立补发：任选物料+数量；case_item_id 只作关联参考，不占退货额度。 */
    private function assertReshipItems(AftersalesCase $case, array $rows): array
    {
        if (! $rows) throw ValidationException::withMessages(['reship_items' => '补发至少需要一条物料。']);
        $caseItems = $case->items()->pluck('id')->all();
        $itemIds = collect($rows)->pluck('item_id')->map(fn ($id) => (int) $id)->unique()->all();
        $items = \App\Models\Erp\Item::query()->whereIn('id', $itemIds)->get()->keyBy('id');
        $out = [];
        foreach ($rows as $row) {
            $item = $items->get((int) ($row['item_id'] ?? 0));
            if (! $item) throw ValidationException::withMessages(['reship_items' => '补发物料不存在。']);
            $qty = round((float) ($row['qty'] ?? 0), 8);
            if ($qty <= 0) throw ValidationException::withMessages(['reship_items' => "{$item->item_name}：补发数量必须大于 0。"]);
            $caseItemId = (int) ($row['case_item_id'] ?? 0);
            if ($caseItemId && ! in_array($caseItemId, $caseItems, true)) {
                throw ValidationException::withMessages(['reship_items' => '关联的售后商品行不属于本售后单。']);
            }
            $out[] = [
                'item_id' => (int) $item->id, 'item_name' => $item->item_name, 'sku_name' => null,
                'qty' => $qty, 'unit_id' => $item->unit_id,
                'action_item_id' => null,
                'sales_order_line_id' => $caseItemId ? (int) ($case->items()->find($caseItemId)?->sales_order_line_id) : null,
            ];
        }
        return $out;
    }
}
