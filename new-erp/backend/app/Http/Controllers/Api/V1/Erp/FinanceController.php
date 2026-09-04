<?php

namespace App\Http\Controllers\Api\V1\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Domain\Finance\Money;
use App\Http\Controllers\Controller;
use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceAttachment;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\FinanceCurrency;
use App\Models\Erp\FinanceExchangeRate;
use App\Models\Erp\FinanceAccountTransfer;
use App\Models\Erp\FinanceInvoice;
use App\Models\Erp\FinanceInvoiceAllocation;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\CounterpartyBalanceService;
use App\Services\Erp\FinanceAccountApplicationService;
use App\Services\Erp\FinanceAllocationApplicationService;
use App\Services\Erp\FinanceAttachmentApplicationService;
use App\Services\Erp\FinanceBusinessSourceResolver;
use App\Services\Erp\FinanceBusinessSourceQueryService;
use App\Services\Erp\PurchasePayableQueryService;
use App\Services\Erp\FinanceInvoiceQueryService;
use App\Services\Erp\FinanceInvoiceApplicationService;
use App\Services\Erp\FinanceCashDocumentApplicationService;
use App\Services\Erp\FinanceCurrencyApplicationService;
use App\Services\Erp\FinanceExchangeRateApplicationService;
use App\Services\Erp\FinanceAccountTransferApplicationService;
use App\Services\Erp\FinanceAccountLedgerService;
use App\Services\Erp\FinanceExchangeRateService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class FinanceController extends Controller
{
    public function currencies(Request $request)
    {
        $this->authorizePermission($request, 'finance.view');
        $query = FinanceCurrency::query()->orderByDesc('is_base')->orderBy('sort')->orderBy('currency_code');
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($keyword = trim((string) $request->input('keyword'))) $query->where(fn ($q) => $q->where('currency_code', 'like', "%{$keyword}%")->orWhere('currency_name', 'like', "%{$keyword}%"));
        return response()->json($query->paginate($this->perPage($request)));
    }

    public function storeCurrency(Request $request, FinanceCurrencyApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.account.manage');
        $data = $request->validate(['currency_code' => 'required|string|max:10', 'currency_name' => 'required|string|max:60', 'symbol' => 'nullable|string|max:12', 'decimal_places' => 'nullable|integer|min:0|max:6', 'is_base' => 'nullable|boolean', 'status' => 'nullable|in:enabled,disabled', 'sort' => 'nullable|integer|min:0|max:9999', 'remark' => 'nullable|string|max:1000']);
        return response()->json(['data' => $service->create($data, $user->legacy_id)], 201);
    }

    public function updateCurrency(Request $request, int $id, FinanceCurrencyApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.account.manage');
        $data = $request->validate(['currency_code' => 'sometimes|string|max:10', 'currency_name' => 'sometimes|required|string|max:60', 'symbol' => 'nullable|string|max:12', 'decimal_places' => 'nullable|integer|min:0|max:6', 'is_base' => 'nullable|boolean', 'status' => 'nullable|in:enabled,disabled', 'sort' => 'nullable|integer|min:0|max:9999', 'remark' => 'nullable|string|max:1000']);
        return response()->json(['data' => $service->update($id, $data, $user->legacy_id)]);
    }

    public function exchangeRates(Request $request)
    {
        $this->authorizePermission($request, 'finance.view');
        $query = FinanceExchangeRate::query()->latest('effective_at')->latest('id');
        foreach (['source_currency', 'target_currency', 'rate_type', 'status'] as $field) {
            if ($request->filled($field)) $query->where($field, in_array($field, ['source_currency', 'target_currency'], true) ? strtoupper((string) $request->input($field)) : (string) $request->input($field));
        }
        return response()->json($query->paginate($this->perPage($request)));
    }

    /**
     * Master-rate versions and actual FX settlements intentionally remain
     * distinct source records. This read model lets the history page place
     * both on one paginated timeline without making actual settlements
     * editable rate-master rows.
     */
    public function exchangeRateHistory(Request $request)
    {
        $this->authorizePermission($request, 'finance.view');
        $master = FinanceExchangeRate::query()->selectRaw("id as record_id, 'rate_master' as record_type, source_currency, target_currency, rate, rate_type, effective_at, source, status, 0 as business_reference_count, NULL as source_document_no, NULL as source_document_id, created_at");
        $actual = FinanceAccountTransfer::query()->whereColumn('source_currency', '<>', 'target_currency')->selectRaw("id as record_id, 'actual_settlement' as record_type, source_currency, target_currency, actual_exchange_rate as rate, 'actual_settlement' as rate_type, business_date as effective_at, 'fx_settlement' as source, 'frozen' as status, 1 as business_reference_count, transfer_no as source_document_no, id as source_document_id, created_at");
        $query = DB::query()->fromSub($master->unionAll($actual), 'finance_rate_history');
        foreach (['source_currency', 'target_currency', 'rate_type', 'status', 'source'] as $field) {
            if ($request->filled($field)) $query->where($field, in_array($field, ['source_currency', 'target_currency'], true) ? strtoupper((string) $request->input($field)) : (string) $request->input($field));
        }
        if ($request->filled('effective_start')) $query->whereDate('effective_at', '>=', $request->date('effective_start'));
        if ($request->filled('effective_end')) $query->whereDate('effective_at', '<=', $request->date('effective_end'));
        return response()->json($query->orderByDesc('effective_at')->orderByDesc('record_id')->paginate($this->perPage($request)));
    }

    public function storeExchangeRate(Request $request, FinanceExchangeRateApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.account.manage');
        $data = $request->validate(['source_currency' => 'required|string|max:10', 'target_currency' => 'required|string|max:10', 'rate' => ['required', 'regex:/^\d+(\.\d{1,10})?$/'], 'rate_type' => 'required|in:business,valuation', 'source' => 'required|in:manual,external,bank,platform', 'effective_at' => 'required|date', 'remark' => 'nullable|string|max:1000']);
        return response()->json(['data' => $service->create($data, $user->legacy_id)], 201);
    }

    public function disableExchangeRate(Request $request, int $id, FinanceExchangeRateApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.account.manage');
        return response()->json(['data' => $service->disable($id, $user->legacy_id)]);
    }

    public function accounts(Request $request)
    {
        $this->authorizePermission($request, 'finance.view');
        $query = FinanceAccount::query()->withExists('movements')->orderBy('sort')->orderByDesc('id');
        if ($request->filled('status')) $query->where('status', $request->string('status'));
        if ($request->filled('account_type')) $query->where('account_type', $request->string('account_type'));
        if ($request->filled('currency')) $query->where('currency', $request->string('currency'));
        if ($accountName = trim((string) $request->input('account_name'))) $query->where('account_name', 'like', "%{$accountName}%");
        if ($keyword = trim((string) $request->input('keyword'))) $query->where(fn ($q) => $q->where('account_no', 'like', "%{$keyword}%")->orWhere('account_name', 'like', "%{$keyword}%")->orWhere('bank_account_no', 'like', "%{$keyword}%"));
        return response()->json($query->paginate($this->perPage($request)));
    }

    public function storeAccount(Request $request, FinanceAccountApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.account.manage');
        $data = $request->validate([
            'reservation_token' => 'required|string', 'creation_session_id' => 'required|string|max:100',
            'account_name' => 'required|string|max:160', 'account_type' => ['required', Rule::in(['bank', 'cash', 'platform', 'other'])],
            'bank_name' => 'nullable|string|max:160', 'bank_account_no' => 'nullable|string|max:120',
            'currency' => 'required|string|max:10', 'status' => 'nullable|in:enabled,disabled', 'sort' => 'nullable|integer|min:0|max:9999', 'remark' => 'nullable|string|max:1000',
        ]);
        return response()->json(['data' => $service->create($data, $user->legacy_id)], 201);
    }

    public function updateAccount(Request $request, int $id, FinanceAccountApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.account.manage');
        $data = $request->validate([
            'account_name' => 'sometimes|required|string|max:160', 'account_type' => 'sometimes|required|in:bank,cash,platform,other',
            'bank_name' => 'nullable|string|max:160', 'bank_account_no' => 'nullable|string|max:120', 'currency' => 'sometimes|required|string|max:10',
            'sort' => 'nullable|integer|min:0|max:9999', 'remark' => 'nullable|string|max:1000',
        ]);
        return response()->json(['data' => $service->update($id, $data, $user->legacy_id)]);
    }

    public function accountStatus(Request $request, int $id, FinanceAccountApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.account.manage');
        $data = $request->validate(['status' => 'required|in:enabled,disabled']);
        return response()->json(['data' => $service->setStatus($id, $data['status'], $user->legacy_id)]);
    }

    public function accountValuation(Request $request, int $id, FinanceAccountLedgerService $ledger, FinanceExchangeRateService $rates)
    {
        $this->authorizePermission($request, 'finance.view');
        $account = FinanceAccount::findOrFail($id);
        $data = $request->validate(['valuation_date' => 'nullable|date']);
        return response()->json(['data' => [...$ledger->carryingBalance($account->id), 'valuation' => $ledger->valuation($account->id, $account->currency, $data['valuation_date'] ?? now()->toDateString(), $rates)]]);
    }

    /**
     * A valuation is an accounting view, never a replacement for the
     * original-currency ledger. Missing rate rows are returned explicitly so
     * the user can complete the valuation instead of seeing a fabricated zero.
     */
    public function accountValuations(Request $request, FinanceAccountLedgerService $ledger, FinanceExchangeRateService $rates)
    {
        $this->authorizePermission($request, 'finance.view');
        $data = $request->validate(['valuation_date' => 'nullable|date', 'currency' => 'nullable|string|max:10', 'status' => 'nullable|in:enabled,disabled', 'keyword' => 'nullable|string|max:160']);
        $query = FinanceAccount::query()->orderBy('sort')->orderBy('id');
        if (!empty($data['currency'])) $query->where('currency', strtoupper($data['currency']));
        if (!empty($data['status'])) $query->where('status', $data['status']);
        if (!empty($data['keyword'])) {
            $keyword = trim((string) $data['keyword']);
            $query->where(fn ($q) => $q->where('account_no', 'like', "%{$keyword}%")
                ->orWhere('account_name', 'like', "%{$keyword}%")
                ->orWhere('bank_name', 'like', "%{$keyword}%"));
        }
        $date = $data['valuation_date'] ?? now()->toDateString();
        $baseCurrency = $rates->baseCurrency()->currency_code;
        $evaluate = function (FinanceAccount $account) use ($ledger, $rates, $date, $baseCurrency) {
            $carrying = $ledger->carryingBalance($account->id);
            if ($account->currency === $baseCurrency) {
                return ['account' => $account, 'valuation_status' => 'base_currency', 'block_reason' => null, 'original_balance' => $carrying['original_balance'], 'carrying_base_amount' => $carrying['base_balance'], 'valuation_date' => $date, 'valuation_rate' => null, 'current_base_valuation' => $carrying['original_balance'], 'unrealized_fx_gain_loss' => '0.0000'];
            }
            if (Money::compare($carrying['original_balance'], '0') === 0) {
                return ['account' => $account, 'valuation_status' => 'zero_balance', 'block_reason' => null, 'original_balance' => '0.0000', 'carrying_base_amount' => $carrying['base_balance'], 'valuation_date' => $date, 'valuation_rate' => null, 'current_base_valuation' => '0.0000', 'unrealized_fx_gain_loss' => '0.0000'];
            }
            try {
                $valuation = $ledger->valuation($account->id, $account->currency, $date, $rates);
                return ['account' => $account, 'valuation_status' => 'complete', 'block_reason' => null, ...$valuation];
            } catch (ValidationException $exception) {
                return ['account' => $account, 'valuation_status' => 'missing_rate', 'block_reason' => collect($exception->errors())->flatten()->first(), 'original_balance' => $carrying['original_balance'], 'carrying_base_amount' => $carrying['base_balance'], 'valuation_date' => $date, 'valuation_rate' => null, 'current_base_valuation' => null, 'unrealized_fx_gain_loss' => null];
            }
        };
        // The table remains paginated, while the header totals deliberately
        // cover every successfully valued account in the current filter. A
        // missing-rate account must never silently turn the totals into zero.
        $summaryRows = (clone $query)->get()->map($evaluate);
        $completeRows = $summaryRows->filter(fn (array $row) => $row['valuation_status'] !== 'missing_rate');
        $complete = $completeRows->count();
        $incomplete = $summaryRows->where('valuation_status', 'missing_rate')->count();
        $currentBaseTotal = $completeRows->reduce(fn (string $sum, array $row) => Money::add($sum, $row['current_base_valuation']), '0.0000');
        $carryingBaseTotal = $completeRows->reduce(fn (string $sum, array $row) => Money::add($sum, $row['carrying_base_amount']), '0.0000');
        $page = $query->paginate($this->perPage($request));
        $page->setCollection($page->getCollection()->map($evaluate));
        return response()->json([...$page->toArray(), 'summary' => ['valuation_date' => $date, 'complete_accounts' => $complete, 'missing_rate_accounts' => $incomplete, 'valuation_complete' => $incomplete === 0, 'current_base_valuation_total' => $currentBaseTotal, 'carrying_base_amount_total' => $carryingBaseTotal, 'unrealized_fx_gain_loss_total' => Money::sub($currentBaseTotal, $carryingBaseTotal)]]);
    }

    public function transfers(Request $request)
    {
        $this->authorizePermission($request, 'finance.view');
        $query = FinanceAccountTransfer::query();
        foreach (['source_account_id', 'target_account_id', 'source_currency', 'target_currency', 'status'] as $field) if ($request->filled($field)) $query->where($field, $request->input($field));
        if ($request->filled('business_date_start')) $query->whereDate('business_date', '>=', $request->input('business_date_start'));
        if ($request->filled('business_date_end')) $query->whereDate('business_date', '<=', $request->input('business_date_end'));
        if ($keyword = trim((string) $request->input('keyword'))) {
            $query->where(function ($builder) use ($keyword): void {
                $builder->where('transfer_no', 'like', "%{$keyword}%")
                    ->orWhere('remark', 'like', "%{$keyword}%")
                    ->orWhereHas('sourceAccount', fn ($account) => $account->where('account_no', 'like', "%{$keyword}%")->orWhere('account_name', 'like', "%{$keyword}%"))
                    ->orWhereHas('targetAccount', fn ($account) => $account->where('account_no', 'like', "%{$keyword}%")->orWhere('account_name', 'like', "%{$keyword}%"));
            });
        }
        $summary = (clone $query)->selectRaw('status, count(*) as record_count')->groupBy('status')->pluck('record_count', 'status');
        $page = $query->with(['sourceAccount', 'targetAccount'])->latest('business_date')->latest('id')->paginate($this->perPage($request));
        $page->setCollection($page->getCollection()->map(fn (FinanceAccountTransfer $transfer) => $this->transferPayload($transfer)));
        return response()->json([...$page->toArray(), 'summary' => [
            'all' => (int) $summary->sum(),
            'draft' => (int) ($summary['draft'] ?? 0),
            'confirmed' => (int) ($summary['confirmed'] ?? 0),
            'voided' => (int) ($summary['voided'] ?? 0),
        ]]);
    }

    public function showTransfer(Request $request, int $id)
    {
        $this->authorizePermission($request, 'finance.view');
        $transfer = FinanceAccountTransfer::query()
            ->with(['sourceAccount', 'targetAccount', 'feeAccount', 'attachments', 'logs'])
            ->findOrFail($id);
        return response()->json(['data' => $this->transferPayload($transfer)]);
    }

    public function storeTransfer(Request $request, FinanceAccountTransferApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.payment.create');
        $data = $this->transferData($request, true);
        return response()->json(['data' => $service->createDraft($data, $user->legacy_id)], 201);
    }

    public function updateTransfer(Request $request, int $id, FinanceAccountTransferApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.payment.create');
        return response()->json(['data' => $service->updateDraft($id, $this->transferData($request, false), $user->legacy_id)]);
    }

    public function previewTransfer(Request $request, FinanceAccountTransferApplicationService $service)
    {
        $this->authorizePermission($request, 'finance.payment.create');
        return response()->json(['data' => $service->preview($this->transferData($request, true))]);
    }

    public function confirmTransfer(Request $request, int $id, FinanceAccountTransferApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.payment.confirm');
        $data = $request->validate(['preview_token' => 'required|string|max:255']);
        return response()->json(['data' => $service->confirm($id, $user->legacy_id, $data['preview_token'])]);
    }

    public function voidTransfer(Request $request, int $id, FinanceAccountTransferApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.payment.void');
        $data = $request->validate(['reason' => 'required|string|max:255']);
        return response()->json(['data' => $service->void($id, $data['reason'], $user->legacy_id)]);
    }

    public function uploadTransferAttachment(Request $request, int $id, FinanceAttachmentApplicationService $service)
    {
        $transfer = FinanceAccountTransfer::findOrFail($id);
        $user = $this->authorizePermission($request, 'finance.payment.create');
        abort_unless($transfer->status === 'draft', 422, '仅草稿资金转账/换汇单允许上传附件。');
        $data = $request->validate(['file' => 'required|file|max:20480', 'attachment_type' => 'nullable|string|max:50']);
        return response()->json(['data' => $service->upload($data['file'], 'account_transfer', $id, $data['attachment_type'] ?? 'settlement_voucher', $user?->legacy_id)], 201);
    }

    public function cashDocuments(Request $request, string $direction)
    {
        $this->authorizePermission($request, 'finance.view');
        abort_unless(in_array($direction, FinanceConstants::directions(), true), 404);
        $query = FinanceCashDocument::query()->with('account')->where('direction', $direction)->latest('business_date')->latest('id');
        foreach (['status', 'party_type', 'currency', 'finance_account_id'] as $field) if ($request->filled($field)) $query->where($field, $request->input($field));
        if ($request->filled('party_id')) $query->where('party_id', $request->integer('party_id'));
        if ($partyKeyword = trim((string) $request->input('party_keyword'))) $query->where('party_name_snapshot', 'like', "%{$partyKeyword}%");
        if ($request->filled('business_date_start')) $query->whereDate('business_date', '>=', $request->date('business_date_start'));
        if ($request->filled('business_date_end')) $query->whereDate('business_date', '<=', $request->date('business_date_end'));
        if ($keyword = trim((string) $request->input('keyword'))) $query->where(fn ($q) => $q->where('document_no', 'like', "%{$keyword}%")->orWhere('party_name_snapshot', 'like', "%{$keyword}%")->orWhere('external_reference_no', 'like', "%{$keyword}%"));
        return response()->json($query->paginate($this->perPage($request))->through(fn ($doc) => $this->cashPayload($doc)));
    }

    public function showCashDocument(Request $request, int $id)
    {
        $this->authorizePermission($request, 'finance.view');
        return response()->json(['data' => $this->cashPayload(FinanceCashDocument::with(['account', 'allocations', 'attachments', 'logs'])->findOrFail($id))]);
    }

    public function storeCashDocument(Request $request, string $direction, FinanceCashDocumentApplicationService $service)
    {
        $permission = $direction === FinanceConstants::DIRECTION_RECEIPT ? 'finance.receipt.create' : 'finance.payment.create';
        $user = $this->authorizePermission($request, $permission);
        $data = $request->validate([
            'reservation_token' => 'required|string', 'creation_session_id' => 'required|string|max:100',
            'party_type' => 'required|in:customer,supplier', 'party_id' => 'required|integer|min:1',
            'business_date' => 'required|date', 'finance_account_id' => 'required|integer|exists:erp_finance_accounts,id',
            'currency' => 'required|string|max:10', 'amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'payment_method' => 'required|string|max:50', 'external_reference_no' => 'nullable|string|max:160',
            'platform_fee_amount' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'], 'platform_fee_account_id' => 'nullable|integer|exists:erp_finance_accounts,id', 'platform_fee_type' => 'nullable|in:platform,bank,other',
            'remark' => 'nullable|string|max:1000', 'idempotency_key' => 'nullable|string|max:100',
        ]);
        return response()->json(['data' => $service->create($direction, $data, $user->legacy_id, $this->operatorName($user))], 201);
    }

    public function updateCashDocument(Request $request, int $id, FinanceCashDocumentApplicationService $service)
    {
        $document = FinanceCashDocument::findOrFail($id);
        $permission = $document->direction === FinanceConstants::DIRECTION_RECEIPT ? 'finance.receipt.create' : 'finance.payment.create';
        $user = $this->authorizePermission($request, $permission);
        $data = $request->validate(['business_date' => 'sometimes|date', 'finance_account_id' => 'sometimes|integer|exists:erp_finance_accounts,id', 'amount' => ['sometimes', 'regex:/^\d+(\.\d{1,4})?$/'], 'payment_method' => 'sometimes|string|max:50', 'external_reference_no' => 'nullable|string|max:160', 'platform_fee_amount' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'], 'platform_fee_account_id' => 'nullable|integer|exists:erp_finance_accounts,id', 'platform_fee_type' => 'nullable|in:platform,bank,other', 'remark' => 'nullable|string|max:1000']);
        return response()->json(['data' => $service->updateDraft($id, $data, $user->legacy_id, $this->operatorName($user))]);
    }

    public function confirmCashDocument(Request $request, int $id, FinanceCashDocumentApplicationService $service)
    {
        $document = FinanceCashDocument::findOrFail($id);
        $permission = $document->direction === FinanceConstants::DIRECTION_RECEIPT ? 'finance.receipt.confirm' : 'finance.payment.confirm';
        $user = $this->authorizePermission($request, $permission);
        return response()->json(['data' => $service->confirm($id, $user->legacy_id, $this->operatorName($user))]);
    }

    public function voidCashDocument(Request $request, int $id, FinanceCashDocumentApplicationService $service)
    {
        $document = FinanceCashDocument::findOrFail($id);
        $permission = $document->direction === FinanceConstants::DIRECTION_RECEIPT ? 'finance.receipt.void' : 'finance.payment.void';
        $user = $this->authorizePermission($request, $permission);
        $data = $request->validate(['reason' => 'required|string|max:255']);
        return response()->json(['data' => $service->void($id, $data['reason'], $user->legacy_id, $this->operatorName($user))]);
    }

    public function allocate(Request $request, int $id, FinanceAllocationApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.allocation.create');
        $data = $request->validate(['items' => 'required|array|min:1|max:100', 'items.*.source_business_type' => 'required|string|max:60', 'items.*.source_document_id' => 'required|integer|min:1', 'items.*.source_line_id' => 'nullable|integer|min:1', 'items.*.allocated_amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'], 'items.*.idempotency_key' => 'required|string|max:100']);
        return response()->json(['data' => $service->allocate($id, $data['items'], $user->legacy_id, $this->operatorName($user))]);
    }

    public function reverseAllocation(Request $request, int $id, FinanceAllocationApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.allocation.reverse');
        $data = $request->validate(['reason' => 'required|string|max:255']);
        return response()->json(['data' => $service->reverse($id, $data['reason'], $user->legacy_id, $this->operatorName($user))]);
    }

    public function source(Request $request, FinanceBusinessSourceResolver $resolver)
    {
        $this->authorizePermission($request, 'finance.view');
        $data = $request->validate(['type' => 'required|string|max:60', 'id' => 'required|integer|min:1']);
        return response()->json(['data' => $resolver->resolve($data['type'], $data['id'])]);
    }

    public function sources(Request $request, FinanceBusinessSourceQueryService $service)
    {
        $this->authorizePermission($request, 'finance.view');
        $data = $request->validate([
            'type' => 'required|string|max:60', 'party_id' => 'nullable|integer|min:1',
            'keyword' => 'nullable|string|max:100', 'per_page' => 'nullable|integer|min:5|max:100',
        ]);
        return response()->json($service->paginate($data['type'], $data, $this->perPage($request)));
    }

    public function payables(Request $request, PurchasePayableQueryService $service)
    {
        $this->authorizePermission($request, 'finance.payable.view');
        $data = $request->validate([
            'supplier_id' => 'nullable|integer|min:1', 'source_id' => 'nullable|integer|min:1',
            'supplier_keyword' => 'nullable|string|max:160', 'purchase_order_no' => 'nullable|string|max:100',
            'source_document_no' => 'nullable|string|max:100', 'business_date_start' => 'nullable|date',
            'business_date_end' => 'nullable|date|after_or_equal:business_date_start',
            'payment_status' => 'nullable|in:unpaid,partial,paid,frozen',
            'invoice_status' => 'nullable|in:unreceived,partial,received', 'has_balance' => 'nullable|in:yes,no',
        ]);
        return response()->json($service->payables($data, $this->perPage($request)));
    }

    public function supplierLedgers(Request $request, PurchasePayableQueryService $service)
    {
        $this->authorizePermission($request, 'finance.supplier-ledger.view');
        $data = $request->validate([
            'supplier_id' => 'nullable|integer|min:1', 'supplier_keyword' => 'nullable|string|max:160',
            'business_date_start' => 'nullable|date', 'business_date_end' => 'nullable|date|after_or_equal:business_date_start',
            'payment_status' => 'nullable|in:unpaid,partial,paid,frozen',
            'invoice_status' => 'nullable|in:unreceived,partial,received', 'has_balance' => 'nullable|in:yes,no',
        ]);
        return response()->json($service->supplierLedgers($data, $this->perPage($request)));
    }

    public function invoices(Request $request, FinanceInvoiceQueryService $service)
    {
        $this->authorizePermission($request, 'finance.invoice.view');
        $data = $request->validate([
            'invoice_direction' => 'nullable|in:purchase,sales',
            'supplier_keyword' => 'nullable|string|max:160',
            'invoice_no' => 'nullable|string|max:120',
            'invoice_code' => 'nullable|string|max:80',
            'status' => 'nullable|in:draft,confirmed,voided,pending_red,red',
            'match_status' => 'nullable|in:unmatched,partial,matched',
            'invoice_date_start' => 'nullable|date',
            'invoice_date_end' => 'nullable|date|after_or_equal:invoice_date_start',
            'received_date_start' => 'nullable|date',
            'received_date_end' => 'nullable|date|after_or_equal:received_date_start',
        ]);
        return response()->json($service->paginate($data, $this->perPage($request)));
    }

    public function showInvoice(Request $request, int $id)
    {
        $this->authorizePermission($request, 'finance.invoice.view');
        $invoice = FinanceInvoice::query()->with(['allocations', 'attachments', 'logs'])->findOrFail($id);
        return response()->json(['data' => $this->invoicePayload($invoice)]);
    }

    public function invoiceDetail(Request $request, int $id, FinanceInvoiceQueryService $service)
    {
        $this->authorizePermission($request, 'finance.invoice.view');
        $data = $request->validate([
            'match_page' => 'nullable|integer|min:1',
            'match_per_page' => 'nullable|integer|min:5|max:100',
            'log_page' => 'nullable|integer|min:1',
            'log_per_page' => 'nullable|integer|min:5|max:100',
        ]);
        return response()->json(['data' => $service->detail(
            $id,
            (int) ($data['match_per_page'] ?? 10),
            (int) ($data['match_page'] ?? 1),
            (int) ($data['log_per_page'] ?? 10),
            (int) ($data['log_page'] ?? 1),
        )]);
    }

    public function invoiceRedPreview(Request $request, int $id, FinanceInvoiceQueryService $service)
    {
        $this->authorizePermission($request, 'finance.invoice.create');
        return response()->json(['data' => $service->redPreview($id)]);
    }

    public function invoiceMatchingSources(Request $request, int $id, FinanceInvoiceQueryService $service)
    {
        $this->authorizePermission($request, 'finance.invoice.match');
        $invoice = FinanceInvoice::findOrFail($id);
        abort_unless($invoice->invoice_direction === FinanceConstants::INVOICE_PURCHASE && $invoice->party_type === FinanceConstants::PARTY_SUPPLIER, 422, '仅采购进项发票可以匹配采购结算来源。');
        $data = $request->validate([
            'settlement_source_no' => 'nullable|string|max:100', 'purchase_order_no' => 'nullable|string|max:100',
            'business_date_start' => 'nullable|date', 'business_date_end' => 'nullable|date|after_or_equal:business_date_start',
        ]);
        return response()->json($service->matchingSources($invoice->id, (int) $invoice->party_id, $data, $this->perPage($request)));
    }

    public function storeInvoice(Request $request, FinanceInvoiceApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.invoice.create');
        return response()->json(['data' => $service->create($this->invoiceData($request, true), $user->legacy_id)], 201);
    }

    public function updateInvoice(Request $request, int $id, FinanceInvoiceApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.invoice.edit_draft');
        return response()->json(['data' => $service->updateDraft($id, $this->invoiceData($request, false), $user->legacy_id)]);
    }

    public function storeRedInvoice(Request $request, int $id, FinanceInvoiceApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.invoice.create');
        $data = $request->validate([
            'reservation_token' => 'required|string|max:100', 'creation_session_id' => 'required|uuid',
            'invoice_no' => 'nullable|string|max:120', 'invoice_code' => 'nullable|string|max:80',
            'invoice_type' => 'required|string|max:40', 'red_date' => 'required|date',
            'red_scope' => 'required|in:full,partial', 'red_reason' => 'required|string|max:255',
            'amount_excl_tax' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'tax_amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'amount_incl_tax' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'tax_detail' => 'nullable|array|max:50', 'tax_detail.*.tax_rate' => 'nullable|numeric|min:0|max:100',
            'tax_detail.*.amount_excl_tax' => 'nullable|numeric|min:0', 'tax_detail.*.tax_amount' => 'nullable|numeric|min:0',
            'remark' => 'nullable|string|max:200',
        ]);
        return response()->json(['data' => $service->createRedInvoice($id, $data, $user->legacy_id)], 201);
    }

    public function storeRedInvoiceDraft(Request $request, int $id, FinanceInvoiceApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.invoice.create');
        $data = $request->validate([
            'reservation_token' => 'required|string|max:100', 'creation_session_id' => 'required|uuid', 'invoice_no' => 'nullable|string|max:120', 'invoice_code' => 'nullable|string|max:80', 'invoice_type' => 'required|string|max:40', 'red_date' => 'required|date', 'red_scope' => 'required|in:full,partial', 'red_reason' => 'required|string|max:255',
            'amount_excl_tax' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'], 'tax_amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'], 'amount_incl_tax' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'], 'tax_detail' => 'nullable|array|max:50', 'remark' => 'nullable|string|max:200',
        ]);
        return response()->json(['data' => $service->createRedInvoiceDraft($id, $data, $user->legacy_id)], 201);
    }

    public function confirmRedInvoiceDraft(Request $request, int $id, FinanceInvoiceApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.invoice.confirm');
        return response()->json(['data' => $service->confirmRedInvoiceDraft($id, $user->legacy_id)]);
    }

    public function saveInvoiceMatches(Request $request, int $id, FinanceInvoiceApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.invoice.match');
        $data = $request->validate([
            'items' => 'present|array|max:100',
            'items.*.source_business_type' => 'required|in:purchase_settlement_source',
            'items.*.source_document_id' => 'required|integer|min:1',
            'items.*.allocated_amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
        ]);
        return response()->json(['data' => $service->saveMatches($id, $data['items'], $user->legacy_id)]);
    }

    public function confirmInvoice(Request $request, int $id, FinanceInvoiceApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.invoice.confirm');
        return response()->json(['data' => $service->confirm($id, $user->legacy_id)]);
    }

    public function reverseInvoiceMatch(Request $request, int $id, FinanceInvoiceApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.invoice.reverse_match');
        $data = $request->validate(['reason' => 'required|string|max:255']);
        return response()->json(['data' => $service->reverseAllocation($id, $data['reason'], $user->legacy_id)]);
    }

    public function uploadInvoiceAttachment(Request $request, int $id, FinanceAttachmentApplicationService $service)
    {
        $user = $this->authorizePermission($request, 'finance.invoice.edit_draft');
        $invoice = FinanceInvoice::findOrFail($id);
        abort_unless($invoice->status === FinanceConstants::STATUS_DRAFT, 422, '仅草稿发票允许上传附件。');
        $data = $request->validate(['file' => 'required|file|max:10240', 'attachment_type' => 'required|in:invoice_scan,reconciliation_voucher,other']);
        return response()->json(['data' => $service->upload($data['file'], 'finance_invoice', $id, $data['attachment_type'], $user->legacy_id)], 201);
    }

    public function deleteInvoiceAttachment(Request $request, int $id)
    {
        $this->authorizePermission($request, 'finance.invoice.edit_draft');
        $attachment = FinanceAttachment::query()->where('document_type', 'finance_invoice')->where('status', 'active')->findOrFail($id);
        $invoice = FinanceInvoice::findOrFail($attachment->document_id);
        abort_unless($invoice->status === FinanceConstants::STATUS_DRAFT, 422, '仅草稿发票允许删除附件。');
        $attachment->update(['status' => 'deleted']);
        return response()->json(['data' => ['id' => $attachment->id]]);
    }

    public function counterpartyBalance(Request $request, string $partyType, int $partyId, CounterpartyBalanceService $service)
    {
        $this->authorizePermission($request, 'finance.view');
        $data = $partyType === FinanceConstants::PARTY_CUSTOMER ? $service->customer($partyId) : $service->supplier($partyId);
        return response()->json(['data' => $data]);
    }

    public function uploadAttachment(Request $request, int $id, FinanceAttachmentApplicationService $service)
    {
        $document = FinanceCashDocument::findOrFail($id);
        $permission = $document->direction === FinanceConstants::DIRECTION_RECEIPT ? 'finance.receipt.create' : 'finance.payment.create';
        $user = $this->authorizePermission($request, $permission);
        abort_unless($document->status === FinanceConstants::STATUS_DRAFT, 422, '只有草稿资金单允许上传附件。');
        $data = $request->validate(['file' => 'required|file|max:20480', 'attachment_type' => 'nullable|string|max:50']);
        return response()->json(['data' => $service->upload($data['file'], 'cash_document', $id, $data['attachment_type'] ?? 'other', $user?->legacy_id)], 201);
    }

    public function previewAttachment(Request $request, int $id)
    {
        $this->authorizePermission($request, 'finance.view');
        $attachment = FinanceAttachment::query()->where('status', 'active')->findOrFail($id);
        abort_unless(str_starts_with((string) $attachment->mime_type, 'image/') || $attachment->mime_type === 'application/pdf', 422, '该附件不支持在线预览。');
        return Storage::disk($attachment->storage_disk)->response($attachment->storage_path, $attachment->original_name, ['Content-Type' => $attachment->mime_type, 'Content-Disposition' => 'inline']);
    }

    public function deleteAttachment(Request $request, int $id)
    {
        $attachment = FinanceAttachment::query()->where('status', 'active')->findOrFail($id);
        if ($attachment->document_type === 'account_transfer') {
            $document = FinanceAccountTransfer::findOrFail($attachment->document_id);
            $this->authorizePermission($request, 'finance.payment.create');
            abort_unless($document->status === 'draft', 422, '仅草稿资金转账/换汇单允许删除附件。');
            $attachment->update(['status' => 'deleted']);
            return response()->json(['data' => ['id' => $id]]);
        }
        $document = FinanceCashDocument::findOrFail($attachment->document_id);
        $permission = $document->direction === FinanceConstants::DIRECTION_RECEIPT ? 'finance.receipt.create' : 'finance.payment.create';
        $this->authorizePermission($request, $permission);
        abort_unless($document->status === FinanceConstants::STATUS_DRAFT, 422, '只有草稿资金单允许删除附件。');
        $attachment->update(['status' => 'deleted']);
        return response()->json(['data' => ['id' => $id]]);
    }

    private function transferData(Request $request, bool $creating): array
    {
        return $request->validate([
            'source_account_id' => $creating ? ['required', 'integer', 'exists:erp_finance_accounts,id'] : ['sometimes', 'required', 'integer', 'exists:erp_finance_accounts,id'],
            'target_account_id' => $creating ? ['required', 'different:source_account_id', 'integer', 'exists:erp_finance_accounts,id'] : ['sometimes', 'required', 'different:source_account_id', 'integer', 'exists:erp_finance_accounts,id'],
            'source_amount' => $creating ? ['required', 'regex:/^\d+(\.\d{1,4})?$/'] : ['sometimes', 'required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'target_amount' => $creating ? ['required', 'regex:/^\d+(\.\d{1,4})?$/'] : ['sometimes', 'required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'mode' => 'nullable|in:transfer,exchange', 'fee_amount' => ['nullable', 'regex:/^\d+(\.\d{1,4})?$/'],
            'fee_currency' => 'nullable|string|max:10', 'fee_bearer' => 'nullable|in:source,target,third_account',
            'fee_account_id' => 'nullable|integer|exists:erp_finance_accounts,id', 'fee_type' => 'nullable|in:platform,bank,other',
            'business_date' => $creating ? ['required', 'date'] : ['sometimes', 'required', 'date'], 'remark' => 'nullable|string|max:1000',
        ]);
    }

    private function cashPayload(FinanceCashDocument $document): array
    {
        $active = $document->relationLoaded('allocations')
            ? $document->allocations->where('status', FinanceConstants::ALLOCATION_ACTIVE)
                ->reduce(fn (string $sum, $row) => Money::add($sum, (string) $row->allocated_amount), '0.0000')
            : Money::normalize((string) FinanceAllocation::where('cash_document_id', $document->id)
                ->where('status', FinanceConstants::ALLOCATION_ACTIVE)->sum('allocated_amount'));
        return [...$document->toArray(), 'allocated_amount' => $active, 'unallocated_amount' => Money::sub((string) $document->amount, $active)];
    }

    private function invoicePayload(FinanceInvoice $invoice): array
    {
        $active = $invoice->relationLoaded('allocations')
            ? $invoice->allocations->where('status', FinanceConstants::ALLOCATION_ACTIVE)
                ->reduce(fn (string $sum, $row) => Money::add($sum, (string) $row->allocated_amount), '0.0000')
            : Money::normalize((string) FinanceInvoiceAllocation::query()->where('invoice_id', $invoice->id)->where('status', FinanceConstants::ALLOCATION_ACTIVE)->sum('allocated_amount'));
        $payload = $invoice->toArray();
        $payload['invoice_date'] = $invoice->invoice_date?->toDateString();
        $payload['received_date'] = $invoice->received_date?->toDateString();
        $payload['red_date'] = $invoice->red_date?->toDateString();

        return [...$payload, 'matched_amount' => $active, 'unmatched_amount' => Money::maxZero(Money::sub((string) $invoice->amount_incl_tax, $active))];
    }

    private function invoiceData(Request $request, bool $creating): array
    {
        return $request->validate([
            'reservation_token' => ($creating ? 'required' : 'nullable').'|string|max:100',
            'creation_session_id' => ($creating ? 'required' : 'nullable').'|uuid',
            'invoice_direction' => ($creating ? 'required' : 'sometimes').'|in:purchase,sales',
            'party_type' => ($creating ? 'required' : 'sometimes').'|in:supplier,customer',
            'party_id' => ($creating ? 'required' : 'sometimes').'|integer|min:1',
            'invoice_no' => ($creating ? 'required' : 'sometimes|required').'|string|max:120', 'invoice_code' => 'nullable|string|max:80',
            'invoice_type' => ($creating ? 'required' : 'sometimes|required').'|string|max:40',
            'invoice_date' => ($creating ? 'required' : 'sometimes|required').'|date',
            'received_date' => ($creating ? 'required' : 'sometimes|required').'|date',
            'currency' => 'nullable|string|in:CNY',
            'amount_excl_tax' => $creating
                ? ['required', 'regex:/^\d+(\.\d{1,4})?$/']
                : ['sometimes', 'required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'tax_amount' => $creating
                ? ['required', 'regex:/^\d+(\.\d{1,4})?$/']
                : ['sometimes', 'required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'amount_incl_tax' => $creating
                ? ['required', 'regex:/^\d+(\.\d{1,4})?$/']
                : ['sometimes', 'required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'tax_detail' => 'required|array|min:1|max:50',
            'tax_detail.*.tax_rate' => 'required|numeric|min:0|max:100',
            'tax_detail.*.amount_excl_tax' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'tax_detail.*.tax_amount' => ['required', 'regex:/^\d+(\.\d{1,4})?$/'],
            'remark' => 'nullable|string|max:200',
        ]);
    }

    private function transferPayload(FinanceAccountTransfer $transfer): array
    {
        $payload = $transfer->toArray();
        $payload['business_date'] = $transfer->business_date?->toDateString();

        return $payload;
    }

    private function authorizePermission(Request $request, string $permission): object
    {
        $auth = app(AuthContextService::class); $user = $auth->currentUser($request);
        abort_unless($user, 401, '请先登录。');
        abort_unless($auth->isSuperAdmin($user) || in_array($permission, $auth->permissionCodes($user), true), 403, '无按钮权限：'.$permission);
        return $user;
    }

    private function operatorName(object $user): string { return (string) ($user->nickname ?: $user->username ?: '系统'); }
    private function perPage(Request $request): int { return min(100, max(5, (int) $request->input('per_page', 20))); }
}
