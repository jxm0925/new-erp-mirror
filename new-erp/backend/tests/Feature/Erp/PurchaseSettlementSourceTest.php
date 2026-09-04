<?php

namespace Tests\Feature\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\Item;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\PurchaseOrder;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\PurchaseReturnItem;
use App\Models\Erp\PurchaseSettlementSource;
use App\Models\Erp\Supplier;
use App\Models\Erp\Unit;
use App\Services\Erp\PurchaseSettlementSourceApplicationService;
use App\Services\Erp\FinanceAllocationApplicationService;
use App\Services\Erp\FinanceCashDocumentApplicationService;
use App\Services\Erp\PurchaseOrderFinanceSummaryService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PurchaseSettlementSourceTest extends TestCase
{
    use DatabaseTransactions;

    public function test_confirmed_receipt_line_creates_source_from_frozen_settlement_fact(): void
    {
        [$receipt, $line] = $this->confirmedReceiptLine('100.0000', '0.0000');

        $sources = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id, 1, '财务来源测试');

        $this->assertCount(1, $sources);
        $source = $sources[0];
        $this->assertSame($receipt->id, $source->source_receipt_id);
        $this->assertSame($line->id, $source->source_line_id);
        $this->assertSame('100.0000', (string) $source->eligible_amount);
        $this->assertSame('100.0000', (string) $source->unallocated_amount);
        $this->assertSame('open', $source->status);
        $this->assertDatabaseHas('erp_finance_operation_logs', ['document_type' => 'purchase_settlement_source', 'document_id' => $source->id, 'action' => 'create_source']);
    }

    public function test_quality_hold_and_completed_ap_offset_change_source_without_rewriting_receipt(): void
    {
        [$receipt, $line] = $this->confirmedReceiptLine('70.0000', '30.0000');
        $service = app(PurchaseSettlementSourceApplicationService::class);
        $source = $service->syncReceipt($receipt->id)[0];

        $this->assertSame('open', $source->status);
        $this->assertSame('30.0000', (string) $source->frozen_amount);

        $return = PurchaseReturn::create([
            'return_no' => $this->code('PRT'), 'return_scope' => 'rejected_before_posting',
            'source_receipt_id' => $receipt->id, 'supplier_id' => $receipt->supplier_id,
            'currency_snapshot' => 'CNY', 'settlement_effect_type' => 'AP_OFFSET',
            'return_date' => now()->toDateString(), 'return_status' => 'pending_outbound', 'audit_status' => 'approved',
            'stock_post_status' => 'not_required', 'return_reason' => '来源金额守恒测试', 'settlement_amount' => '20.0000',
        ]);
        PurchaseReturnItem::create([
            'return_id' => $return->id, 'source_receipt_item_id' => $line->id, 'item_id' => $line->item_id,
            'requested_return_qty' => 2, 'requested_base_qty' => 2, 'approved_base_qty' => 2, 'posted_base_qty' => 2,
            'settlement_amount' => '20.0000', 'return_amount_excl_tax' => '20.0000',
            'return_tax_amount' => '0.0000', 'return_amount_incl_tax' => '20.0000',
        ]);

        $service->syncReceipt($receipt->id);
        $source->refresh();

        // An outbound return is not an AP offset until the return fact is
        // formally complete; otherwise finance would pay less before goods
        // have actually left the enterprise.
        $this->assertSame('0.0000', (string) $source->ap_offset_amount);
        $this->assertSame('70.0000', (string) $source->unallocated_amount);

        $return->update(['return_status' => 'completed']);
        $service->syncReceipt($receipt->id);
        $source->refresh();

        $this->assertSame('20.0000', (string) $source->ap_offset_amount);
        $this->assertSame('50.0000', (string) $source->unallocated_amount);
        $this->assertSame('70.0000', (string) $line->fresh()->settlement_amount);
        $this->assertSame('30.0000', (string) $line->fresh()->quality_hold_amount);
    }

    public function test_confirmed_supplier_payment_partially_allocates_source_and_refreshes_its_balance(): void
    {
        [$receipt] = $this->confirmedReceiptLine('100.0000', '0.0000');
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id)[0];
        $account = FinanceAccount::create([
            'account_no' => $this->code('FAC'), 'account_name' => '采购结算来源付款账户',
            'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled',
        ]);
        $payment = FinanceCashDocument::create([
            'direction' => FinanceConstants::DIRECTION_PAYMENT, 'document_no' => $this->code('FP'),
            'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $receipt->supplier_id,
            'party_name_snapshot' => '采购结算来源测试供应商', 'business_date' => now()->toDateString(),
            'finance_account_id' => $account->id, 'currency' => 'CNY', 'amount' => '40.0000',
            'payment_method' => 'bank_transfer', 'status' => FinanceConstants::STATUS_CONFIRMED, 'confirmed_at' => now(),
        ]);

        $result = app(FinanceAllocationApplicationService::class)->allocate($payment->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id,
            'allocated_amount' => '40.0000', 'idempotency_key' => $this->code('ALLOC'),
        ]], 1, '采购结算来源测试');

        $this->assertCount(1, $result['allocations']);
        $source->refresh();
        $this->assertSame('40.0000', (string) $source->allocated_amount);
        $this->assertSame('60.0000', (string) $source->unallocated_amount);
        $this->assertSame('partially_paid', $source->status);
    }

    public function test_purchase_order_read_model_aggregates_sources_without_creating_payables_from_order(): void
    {
        [$receipt] = $this->confirmedReceiptLine('100.0000', '0.0000');
        $order = PurchaseOrder::create([
            'purchase_order_no' => $this->code('PO'),
            'supplier_id' => $receipt->supplier_id,
            'purchase_status' => 'received',
            'audit_status' => 'approved',
            'receipt_status' => 'received',
            'total_amount' => '120.0000',
            'amount_incl_tax' => '120.0000',
        ]);
        $receipt->update(['order_id' => $order->id]);
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id)[0];

        $account = FinanceAccount::create([
            'account_no' => $this->code('FAC'), 'account_name' => '只读汇总付款账户',
            'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled',
        ]);
        $payment = FinanceCashDocument::create([
            'direction' => FinanceConstants::DIRECTION_PAYMENT, 'document_no' => $this->code('FP'),
            'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $receipt->supplier_id,
            'party_name_snapshot' => '只读汇总供应商', 'business_date' => now()->toDateString(),
            'finance_account_id' => $account->id, 'currency' => 'CNY', 'amount' => '40.0000',
            'payment_method' => 'bank_transfer', 'status' => FinanceConstants::STATUS_CONFIRMED, 'confirmed_at' => now(),
        ]);
        app(FinanceAllocationApplicationService::class)->allocate($payment->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id,
            'allocated_amount' => '40.0000', 'idempotency_key' => $this->code('ALLOC'),
        ]], 1, '只读汇总测试');

        $summary = app(PurchaseOrderFinanceSummaryService::class)->forOrder($order->fresh());

        $this->assertSame('120.0000', $summary['contract_amount']);
        $this->assertSame('100.0000', $summary['confirmed_receipt_amount']);
        $this->assertSame('100.0000', $summary['current_payable_amount']);
        $this->assertSame('40.0000', $summary['paid_amount']);
        $this->assertSame('60.0000', $summary['unpaid_amount']);
        $this->assertSame('partial', $summary['payment_status']);
        $this->assertSame('pending_payment', $summary['financial_settlement_status']);
    }

    public function test_replacement_receipt_does_not_create_another_purchase_settlement_source(): void
    {
        [$receipt] = $this->confirmedReceiptLine('100.0000', '0.0000');
        $receipt->update(['settlement_mode' => 'replacement_no_charge']);

        $sources = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id);

        $this->assertCount(0, $sources);
        $this->assertDatabaseMissing('erp_purchase_settlement_sources', ['source_receipt_id' => $receipt->id]);
    }

    public function test_reversing_purchase_source_allocation_restores_payable_balance_without_erasing_history(): void
    {
        [$receipt] = $this->confirmedReceiptLine('100.0000', '0.0000');
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id)[0];
        $account = FinanceAccount::create([
            'account_no' => $this->code('FAC'), 'account_name' => '核销撤销账户',
            'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled',
        ]);
        $payment = FinanceCashDocument::create([
            'direction' => FinanceConstants::DIRECTION_PAYMENT, 'document_no' => $this->code('FP'),
            'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $receipt->supplier_id,
            'party_name_snapshot' => '核销撤销供应商', 'business_date' => now()->toDateString(),
            'finance_account_id' => $account->id, 'currency' => 'CNY', 'amount' => '40.0000',
            'payment_method' => 'bank_transfer', 'status' => FinanceConstants::STATUS_CONFIRMED, 'confirmed_at' => now(),
        ]);
        $allocation = app(FinanceAllocationApplicationService::class)->allocate($payment->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id,
            'allocated_amount' => '40.0000', 'idempotency_key' => $this->code('ALLOC'),
        ]], 1, '核销撤销测试')['allocations'][0];

        $reversal = app(FinanceAllocationApplicationService::class)->reverse($allocation->id, '录入有误，撤销本次核销', 1, '核销撤销测试');

        $source->refresh();
        $this->assertSame(FinanceConstants::ALLOCATION_REVERSAL, $reversal->status);
        $this->assertSame('0.0000', (string) $source->allocated_amount);
        $this->assertSame('100.0000', (string) $source->unallocated_amount);
        $this->assertDatabaseHas('erp_finance_allocations', ['id' => $allocation->id, 'status' => FinanceConstants::ALLOCATION_REVERSED]);
    }

    public function test_purchase_source_allocation_rejects_amount_above_current_payable_balance(): void
    {
        [$receipt] = $this->confirmedReceiptLine('100.0000', '0.0000');
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id)[0];
        $account = FinanceAccount::create([
            'account_no' => $this->code('FAC'), 'account_name' => '超额拦截账户',
            'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled',
        ]);
        $payment = FinanceCashDocument::create([
            'direction' => FinanceConstants::DIRECTION_PAYMENT, 'document_no' => $this->code('FP'),
            'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $receipt->supplier_id,
            'party_name_snapshot' => '超额拦截供应商', 'business_date' => now()->toDateString(),
            'finance_account_id' => $account->id, 'currency' => 'CNY', 'amount' => '101.0000',
            'payment_method' => 'bank_transfer', 'status' => FinanceConstants::STATUS_CONFIRMED, 'confirmed_at' => now(),
        ]);

        $this->expectException(ValidationException::class);
        app(FinanceAllocationApplicationService::class)->allocate($payment->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id,
            'allocated_amount' => '101.0000', 'idempotency_key' => $this->code('ALLOC'),
        ]], 1, '超额拦截测试');
    }

    public function test_supplier_prepayment_can_be_allocated_after_a_real_settlement_source_exists(): void
    {
        $supplier = Supplier::create([
            'supplier_code' => $this->code('SUP'), 'supplier_name' => '采购预付款测试供应商',
            'supplier_type' => 'manufacturer', 'status' => 'enabled',
        ]);
        $account = FinanceAccount::create([
            'account_no' => $this->code('FAC'), 'account_name' => '采购预付款测试账户',
            'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled',
        ]);
        $prepayment = FinanceCashDocument::create([
            'direction' => FinanceConstants::DIRECTION_PAYMENT, 'document_no' => $this->code('FP'),
            'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $supplier->id,
            'party_name_snapshot' => $supplier->supplier_name, 'business_date' => now()->toDateString(),
            'finance_account_id' => $account->id, 'currency' => 'CNY', 'amount' => '100.0000',
            'payment_method' => 'bank_transfer', 'status' => FinanceConstants::STATUS_CONFIRMED, 'confirmed_at' => now(),
        ]);

        // A confirmed payment with no source is a supplier prepayment, not a
        // fabricated payable. It becomes allocatable only after the receipt fact exists.
        $this->assertSame('0.0000', app(FinanceAllocationApplicationService::class)->activeTotalForDocument($prepayment->id));
        $this->assertSame(FinanceConstants::STATUS_CONFIRMED, $prepayment->status);

        [$receipt] = $this->confirmedReceiptLineForSupplier($supplier, '100.0000', '0.0000');
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id)[0];
        app(FinanceAllocationApplicationService::class)->allocate($prepayment->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id,
            'allocated_amount' => '100.0000', 'idempotency_key' => $this->code('ALLOC'),
        ]], 1, '采购预付款后续核销测试');

        $source->refresh();
        $this->assertSame('0.0000', (string) $source->unallocated_amount);
        $this->assertSame('paid', $source->status);
    }

    public function test_multiple_payments_can_settle_one_source_and_reversal_then_void_preserves_history(): void
    {
        [$receipt] = $this->confirmedReceiptLine('100.0000', '0.0000');
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id)[0];
        $account = FinanceAccount::create([
            'account_no' => $this->code('FAC'), 'account_name' => '多次付款测试账户',
            'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled',
        ]);
        $first = $this->confirmedSupplierPayment($receipt, $account, '30.0000');
        $second = $this->confirmedSupplierPayment($receipt, $account, '70.0000');
        $allocations = app(FinanceAllocationApplicationService::class)->allocate($first->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id,
            'allocated_amount' => '30.0000', 'idempotency_key' => $this->code('ALLOC'),
        ]], 1, '第一次付款核销')['allocations'];
        app(FinanceAllocationApplicationService::class)->allocate($second->id, [[
            'source_business_type' => FinanceConstants::SOURCE_PURCHASE_SETTLEMENT_SOURCE,
            'source_document_id' => $source->id,
            'allocated_amount' => '70.0000', 'idempotency_key' => $this->code('ALLOC'),
        ]], 1, '第二次付款核销');

        $source->refresh();
        $this->assertSame('100.0000', (string) $source->allocated_amount);
        $this->assertSame('paid', $source->status);

        app(FinanceAllocationApplicationService::class)->reverse($allocations[0]->id, '第一笔付款录入错误，撤销核销', 1, '财务测试员');
        $voided = app(FinanceCashDocumentApplicationService::class)->void($first->id, '第一笔付款作废，已先撤销核销', 1, '财务测试员');

        $source->refresh();
        $this->assertSame(FinanceConstants::STATUS_VOIDED, $voided->status);
        $this->assertSame('70.0000', (string) $source->allocated_amount);
        $this->assertSame('30.0000', (string) $source->unallocated_amount);
        $this->assertDatabaseHas('erp_finance_allocations', ['id' => $allocations[0]->id, 'status' => FinanceConstants::ALLOCATION_REVERSED]);
    }

    private function confirmedReceiptLine(string $eligibleAmount, string $frozenAmount): array
    {
        $unit = Unit::create([
            'unit_code' => $this->code('U'), 'unit_name' => '件', 'unit_type' => 'count',
            'decimal_places' => 0, 'is_base' => true, 'status' => 'enabled',
        ]);
        $supplier = Supplier::create([
            'supplier_code' => $this->code('SUP'), 'supplier_name' => '采购结算来源测试供应商',
            'supplier_type' => 'manufacturer', 'status' => 'enabled',
        ]);
        $item = Item::create([
            'item_code' => $this->code('ITEM'), 'item_name' => '采购结算来源测试物料',
            'item_type' => 'consumable', 'unit_id' => $unit->id,
            'is_purchase_item' => true, 'is_stock_item' => false, 'status' => 'enabled',
        ]);
        $receipt = PurchaseReceipt::create([
            'receipt_no' => $this->code('PRC'), 'supplier_id' => $supplier->id,
            'receipt_date' => now()->toDateString(), 'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed',
            'stock_post_status' => 'not_required', 'settlement_mode' => 'normal', 'currency_snapshot' => 'CNY',
            'settlement_amount' => $eligibleAmount, 'quality_hold_amount' => $frozenAmount,
        ]);
        $line = PurchaseReceiptItem::create([
            'receipt_id' => $receipt->id, 'item_id' => $item->id, 'purchase_unit_id' => $unit->id,
            'purchase_unit_name_snapshot' => '件', 'base_unit_id' => $unit->id, 'base_unit_name_snapshot' => '件',
            'conversion_factor_snapshot' => 1, 'receipt_qty' => 10, 'qualified_qty' => 7, 'unqualified_qty' => 3,
            'standard_base_qty' => 10, 'actual_base_qty' => 10, 'qualified_base_qty' => 7, 'unqualified_base_qty' => 3,
            'original_received_base_qty' => 10, 'original_qualified_base_qty' => 7, 'original_unqualified_base_qty' => 3,
            'unit_price' => 10, 'receipt_cost' => 100, 'amount_excl_tax' => 100, 'tax_amount_snapshot' => 0,
            'amount_incl_tax' => 100, 'settlement_amount' => $eligibleAmount, 'qualified_payable_amount' => $eligibleAmount,
            'quality_hold_amount' => $frozenAmount, 'currency_snapshot' => 'CNY', 'finance_fact_status' => 'frozen',
        ]);

        return [$receipt, $line];
    }

    private function confirmedReceiptLineForSupplier(Supplier $supplier, string $eligibleAmount, string $frozenAmount): array
    {
        [$receipt, $line] = $this->confirmedReceiptLine($eligibleAmount, $frozenAmount);
        $receipt->update(['supplier_id' => $supplier->id]);

        return [$receipt->fresh(), $line];
    }

    private function confirmedSupplierPayment(PurchaseReceipt $receipt, FinanceAccount $account, string $amount): FinanceCashDocument
    {
        return FinanceCashDocument::create([
            'direction' => FinanceConstants::DIRECTION_PAYMENT, 'document_no' => $this->code('FP'),
            'party_type' => FinanceConstants::PARTY_SUPPLIER, 'party_id' => $receipt->supplier_id,
            'party_name_snapshot' => $receipt->supplier->supplier_name, 'business_date' => now()->toDateString(),
            'finance_account_id' => $account->id, 'currency' => 'CNY', 'amount' => $amount,
            'payment_method' => 'bank_transfer', 'status' => FinanceConstants::STATUS_CONFIRMED, 'confirmed_at' => now(),
        ]);
    }

    private function code(string $prefix): string
    {
        return $prefix.'-'.strtoupper(substr((string) Str::ulid(), -10));
    }
}
