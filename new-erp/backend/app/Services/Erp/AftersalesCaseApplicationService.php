<?php

namespace App\Services\Erp;

use App\Models\Erp\AftersalesAction;
use App\Models\Erp\AftersalesCase;
use App\Models\Erp\AftersalesCaseItem;
use App\Models\Erp\AftersalesLog;
use App\Models\Erp\InventorySerial;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\SalesOrderLine;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 售后单写入口径：全部走订单行锁 + 售后单行锁，落 erp_aftersales_logs。
 * 状态机：pending → processing → ready_to_close（全部处理完成/取消且至少一个完成）→ closed；
 * pending/processing/ready_to_close 下没有任何已发生的库存或资金动作时可取消。
 */
final class AftersalesCaseApplicationService
{
    private const ISSUE_TYPES = ['quality', 'transport_damage', 'wrong_shipment', 'installation', 'consultation', 'other'];
    private const ACTIVE = ['pending', 'processing', 'ready_to_close'];
    private const SKIP_LINE_TYPES = ['service', 'no_delivery', 'fee', 'auxiliary', 'deduction'];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly AuthContextService $auth,
    ) {}

    /** 候选与保存共用数量、逐台占用口径；写入调用方须已锁订单。 */
    public static function quantitiesForCase(SalesOrderLine $line, ?int $excludeCaseId = null): array
    {
        $returns = DB::table('erp_sales_return_items')
            ->join('erp_sales_returns', 'erp_sales_returns.id', '=', 'erp_sales_return_items.sales_return_id')
            ->where('erp_sales_return_items.sales_order_line_id', $line->id)
            ->where('erp_sales_returns.return_status', '!=', 'cancelled')
            ->get(['erp_sales_return_items.id', 'erp_sales_return_items.sales_return_id',
                'erp_sales_return_items.requested_sales_qty', 'erp_sales_return_items.requested_base_qty',
                'erp_sales_return_items.received_base_qty', 'erp_sales_returns.return_status']);
        $returnQuantities = [];
        foreach ($returns as $row) {
            // 退货关闭只释放未收余量；已收到货仍按本退货行冻结的销售/基本单位比例占用。
            $qty = $row->return_status === 'closed'
                ? ((float) $row->requested_base_qty > 0
                    ? min((float) $row->requested_sales_qty, (float) $row->received_base_qty / (float) $row->requested_base_qty * (float) $row->requested_sales_qty) : 0)
                : (float) $row->requested_sales_qty;
            $returnQuantities[$row->sales_return_id] = ($returnQuantities[$row->sales_return_id] ?? 0.0) + $qty;
        }
        $coveredSerials = DB::table('erp_sales_return_serial_identities')->whereIn('sales_return_item_id', $returns->pluck('id'))
            ->pluck('inventory_serial_id')->flip();
        $openReturnIds = $returns->where('return_status', '!=', 'closed')->pluck('sales_return_id')->all();
        $cases = AftersalesCase::query()->with([
            'items' => fn ($query) => $query->where('sales_order_line_id', $line->id), 'actions.items',
        ])->where('sales_order_id', $line->sales_order_id)->whereIn('status', [...self::ACTIVE, 'closed'])
            ->when($excludeCaseId, fn ($query) => $query->where('id', '<>', $excludeCaseId))->get();
        $quantities = ['returned_qty' => array_sum($returnQuantities), 'in_aftersales_qty' => 0.0, 'completed_aftersales_qty' => 0.0];
        $serialOccupations = [];
        foreach ($cases as $case) {
            $items = $case->items->keyBy('id');
            $plainQty = (float) $items->whereNull('inventory_serial_id')->sum('qty');
            $covered = 0.0;
            foreach ($case->actions as $action) {
                $covered += $returnQuantities[$action->sales_return_id] ?? 0.0;
                if (! in_array($action->sales_return_id, $openReturnIds)) continue;
                foreach ($action->items as $actionItem) {
                    $serialId = $items->get($actionItem->case_item_id)?->inventory_serial_id;
                    if ($serialId && (float) $actionItem->return_qty > 0) $coveredSerials->put($serialId, true);
                }
            }
            $bucket = $case->status === 'closed' ? 'completed_aftersales_qty' : 'in_aftersales_qty';
            $quantities[$bucket] += max(0, $plainQty - $covered);
            foreach ($items->whereNotNull('inventory_serial_id') as $item) {
                $serialId = (int) $item->inventory_serial_id;
                $occupation = $serialOccupations[$serialId] ?? ['in_case' => false, 'completed_case' => false, 'case_no' => $case->case_no];
                if ($case->status === 'closed') {
                    $occupation['completed_case'] = true;
                } else {
                    $occupation['in_case'] = true;
                    $occupation['case_no'] = $case->case_no;
                }
                $serialOccupations[$serialId] = $occupation;
            }
        }
        // 历史上同一 SN 可能办理过多次售后，只占一台；已由退货占用的 SN 不再重复扣减。
        foreach ($serialOccupations as $serialId => $occupation) {
            if ($coveredSerials->has($serialId)) continue;
            $quantities[$occupation['in_case'] ? 'in_aftersales_qty' : 'completed_aftersales_qty'] += 1;
        }
        $quantities['reshipped_qty'] = AftersalesReshipApplicationService::reshippedQuantity((int) $line->sales_order_id, (int) $line->id);
        $quantities['available_qty'] = max(0, (float) $line->shipped_qty + $quantities['reshipped_qty']
            - $quantities['returned_qty'] - $quantities['in_aftersales_qty'] - $quantities['completed_aftersales_qty']);
        $quantities['serial_occupations'] = $serialOccupations;
        return $quantities;
    }

    public static function isEligibleLine(SalesOrderLine $line): bool
    {
        return $line->item_id && ! in_array($line->line_type, self::SKIP_LINE_TYPES, true) && (float) $line->shipped_qty > 0;
    }

    public function create(array $payload, object $actor): AftersalesCase
    {
        return DB::transaction(function () use ($payload, $actor): AftersalesCase {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail((int) $payload['sales_order_id']);
            $order->assertCurrentBusiness();
            $order->setRelation('lines', $order->lines()->orderBy('id')->lockForUpdate()->get());
            $this->assertCaseFields($payload);
            $items = $this->assertItems($order, $payload['items'] ?? []);
            $caseNo = ! empty($payload['reservation_token'])
                ? $this->numbers->reservedNumber($payload['reservation_token'], 'aftersales_case', (int) $actor->legacy_id, $payload['creation_session_id'] ?? null)
                : $this->numbers->next('aftersales_case', 'AS');
            $case = AftersalesCase::create([
                'case_no' => $caseNo,
                'sales_order_id' => $order->id,
                'customer_id' => $order->customer_id,
                'customer_snapshot' => [
                    'name' => $order->customer_name ?? $order->customer?->customer_name,
                    'phone' => $order->customer?->contact_phone ?? $order->receiver_phone ?? null,
                    'receiver' => $order->receiver_name ?? null,
                    'address' => $order->receiver_address ?? null,
                ],
                'owner_legacy_id' => (int) ($payload['owner_legacy_id'] ?? $actor->legacy_id),
                'owner_name' => $payload['owner_name'] ?? (string) $actor->name,
                'issue_type' => $payload['issue_type'],
                'warranty_status' => $payload['warranty_status'] ?? 'in_warranty',
                'responsibility' => $payload['responsibility'] ?? null,
                'reported_at' => $payload['reported_at'],
                'description' => $payload['description'],
                'status' => 'pending',
                'created_by_legacy_id' => (int) $actor->legacy_id,
            ]);
            foreach ($items as $row) {
                AftersalesCaseItem::create($row + ['case_id' => $case->id]);
            }
            app(AftersalesAttachmentService::class)->bindDraft($case->id, $payload['attachment_draft_token'] ?? null, $actor);
            if (! empty($payload['reservation_token'])) {
                $this->numbers->consume($payload['reservation_token'], 'aftersales_case', $caseNo, (int) $actor->legacy_id, 'aftersales_case', $case->id);
            }
            $this->log($case, 'create', null, 'pending', '新建售后单', $actor);
            if (! empty($payload['accept'])) {
                $case->update(['status' => 'processing', 'accepted_by_legacy_id' => (int) $actor->legacy_id, 'accepted_at' => now()]);
                $this->log($case, 'accept', 'pending', 'processing', '新建即受理', $actor);
            }
            return $case->fresh(['items', 'actions']);
        }, 5);
    }

    public function update(int $id, array $payload, object $actor): AftersalesCase
    {
        return DB::transaction(function () use ($id, $payload, $actor): AftersalesCase {
            $case = $this->lockCase($id);
            if (! in_array($case->status, ['pending', 'processing'], true)) {
                throw ValidationException::withMessages(['status' => '只有待受理或处理中的售后单可以编辑。']);
            }
            $order = SalesOrder::query()->lockForUpdate()->findOrFail((int) $case->sales_order_id);
            $order->setRelation('lines', $order->lines()->orderBy('id')->lockForUpdate()->get());
            $this->assertCaseFields($payload, true);
            if (array_key_exists('items', $payload)) {
                $hasActions = $case->actions()->where('status', '!=', 'cancelled')->exists();
                if ($hasActions) throw ValidationException::withMessages(['items' => '已有处理使用的售后单不能改涉及商品，请先取消处理。']);
                AftersalesCaseItem::query()->where('case_id', $case->id)->delete();
                foreach ($this->assertItems($order, $payload['items'] ?? [], $case->id) as $row) {
                    AftersalesCaseItem::create($row + ['case_id' => $case->id]);
                }
            }
            $case->update(array_filter([
                'owner_legacy_id' => $payload['owner_legacy_id'] ?? null,
                'owner_name' => $payload['owner_name'] ?? null,
                'issue_type' => $payload['issue_type'] ?? null,
                'warranty_status' => $payload['warranty_status'] ?? null,
                'responsibility' => array_key_exists('responsibility', $payload) ? $payload['responsibility'] : '',
                'reported_at' => $payload['reported_at'] ?? null,
                'description' => $payload['description'] ?? null,
            ], fn ($v) => $v !== '') + ['updated_by_legacy_id' => (int) $actor->legacy_id]);
            $this->log($case, 'update', null, null, '编辑售后单', $actor);
            return $case->fresh(['items', 'actions']);
        }, 5);
    }

    public function accept(int $id, object $actor): AftersalesCase
    {
        return DB::transaction(function () use ($id, $actor): AftersalesCase {
            $case = $this->lockCase($id);
            if ($case->status !== 'pending') throw ValidationException::withMessages(['status' => '只有待受理的售后单可以受理。']);
            $case->update(['status' => 'processing', 'accepted_by_legacy_id' => (int) $actor->legacy_id, 'accepted_at' => now()]);
            $this->log($case, 'accept', 'pending', 'processing', '受理售后单', $actor);
            return $case;
        }, 5);
    }

    public function cancel(int $id, array $payload, object $actor): AftersalesCase
    {
        return DB::transaction(function () use ($id, $payload, $actor): AftersalesCase {
            $case = $this->lockCase($id);
            if (! in_array($case->status, self::ACTIVE, true)) {
                throw ValidationException::withMessages(['status' => '当前状态不能取消。']);
            }
            if (empty($payload['cancel_reason'])) throw ValidationException::withMessages(['cancel_reason' => '取消售后单必须填写原因。']);
            $actions = $case->actions()->orderBy('id')->lockForUpdate()->get();
            $returnFacts = $actions->load(['salesReturn' => fn ($query) => $query->lockForUpdate()])
                ->contains(fn ($a) => $a->salesReturn && $a->salesReturn->return_status !== 'cancelled');
            if ($returnFacts) throw ValidationException::withMessages(['status' => '已有处理产生了退货单，不能整单取消；请逐个完成或取消处理后再关闭。']);
            $reships = app(AftersalesReshipApplicationService::class);
            foreach ($actions as $action) {
                $reships->cancelForAction($action, $payload['cancel_reason']);
            }
            $from = $case->status;
            $case->actions()->where('status', 'processing')->update(['status' => 'cancelled', 'cancelled_at' => now(), 'cancel_reason' => $payload['cancel_reason']]);
            $case->update(['status' => 'cancelled', 'cancel_reason' => $payload['cancel_reason'],
                'cancelled_by_legacy_id' => (int) $actor->legacy_id, 'cancelled_at' => now()]);
            $this->log($case, 'cancel', $from, 'cancelled', '取消售后单：'.$payload['cancel_reason'], $actor);
            return $case;
        }, 5);
    }

    public function close(int $id, array $payload, object $actor): AftersalesCase
    {
        return DB::transaction(function () use ($id, $payload, $actor): AftersalesCase {
            $case = $this->lockCase($id);
            if (! in_array($case->status, ['processing', 'ready_to_close'], true)) {
                throw ValidationException::withMessages(['status' => '只有处理中或待关闭的售后单可以关闭。']);
            }
            $open = $case->actions()->where('status', 'processing')->with('salesReturn')->get();
            if ($open->isNotEmpty()) {
                $names = $open->map(fn ($a) => $this->actionLabel($a).'（处理中）')->implode('、');
                throw ValidationException::withMessages(['status' => "还有未完成处理：{$names}。请先完成或取消。"]);
            }
            $result = trim((string) ($payload['result'] ?? ''));
            $closeRemark = trim((string) ($payload['close_remark'] ?? ''));
            $responsibility = trim((string) ($payload['responsibility'] ?? ''));
            $errors = [];
            if ($result === '') $errors[] = '处理结果必填';
            if ($closeRemark === '') $errors[] = '关闭说明必填';
            if ($responsibility === '') $errors[] = '责任方必填';
            if ($errors) throw ValidationException::withMessages(['close' => $errors]);
            $from = $case->status;
            $case->update(['status' => 'closed', 'result' => $result, 'responsibility' => $responsibility,
                'customer_confirmed' => array_key_exists('customer_confirmed', $payload) ? (int) $payload['customer_confirmed'] : null,
                'close_remark' => $closeRemark, 'closed_by_legacy_id' => (int) $actor->legacy_id, 'closed_at' => now()]);
            $this->log($case, 'close', $from, 'closed', '关闭售后单：'.$result, $actor);
            return $case;
        }, 5);
    }

    /** 受理后重算自动状态：全部处理完成/取消且有完成 → ready_to_close；新增/取消处理回到 processing。 */
    public function refreshStatus(AftersalesCase $case, object $actor): void
    {
        if (! in_array($case->status, ['processing', 'ready_to_close'], true)) return;
        $actions = $case->actions()->get();
        $allDone = $actions->isNotEmpty() && $actions->every(fn ($a) => in_array($a->status, ['completed', 'cancelled'], true))
            && $actions->contains(fn ($a) => $a->status === 'completed');
        $to = $allDone ? 'ready_to_close' : 'processing';
        if ($to !== $case->status) {
            $from = $case->status;
            $case->update(['status' => $to]);
            $this->log($case, 'status', $from, $to,
                $allDone ? '全部处理完成，转入待关闭' : '处理状态变化，转回处理中', $actor);
        }
    }

    public function actionLabel(AftersalesAction $action): string
    {
        return match ($action->action_type) {
            'refund' => '退货退款', 'exchange' => '换货', 'repair' => '返修', 'reship' => '补发', 'record' => '仅记录', 'compensation' => '赔付记录', default => $action->action_type,
        };
    }

    public function issueTypeLabel(string $type): string
    {
        return match ($type) {
            'quality' => '质量故障', 'transport_damage' => '运输破损', 'wrong_shipment' => '错发漏发',
            'installation' => '安装问题', 'consultation' => '使用咨询', 'other' => '其他', default => $type,
        };
    }

    public function log(AftersalesCase $case, string $event, ?string $from, ?string $to, string $content, object $actor, ?int $actionId = null, ?int $claimId = null): void
    {
        AftersalesLog::create(['case_id' => $case->id, 'action_id' => $actionId, 'claim_id' => $claimId, 'event' => $event,
            'from_status' => $from, 'to_status' => $to, 'content' => $content,
            'operator_legacy_id' => (int) $actor->legacy_id, 'operator_name' => (string) $actor->name]);
    }

    public function lockCase(int $id): AftersalesCase
    {
        // 订单是退货与售后的共同根锁，统一先锁订单，避免取消、补发和退货反向等待。
        $case = AftersalesCase::query()->findOrFail($id);
        SalesOrder::query()->whereKey($case->sales_order_id)->lockForUpdate()->firstOrFail();
        return AftersalesCase::query()->whereKey($id)->lockForUpdate()->firstOrFail();
    }

    private function assertCaseFields(array $payload, bool $partial = false): void
    {
        $issueType = $payload['issue_type'] ?? null;
        if (! $partial && ! in_array($issueType, self::ISSUE_TYPES, true)) throw ValidationException::withMessages(['issue_type' => '问题类型不正确。']);
        if ($issueType !== null && ! in_array($issueType, self::ISSUE_TYPES, true)) throw ValidationException::withMessages(['issue_type' => '问题类型不正确。']);
        if (! $partial) {
            if (empty($payload['description'])) throw ValidationException::withMessages(['description' => '问题描述必填。']);
            if (empty($payload['reported_at'])) throw ValidationException::withMessages(['reported_at' => '客户反馈时间必填。']);
        }
        if (isset($payload['warranty_status']) && ! in_array($payload['warranty_status'], ['in_warranty', 'out_of_warranty', 'pending_inspection'], true)) {
            throw ValidationException::withMessages(['warranty_status' => '保修判断不正确。']);
        }
    }

    /**
     * 校验并组装售后商品行：数量不超可售后；序列号商品逐台选且必须选满 qty 台；
     * 已完成售后的原设备保留占用，取消才释放；补发的新设备按自己的 SN 另算。
     */
    private function assertItems(SalesOrder $order, array $rows, ?int $excludeCaseId = null): array
    {
        $byLine = collect($rows)->groupBy(fn ($r) => (int) ($r['sales_order_line_id'] ?? 0));
        $items = [];
        $selectedSerials = [];
        foreach ($byLine as $lineId => $group) {
            // lines 是按 id 排序的普通集合（下标从 0 开始），必须按行 id 查找，不能用 get($id)。
            $line = $order->lines->firstWhere('id', (int) $lineId);
            if (! $line || ! self::isEligibleLine($line)) {
                throw ValidationException::withMessages(['items' => "订单行 #{$lineId} 不能售后（未发货或不可退货类型）。"]);
            }
            $serials = collect($group)->whereNotNull('inventory_serial_id')->values();
            $plainQty = (float) collect($group)->whereNull('inventory_serial_id')->sum('qty');
            $serialQty = $serials->count();
            $line->loadMissing('item');
            if ($line->item?->serialTrackingMode() === 'required' && ($serialQty === 0 || $plainQty > 0)) {
                throw ValidationException::withMessages(['items' => "{$line->item_name}：序列号设备必须逐台选择设备编号。"]);
            }
            $qty = $plainQty + $serialQty;
            $quantities = self::quantitiesForCase($line, $excludeCaseId);
            $available = $quantities['available_qty'];
            if ($qty <= 0 || $qty > $available + 0.00000001) {
                throw ValidationException::withMessages(['items' => "{$line->item_name}：本次数量 {$qty} 超过可售后数量 {$available}（已发货 {$line->shipped_qty}）。"]);
            }
            if ($serials->isNotEmpty()) {
                $shippedIds = $this->shippedSerialIds($line);
                $serialModels = InventorySerial::query()->whereIn('id', $serials->pluck('inventory_serial_id'))
                    ->where('item_id', $line->item_id)->where('serial_status', 'shipped')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
                if ($plainQty != 0) throw ValidationException::withMessages(['items' => "{$line->item_name}：序列号设备的数量必须与所选台数一致。"]);
                foreach ($serials as $r) {
                    $serialId = (int) $r['inventory_serial_id'];
                    if (! in_array($serialId, $shippedIds, true) || ! $serialModels->has($serialId)) {
                        throw ValidationException::withMessages(['items' => '所选序列号不是本订单行仍在客户处的设备。']);
                    }
                    $occupation = $quantities['serial_occupations'][$serialId] ?? null;
                    if ($occupation) {
                        $state = $occupation['in_case'] ? '售后中' : '已完成售后';
                        $serialNo = $serialModels->get($serialId)->serial_no;
                        throw ValidationException::withMessages(['items' => "序列号 {$serialNo} 已在售后单 {$occupation['case_no']}（{$state}）中，不能重复选择原设备。"]);
                    }
                    if (isset($selectedSerials[$serialId])) throw ValidationException::withMessages(['items' => '同一设备编号不能重复选择。']);
                    $selectedSerials[$serialId] = true;
                }
            }
            $lineModel = [
                'sales_order_line_id' => $line->id, 'item_id' => $line->item_id,
                'product_name' => $line->product_name, 'sku_name' => $line->sku_name, 'item_name' => $line->item_name,
            ];
            if ($serials->isNotEmpty()) {
                foreach ($serials as $r) {
                    $items[] = $lineModel + ['qty' => 1, 'inventory_serial_id' => (int) $r['inventory_serial_id'],
                        'serial_no' => $serialModels->get((int) $r['inventory_serial_id'])->serial_no, 'remark' => $r['remark'] ?? null];
                }
            } else {
                $items[] = $lineModel + ['qty' => $qty, 'inventory_serial_id' => null, 'serial_no' => null,
                    'remark' => $group->first()['remark'] ?? null];
            }
        }
        if (! $items) throw ValidationException::withMessages(['items' => '售后单至少需要一件涉及商品。']);
        return $items;
    }

    /** 本订单行可售后的序列号：原发货 + 售后补发出去的、仍在客户处（serial_status = shipped）的设备。 */
    public function shippedSerialIds(SalesOrderLine $line): array
    {
        // 只有仍在客户处（serial_status = shipped）的序列号才能售后；已退回/已处置的不算。
        $shippedIds = DB::table('erp_sales_shipment_lines')->where('sales_order_line_id', $line->id)
            ->whereNotNull('serial_snapshot')->get(['serial_snapshot'])
            ->flatMap(fn ($r) => json_decode((string) $r->serial_snapshot, true)['inventory_serial_ids'] ?? [])
            ->map(fn ($id) => (int) $id)
            ->merge(AftersalesReshipApplicationService::reshippedSerialIds((int) $line->sales_order_id, (int) $line->id))
            ->unique()->values()->all();
        if (! $shippedIds) return [];
        return DB::table('erp_inventory_serials')->whereIn('id', $shippedIds)
            ->where('serial_status', 'shipped')->pluck('id')->map(fn ($id) => (int) $id)->all();
    }
}
