<?php

namespace App\Services\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Models\Erp\FinanceAttachment;
use App\Models\Erp\FinanceInvoice;
use App\Models\Erp\FinanceInvoiceAllocation;
use App\Models\Erp\FinanceOperationLog;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesInvoiceRequest;
use App\Models\Erp\SalesOrder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * 销售开票申请：销售按订单申请给客户开票，财务开票后登记发票号，
 * 系统生成已确认的销项发票并关联到订单。申请金额不超过订单应收减去已开票和开票中的金额。
 */
class SalesInvoiceRequestService
{
    public const PENDING = 'pending';
    public const ISSUED = 'issued';
    public const REJECTED = 'rejected';
    public const WITHDRAWN = 'withdrawn';

    public const INVOICE_TYPES = ['vat_normal', 'vat_special'];

    private const BILLING_FIELDS = ['invoice_type', 'invoice_title', 'invoice_tax_no', 'invoice_address_phone', 'invoice_bank_account', 'invoice_email'];

    public function __construct(
        private readonly DocumentNumberService $numbers,
        private readonly FinancePartyResolver $parties,
        private readonly SalesFinanceSettlementService $settlements,
        private readonly FinanceAttachmentApplicationService $attachments,
    ) {}

    /** 订单开票汇总：应收、已开票、开票中、还能申请。 */
    public function summary(SalesOrder $order): array
    {
        $receivable = $this->settlements->receivableAmount($order);
        $invoiced = $this->invoicedAmount((int) $order->id);
        $pending = Money::normalize((string) SalesInvoiceRequest::query()
            ->where('sales_order_id', $order->id)->where('status', self::PENDING)->sum('amount'));
        return [
            'receivable_amount' => $receivable,
            'invoiced_amount' => $invoiced,
            'pending_amount' => $pending,
            'available_amount' => Money::maxZero(Money::sub(Money::sub($receivable, $invoiced), $pending)),
            'currency' => (string) ($order->currency ?: 'CNY'),
        ];
    }

    public function forOrder(SalesOrder $order): array
    {
        $customer = $order->customer_id ? SalesCustomer::find($order->customer_id) : null;
        $requests = SalesInvoiceRequest::query()->where('sales_order_id', $order->id)->latest('id')->get();
        return [
            'summary' => $this->summary($order),
            'can_request' => $this->requestBlocker($order) === null,
            'request_blocker' => $this->requestBlocker($order),
            'billing_defaults' => $this->billingDefaults($customer, $order),
            'requests' => $requests->map(fn (SalesInvoiceRequest $row) => $this->payload($row))->values(),
        ];
    }

    public function create(SalesOrder $order, array $data, object $user): SalesInvoiceRequest
    {
        return DB::transaction(function () use ($order, $data, $user): SalesInvoiceRequest {
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($order->id);
            if ($blocker = $this->requestBlocker($order)) throw ValidationException::withMessages(['sales_order_id' => $blocker]);
            $amount = Money::normalize((string) $data['amount']);
            $summary = $this->summary($order);
            if (Money::compare($amount, '0') <= 0) throw ValidationException::withMessages(['amount' => '开票金额必须大于 0。']);
            if (Money::compare($amount, $summary['available_amount']) > 0) {
                throw ValidationException::withMessages(['amount' => '开票金额超过可申请金额 '.$this->moneyText($summary['available_amount']).'（应收减去已开票和开票中的金额）。']);
            }
            $type = (string) $data['invoice_type'];
            if ($type === 'vat_special') {
                foreach (['invoice_tax_no' => '纳税人识别号', 'invoice_address_phone' => '地址电话', 'invoice_bank_account' => '开户行及账号'] as $field => $label) {
                    if (trim((string) ($data[$field] ?? '')) === '') throw ValidationException::withMessages([$field => '开专票必须填写'.$label.'。']);
                }
            }
            $billing = [];
            foreach (self::BILLING_FIELDS as $field) $billing[$field] = ($v = trim((string) ($data[$field] ?? ''))) === '' ? null : $v;
            $request = SalesInvoiceRequest::create([
                'request_no' => $this->numbers->next('sales_invoice_request', 'KP'),
                'sales_order_id' => $order->id, 'sales_order_no' => $order->sales_order_no,
                'customer_id' => $order->customer_id, 'customer_name' => $order->customer_name_snapshot ?: $order->customer_name,
                ...$billing,
                'amount' => $amount, 'tax_rate' => $data['tax_rate'] ?? 13, 'currency' => $summary['currency'],
                'remark' => $data['remark'] ?? null, 'status' => self::PENDING,
                'requested_by' => $user->legacy_id ?? null, 'requested_by_name' => $this->userName($user),
            ]);
            if (!empty($data['save_to_customer'])) {
                SalesCustomer::query()->whereKey($order->customer_id)->update($billing + ['updated_at' => now()]);
            }
            return $request;
        }, 5);
    }

