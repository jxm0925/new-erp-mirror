<?php

namespace Tests\Feature\Erp;

use App\Models\Erp\{DocumentNumberReservation, FinanceAccount, FinanceAccountMovement, FinanceAllocation, FinanceCashDocument, FinanceCashPurchaseAllocation, FinanceCashPurchaseRevision, FinanceCurrency, FinanceOperationLog, Item, PaymentMethod, PurchaseLog, PurchaseOrder, PurchasePaymentPlanItem, PurchaseReceipt, PurchaseReceiptItem, PurchaseReturn, PurchaseSettlementSource, Supplier, Unit};
use App\Services\Erp\{AuthContextService, FinanceAllocationApplicationService, FinanceCashConfirmationApplicationService, FinanceCashDocumentApplicationService, PurchasePaymentPlanQueryService, PurchaseSettlementSourceApplicationService};
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class PurchasePaymentPlanAndCashPurposeTest extends TestCase
{
    use DatabaseTransactions;

    private FinanceAccount $account;
    private PaymentMethod $method;
    private array $permissions = ['finance.view', 'finance.payment.create', 'finance.receipt.create',
        'finance.payment.confirm', 'finance.receipt.confirm', 'finance.payment.void', 'finance.receipt.void',
        'finance.allocation.create', 'finance.allocation.reverse', 'purchase.order.view', 'purchase.order.edit'];

    protected function setUp(): void
    {
        parent::setUp();
        FinanceCurrency::where('is_base', true)->update(['is_base' => false]);
        foreach ([['CNY', true], ['USD', false]] as [$code, $base]) FinanceCurrency::updateOrCreate(['currency_code' => $code], [
            'currency_name' => $code, 'decimal_places' => 2, 'is_base' => $base, 'status' => 'enabled',
        ]);
        $this->account = FinanceAccount::create(['account_no' => $this->code('ACCOUNT'), 'account_name' => '采购用途测试账户', 'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled']);
        $this->method = PaymentMethod::create(['method_code' => $this->code('METHOD'), 'method_name' => '采购用途测试方式',
            'available_for_receipt' => true, 'available_for_payment' => true, 'available_for_sales' => true, 'status' => 'enabled']);
        $this->mock(AuthContextService::class, function ($mock): void {
            $mock->shouldReceive('currentUser')->andReturn((object) ['legacy_id' => 1, 'nickname' => '采购付款测试员', 'username' => 'purchase-purpose-test']);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
        });
    }

    public function test_multi_stage_plan_keeps_unknown_dates_partial_arrangement_and_audit_without_financial_facts(): void
    {
        $order = $this->order();
        $beforeCash = FinanceCashDocument::count();
        $beforeSources = PurchaseSettlementSource::count();
        $items = [];
        foreach (['deposit', 'before_shipment', 'after_receipt', 'monthly', 'agreed_date', 'to_be_agreed'] as $index => $trigger) {
            $items[] = ['title' => '第'.($index + 1).'期', 'trigger_type' => $trigger,
                'amount' => $index === 0 ? '10.1234' : ($index === 1 ? '10.8766' : '10'), 'due_date' => null];
        }
        $data = $this->putJson($this->planUrl($order), ['version' => 0, 'items' => $items])->assertOk()
            ->assertJsonPath('data.version', 1)->assertJsonPath('data.planned_amount', '61.0000')
            ->assertJsonPath('data.unplanned_amount', '39.0000')->assertJsonCount(6, 'data.items')->json('data');
        $this->assertSame('10.1234', $data['items'][0]['amount']);
        foreach ($data['items'] as $item) {
            $this->assertNull($item['due_date']);
            $this->assertFalse($item['overdue']);
            $this->assertSame('0.0000', $item['paid_amount']);
        }
        $this->assertSame($beforeCash, FinanceCashDocument::count());
        $this->assertSame($beforeSources, PurchaseSettlementSource::count());
        $log = PurchaseLog::where('target_id', $order->id)->where('action', 'update_payment_plan')->firstOrFail();
        $this->assertSame(1, $log->evidence['version']);
        $this->assertCount(6, $log->evidence['after']);
    }

    public function test_plan_checks_version_total_and_cross_order_period_identity(): void
    {
        $order = $this->order();
        $plan = $this->savePlan($order, [['trigger_type' => 'deposit', 'amount' => '40', 'due_date' => null]])[0];
        $this->putJson($this->planUrl($order), ['version' => 0, 'items' => []])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->putJson($this->planUrl($order), ['version' => 1, 'items' => [['id' => $plan['id'], 'trigger_type' => 'deposit', 'amount' => '101']]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $other = $this->order($order->supplier);
        $this->putJson($this->planUrl($other), ['version' => 0, 'items' => [['id' => $plan['id'], 'trigger_type' => 'deposit', 'amount' => '40']]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->assertSame('40.0000', PurchasePaymentPlanItem::findOrFail($plan['id'])->amount);
        $this->assertSame(1, $order->fresh()->payment_plan_version);
    }

    public function test_monthly_arrangement_allows_early_payment_and_reads_confirmed_cash_purpose_only(): void
    {
        $order = $this->order();
        $plan = $this->savePlan($order, [['trigger_type' => 'monthly', 'amount' => '30', 'due_date' => '2027-01-31']])[0];
        $cash = $this->cash($order->supplier, '30', [$this->purpose($order, '30', $plan['id'])]);
        $this->getJson($this->planUrl($order))->assertOk()->assertJsonPath('data.paid_amount', '0.0000');
        $this->confirm($cash)->assertOk()->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.allocated_amount', '0.0000');
        $this->getJson($this->planUrl($order))->assertOk()->assertJsonPath('data.paid_amount', '30.0000')
            ->assertJsonPath('data.prepaid_amount', '30.0000')->assertJsonPath('data.current_payable_amount', '0.0000')
            ->assertJsonPath('data.items.0.remaining_amount', '0.0000')->assertJsonPath('data.items.0.due_date', '2027-01-31');
        $this->assertSame(0, FinanceAllocation::where('cash_document_id', $cash->id)->count());
    }

    public function test_cash_draft_create_restore_and_amount_edit_require_complete_purpose_conservation(): void
    {
        $order = $this->order();
        $reservation = DocumentNumberReservation::create(['document_type' => 'finance_payment', 'creation_session_id' => (string) Str::uuid(),
            'document_no' => $this->code('RESERVED'), 'reservation_token' => (string) Str::uuid(), 'status' => 'reserved',
            'reserved_by_legacy_id' => 1, 'expires_at' => now()->addDay()]);
        $payload = ['reservation_token' => $reservation->reservation_token, 'creation_session_id' => $reservation->creation_session_id,
            'party_type' => 'supplier', 'party_id' => $order->supplier_id, 'business_date' => '2026-10-05',
            'finance_account_id' => $this->account->id, 'currency' => 'CNY', 'amount' => '30', 'payment_method_id' => $this->method->id,
            'purchase_order_allocations' => [$this->purpose($order, '30')]];
        $cashId = $this->postJson('/api/v1/erp/finance/cash-documents/payment', $payload)->assertCreated()
            ->assertJsonPath('data.purchase_order_allocations.0.purchase_order_no', $order->purchase_order_no)->json('data.id');
        $this->getJson('/api/v1/erp/finance/cash-documents/show/'.$cashId)->assertOk()->assertJsonPath('data.purchase_order_allocations.0.amount', '30.0000');
        $this->putJson('/api/v1/erp/finance/cash-documents/'.$cashId, ['amount' => '40'])->assertUnprocessable()->assertJsonValidationErrors('purchase_order_allocations');
        $this->putJson('/api/v1/erp/finance/cash-documents/'.$cashId, ['amount' => '40', 'purchase_order_allocations' => [$this->purpose($order, '40')]])
            ->assertOk()->assertJsonPath('data.amount', '40.0000')->assertJsonPath('data.purchase_order_allocations.0.amount', '40.0000');
        $this->assertSame(0, FinanceCashPurchaseAllocation::where('cash_document_id', $cashId)->count());
        $this->assertSame(0, FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $cashId)->count());
    }

    public function test_confirmation_rejects_unapproved_cross_supplier_currency_duplicate_and_unbalanced_purposes(): void
    {
        $order = $this->order();
        $otherSupplier = $this->order();
        $foreign = $this->order($order->supplier, '100', ['currency' => 'USD']);
        $draft = $this->order($order->supplier, '100', ['purchase_status' => 'draft', 'audit_status' => 'pending', 'finance_fact_status' => 'pending']);
        foreach ([[$this->purpose($otherSupplier, '30')], [$this->purpose($foreign, '30')], [$this->purpose($draft, '30')],
            [$this->purpose($order, '15'), $this->purpose($order, '15')], [$this->purpose($order, '29.9999')]] as $purposes) {
            $cash = $this->cash($order->supplier, '30', $purposes);
            $this->confirm($cash)->assertUnprocessable()->assertJsonValidationErrors('purchase_order_allocations');
            $this->assertSame('draft', $cash->fresh()->status);
            $this->assertSame(0, FinanceCashPurchaseAllocation::where('cash_document_id', $cash->id)->count());
            $this->assertSame(0, FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $cash->id)->count());
        }
    }

    public function test_one_cash_can_pay_multiple_orders_and_periods_and_freezes_purposes_without_payables(): void
    {
        $first = $this->order();
        $second = $this->order($first->supplier);
        $plans = $this->savePlan($first, [['trigger_type' => 'deposit', 'amount' => '20'], ['trigger_type' => 'before_shipment', 'amount' => '40']]);
        $cash = $this->cash($first->supplier, '100', [$this->purpose($first, '20', $plans[0]['id']), $this->purpose($first, '40', $plans[1]['id']), $this->purpose($second, '40')]);
        $this->confirm($cash)->assertOk()->assertJsonCount(3, 'data.purchase_order_allocations')->assertJsonPath('data.purchase_order_allocation_version', 1);
        $this->assertSame([], $cash->fresh()->purchase_order_allocations);
        $this->getJson($this->planUrl($first))->assertOk()->assertJsonPath('data.paid_amount', '60.0000')->assertJsonPath('data.current_payable_amount', '0.0000');
        $this->getJson($this->planUrl($second))->assertOk()->assertJsonPath('data.paid_amount', '40.0000');
        $this->getJson('/api/v1/erp/finance/cash-documents/payment?party_id='.$first->supplier_id)->assertOk()->assertJsonPath('data.0.purchase_order_allocations.0.trigger_type', 'deposit');
    }

    public function test_confirmation_and_later_invalid_allocation_roll_back_purpose_cash_ledger_and_sources_together(): void
    {
        $first = $this->order();
        $second = $this->order($first->supplier);
        $firstSource = $this->source($first)[2];
        $secondSource = $this->source($second)[2];
        $cash = $this->cash($first->supplier, '100', [$this->purpose($first, '100')]);
        $this->confirm($cash, [$this->allocation($firstSource, '60'), $this->allocation($secondSource, '40')])
            ->assertUnprocessable()->assertJsonValidationErrors('purchase_order_allocations');
        $this->assertSame('draft', $cash->fresh()->status);
        $this->assertCount(1, $cash->fresh()->purchase_order_allocations);
        $this->assertSame(0, FinanceCashPurchaseAllocation::where('cash_document_id', $cash->id)->count());
        $this->assertSame(0, FinanceCashPurchaseRevision::where('cash_document_id', $cash->id)->count());
        $this->assertSame(0, FinanceAllocation::where('cash_document_id', $cash->id)->count());
        $this->assertSame(0, FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $cash->id)->count());
        $this->assertSame('0.0000', $firstSource->fresh()->allocated_amount);
    }

    public function test_purpose_only_confirmation_retry_does_not_duplicate_cash_facts_or_revisions(): void
    {
        $order = $this->order();
        $cash = $this->cash($order->supplier, '100', [$this->purpose($order, '100')]);
        $first = $this->confirm($cash)->assertOk()->json('data');
        $second = $this->confirm($cash)->assertOk()->json('data');
        $this->assertSame($first['confirmed_at'], $second['confirmed_at']);
        $this->assertSame($first['purchase_order_allocations'][0]['id'], $second['purchase_order_allocations'][0]['id']);
        $this->assertSame(1, FinanceCashPurchaseRevision::where('cash_document_id', $cash->id)->count());
        $this->assertSame(1, FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $cash->id)->count());
    }

    public function test_confirmed_legacy_cash_can_be_explicitly_linked_with_version_idempotency_and_audit(): void
    {
        $order = $this->order();
        $cash = $this->cash($order->supplier, '100');
        $this->confirm($cash)->assertOk();
        $payload = ['version' => 0, 'idempotency_key' => (string) Str::uuid(), 'reason' => '核对合同后补记用途', 'purchase_order_allocations' => [$this->purpose($order, '100')]];
        $url = '/api/v1/erp/finance/cash-documents/'.$cash->id.'/purchase-orders';
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.purchase_order_allocation_version', 1);
        $this->putJson($url, $payload)->assertOk()->assertJsonPath('data.purchase_order_allocation_version', 1);
        $this->putJson($url, [...$payload, 'idempotency_key' => (string) Str::uuid()])->assertUnprocessable()->assertJsonValidationErrors('version');
        $this->putJson($url, [...$payload, 'reason' => '不同内容'])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame(1, FinanceCashPurchaseAllocation::where('cash_document_id', $cash->id)->count());
        $this->assertSame(1, FinanceCashPurchaseRevision::where('cash_document_id', $cash->id)->count());
        $this->assertSame(1, FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $cash->id)->count());
        $this->assertSame(1, FinanceOperationLog::where('document_id', $cash->id)->where('document_type', 'cash_document')->where('action', 'revise_purchase_order_purposes')->count());
    }

    public function test_purpose_revision_cannot_move_or_reduce_an_order_below_its_active_allocations(): void
    {
        $first = $this->order();
        $second = $this->order($first->supplier);
        $source = $this->source($first)[2];
        $cash = $this->cash($first->supplier, '100', [$this->purpose($first, '100')]);
        $this->confirm($cash, [$this->allocation($source, '60')])->assertOk();
        $url = '/api/v1/erp/finance/cash-documents/'.$cash->id.'/purchase-orders';
        foreach ([[], [$this->purpose($second, '100')], [$this->purpose($first, '50'), $this->purpose($second, '50')]] as $rows) {
            $this->putJson($url, ['version' => 1, 'reason' => '更正用途', 'idempotency_key' => (string) Str::uuid(), 'purchase_order_allocations' => $rows])
                ->assertUnprocessable()->assertJsonValidationErrors('purchase_order_allocations');
        }
        $this->putJson($url, ['version' => 1, 'reason' => '保留已核销部分，余款归第二单', 'idempotency_key' => (string) Str::uuid(),
            'purchase_order_allocations' => [$this->purpose($first, '60'), $this->purpose($second, '40')]])
            ->assertOk()->assertJsonPath('data.purchase_order_allocation_version', 2);
        $this->getJson($this->planUrl($first))->assertOk()->assertJsonPath('data.paid_amount', '60.0000')->assertJsonPath('data.prepaid_amount', '0.0000');
        $this->getJson($this->planUrl($second))->assertOk()->assertJsonPath('data.paid_amount', '40.0000');
    }

    public function test_purpose_budget_blocks_cross_order_allocation_but_unlinked_historical_cash_remains_compatible(): void
    {
        $first = $this->order();
        $second = $this->order($first->supplier);
        $third = $this->order($first->supplier);
        $firstSource = $this->source($first)[2];
        $thirdSource = $this->source($third)[2];
        $cash = $this->cash($first->supplier, '100', [$this->purpose($first, '60'), $this->purpose($second, '40')]);
        $this->confirm($cash)->assertOk();
        $this->allocateHttp($cash, $this->allocation($firstSource, '70'))->assertUnprocessable()->assertJsonValidationErrors('purchase_order_allocations');
        $this->allocateHttp($cash, $this->allocation($thirdSource, '10'))->assertUnprocessable()->assertJsonValidationErrors('purchase_order_allocations');
        $this->allocateHttp($cash, $this->allocation($firstSource, '60'))->assertOk();
        $legacy = $this->cash($first->supplier, '10');
        $this->confirm($legacy)->assertOk();
        $this->allocateHttp($legacy, $this->allocation($thirdSource, '10'))->assertOk();
        $this->assertSame(0, FinanceCashPurchaseAllocation::where('cash_document_id', $legacy->id)->count());
    }

    public function test_refunded_deposit_reduces_pool_and_cannot_be_spent_again_from_original_payment(): void
    {
        $order = $this->order();
        $source = $this->source($order)[2];
        $payment = $this->cash($order->supplier, '100', [$this->purpose($order, '100')]);
        $this->confirm($payment, [$this->allocation($source, '60')])->assertOk();
        $refund = $this->cash($order->supplier, '20', [$this->purpose($order, '20')], ['direction' => 'receipt']);
        $this->confirm($refund)->assertOk();
        $this->getJson($this->planUrl($order))->assertOk()->assertJsonPath('data.net_paid_amount', '80.0000')
            ->assertJsonPath('data.unallocated_payment_amount', '40.0000')->assertJsonPath('data.unallocated_refund_amount', '20.0000')
            ->assertJsonPath('data.prepaid_amount', '20.0000');
        $this->allocateHttp($payment, $this->allocation($source, '40'))->assertUnprocessable()->assertJsonValidationErrors('purchase_order_allocations');
        $this->allocateHttp($payment, $this->allocation($source, '20'))->assertOk();
        $this->getJson($this->planUrl($order))->assertOk()->assertJsonPath('data.prepaid_amount', '0.0000');
    }

    public function test_settled_return_refund_preserves_unused_advance_and_reversal_cannot_overdraw_it(): void
    {
        $order = $this->order();
        [$receipt, , $source] = $this->source($order);
        $payment = $this->cash($order->supplier, '100', [$this->purpose($order, '100')]);
        $this->confirm($payment, [$this->allocation($source, '60')])->assertOk();
        $return = $this->supplierReturn($receipt, '20');
        $refund = $this->cash($order->supplier, '20', [$this->purpose($order, '20')], ['direction' => 'receipt']);
        $refundAllocation = $this->confirm($refund, [$this->allocation($return, '20')])->assertOk()->json('data.allocations.0.id');
        $this->getJson($this->planUrl($order))->assertOk()->assertJsonPath('data.unallocated_refund_amount', '0.0000')
            ->assertJsonPath('data.allocated_refund_amount', '20.0000')->assertJsonPath('data.prepaid_amount', '40.0000');
        $this->allocateHttp($payment, $this->allocation($source, '40'))->assertOk();
        $this->postJson('/api/v1/erp/finance/allocations/'.$refundAllocation.'/reverse', ['reason' => '试图释放已使用的退货退款额度'])
            ->assertUnprocessable()->assertJsonValidationErrors('purchase_order_allocations');
        $this->assertSame('active', FinanceAllocation::findOrFail($refundAllocation)->status);
        $this->assertSame(0, FinanceAllocation::where('reversal_of_id', $refundAllocation)->count());
    }

    public function test_unfunded_deposit_refund_rolls_back_and_voided_cash_is_excluded_from_totals(): void
    {
        $order = $this->order();
        $payment = $this->cash($order->supplier, '100', [$this->purpose($order, '100')]);
        $this->confirm($payment)->assertOk();
        $tooMuch = $this->cash($order->supplier, '101', [$this->purpose($order, '101')], ['direction' => 'receipt']);
        $this->confirm($tooMuch)->assertUnprocessable()->assertJsonValidationErrors('purchase_order_allocations');
        $this->assertSame('draft', $tooMuch->fresh()->status);
        $this->assertSame(0, FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $tooMuch->id)->count());
        $refund = $this->cash($order->supplier, '20', [$this->purpose($order, '20')], ['direction' => 'receipt']);
        $this->confirm($refund)->assertOk();
        $this->postJson('/api/v1/erp/finance/cash-documents/'.$payment->id.'/void', ['reason' => '仍有已退订金不能移除资金来源'])
            ->assertUnprocessable()->assertJsonValidationErrors('purchase_order_allocations');
        $this->postJson('/api/v1/erp/finance/cash-documents/'.$refund->id.'/void', ['reason' => '更正退款'])->assertOk();
        $this->getJson($this->planUrl($order))->assertOk()->assertJsonPath('data.paid_amount', '100.0000')->assertJsonPath('data.refund_amount', '0.0000');
        $this->postJson('/api/v1/erp/finance/cash-documents/'.$payment->id.'/void', ['reason' => '更正付款'])->assertOk();
        $this->getJson($this->planUrl($order))->assertOk()->assertJsonPath('data.paid_amount', '0.0000')->assertJsonPath('data.prepaid_amount', '0.0000');
        $this->assertSame(1, FinanceCashPurchaseAllocation::where('cash_document_id', $payment->id)->count(), 'Voiding preserves purpose audit facts.');
    }

    public function test_true_overpayment_requires_reason_and_is_reported_without_truncating_or_creating_payable(): void
    {
        $order = $this->order();
        $cash = $this->cash($order->supplier, '120', [$this->purpose($order, '120')]);
        $this->confirm($cash)->assertUnprocessable()->assertJsonValidationErrors('purchase_order_allocation_reason');
        $this->putJson('/api/v1/erp/finance/cash-documents/'.$cash->id, ['purchase_order_allocation_reason' => '已核实实际多付20，等待供应商退回'])->assertOk();
        $this->confirm($cash)->assertOk();
        $this->getJson($this->planUrl($order))->assertOk()->assertJsonPath('data.paid_amount', '120.0000')->assertJsonPath('data.overpaid_amount', '20.0000')
            ->assertJsonPath('data.contract_unpaid_amount', '0.0000')->assertJsonPath('data.current_payable_amount', '0.0000')->assertJsonPath('data.payment_status', 'overpaid');
    }

    public function test_payment_allocation_reversal_changes_prepaid_balance_but_not_contract_cash_paid(): void
    {
        $order = $this->order();
        $source = $this->source($order)[2];
        $cash = $this->cash($order->supplier, '100', [$this->purpose($order, '100')]);
        $allocationId = $this->confirm($cash, [$this->allocation($source, '60')])->assertOk()->json('data.allocations.0.id');
        $this->postJson('/api/v1/erp/finance/allocations/'.$allocationId.'/reverse', ['reason' => '撤回核销保留原付款'])->assertOk();
        $this->getJson($this->planUrl($order))->assertOk()->assertJsonPath('data.paid_amount', '100.0000')
            ->assertJsonPath('data.allocated_payment_amount', '0.0000')->assertJsonPath('data.prepaid_amount', '100.0000')->assertJsonPath('data.unpaid_payable_amount', '100.0000');
    }

    public function test_used_period_cannot_be_removed_or_lowered_below_confirmed_net_paid(): void
    {
        $order = $this->order();
        $plan = $this->savePlan($order, [['trigger_type' => 'deposit', 'amount' => '40']])[0];
        $cash = $this->cash($order->supplier, '30', [$this->purpose($order, '30', $plan['id'])]);
        $this->confirm($cash)->assertOk();
        $this->putJson($this->planUrl($order), ['version' => 1, 'items' => []])->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->putJson($this->planUrl($order), ['version' => 1, 'items' => [['id' => $plan['id'], 'trigger_type' => 'deposit', 'amount' => '20']]])
            ->assertUnprocessable()->assertJsonValidationErrors('items');
        $this->putJson($this->planUrl($order), ['version' => 1, 'items' => [['id' => $plan['id'], 'trigger_type' => 'deposit', 'amount' => '30', 'due_date' => null]]])
            ->assertOk()->assertJsonPath('data.items.0.remaining_amount', '0.0000');
    }

    public function test_removing_unused_period_cancels_its_actual_model_id_and_retains_other_periods(): void
    {
        $order = $this->order();
        $plans = $this->savePlan($order, [['trigger_type' => 'deposit', 'amount' => '30'], ['trigger_type' => 'after_receipt', 'amount' => '70']]);
        $this->putJson($this->planUrl($order), ['version' => 1, 'items' => [['id' => $plans[0]['id'], 'trigger_type' => 'deposit', 'amount' => '30']]])
            ->assertOk()->assertJsonCount(1, 'data.items')->assertJsonPath('data.unplanned_amount', '70.0000');
        $this->assertSame('active', PurchasePaymentPlanItem::findOrFail($plans[0]['id'])->status);
        $this->assertSame('cancelled', PurchasePaymentPlanItem::findOrFail($plans[1]['id'])->status);
    }

    public function test_statistics_summary_uses_all_filtered_orders_before_pagination_and_separates_currencies(): void
    {
        $first = $this->order();
        $orders = [$first];
        for ($index = 0; $index < 5; $index++) $orders[] = $this->order($first->supplier);
        $this->order($first->supplier, '100', ['currency' => 'USD']);
        foreach ([[0, '30'], [1, '20']] as [$index, $amount]) {
            $cash = $this->cash($first->supplier, $amount, [$this->purpose($orders[$index], $amount)]);
            $this->confirm($cash)->assertOk();
        }
        $this->savePlan($first, [['trigger_type' => 'monthly', 'amount' => '100', 'due_date' => '2026-12-31']]);
        $base = '/api/v1/erp/finance/purchase-payment-statistics?supplier_id='.$first->supplier_id;
        $data = $this->getJson($base.'&per_page=2')->assertOk()->assertJsonPath('total', 7)->assertJsonCount(2, 'data')->json();
        $summary = collect($data['summary_by_currency'])->keyBy('currency');
        $this->assertSame(6, $summary['CNY']['order_count']);
        $this->assertSame('600.0000', $summary['CNY']['contract_amount']);
        $this->assertSame('50.0000', $summary['CNY']['paid_amount']);
        $this->assertSame('100.0000', $summary['USD']['contract_amount']);
        $this->assertSame('0.0000', $summary['USD']['paid_amount']);
        $this->getJson($base.'&payment_status=partial&per_page=1')->assertOk()->assertJsonPath('total', 2)
            ->assertJsonPath('summary_by_currency.0.contract_amount', '200.0000')->assertJsonPath('summary_by_currency.0.paid_amount', '50.0000');
        $this->getJson($base.'&trigger_type=monthly&due_date_start=2026-12-01&due_date_end=2026-12-31')->assertOk()->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.purchase_order_id', $first->id)->assertJsonPath('data.0.paid_amount', '30.0000');
        $this->getJson('/api/v1/erp/purchase/payment-orders?supplier_id='.$first->supplier_id.'&currency=USD')->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.currency', 'USD');
    }

    public function test_permissions_are_enforced_for_plan_read_write_statistics_and_confirmed_purpose_actions(): void
    {
        $order = $this->order();
        $this->permissions = ['finance.view'];
        $this->getJson($this->planUrl($order))->assertOk();
        $this->putJson($this->planUrl($order), ['version' => 0, 'items' => []])->assertForbidden();
        $this->permissions = ['purchase.order.view'];
        $this->getJson($this->planUrl($order))->assertOk();
        $this->getJson('/api/v1/erp/finance/purchase-payment-statistics')->assertForbidden();
        $cash = $this->cash($order->supplier, '100', [], ['status' => 'confirmed']);
        $this->putJson('/api/v1/erp/finance/cash-documents/'.$cash->id.'/purchase-orders', ['version' => 0, 'reason' => '补用途',
            'idempotency_key' => (string) Str::uuid(), 'purchase_order_allocations' => [$this->purpose($order, '100')]])->assertForbidden();
    }

    public function test_resolve_source_provides_authoritative_purchase_order_identity_without_creating_purpose(): void
    {
        $order = $this->order();
        [$receipt, , $source] = $this->source($order);
        foreach ([['purchase_receipt', $receipt->id], ['purchase_settlement_source', $source->id],
            ['purchase_return_supplier_refund', $this->supplierReturn($receipt, '20')->id]] as [$type, $id]) {
            $this->getJson('/api/v1/erp/finance/sources/resolve?type='.$type.'&id='.$id)->assertOk()->assertJsonPath('data.purchase_order_id', $order->id);
        }
        $this->assertSame(0, FinanceCashPurchaseAllocation::where('purchase_order_id', $order->id)->count());
    }

    private function order(?Supplier $supplier = null, string $amount = '100', array $attributes = []): PurchaseOrder
    {
        $supplier ??= Supplier::create(['supplier_code' => $this->code('SUP'), 'supplier_name' => '采购付款测试供应商', 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
        return PurchaseOrder::create(['purchase_order_no' => $this->code('PO'), 'supplier_id' => $supplier->id, 'order_date' => '2026-10-05',
            'currency' => 'CNY', 'tax_mode' => 'tax_included', 'total_amount' => $amount, 'amount_incl_tax' => $amount,
            'purchase_status' => 'processing', 'audit_status' => 'approved', 'receipt_status' => 'not_received', 'finance_fact_status' => 'frozen', ...$attributes]);
    }

    private function savePlan(PurchaseOrder $order, array $items): array
    {
        return $this->putJson($this->planUrl($order), ['version' => $order->fresh()->payment_plan_version, 'items' => $items])->assertOk()->json('data.items');
    }

    private function planUrl(PurchaseOrder $order): string
    {
        return '/api/v1/erp/purchase/orders/'.$order->id.'/payment-plan';
    }

    private function purpose(PurchaseOrder $order, string $amount, ?int $planId = null): array
    {
        return ['purchase_order_id' => $order->id, 'payment_plan_id' => $planId, 'amount' => $amount];
    }

    private function cash(Supplier $supplier, string $amount, array $purposes = [], array $attributes = []): FinanceCashDocument
    {
        return FinanceCashDocument::create(['document_no' => $this->code('CASH'), 'direction' => 'payment', 'party_type' => 'supplier',
            'party_id' => $supplier->id, 'party_name_snapshot' => $supplier->supplier_name, 'business_date' => '2026-10-05',
            'finance_account_id' => $this->account->id, 'currency' => 'CNY', 'amount' => $amount,
            'payment_method_id' => $this->method->id, 'payment_method' => $this->method->method_code, 'status' => 'draft',
            'purchase_order_allocations' => $purposes, ...$attributes]);
    }

    private function confirm(FinanceCashDocument $cash, array $items = [])
    {
        return $this->postJson('/api/v1/erp/finance/cash-documents/'.$cash->id.'/confirm', ['items' => $items]);
    }

    private function allocateHttp(FinanceCashDocument $cash, array $item)
    {
        return $this->postJson('/api/v1/erp/finance/cash-documents/'.$cash->id.'/allocations', ['items' => [$item]]);
    }

    private function allocation(PurchaseSettlementSource|PurchaseReturn $source, string $amount): array
    {
        return ['source_business_type' => $source instanceof PurchaseReturn ? 'purchase_return_supplier_refund' : 'purchase_settlement_source',
            'source_document_id' => $source->id, 'allocated_amount' => $amount, 'idempotency_key' => (string) Str::uuid()];
    }

    private function source(PurchaseOrder $order): array
    {
        $unit = Unit::create(['unit_code' => $this->code('UNIT'), 'unit_name' => '件', 'decimal_places' => 0, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code('ITEM'), 'item_name' => '采购用途回归物料', 'unit_id' => $unit->id, 'item_type' => 'consumable', 'is_stock_item' => false, 'status' => 'enabled']);
        $receipt = PurchaseReceipt::create(['receipt_no' => $this->code('RECEIPT'), 'supplier_id' => $order->supplier_id, 'order_id' => $order->id,
            'receipt_date' => '2026-10-05', 'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed', 'stock_post_status' => 'not_required',
            'settlement_mode' => 'normal', 'currency_snapshot' => 'CNY', 'settlement_amount' => '100']);
        $line = PurchaseReceiptItem::create(['receipt_id' => $receipt->id, 'item_id' => $item->id, 'receipt_qty' => 10, 'qualified_qty' => 10,
            'unit_price' => 10, 'receipt_cost' => 100, 'amount_excl_tax' => 100, 'tax_amount_snapshot' => 0, 'amount_incl_tax' => 100,
            'settlement_amount' => 100, 'qualified_payable_amount' => 100, 'quality_hold_amount' => 0, 'currency_snapshot' => 'CNY', 'finance_fact_status' => 'frozen']);
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id)[0];
        return [$receipt, $line, $source];
    }

    private function supplierReturn(PurchaseReceipt $receipt, string $amount): PurchaseReturn
    {
        return PurchaseReturn::create(['return_no' => $this->code('RETURN'), 'return_scope' => 'posted_inventory', 'source_receipt_id' => $receipt->id,
            'supplier_id' => $receipt->supplier_id, 'currency_snapshot' => 'CNY', 'return_date' => '2026-10-05', 'return_status' => 'completed',
            'audit_status' => 'approved', 'stock_post_status' => 'posted', 'return_reason' => '测试正式供应商退款',
            'settlement_effect_type' => 'SUPPLIER_REFUND', 'settlement_amount' => $amount]);
    }

    private function code(string $prefix): string
    {
        return 'PO-PAY-'.$prefix.'-'.Str::upper(Str::random(9));
    }
}
