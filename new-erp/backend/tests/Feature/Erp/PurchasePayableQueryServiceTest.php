<?php

namespace Tests\Feature\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceAttachment;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\FinanceInvoice;
use App\Models\Erp\FinanceInvoiceAllocation;
use App\Models\Erp\Item;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\Supplier;
use App\Models\Erp\Unit;
use App\Services\Erp\FinanceAllocationApplicationService;
use App\Services\Erp\FinanceInvoiceApplicationService;
use App\Services\Erp\FinanceInvoiceQueryService;
use App\Services\Erp\DocumentNumberService;
use App\Services\Erp\PurchasePayableQueryService;
use App\Services\Erp\PurchaseSettlementSourceApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchasePayableQueryServiceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_payable_and_supplier_ledger_read_models_are_built_from_real_settlement_facts(): void
    {
        [$supplier, $receipt] = $this->confirmedReceipt('100.0000', '20.0000');
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id, 1, '测试操作员')[0];
        $account = FinanceAccount::create([
            'account_no' => $this->code('FAC'), 'account_name' => '应付聚合测试账户',
            'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled',
        ]);
        $payment = FinanceCashDocument::create([
            'direction' => FinanceConstants::DIRECTION_PAYMENT, 'document_no' => $this->code('PAY'),
            'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $supplier->id,
            'party_name_snapshot' => $supplier->supplier_name, 'business_date' => now()->toDateString(),
            'finance_account_id' => $account->id, 'currency' => 'CNY', 'amount' => '120.0000',
            'payment_method' => 'bank_transfer', 'status' => FinanceConstants::STATUS_CONFIRMED, 'confirmed_at' => now(),
        ]);
        app(FinanceAllocationApplicationService::class)->allocate($payment->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id, 'allocated_amount' => '40.0000', 'idempotency_key' => $this->code('ALLOC'),
        ]], 1, '测试操作员');

        $service = app(PurchasePayableQueryService::class);
        $payables = $service->payables(['supplier_id' => $supplier->id], 20);
        $this->assertCount(1, $payables['data']);
        $this->assertSame('100.0000', $payables['data'][0]['current_payable_amount']);
        $this->assertSame('20.0000', $payables['data'][0]['quality_frozen_amount']);
        $this->assertSame('40.0000', $payables['data'][0]['paid_amount']);
        $this->assertSame('60.0000', $payables['data'][0]['unpaid_amount']);
        $this->assertSame('partial', $payables['data'][0]['payment_status']);

        $ledger = $service->supplierLedgers(['supplier_id' => $supplier->id], 20);
        $this->assertCount(1, $ledger['data']);
        $this->assertSame('100.0000', $ledger['data'][0]['current_payable_amount']);
        $this->assertSame('40.0000', $ledger['data'][0]['paid_amount']);
        $this->assertSame('80.0000', $ledger['data'][0]['prepayment_balance_amount']);
        $this->assertSame('60.0000', $ledger['data'][0]['unpaid_amount']);
    }

    public function test_purchase_invoice_matching_updates_the_same_settlement_source_and_the_paginated_register(): void
    {
        [$supplier, $receipt] = $this->confirmedReceipt('100.0000', '0.0000');
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id, 1, '测试操作员')[0];
        $invoice = FinanceInvoice::create([
            'invoice_direction' => FinanceConstants::INVOICE_PURCHASE, 'document_no' => $this->code('FI'),
            'invoice_no' => 'INV-'.$this->code('NO'), 'invoice_code' => '3100', 'invoice_type' => 'vat_special',
            'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $supplier->id,
            'party_name_snapshot' => $supplier->supplier_name, 'invoice_date' => now()->toDateString(),
            'received_date' => now()->toDateString(), 'currency' => 'CNY',
            'amount_excl_tax' => '88.4956', 'tax_amount' => '11.5044', 'amount_incl_tax' => '100.0000',
            'status' => FinanceConstants::STATUS_DRAFT,
        ]);

        app(FinanceInvoiceApplicationService::class)->allocate($invoice->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id, 'allocated_amount' => '60.0000', 'idempotency_key' => $this->code('INV-MATCH'),
        ]]);
        $this->assertSame('0.0000', (string) $source->fresh()->invoice_matched_amount);
        $this->assertSame('100.0000', (string) $source->fresh()->invoice_unmatched_amount);

        $register = app(FinanceInvoiceQueryService::class)->paginate(['invoice_direction' => 'purchase'], 20);
        $row = collect($register['data'])->firstWhere('id', $invoice->id);
        $this->assertSame('60.0000', $row['matched_amount']);
        $this->assertSame('40.0000', $row['unmatched_amount']);
        $this->assertSame('partial', $row['match_status']);
        $this->assertSame('100.0000', $register['summary']['invoice_total_amount']);
    }

    public function test_saved_invoice_matches_append_draft_rows_and_keep_the_purchase_source_balanced(): void
    {
        [$supplier, $receipt] = $this->confirmedReceipt('100.0000', '0.0000');
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id, 1, '测试操作员')[0];
        $invoice = FinanceInvoice::create([
            'invoice_direction' => FinanceConstants::INVOICE_PURCHASE, 'document_no' => $this->code('FI'),
            'invoice_no' => 'INV-'.$this->code('NO'), 'party_type' => FinanceConstants::PARTY_SUPPLIER,
            'party_id' => $supplier->id, 'party_name_snapshot' => $supplier->supplier_name,
            'invoice_date' => now()->toDateString(), 'currency' => 'CNY',
            'amount_excl_tax' => '88.4956', 'tax_amount' => '11.5044', 'amount_incl_tax' => '100.0000',
            'status' => FinanceConstants::STATUS_DRAFT,
        ]);
        $query = app(FinanceInvoiceQueryService::class);
        $candidates = $query->matchingSources($invoice->id, $supplier->id, [], 20);
        $this->assertSame('100.0000', $candidates['data'][0]['available_amount']);

        $service = app(FinanceInvoiceApplicationService::class);
        $service->saveMatches($invoice->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id, 'allocated_amount' => '60.0000',
        ]], 1);
        $service->saveMatches($invoice->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id, 'allocated_amount' => '40.0000',
        ]], 1);

        $this->assertSame('0.0000', (string) $source->fresh()->invoice_matched_amount);
        $this->assertSame('100.0000', (string) $source->fresh()->invoice_unmatched_amount);
        $this->assertSame('100.0000', (string) $invoice->allocations()->where('status', FinanceConstants::ALLOCATION_ACTIVE)->sum('allocated_amount'));
        $this->assertSame(2, (int) $invoice->allocations()->where('status', FinanceConstants::ALLOCATION_ACTIVE)->count());
        $this->assertSame(0, (int) $invoice->allocations()->where('status', FinanceConstants::ALLOCATION_REVERSED)->count());

        FinanceAttachment::create([
            'document_type' => 'finance_invoice', 'document_id' => $invoice->id,
            'attachment_type' => 'invoice_scan', 'original_name' => 'invoice.pdf',
            'storage_disk' => 'oss', 'storage_path' => 'tests/invoice.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 1, 'uploaded_at' => now(), 'status' => 'active',
        ]);
        app(FinanceInvoiceApplicationService::class)->confirm($invoice->id, 1);
        $this->assertSame('100.0000', (string) $source->fresh()->invoice_matched_amount);
        $this->assertSame('0.0000', (string) $source->fresh()->invoice_unmatched_amount);
    }

    public function test_tax_excluded_purchase_fact_matches_the_invoice_gross_amount_including_tax(): void
    {
        [$supplier, $receipt] = $this->confirmedReceipt('0.0000', '0.0000');
        $receipt->update(['tax_mode_snapshot' => 'tax_excluded']);
        $line = $receipt->items()->firstOrFail();
        $facts = app(\App\Services\Erp\PurchaseFinancialFactService::class)->amountFacts(100, 13, 'tax_excluded');
        $line->update([
            'receipt_qty' => 1,
            'qualified_qty' => 1,
            'unqualified_qty' => 0,
            'standard_base_qty' => 1,
            'actual_base_qty' => 1,
            'qualified_base_qty' => 1,
            'unqualified_base_qty' => 0,
            'original_received_base_qty' => 1,
            'original_qualified_base_qty' => 1,
            'original_unqualified_base_qty' => 0,
            'unit_price' => 100,
            'tax_rate' => 13,
            'receipt_cost' => 100,
            ...$facts,
        ]);
        app(\App\Services\Erp\PurchaseReceiptSettlementService::class)->refresh($receipt->id);
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id, 1)[0];

        $this->assertSame('113.0000', (string) $source->fresh()->original_amount);
        $this->assertSame('113.0000', (string) $source->fresh()->eligible_amount);
        $this->assertSame('113.0000', (string) $source->fresh()->invoice_unmatched_amount);

        $invoice = FinanceInvoice::create([
            'invoice_direction' => FinanceConstants::INVOICE_PURCHASE,
            'document_no' => $this->code('FI'),
            'invoice_no' => 'TAX-'.$this->code('NO'),
            'invoice_type' => 'vat_special',
            'party_type' => FinanceConstants::PARTY_SUPPLIER,
            'party_id' => $supplier->id,
            'party_name_snapshot' => $supplier->supplier_name,
            'invoice_date' => now()->toDateString(),
            'currency' => 'CNY',
            'amount_excl_tax' => '100.0000',
            'tax_amount' => '13.0000',
            'amount_incl_tax' => '113.0000',
            'status' => FinanceConstants::STATUS_DRAFT,
        ]);
        app(FinanceInvoiceApplicationService::class)->allocate($invoice->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id,
            'allocated_amount' => '113.0000',
            'idempotency_key' => $this->code('TAX-MATCH'),
        ]], 1);
        FinanceAttachment::create([
            'document_type' => 'finance_invoice', 'document_id' => $invoice->id,
            'attachment_type' => 'invoice_scan', 'original_name' => 'tax-invoice.pdf',
            'storage_disk' => 'oss', 'storage_path' => 'tests/tax-invoice.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 1, 'uploaded_at' => now(), 'status' => 'active',
        ]);
        app(FinanceInvoiceApplicationService::class)->confirm($invoice->id, 1);

        $this->assertSame('113.0000', (string) $source->fresh()->invoice_matched_amount);
        $this->assertSame('0.0000', (string) $source->fresh()->invoice_unmatched_amount);
    }

    public function test_invoice_matching_candidates_use_live_fact_balance_when_source_read_summary_is_stale(): void
    {
        [$supplier, $receipt] = $this->confirmedReceipt('100.0000', '0.0000');
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id, 1)[0];
        $source->update(['invoice_unmatched_amount' => '0.0000']);

        $invoice = FinanceInvoice::create([
            'invoice_direction' => FinanceConstants::INVOICE_PURCHASE,
            'document_no' => $this->code('FI'),
            'invoice_no' => 'LIVE-'.$this->code('NO'),
            'invoice_type' => 'vat_special',
            'party_type' => FinanceConstants::PARTY_SUPPLIER,
            'party_id' => $supplier->id,
            'party_name_snapshot' => $supplier->supplier_name,
            'invoice_date' => now()->toDateString(),
            'currency' => 'CNY',
            'amount_excl_tax' => '88.4956',
            'tax_amount' => '11.5044',
            'amount_incl_tax' => '100.0000',
            'status' => FinanceConstants::STATUS_DRAFT,
        ]);

        $candidates = app(FinanceInvoiceQueryService::class)->matchingSources($invoice->id, $supplier->id, [], 20);

        $this->assertSame(1, $candidates['total']);
        $this->assertSame($source->id, $candidates['data'][0]['id']);
        $this->assertSame('100.0000', $candidates['data'][0]['available_amount']);
    }

    public function test_invoice_tax_detail_is_the_only_amount_source_of_truth(): void
    {
        [$supplier] = $this->confirmedReceipt('0.0000', '0.0000');
        $sessionId = (string) Str::uuid();
        $reservation = app(DocumentNumberService::class)->reserve('finance_invoice', $sessionId, 1, 'test');
        $service = app(FinanceInvoiceApplicationService::class);
        $invoice = $service->create([
            'reservation_token' => $reservation->reservation_token,
            'creation_session_id' => $sessionId,
            'invoice_direction' => FinanceConstants::INVOICE_PURCHASE,
            'invoice_no' => 'DETAIL-'.$this->code('NO'),
            'invoice_type' => 'vat_special',
            'party_type' => FinanceConstants::PARTY_SUPPLIER,
            'party_id' => $supplier->id,
            'invoice_date' => now()->toDateString(),
            'received_date' => now()->toDateString(),
            'currency' => 'CNY',
            'amount_excl_tax' => '100.0000',
            'tax_amount' => '13.0000',
            'amount_incl_tax' => '113.0000',
            'tax_detail' => [['tax_rate' => 13, 'amount_excl_tax' => '100.0000', 'tax_amount' => '13.0000']],
        ], 1);
        $this->assertSame('100.0000', (string) $invoice->amount_excl_tax);
        $this->assertSame('13.0000', (string) $invoice->tax_amount);
        $this->assertSame('113.0000', (string) $invoice->amount_incl_tax);

        try {
            $service->updateDraft($invoice->id, [
                'amount_excl_tax' => '100.0000', 'tax_amount' => '13.0000', 'amount_incl_tax' => '113.0000',
                'tax_detail' => [['tax_rate' => 13, 'amount_excl_tax' => '100.0000', 'tax_amount' => '12.0000']],
            ], 1);
            $this->fail('税额不得与税率明细脱节。');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('tax_detail.0.tax_amount', $exception->errors());
        }
    }

    public function test_matching_candidates_keep_current_draft_amount_editable_but_reserve_it_from_later_drafts(): void
    {
        [$supplier, $receipt] = $this->confirmedReceipt('100.0000', '0.0000');
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id, 1, '测试操作员')[0];
        $first = FinanceInvoice::create([
            'invoice_direction' => FinanceConstants::INVOICE_PURCHASE, 'document_no' => $this->code('FI'),
            'invoice_no' => 'FIRST-'.$this->code('NO'), 'invoice_type' => 'vat_special',
            'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $supplier->id,
            'party_name_snapshot' => $supplier->supplier_name, 'invoice_date' => now()->toDateString(),
            'currency' => 'CNY', 'amount_excl_tax' => '100.0000', 'tax_amount' => '0.0000',
            'amount_incl_tax' => '100.0000', 'status' => FinanceConstants::STATUS_DRAFT,
        ]);
        app(FinanceInvoiceApplicationService::class)->saveMatches($first->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id, 'allocated_amount' => '60.0000',
        ]], 1);

        $query = app(FinanceInvoiceQueryService::class);
        $firstCandidates = $query->matchingSources($first->id, $supplier->id, [], 20);
        $this->assertSame('60.0000', $firstCandidates['data'][0]['current_invoice_matched_amount']);
        $this->assertSame('40.0000', $firstCandidates['data'][0]['available_amount']);

        $later = FinanceInvoice::create([
            'invoice_direction' => FinanceConstants::INVOICE_PURCHASE, 'document_no' => $this->code('FI'),
            'invoice_no' => 'LATER-'.$this->code('NO'), 'invoice_type' => 'vat_special',
            'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $supplier->id,
            'party_name_snapshot' => $supplier->supplier_name, 'invoice_date' => now()->toDateString(),
            'currency' => 'CNY', 'amount_excl_tax' => '100.0000', 'tax_amount' => '0.0000',
            'amount_incl_tax' => '100.0000', 'status' => FinanceConstants::STATUS_DRAFT,
        ]);
        $laterCandidates = $query->matchingSources($later->id, $supplier->id, [], 20);
        $this->assertSame('40.0000', $laterCandidates['data'][0]['available_amount']);
        $this->assertSame('60.0000', $laterCandidates['data'][0]['reserved_by_draft_amount']);
        $this->assertSame('40.0000', $laterCandidates['summary']['available_amount']);
    }

    public function test_invoice_detail_read_model_is_paginated_and_uses_frozen_matching_facts(): void
    {
        [$supplier, $receipt] = $this->confirmedReceipt('100.0000', '0.0000');
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id, 1, '测试操作员')[0];
        $invoice = FinanceInvoice::create([
            'invoice_direction' => FinanceConstants::INVOICE_PURCHASE, 'document_no' => $this->code('FI'),
            'invoice_no' => 'INV-'.$this->code('NO'), 'invoice_code' => '3100', 'invoice_type' => 'vat_special',
            'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $supplier->id,
            'party_name_snapshot' => $supplier->supplier_name, 'invoice_date' => now()->toDateString(),
            'received_date' => now()->toDateString(), 'currency' => 'CNY',
            'amount_excl_tax' => '88.4956', 'tax_amount' => '11.5044', 'amount_incl_tax' => '100.0000',
            'status' => FinanceConstants::STATUS_DRAFT,
        ]);
        app(FinanceInvoiceApplicationService::class)->allocate($invoice->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id, 'allocated_amount' => '100.0000', 'idempotency_key' => $this->code('INV-DETAIL'),
        ]], 1);

        $detail = app(FinanceInvoiceQueryService::class)->detail($invoice->id, 10, 1, 10, 1);

        $this->assertSame('100.0000', $detail['invoice']['matched_amount']);
        $this->assertSame('0.0000', $detail['invoice']['unmatched_amount']);
        $this->assertSame(1, $detail['match_history']['total']);
        $this->assertSame($source->source_document_no, $detail['match_history']['data'][0]['source_document_no']);
        $this->assertSame('100.0000', $detail['match_history']['data'][0]['source_amount_snapshot']);
        $this->assertGreaterThanOrEqual(1, $detail['operation_logs']['total']);
    }

    public function test_red_invoice_reopens_the_purchase_source_without_becoming_a_new_pending_match(): void
    {
        [$supplier, $receipt] = $this->confirmedReceipt('100.0000', '0.0000');
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id, 1)[0];
        $blue = FinanceInvoice::create([
            'invoice_direction' => FinanceConstants::INVOICE_PURCHASE, 'document_no' => $this->code('FI'),
            'invoice_no' => 'BLUE-'.$this->code('NO'), 'invoice_type' => 'vat_special',
            'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $supplier->id,
            'party_name_snapshot' => $supplier->supplier_name, 'invoice_date' => now()->toDateString(),
            'received_date' => now()->toDateString(), 'currency' => 'CNY',
            'amount_excl_tax' => '100.0000', 'tax_amount' => '0.0000', 'amount_incl_tax' => '100.0000',
            'tax_detail' => [['tax_rate' => 0, 'amount_excl_tax' => '100.0000', 'tax_amount' => '0.0000']],
            'status' => FinanceConstants::STATUS_CONFIRMED, 'confirmed_at' => now(),
        ]);
        FinanceInvoiceAllocation::create([
            'invoice_id' => $blue->id,
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id, 'source_document_no' => $source->source_document_no,
            'source_amount_snapshot' => '100.0000', 'allocated_amount' => '100.0000',
            'status' => FinanceConstants::ALLOCATION_ACTIVE, 'idempotency_key' => $this->code('BLUE-MATCH'),
        ]);
        app(PurchaseSettlementSourceApplicationService::class)->refresh($source->id, 1);
        $this->assertSame('0.0000', (string) $source->fresh()->invoice_unmatched_amount);

        $sessionId = (string) Str::uuid();
        $reservation = app(DocumentNumberService::class)->reserve('finance_invoice', $sessionId, 1, 'test');
        $partialRed = app(FinanceInvoiceApplicationService::class)->createRedInvoice($blue->id, [
            'reservation_token' => $reservation->reservation_token, 'creation_session_id' => $sessionId,
            'invoice_type' => 'vat_special', 'red_date' => now()->toDateString(),
            'red_scope' => 'partial', 'red_reason' => '部分退货红冲测试',
            'amount_excl_tax' => '40.0000', 'tax_amount' => '0.0000', 'amount_incl_tax' => '40.0000',
        ], 1);
        $this->assertSame(FinanceConstants::STATUS_RED, $partialRed->status);
        $this->assertSame('40.0000', (string) $source->fresh()->invoice_unmatched_amount);

        $fullSessionId = (string) Str::uuid();
        $fullReservation = app(DocumentNumberService::class)->reserve('finance_invoice', $fullSessionId, 1, 'test');
        $red = app(FinanceInvoiceApplicationService::class)->createRedInvoice($blue->id, [
            'reservation_token' => $fullReservation->reservation_token, 'creation_session_id' => $fullSessionId,
            'invoice_type' => 'vat_special', 'red_date' => now()->toDateString(),
            'red_scope' => 'full', 'red_reason' => '余量全额红冲测试',
            'amount_excl_tax' => '60.0000', 'tax_amount' => '0.0000', 'amount_incl_tax' => '60.0000',
        ], 1);

        $this->assertSame(FinanceConstants::STATUS_CONFIRMED, $blue->fresh()->status);
        $this->assertSame(FinanceConstants::STATUS_RED, $red->status);
        $this->assertSame('100.0000', (string) $source->fresh()->invoice_unmatched_amount);

        // 红冲不是删除蓝票；同一采购来源重新收到新票后，必须能以新票
        // 重新覆盖，而不会覆写蓝票或红票的历史匹配事实。
        $reissueSessionId = (string) Str::uuid();
        $reissueReservation = app(DocumentNumberService::class)->reserve('finance_invoice', $reissueSessionId, 1, 'test');
        $reissue = app(FinanceInvoiceApplicationService::class)->create([
            'reservation_token' => $reissueReservation->reservation_token, 'creation_session_id' => $reissueSessionId,
            'invoice_direction' => FinanceConstants::INVOICE_PURCHASE, 'invoice_no' => 'REISSUE-'.$this->code('NO'),
            'invoice_type' => 'vat_special', 'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $supplier->id,
            'invoice_date' => now()->toDateString(), 'received_date' => now()->toDateString(), 'currency' => 'CNY',
            'amount_excl_tax' => '100.0000', 'tax_amount' => '0.0000', 'amount_incl_tax' => '100.0000',
            'tax_detail' => [['tax_rate' => 0, 'amount_excl_tax' => '100.0000', 'tax_amount' => '0.0000']],
        ], 1);
        app(FinanceInvoiceApplicationService::class)->allocate($reissue->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id, 'allocated_amount' => '100.0000', 'idempotency_key' => $this->code('REISSUE-MATCH'),
        ]], 1);
        FinanceAttachment::create([
            'document_type' => 'finance_invoice', 'document_id' => $reissue->id,
            'attachment_type' => 'invoice_scan', 'original_name' => 'reissue.pdf',
            'storage_disk' => 'oss', 'storage_path' => 'tests/reissue.pdf',
            'mime_type' => 'application/pdf', 'file_size' => 1, 'uploaded_at' => now(), 'status' => 'active',
        ]);
        app(FinanceInvoiceApplicationService::class)->confirm($reissue->id, 1);
        $this->assertSame(FinanceConstants::STATUS_CONFIRMED, $reissue->fresh()->status);
        $this->assertSame('0.0000', (string) $source->fresh()->invoice_unmatched_amount);

        $register = app(FinanceInvoiceQueryService::class)->paginate(['invoice_direction' => 'purchase'], 20);
        $this->assertSame('100.0000', $register['summary']['invoice_total_amount']);
        $this->assertSame('0.0000', $register['summary']['unmatched_amount']);
        $this->assertSame(0, app(FinanceInvoiceQueryService::class)->paginate(['match_status' => 'unmatched'], 20)['total']);
    }

    private function confirmedReceipt(string $settlement, string $frozen): array
    {
        $unit = Unit::create(['unit_code' => $this->code('U'), 'unit_name' => '台', 'unit_type' => 'count', 'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled']);
        $supplier = Supplier::create(['supplier_code' => $this->code('SUP'), 'supplier_name' => '应付聚合测试供应商', 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code('ITEM'), 'item_name' => '应付聚合测试物料', 'item_type' => 'consumable', 'unit_id' => $unit->id, 'is_purchase_item' => true, 'is_stock_item' => false, 'status' => 'enabled']);
        $receipt = PurchaseReceipt::create(['receipt_no' => $this->code('PRC'), 'supplier_id' => $supplier->id, 'receipt_date' => now()->toDateString(), 'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed', 'stock_post_status' => 'not_required', 'settlement_mode' => 'normal', 'currency_snapshot' => 'CNY', 'settlement_amount' => $settlement, 'quality_hold_amount' => $frozen]);
        PurchaseReceiptItem::create([
            'receipt_id' => $receipt->id, 'item_id' => $item->id, 'purchase_unit_id' => $unit->id, 'purchase_unit_name_snapshot' => '台', 'base_unit_id' => $unit->id, 'base_unit_name_snapshot' => '台', 'conversion_factor_snapshot' => 1,
            'receipt_qty' => 10, 'qualified_qty' => 8, 'unqualified_qty' => 2, 'standard_base_qty' => 10, 'actual_base_qty' => 10, 'qualified_base_qty' => 8, 'unqualified_base_qty' => 2, 'original_received_base_qty' => 10, 'original_qualified_base_qty' => 8, 'original_unqualified_base_qty' => 2,
            'unit_price' => 10, 'receipt_cost' => 100, 'amount_excl_tax' => 100, 'tax_amount_snapshot' => 0, 'amount_incl_tax' => 100, 'settlement_amount' => $settlement, 'qualified_payable_amount' => $settlement, 'quality_hold_amount' => $frozen, 'currency_snapshot' => 'CNY', 'finance_fact_status' => 'frozen',
        ]);
        return [$supplier, $receipt];
    }

    private function code(string $prefix): string
    {
        return $prefix.'-'.strtoupper(substr((string) Str::ulid(), -10));
    }
}