    public function withdraw(int $id, object $user, bool $any = false): SalesInvoiceRequest
    {
        return DB::transaction(function () use ($id, $user, $any): SalesInvoiceRequest {
            $request = SalesInvoiceRequest::query()->lockForUpdate()->findOrFail($id);
            if ($request->status !== self::PENDING) throw ValidationException::withMessages(['status' => '只有待开票的申请可以撤回。']);
            if (!$any && (int) $request->requested_by !== (int) ($user->legacy_id ?? 0)) {
                throw ValidationException::withMessages(['status' => '只能撤回自己提交的开票申请。']);
            }
            $request->update(['status' => self::WITHDRAWN, 'handled_by' => $user->legacy_id ?? null, 'handled_by_name' => $this->userName($user), 'handled_at' => now()]);
            return $request->fresh();
        }, 5);
    }

    public function reject(int $id, string $reason, object $user): SalesInvoiceRequest
    {
        return DB::transaction(function () use ($id, $reason, $user): SalesInvoiceRequest {
            $request = SalesInvoiceRequest::query()->lockForUpdate()->findOrFail($id);
            if ($request->status !== self::PENDING) throw ValidationException::withMessages(['status' => '只有待开票的申请可以驳回。']);
            if (trim($reason) === '') throw ValidationException::withMessages(['reason' => '请填写驳回原因。']);
            $request->update(['status' => self::REJECTED, 'handle_remark' => trim($reason), 'handled_by' => $user->legacy_id ?? null, 'handled_by_name' => $this->userName($user), 'handled_at' => now()]);
            return $request->fresh();
        }, 5);
    }

