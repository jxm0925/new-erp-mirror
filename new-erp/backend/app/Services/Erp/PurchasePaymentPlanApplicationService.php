<?php

namespace App\Services\Erp;

use App\Domain\Finance\Money;
use App\Models\Erp\FinanceCashPurchaseAllocation;
use App\Models\Erp\PurchaseLog;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchasePaymentPlanItem;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class PurchasePaymentPlanApplicationService
{
    public const TRIGGERS = ['deposit', 'before_shipment', 'after_receipt', 'monthly', 'agreed_date', 'to_be_agreed'];

    public function __construct(private readonly PurchasePaymentPlanQueryService $query) {}

    public static function rules(): array
    {
        return [
            'version' => 'required|integer|min:0', 'items' => 'present|array|max:100',
            'items.*.id' => 'nullable|integer|min:1|distinct', 'items.*.title' => 'nullable|string|max:120',
            'items.*.trigger_type' => 'required|in:'.implode(',', self::TRIGGERS),
            'items.*.amount' => ['required', 'regex:/^\d{1,14}(\.\d{1,4})?$/'],
            'items.*.due_date' => 'nullable|date_format:Y-m-d', 'items.*.remark' => 'nullable|string|max:1000',
        ];
    }

    public function save(int $orderId, array $data, ?int $operatorId, ?string $operatorName): array
    {
        Validator::make($data, self::rules())->validate();
        DB::transaction(function () use ($orderId, $data, $operatorId, $operatorName): void {
            $order = PurchaseOrder::query()->lockForUpdate()->findOrFail($orderId);
            if ($order->purchase_status === 'cancelled') throw ValidationException::withMessages(['status' => '已取消采购订单不能修改付款安排。']);
            if ((int) $order->payment_plan_version !== (int) $data['version']) throw ValidationException::withMessages(['version' => '付款安排已被其他操作修改，请刷新后重试。']);
            $existing = PurchasePaymentPlanItem::query()->where('purchase_order_id', $orderId)->where('status', 'active')->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $before = $existing->values()->toArray();
            $uses = FinanceCashPurchaseAllocation::query()
                ->join('erp_finance_cash_documents as cash', 'cash.id', '=', 'erp_finance_cash_purchase_allocations.cash_document_id')
                ->where('erp_finance_cash_purchase_allocations.purchase_order_id', $orderId)
                ->where('erp_finance_cash_purchase_allocations.status', 'active')->where('cash.status', 'confirmed')
                ->whereNotNull('erp_finance_cash_purchase_allocations.payment_plan_id')->lockForUpdate()
                ->get(['erp_finance_cash_purchase_allocations.*', 'cash.direction as cash_direction']);
            $total = '0.0000';
            $kept = [];
            foreach ($data['items'] as $index => $input) {
                $amount = Money::normalize((string) $input['amount']);
                if (Money::compare($amount, '0') <= 0) throw ValidationException::withMessages(['items' => '每期安排金额必须大于 0。']);
                $item = isset($input['id']) ? $existing->get((int) $input['id']) : null;
                if (isset($input['id']) && ! $item) throw ValidationException::withMessages(['items' => '付款期次已变化或不属于该订单，请刷新后重试。']);
                if ($item && Money::compare($amount, $item->amount) < 0) {
                    $net = $uses->where('payment_plan_id', $item->id)->reduce(fn (string $sum, $row) => $row->cash_direction === 'payment' ? Money::add($sum, $row->amount) : Money::sub($sum, $row->amount), '0.0000');
                    if (Money::compare($amount, $net) < 0) throw ValidationException::withMessages(['items' => '付款安排金额不能调低到该期已确认净付款金额以下。']);
                }
                $item ??= new PurchasePaymentPlanItem(['purchase_order_id' => $orderId, 'created_by' => $operatorId]);
                $item->fill(['sequence_no' => $index + 1, 'title' => trim((string) ($input['title'] ?? '')) ?: null,
                    'trigger_type' => $input['trigger_type'], 'currency' => $order->currency ?: 'CNY', 'amount' => $amount,
                    'due_date' => $input['due_date'] ?? null, 'remark' => $input['remark'] ?? null, 'status' => 'active', 'updated_by' => $operatorId]);
                $item->save();
                $kept[] = $item->id;
                $total = Money::add($total, $amount);
            }
            if (Money::compare($total, FinanceCashPurchaseAllocationApplicationService::contractAmount($order)) > 0) throw ValidationException::withMessages(['items' => '付款安排合计不能超过采购合同金额；可保留尚未安排的余款。']);
            $removed = $existing->except($kept);
            $removedIds = $removed->pluck('id')->all();
            if ($uses->whereIn('payment_plan_id', $removedIds)->isNotEmpty()) throw ValidationException::withMessages(['items' => '已有正式付款或退款用途的期次必须保留，不能移除。']);
            PurchasePaymentPlanItem::query()->whereIn('id', $removedIds)->update(['status' => 'cancelled', 'updated_by' => $operatorId]);
            $version = (int) $order->payment_plan_version + 1;
            $order->update(['payment_plan_version' => $version]);
            PurchaseLog::create([
                'target_type' => 'purchase_order', 'target_id' => $orderId, 'action' => 'update_payment_plan',
                'content' => '维护付款安排；不生成应付、资金单或扣款。', 'operator' => $operatorName,
                'evidence' => ['operator_id' => $operatorId, 'version' => $version, 'currency' => $order->currency ?: 'CNY',
                    'before' => $before, 'after' => PurchasePaymentPlanItem::query()->whereIn('id', $kept)->orderBy('sequence_no')->get()->toArray()],
            ]);
        }, 5);
        return $this->query->forOrder($orderId);
    }
}
