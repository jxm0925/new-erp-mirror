<?php

namespace Tests\Feature\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\FinanceInvoice;
use App\Models\Erp\PurchaseExchangeOrder;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\Supplier;
use App\Services\Erp\FinanceAllocationApplicationService;
use App\Services\Erp\FinanceCashDocumentApplicationService;
use App\Services\Erp\FinanceInvoiceApplicationService;
use App\Services\Erp\PurchaseFinanceSettlementService;
use App\Services\Erp\SalesFinanceSettlementService;
use App\Services\Erp\SalesOrderFundingGateService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FinanceCoreV1Test extends TestCase
{
    use DatabaseTransactions;

    public function test_a_b_c_sales_funds_gate_is_derived_from_real_allocations(): void
    {
        [$customer, $order] = $this->salesOrder('100000.0000');
        $settlement = app(SalesFinanceSettlementService::class);
        $this->assertFalse($settlement->status($order)['production_funds_satisfied']);
        $this->assertFalse($settlement->status($order)['shipment_funds_satisfied']);

        $first = $this->cash(FinanceConstants::DIRECTION_RECEIPT, FinanceConstants::PARTY_CUSTOMER, $customer->id, $customer->customer_name, '30000.0000');
        $this->allocate($first, FinanceConstants::SOURCE_SALES_ORDER, $order->id, '30000.0000', 'A-B-FIRST');
        $status = $settlement->status($order);
        $this->assertSame('30000.0000', $status['received_amount']);
        $this->assertSame('70000.0000', $status['outstanding_amount']);
        $this->assertTrue($status['production_funds_satisfied']);
        $this->assertFalse($status['shipment_funds_satisfied']);

        $second = $this->cash(FinanceConstants::DIRECTION_RECEIPT, FinanceConstants::PARTY_CUSTOMER, $customer->id, $customer->customer_name, '70000.0000');
        $this->allocate($second, FinanceConstants::SOURCE_SALES_ORDER, $order->id, '70000.0000', 'A-C-SECOND');
        $status = $settlement->status($order);
        $this->assertSame('100000.0000', $status['net_received_amount']);
        $this->assertSame('0.0000', $status['outstanding_amount']);
        $this->assertTrue($status['shipment_funds_satisfied']);
    }

    public function test_d_one_receipt_can_allocate_multiple_orders_and_conserves_total(): void
    {
        [$customer, $a] = $this->salesOrder('60000.0000');
        [, $b] = $this->salesOrder('40000.0000', $customer);
        $cash = $this->cash(FinanceConstants::DIRECTION_RECEIPT, FinanceConstants::PARTY_CUSTOMER, $customer->id, $customer->customer_name, '100000.0000');
        $result = app(FinanceAllocationApplicationService::class)->allocate($cash->id, [
            $this->allocationRow(FinanceConstants::SOURCE_SALES_ORDER, $a->id, '60000.0000', 'D-A'),
            $this->allocationRow(FinanceConstants::SOURCE_SALES_ORDER, $b->id, '40000.0000', 'D-B'),
        ], 1, '测试管理员');
        $this->assertSame('0.0000', $result['remaining_amount']);
        $this->assertCount(2, $result['allocations']);
    }

    public function test_e_f_one_order_accepts_multiple_receipts_and_rejects_over_allocation(): void
    {
        [$customer, $order] = $this->salesOrder('90000.0000');
        foreach (['10000.0000', '20000.0000', '60000.0000'] as $index => $amount) {
            $this->allocate($this->cash(FinanceConstants::DIRECTION_RECEIPT, FinanceConstants::PARTY_CUSTOMER, $customer->id, $customer->customer_name, $amount), FinanceConstants::SOURCE_SALES_ORDER, $order->id, $amount, 'E-'.$index);
        }
        $this->assertSame('90000.0000', app(SalesFinanceSettlementService::class)->status($order)['received_amount']);

        $small = $this->cash(FinanceConstants::DIRECTION_RECEIPT, FinanceConstants::PARTY_CUSTOMER, $customer->id, $customer->customer_name, '30000.0000');
        $this->expectException(ValidationException::class);
        $this->allocate($small, FinanceConstants::SOURCE_SALES_ORDER, $order->id, '30001.0000', 'F-OVER');
    }

    public function test_g_duplicate_idempotency_cannot_allocate_twice(): void
    {
        [$customer, $order] = $this->salesOrder('100.0000');
        $cash = $this->cash(FinanceConstants::DIRECTION_RECEIPT, FinanceConstants::PARTY_CUSTOMER, $customer->id, $customer->customer_name, '100.0000');
        $this->allocate($cash, FinanceConstants::SOURCE_SALES_ORDER, $order->id, '50.0000', 'G-SAME');
        try {
            $this->allocate($cash, FinanceConstants::SOURCE_SALES_ORDER, $order->id, '50.0000', 'G-SAME');
            $this->fail('重复幂等键必须被数据库唯一约束拒绝');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('idempotency_key', $exception->errors());
        }
        $this->assertSame(1, FinanceAllocation::where('idempotency_key', 'G-SAME')->count());
    }

    public function test_h_reversing_allocation_recalculates_sales_gate_and_preserves_history(): void
    {
        [$customer, $order] = $this->salesOrder('100.0000');
        $cash = $this->cash(FinanceConstants::DIRECTION_RECEIPT, FinanceConstants::PARTY_CUSTOMER, $customer->id, $customer->customer_name, '100.0000');
        $allocation = $this->allocate($cash, FinanceConstants::SOURCE_SALES_ORDER, $order->id, '100.0000', 'H-A');
        app(FinanceAllocationApplicationService::class)->reverse($allocation->id, '原收款认领错误', 1, '测试管理员');
        $status = app(SalesFinanceSettlementService::class)->status($order);
        $this->assertFalse($status['production_funds_satisfied']);
        $this->assertFalse($status['shipment_funds_satisfied']);
        $this->assertDatabaseHas('erp_finance_allocations', ['id' => $allocation->id, 'status' => 'reversed']);
        $this->assertDatabaseHas('erp_finance_allocations', ['reversal_of_id' => $allocation->id, 'status' => 'reversal']);
        app(FinanceCashDocumentApplicationService::class)->void($cash->id, '核销已撤销，资金流水作废', 1, '测试管理员');
        $this->assertSame('voided', $cash->fresh()->status);
    }

    public function test_sales_refund_is_a_separate_payment_fact_and_immediately_blocks_shipment_gate(): void
    {
        [$customer, $order] = $this->salesOrder('100.0000');
        $receipt = $this->cash(FinanceConstants::DIRECTION_RECEIPT, FinanceConstants::PARTY_CUSTOMER, $customer->id, $customer->customer_name, '100.0000');
        $this->allocate($receipt, FinanceConstants::SOURCE_SALES_ORDER, $order->id, '100.0000', 'REFUND-RECEIPT');
        app(SalesOrderFundingGateService::class)->assertCanShip($order);

        $refund = $this->cash(FinanceConstants::DIRECTION_PAYMENT, FinanceConstants::PARTY_CUSTOMER, $customer->id, $customer->customer_name, '40.0000');
        $this->allocate($refund, FinanceConstants::SOURCE_SALES_ORDER_REFUND, $order->id, '40.0000', 'REFUND-PAYMENT');

        $status = app(SalesFinanceSettlementService::class)->status($order);
        $this->assertSame('40.0000', $status['refunded_amount']);
        $this->assertSame('60.0000', $status['net_received_amount']);
        $this->expectException(\DomainException::class);
        app(SalesOrderFundingGateService::class)->assertCanShip($order);
    }

    public function test_i_j_k_l_purchase_payment_boundary_uses_frozen_facts_and_exchange_is_zero(): void
    {
        $supplier = $this->supplier();
        $receipt = PurchaseReceipt::create([
            'receipt_no' => 'PRC-FIN-001', 'supplier_id' => $supplier->id, 'receipt_date' => now()->toDateString(),
            'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed', 'stock_post_status' => 'not_required',
            'settlement_mode' => 'normal', 'currency_snapshot' => 'CNY', 'settlement_amount' => '1000.0000',
            'quality_hold_amount' => '100.0000', 'rejected_claim_amount' => '50.0000', 'inventory_cost_amount' => '700.0000',
        ]);
        $payment = $this->cash(FinanceConstants::DIRECTION_PAYMENT, FinanceConstants::PARTY_SUPPLIER, $supplier->id, $supplier->supplier_name, '1000.0000');
        $this->allocate($payment, FinanceConstants::SOURCE_PURCHASE_RECEIPT, $receipt->id, '1000.0000', 'I-PAY');
        $this->assertSame('0.0000', app(PurchaseFinanceSettlementService::class)->receipt($receipt)['unpaid_amount']);

        $exchange = new PurchaseExchangeOrder([
            'exchange_no' => 'PEX-FIN-001', 'supplier_id' => $supplier->id,
            'replacement_payable_amount' => '0.0000', 'currency_snapshot' => 'CNY',
        ]);
        $this->assertFalse(app(PurchaseFinanceSettlementService::class)->exchange($exchange)['can_be_payment_source']);

        foreach (['AP_OFFSET', 'SUPPLIER_REFUND'] as $effect) {
            $return = PurchaseReturn::create([
                'return_no' => 'PRT-'.$effect, 'return_scope' => 'posted_inventory', 'source_receipt_id' => $receipt->id,
                'supplier_id' => $supplier->id, 'currency_snapshot' => 'CNY', 'settlement_effect_type' => $effect,
                'return_date' => now()->toDateString(), 'return_status' => 'completed', 'audit_status' => 'approved',
                'stock_post_status' => 'posted', 'return_reason' => '财务语义测试', 'settlement_amount' => '100.0000',
            ]);
            $this->assertSame($effect, app(PurchaseFinanceSettlementService::class)->returnSemantics($return)['settlement_effect_type']);
        }
    }

    public function test_m_n_nonstock_settlement_and_historical_facts_ignore_master_changes(): void
    {
        $supplier = $this->supplier();
        $receipt = PurchaseReceipt::create([
            'receipt_no' => 'PRC-NONSTOCK-001', 'supplier_id' => $supplier->id, 'receipt_date' => now()->toDateString(),
            'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed', 'stock_post_status' => 'not_required',
            'settlement_mode' => 'normal', 'currency_snapshot' => 'CNY', 'settlement_amount' => '888.8800',
            'inventory_cost_amount' => '0.0000',
        ]);
        $payment = $this->cash(FinanceConstants::DIRECTION_PAYMENT, FinanceConstants::PARTY_SUPPLIER, $supplier->id, $supplier->supplier_name, '888.8800');
        $allocation = $this->allocate($payment, FinanceConstants::SOURCE_PURCHASE_RECEIPT, $receipt->id, '888.8800', 'M-N');
        $supplier->update(['supplier_name' => '修改后的供应商名称', 'payment_method' => '修改后的付款方式']);
        $receipt->update(['inventory_cost_amount' => '123.4500']);
        $this->assertSame('888.8800', (string) $allocation->fresh()->source_amount_snapshot);
        $this->assertSame('888.8800', (string) $allocation->fresh()->allocated_amount);
        $this->assertSame('0.0000', app(PurchaseFinanceSettlementService::class)->receipt($receipt)['unpaid_amount']);
    }

    public function test_invoice_foundation_conserves_amount_prevents_over_invoice_and_freezes_source_snapshot(): void
    {
        [$customer, $order] = $this->salesOrder('100.0000');
        $invoice = FinanceInvoice::create([
            'invoice_direction' => FinanceConstants::INVOICE_SALES, 'document_no' => uniqid('FI-'),
            'invoice_no' => 'INV-FOUNDATION-001', 'party_type' => FinanceConstants::PARTY_CUSTOMER,
            'party_id' => $customer->id, 'party_name_snapshot' => $customer->customer_name,
            'invoice_date' => now()->toDateString(), 'currency' => 'CNY',
            'amount_excl_tax' => '88.4956', 'tax_amount' => '11.5044', 'amount_incl_tax' => '100.0000',
            'status' => FinanceConstants::STATUS_DRAFT,
        ]);
        $service = app(FinanceInvoiceApplicationService::class);
        $first = $service->allocate($invoice->id, [[
            'source_business_type' => FinanceConstants::SOURCE_SALES_ORDER,
            'source_document_id' => $order->id, 'allocated_amount' => '60.0000', 'idempotency_key' => 'INV-A',
        ]]);
        $this->assertSame('40.0000', $first['remaining_amount']);
        try {
            $service->allocate($invoice->id, [[
                'source_business_type' => FinanceConstants::SOURCE_SALES_ORDER,
                'source_document_id' => $order->id, 'allocated_amount' => '50.0000', 'idempotency_key' => 'INV-OVER',
            ]]);
            $this->fail('超过发票或来源剩余金额必须被拒绝');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('allocated_amount', $exception->errors());
        }
        $service->allocate($invoice->id, [[
            'source_business_type' => FinanceConstants::SOURCE_SALES_ORDER,
            'source_document_id' => $order->id, 'allocated_amount' => '40.0000', 'idempotency_key' => 'INV-B',
        ]]);
        $this->assertSame(FinanceConstants::STATUS_CONFIRMED, $service->confirm($invoice->id, 1)->status);
        $order->update(['total_amount' => '999.0000']);
        $this->assertSame('100.0000', (string) $invoice->fresh()->amount_incl_tax);
        $this->assertSame('100.0000', (string) $invoice->allocations()->sum('allocated_amount'));
    }

    private function salesOrder(string $amount, ?SalesCustomer $customer = null): array
    {
        $customer ??= SalesCustomer::create(['customer_code' => uniqid('CUST-'), 'customer_name' => '财务测试客户', 'status' => 'enabled']);
        $order = SalesOrder::create([
            'sales_order_no' => uniqid('SO-FIN-'), 'customer_id' => $customer->id, 'customer_name' => $customer->customer_name,
            'customer_name_snapshot' => $customer->customer_name, 'order_status' => 'confirmed', 'confirm_status' => 'confirmed',
            'total_amount' => $amount, 'currency' => 'CNY',
        ]);
        return [$customer, $order];
    }

    private function supplier(): Supplier
    {
        return Supplier::create(['supplier_code' => uniqid('SUP-FIN-'), 'supplier_name' => '财务测试供应商', 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
    }

    private function cash(string $direction, string $partyType, int $partyId, string $partyName, string $amount): FinanceCashDocument
    {
        $account = FinanceAccount::firstOrCreate(['account_no' => 'FAC-TEST'], ['account_name' => '测试银行账户', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']);
        return FinanceCashDocument::create([
            'direction' => $direction, 'document_no' => uniqid($direction === 'receipt' ? 'FR-' : 'FP-'),
            'party_type' => $partyType, 'party_id' => $partyId, 'party_name_snapshot' => $partyName,
            'business_date' => now()->toDateString(), 'finance_account_id' => $account->id, 'currency' => 'CNY',
            'amount' => $amount, 'payment_method' => 'bank_transfer', 'status' => 'confirmed', 'confirmed_at' => now(),
        ]);
    }

    private function allocate(FinanceCashDocument $cash, string $type, int $id, string $amount, string $key): FinanceAllocation
    {
        return app(FinanceAllocationApplicationService::class)->allocate($cash->id, [$this->allocationRow($type, $id, $amount, $key)], 1, '测试管理员')['allocations'][0];
    }

    private function allocationRow(string $type, int $id, string $amount, string $key): array
    {
        return ['source_business_type' => $type, 'source_document_id' => $id, 'allocated_amount' => $amount, 'idempotency_key' => $key];
    }
}