    /** 财务开票登记：生成已确认的销项发票并关联到订单。 */
    public function issue(int $id, array $data, ?UploadedFile $file, object $user): SalesInvoiceRequest
    {
        $operatorId = $user->legacy_id ?? null;
        $request = DB::transaction(function () use ($id, $data, $operatorId, $user): SalesInvoiceRequest {
            $request = SalesInvoiceRequest::query()->lockForUpdate()->findOrFail($id);
            if ($request->status !== self::PENDING) throw ValidationException::withMessages(['status' => '只有待开票的申请可以登记开票。']);
            $order = SalesOrder::query()->lockForUpdate()->findOrFail($request->sales_order_id);
            if ($order->order_status === 'cancelled') throw ValidationException::withMessages(['status' => '订单已取消，请驳回该申请。']);
            $amount = Money::normalize((string) $request->amount);
            $room = Money::sub($this->settlements->receivableAmount($order), $this->invoicedAmount((int) $order->id));
            if (Money::compare($amount, $room) > 0) {
                throw ValidationException::withMessages(['amount' => '订单应收已变化，剩余可开票 '.$this->moneyText(Money::maxZero($room)).'，请驳回后让销售重新申请。']);
            }
            $invoiceNo = trim((string) $data['invoice_no']);
            if (FinanceInvoice::query()->where('invoice_direction', FinanceConstants::INVOICE_SALES)->where('invoice_no', $invoiceNo)
                ->whereIn('status', [FinanceConstants::STATUS_DRAFT, FinanceConstants::STATUS_CONFIRMED])->exists()) {
                throw ValidationException::withMessages(['invoice_no' => '发票号码 '.$invoiceNo.' 已登记过。']);
            }
            $rate = round((float) ($data['tax_rate'] ?? $request->tax_rate), 2);
            $excl = Money::normalize(number_format(round((float) $amount / (1 + $rate / 100), 2), 2, '.', ''));
            $tax = Money::sub($amount, $excl);
            $party = $this->parties->resolve(FinanceConstants::PARTY_CUSTOMER, (int) $request->customer_id);
            $invoice = FinanceInvoice::create([
                'invoice_direction' => FinanceConstants::INVOICE_SALES,
                'document_no' => $this->numbers->next('finance_invoice', 'FI'),
                'invoice_no' => $invoiceNo, 'invoice_code' => $data['invoice_code'] ?? null,
                'invoice_type' => $request->invoice_type, ...$party,
                'invoice_date' => $data['invoice_date'], 'received_date' => $data['invoice_date'],
                'currency' => $request->currency ?: 'CNY',
                'amount_excl_tax' => $excl, 'tax_amount' => $tax, 'amount_incl_tax' => $amount,
                'tax_detail' => [['tax_rate' => $rate, 'amount_excl_tax' => $excl, 'tax_amount' => $tax]],
                'remark' => trim('开票申请 '.$request->request_no.'；抬头：'.$request->invoice_title.($request->invoice_tax_no ? '；税号：'.$request->invoice_tax_no : '')),
                'status' => FinanceConstants::STATUS_CONFIRMED,
                'created_by' => $operatorId, 'confirmed_by' => $operatorId, 'confirmed_at' => now(),
            ]);
            FinanceInvoiceAllocation::create([
                'invoice_id' => $invoice->id, 'source_business_type' => FinanceConstants::SOURCE_SALES_ORDER,
                'source_document_id' => $order->id, 'source_document_no' => $order->sales_order_no,
                'source_amount_snapshot' => $this->settlements->receivableAmount($order),
                'allocated_amount' => $amount, 'status' => FinanceConstants::ALLOCATION_ACTIVE,
                'idempotency_key' => 'SIR-'.$request->id,
            ]);
            FinanceOperationLog::create([
                'document_type' => 'finance_invoice', 'document_id' => $invoice->id, 'action' => 'issue_sales_invoice',
                'from_status' => null, 'to_status' => FinanceConstants::STATUS_CONFIRMED, 'operator_id' => $operatorId,
                'operator_name' => $this->userName($user), 'content' => '按开票申请 '.$request->request_no.' 登记销项发票，关联订单 '.$order->sales_order_no,
                'fact_snapshot' => ['document_no' => $invoice->document_no, 'amount_incl_tax' => $invoice->amount_incl_tax, 'currency' => $invoice->currency],
            ]);
            $request->update([
                'status' => self::ISSUED, 'finance_invoice_id' => $invoice->id, 'invoice_no' => $invoiceNo,
                'invoice_date' => $data['invoice_date'], 'tax_rate' => $rate, 'handle_remark' => $data['remark'] ?? null,
                'handled_by' => $operatorId, 'handled_by_name' => $this->userName($user), 'handled_at' => now(),
            ]);
            return $request->fresh();
        }, 5);
        if ($file) {
            try {
                $this->attachments->upload($file, 'finance_invoice', (int) $request->finance_invoice_id, 'invoice_scan', $operatorId);
            } catch (\Throwable $e) {
                report($e);
                $request->setAttribute('upload_failed', true);
            }
        }
        return $request;
    }

    /** 已开票的申请补传发票文件（电子发票 PDF / 纸票扫描件）。 */
    public function uploadFile(int $id, UploadedFile $file, object $user): SalesInvoiceRequest
    {
        $request = SalesInvoiceRequest::query()->findOrFail($id);
        if ($request->status !== self::ISSUED || !$request->finance_invoice_id) {
            throw ValidationException::withMessages(['file' => '开票登记后才能上传发票文件。']);
        }
        $this->attachments->upload($file, 'finance_invoice', (int) $request->finance_invoice_id, 'invoice_scan', $user->legacy_id ?? null);
        return $request->fresh();
    }

    public function paginate(array $filters, int $perPage): array
    {
        $query = SalesInvoiceRequest::query()->latest('id');
        if (!empty($filters['status'])) $query->where('status', $filters['status']);
        if ($keyword = trim((string) ($filters['keyword'] ?? ''))) {
            $query->where(fn ($q) => $q->where('request_no', 'like', "%{$keyword}%")->orWhere('sales_order_no', 'like', "%{$keyword}%")
                ->orWhere('customer_name', 'like', "%{$keyword}%")->orWhere('invoice_title', 'like', "%{$keyword}%")->orWhere('invoice_no', 'like', "%{$keyword}%"));
        }
        if (!empty($filters['date_start'])) $query->whereDate('created_at', '>=', $filters['date_start']);
        if (!empty($filters['date_end'])) $query->whereDate('created_at', '<=', $filters['date_end']);
        $page = $query->paginate($perPage);
        $payload = $page->toArray();
        $payload['data'] = collect($page->items())->map(fn (SalesInvoiceRequest $row) => $this->payload($row))->values();
        $payload['summary'] = [
            'pending_count' => SalesInvoiceRequest::where('status', self::PENDING)->count(),
            'pending_amount' => Money::normalize((string) SalesInvoiceRequest::where('status', self::PENDING)->sum('amount')),
            'issued_month_amount' => Money::normalize((string) SalesInvoiceRequest::where('status', self::ISSUED)->where('handled_at', '>=', now()->startOfMonth())->sum('amount')),
        ];
        return $payload;
    }

    public function payload(SalesInvoiceRequest $row): array
    {
        $data = $row->toArray();
        $data['status_label'] = [self::PENDING => '待开票', self::ISSUED => '已开票', self::REJECTED => '已驳回', self::WITHDRAWN => '已撤回'][$row->status] ?? $row->status;
        $data['invoice_type_label'] = ['vat_normal' => '增值税普通发票', 'vat_special' => '增值税专用发票'][$row->invoice_type] ?? $row->invoice_type;
        $data['attachments'] = $row->finance_invoice_id
            ? FinanceAttachment::query()->where('document_type', 'finance_invoice')->where('document_id', $row->finance_invoice_id)->where('status', 'active')->get(['id', 'original_name', 'attachment_type'])->toArray()
            : [];
        return $data;
    }

    private function requestBlocker(SalesOrder $order): ?string
    {
        if (!$order->customer_id) return '订单还没有绑定客户，不能申请开票。';
        if (!in_array($order->order_status, ['confirmed', 'closed'], true)) return '订单确认后才能申请开票。';
        return null;
    }

    private function billingDefaults(?SalesCustomer $customer, SalesOrder $order): array
    {
        $defaults = [];
        foreach (self::BILLING_FIELDS as $field) $defaults[$field] = $customer?->{$field};
        $defaults['invoice_type'] = $defaults['invoice_type'] ?: 'vat_normal';
        $defaults['invoice_title'] = $defaults['invoice_title'] ?: ($customer?->customer_name ?: ($order->customer_name_snapshot ?: $order->customer_name));
        $defaults['has_saved'] = (bool) ($customer?->invoice_title);
        return $defaults;
    }

    /** 已确认销项发票（含红冲负数）在该订单上的净开票额。 */
    private function invoicedAmount(int $orderId): string
    {
        return Money::normalize((string) FinanceInvoiceAllocation::query()
            ->where('source_business_type', FinanceConstants::SOURCE_SALES_ORDER)->where('source_document_id', $orderId)
            ->where('status', FinanceConstants::ALLOCATION_ACTIVE)
            ->whereHas('invoice', fn ($q) => $q->where('invoice_direction', FinanceConstants::INVOICE_SALES)
                ->whereIn('status', [FinanceConstants::STATUS_DRAFT, FinanceConstants::STATUS_CONFIRMED, FinanceConstants::STATUS_RED]))
            ->sum('allocated_amount'));
    }

    private function userName(object $user): string
    {
        return (string) (($user->nickname ?? null) ?: ($user->username ?? '系统'));
    }

    private function moneyText(string $value): string
    {
        return '¥'.number_format((float) $value, 2);
    }
}
