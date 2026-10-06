<?php

namespace Tests\Feature\Erp;

use App\Domain\Finance\FinanceConstants;
use App\Models\Erp\DocumentNumberReservation;
use App\Models\Erp\FinanceAccount;
use App\Models\Erp\FinanceAccountMovement;
use App\Models\Erp\FinanceAllocation;
use App\Models\Erp\FinanceCashDocument;
use App\Models\Erp\FinanceCurrency;
use App\Models\Erp\FinanceOperationLog;
use App\Models\Erp\FinancePlatformFee;
use App\Models\Erp\Item;
use App\Models\Erp\PaymentMethod;
use App\Models\Erp\PurchaseReceipt;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\SalesCustomer;
use App\Models\Erp\SalesOrder;
use App\Models\Erp\Supplier;
use App\Models\Erp\Unit;
use App\Services\Erp\AuthContextService;
use App\Services\Erp\FinanceAccountLedgerService;
use App\Services\Erp\FinanceAllocationApplicationService;
use App\Services\Erp\FinanceCashDocumentApplicationService;
use App\Services\Erp\PurchaseSettlementSourceApplicationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Str;
use Tests\TestCase;

class FinanceCashConfirmationTest extends TestCase
{
    use DatabaseTransactions;

    private array $permissions = [
        'finance.view', 'finance.receipt.create', 'finance.payment.create',
        'finance.receipt.confirm', 'finance.payment.confirm', 'finance.allocation.create',
    ];
    private FinanceAccount $account;
    private PaymentMethod $method;

    protected function setUp(): void
    {
        parent::setUp();
        // Each test owns its reference fixtures inside DatabaseTransactions;
        // a freshly migrated test database need not copy development seed data.
        FinanceCurrency::query()->where('is_base', true)->update(['is_base' => false]);
        foreach ([['CNY', '人民币', true], ['USD', '美元', false]] as [$code, $name, $isBase]) {
            FinanceCurrency::updateOrCreate(['currency_code' => $code], [
                'currency_name' => $name, 'decimal_places' => 2, 'is_base' => $isBase, 'status' => 'enabled',
            ]);
        }
        $this->account = FinanceAccount::create([
            'account_no' => $this->code('ACC'), 'account_name' => '确认并核销测试账户',
            'account_type' => 'bank', 'currency' => 'CNY', 'status' => 'enabled',
        ]);
        $this->method = PaymentMethod::create([
            'method_code' => $this->code('METHOD'), 'method_name' => '确认并核销付款方式',
            'available_for_sales' => true, 'available_for_receipt' => true,
            'available_for_payment' => true, 'status' => 'enabled',
        ]);
        $this->mock(AuthContextService::class, function ($mock) {
            $mock->shouldReceive('currentUser')->andReturn((object) ['legacy_id' => 1, 'nickname' => '财务确认测试员', 'username' => 'finance-confirm-test']);
            $mock->shouldReceive('isSuperAdmin')->andReturn(false);
            $mock->shouldReceive('permissionCodes')->andReturnUsing(fn () => $this->permissions);
        });
    }

    public function test_confirmation_posts_cash_fees_and_multiple_allocations_once_and_returns_balances(): void
    {
        [$customer, $first] = $this->salesOrder('60');
        [, $second] = $this->salesOrder('40', $customer);
        $items = [$this->row('sales_order', $first->id, '60'), $this->row('sales_order', $second->id, '40')];
        $cash = $this->cash($customer, '100', ['draft_allocation_items' => $items, 'platform_fee_amount' => '2', 'platform_fee_account_id' => $this->account->id, 'platform_fee_type' => 'bank']);

        $response = $this->postJson($this->confirmUrl($cash), ['items' => $items])->assertOk()
            ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.allocated_amount', '100.0000')
            ->assertJsonPath('data.unallocated_amount', '0.0000')->assertJsonPath('data.draft_allocation_items', [])
            ->assertJsonCount(2, 'data.allocations');

        $this->assertSame(1, $this->cashMovements($cash));
        $this->assertSame(1, FinancePlatformFee::where('cash_document_id', $cash->id)->count());
        $this->assertSame(2, FinanceAccountMovement::where('finance_account_id', $this->account->id)->count());
        $this->assertSame('98.0000', app(FinanceAccountLedgerService::class)->carryingBalance($this->account->id)['original_balance']);
        $this->assertSame('passed', $first->fresh()->shipment_funding_status);
        $this->assertSame('passed', $second->fresh()->shipment_funding_status);
        $this->assertSame(1, $this->logs($cash, 'confirm'));
        $this->assertSame(1, $this->logs($cash, 'allocate'));
        $this->assertNotEmpty($response->json('data.confirmed_at'));
    }

    public function test_failure_on_later_row_rolls_back_confirmation_fee_ledger_allocations_and_draft_clear(): void
    {
        [$customer, $first] = $this->salesOrder('60');
        [, $otherPartyOrder] = $this->salesOrder('40');
        $items = [$this->row('sales_order', $first->id, '60'), $this->row('sales_order', $otherPartyOrder->id, '40')];
        $cash = $this->cash($customer, '100', ['draft_allocation_items' => $items, 'platform_fee_amount' => '2', 'platform_fee_account_id' => $this->account->id]);
        $before = $cash->fresh()->getAttributes();

        $this->postJson($this->confirmUrl($cash), ['items' => $items])->assertUnprocessable()->assertJsonValidationErrors('party_id');

        $this->assertSame($before, $cash->fresh()->getAttributes());
        $this->assertNoPostedFacts($cash);
        $this->assertSame(0, FinanceAccountMovement::where('finance_account_id', $this->account->id)->count());
        $this->assertEquals($items, $cash->fresh()->draft_allocation_items);
        $this->assertSame('0.0000', app(FinanceAllocationApplicationService::class)->activeTotalForSource('sales_order', $first->id));
    }

    public function test_cash_and_source_over_allocation_and_currency_mismatch_each_roll_back_confirmation(): void
    {
        [$customer, $order] = $this->salesOrder('100');
        foreach ([['amount' => '50', 'allocated' => '60', 'currency' => 'CNY', 'error' => 'allocated_amount'],
            ['amount' => '150', 'allocated' => '110', 'currency' => 'CNY', 'error' => 'allocated_amount'],
            ['amount' => '50', 'allocated' => '40', 'currency' => 'USD', 'error' => 'currency']] as $case) {
            $order->update(['currency' => $case['currency']]);
            $cash = $this->cash($customer, $case['amount']);
            $this->postJson($this->confirmUrl($cash), ['items' => [$this->row('sales_order', $order->id, $case['allocated'])]])
                ->assertUnprocessable()->assertJsonValidationErrors($case['error']);
            $this->assertSame('draft', $cash->fresh()->status);
            $this->assertNoPostedFacts($cash);
        }
    }

    public function test_empty_or_omitted_items_confirm_prepayment_without_using_saved_draft_intent(): void
    {
        [$customer, $order] = $this->salesOrder('100');
        $this->permissions = ['finance.receipt.confirm'];
        foreach ([[], ['items' => []]] as $payload) {
            $cash = $this->cash($customer, '100', ['draft_allocation_items' => [$this->row('sales_order', $order->id, '100')]]);
            $this->postJson($this->confirmUrl($cash), $payload)->assertOk()
                ->assertJsonPath('data.status', 'confirmed')->assertJsonPath('data.allocated_amount', '0.0000')
                ->assertJsonPath('data.unallocated_amount', '100.0000')->assertJsonPath('data.draft_allocation_items', []);
            $this->assertSame(1, $this->cashMovements($cash));
            $this->assertSame(0, $cash->allocations()->count());
        }
    }

    public function test_same_request_retry_is_idempotent_and_changed_payload_or_new_keys_are_rejected(): void
    {
        [$customer, $order] = $this->salesOrder('100');
        $cash = $this->cash($customer, '100', ['platform_fee_amount' => '2', 'platform_fee_account_id' => $this->account->id]);
        $item = $this->row('sales_order', $order->id, '100');
        $item['idempotency_key'] = 'retry-case-'.Str::uuid();
        $original = $this->postJson($this->confirmUrl($cash), ['items' => [$item]])->assertOk()->json('data');
        $retry = $this->postJson($this->confirmUrl($cash), ['items' => [[...$item, 'allocated_amount' => '100.0000']]])
            ->assertOk()->assertJsonPath('data.allocated_amount', '100.0000')->json('data');
        $this->assertSame($original['allocations'][0]['id'], $retry['allocations'][0]['id']);
        $this->assertSame($original['confirmed_at'], $retry['confirmed_at']);
        $this->assertSame($original['lock_version'], $retry['lock_version']);
        foreach ([['allocated_amount' => '99'], ['source_line_id' => 1], ['source_document_id' => $order->id + 1],
            ['idempotency_key' => strtoupper($item['idempotency_key']), 'allocated_amount' => '99']] as $change) {
            $this->postJson($this->confirmUrl($cash), ['items' => [[...$item, ...$change]]])
                ->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        }
        $this->postJson($this->confirmUrl($cash), ['items' => [$this->row('sales_order', $order->id, '1')]])
            ->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame(1, $cash->allocations()->count());
        $this->assertSame(1, $this->cashMovements($cash));
        $this->assertSame(1, FinancePlatformFee::where('cash_document_id', $cash->id)->count());
        $this->assertSame(2, FinanceAccountMovement::where('finance_account_id', $this->account->id)->count());
        $this->assertSame(1, $this->logs($cash, 'confirm'));
        $this->assertSame(1, $this->logs($cash, 'allocate'));
    }

    public function test_reversed_allocation_and_key_used_by_another_cash_document_cannot_be_replayed(): void
    {
        [$customer, $order] = $this->salesOrder('100');
        $cash = $this->cash($customer, '100');
        $item = $this->row('sales_order', $order->id, '100');
        $allocationId = $this->postJson($this->confirmUrl($cash), ['items' => [$item]])->assertOk()->json('data.allocations.0.id');
        $otherCash = $this->cash($customer, '100');
        $this->postJson($this->confirmUrl($otherCash), ['items' => [$item]])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertNoPostedFacts($otherCash);
        app(FinanceAllocationApplicationService::class)->reverse($allocationId, '测试撤销已完成核销', 1, '测试');
        $this->postJson($this->confirmUrl($cash), ['items' => [$item]])->assertUnprocessable()->assertJsonValidationErrors('idempotency_key');
        $this->assertSame('0.0000', app(FinanceAllocationApplicationService::class)->activeTotalForDocument($cash->id));
        $this->assertSame(1, $this->cashMovements($cash));
    }

    public function test_combined_confirmation_requires_both_direction_confirmation_and_allocation_permissions(): void
    {
        [$customer, $order] = $this->salesOrder('100');
        foreach (['receipt', 'payment'] as $direction) {
            foreach ([['finance.'.$direction.'.confirm'], ['finance.allocation.create']] as $permissions) {
                $this->permissions = $permissions;
                $cash = $this->cash($customer, '100', ['direction' => $direction]);
                $this->postJson($this->confirmUrl($cash), ['items' => [$this->row('sales_order', $order->id, '100')]])->assertForbidden();
                $this->assertNoPostedFacts($cash);
                $this->assertSame('draft', $cash->fresh()->status);
            }
        }
    }

    public function test_duplicate_request_keys_are_rejected_before_any_facts_are_posted(): void
    {
        [$customer, $order] = $this->salesOrder('100');
        $cash = $this->cash($customer, '100');
        $item = $this->row('sales_order', $order->id, '50');
        $this->postJson($this->confirmUrl($cash), ['items' => [$item, $item]])->assertUnprocessable()
            ->assertJsonValidationErrors('items.0.idempotency_key');
        $this->assertNoPostedFacts($cash);
    }

    public function test_pending_and_settled_filters_apply_before_pagination_and_keep_party_currency_filters(): void
    {
        [$customer, $order] = $this->salesOrder('10000');
        $pendingIds = [];
        for ($index = 0; $index < 7; $index++) {
            $cash = $this->cash($customer, '100', ['status' => 'confirmed']);
            $pendingIds[] = $cash->id;
            $allocation = $this->allocate($cash, 'sales_order', $order->id, '20');
            if ($index === 0) app(FinanceAllocationApplicationService::class)->reverse($allocation->id, '保留撤销与反向记录用于余额筛选', 1, '测试');
        }
        $settledIds = [];
        for ($index = 0; $index < 6; $index++) {
            $cash = $this->cash($customer, '100', ['status' => 'confirmed']);
            $settledIds[] = $cash->id;
            $this->allocate($cash, 'sales_order', $order->id, '100');
        }
        $this->cash($customer, '100', ['draft_allocation_items' => [$this->row('sales_order', $order->id, '100')]]);
        $this->cash($customer, '100', ['status' => 'voided']);
        $this->cash($customer, '100', ['status' => 'confirmed', 'currency' => 'USD']);
        $this->cash($customer, '100', ['status' => 'confirmed', 'party_type' => 'supplier']);
        [$otherCustomer] = $this->salesOrder('100');
        $this->cash($otherCustomer, '100', ['status' => 'confirmed']);
        $base = '/api/v1/erp/finance/cash-documents/receipt?party_type=customer&party_id='.$customer->id.'&currency=CNY&per_page=5';

        $first = $this->getJson($base.'&allocation_status=pending')->assertOk()->assertJsonPath('total', 7)->assertJsonCount(5, 'data')->json('data');
        $second = $this->getJson($base.'&allocation_status=pending&page=2')->assertOk()->assertJsonPath('total', 7)->assertJsonCount(2, 'data')->json('data');
        $this->assertEqualsCanonicalizing($pendingIds, array_column([...$first, ...$second], 'id'));
        foreach ([...$first, ...$second] as $row) {
            $reversed = $row['id'] === $pendingIds[0];
            $this->assertSame($reversed ? '0.0000' : '20.0000', $row['allocated_amount']);
            $this->assertSame($reversed ? '100.0000' : '80.0000', $row['unallocated_amount']);
        }
        $first = $this->getJson($base.'&allocation_status=settled')->assertOk()->assertJsonPath('total', 6)->assertJsonCount(5, 'data')->json('data');
        $second = $this->getJson($base.'&allocation_status=settled&page=2')->assertOk()->assertJsonPath('total', 6)->assertJsonCount(1, 'data')->json('data');
        $this->assertEqualsCanonicalizing($settledIds, array_column([...$first, ...$second], 'id'));
        foreach ([...$first, ...$second] as $row) $this->assertSame('0.0000', $row['unallocated_amount']);
        $this->getJson($base.'&allocation_status=invalid')->assertUnprocessable()->assertJsonValidationErrors('allocation_status');
    }

    public function test_draft_create_update_and_show_preserve_intent_without_creating_allocations_or_ledger_rows(): void
    {
        [$customer, $order] = $this->salesOrder('100');
        $item = $this->row('sales_order', $order->id, '60');
        $reservation = $this->reservation();
        $response = $this->postJson('/api/v1/erp/finance/cash-documents/receipt', [
            'reservation_token' => $reservation->reservation_token, 'creation_session_id' => $reservation->creation_session_id,
            'party_type' => 'customer', 'party_id' => $customer->id, 'business_date' => now()->toDateString(),
            'finance_account_id' => $this->account->id, 'currency' => 'CNY', 'amount' => '100',
            'payment_method_id' => $this->method->id, 'draft_allocation_items' => [$item],
        ])->assertCreated()->assertJsonPath('data.draft_allocation_items.0.allocated_amount', '60.0000');
        $cash = FinanceCashDocument::findOrFail($response->json('data.id'));
        $this->getJson('/api/v1/erp/finance/cash-documents/show/'.$cash->id)->assertOk()
            ->assertJsonPath('data.draft_allocation_items.0.idempotency_key', $item['idempotency_key'])
            ->assertJsonPath('data.allocated_amount', '0.0000')->assertJsonPath('data.unallocated_amount', '100.0000');
        $this->putJson('/api/v1/erp/finance/cash-documents/'.$cash->id, ['draft_allocation_items' => [[...$item, 'allocated_amount' => '40']]])
            ->assertOk()->assertJsonPath('data.draft_allocation_items.0.allocated_amount', '40.0000');
        $this->putJson('/api/v1/erp/finance/cash-documents/'.$cash->id, ['remark' => '只改备注'])
            ->assertOk()->assertJsonPath('data.draft_allocation_items.0.allocated_amount', '40.0000');
        $this->assertNoPostedFacts($cash);
        $this->putJson('/api/v1/erp/finance/cash-documents/'.$cash->id, ['draft_allocation_items' => []])
            ->assertOk()->assertJsonPath('data.draft_allocation_items', []);
    }

    public function test_draft_validation_rejects_wrong_party_currency_direction_and_malformed_rows(): void
    {
        [$customer, $order] = $this->salesOrder('100');
        [, $wrongParty] = $this->salesOrder('100');
        [, $wrongCurrency] = $this->salesOrder('100', $customer, 'USD');
        $cash = $this->cash($customer, '100');
        foreach ([[$this->row('sales_order', $wrongParty->id, '20'), 'party_id'],
            [$this->row('sales_order', $wrongCurrency->id, '20'), 'currency'],
            [$this->row('sales_order_refund', $order->id, '20'), 'source_business_type'],
            [$this->row('sales_order', $order->id, '0'), 'allocated_amount'],
            [[...$this->row('sales_order', $order->id, '20'), 'idempotency_key' => ''], 'draft_allocation_items.0.idempotency_key']] as [$row, $error]) {
            $this->putJson('/api/v1/erp/finance/cash-documents/'.$cash->id, ['draft_allocation_items' => [$row]])
                ->assertUnprocessable()->assertJsonValidationErrors($error);
            $this->assertNull($cash->fresh()->draft_allocation_items);
            $this->assertNoPostedFacts($cash);
        }
    }

    public function test_saved_draft_intent_does_not_reserve_source_balance_and_confirmation_rechecks_it(): void
    {
        [$customer, $order] = $this->salesOrder('100');
        $cash = $this->cash($customer, '100');
        $items = [$this->row('sales_order', $order->id, '100')];
        app(FinanceCashDocumentApplicationService::class)->updateDraft($cash->id, ['draft_allocation_items' => $items], 1, '测试');
        $other = $this->cash($customer, '100', ['status' => 'confirmed']);
        $this->allocate($other, 'sales_order', $order->id, '100');
        $this->postJson($this->confirmUrl($cash), ['items' => $items])->assertUnprocessable()->assertJsonValidationErrors('allocated_amount');
        $this->assertSame('draft', $cash->fresh()->status);
        $this->assertCount(1, $cash->fresh()->draft_allocation_items);
        $this->assertNoPostedFacts($cash);
        $this->assertSame('100.0000', app(FinanceAllocationApplicationService::class)->activeTotalForSource('sales_order', $order->id));
    }

    public function test_supplier_payment_confirmation_and_resolve_use_real_purchase_settlement_balance(): void
    {
        [$receipt, $line] = $this->purchaseReceiptWithLine();
        $source = app(PurchaseSettlementSourceApplicationService::class)->syncReceipt($receipt->id)[0];
        $supplier = $receipt->supplier;
        $earlier = $this->cash($supplier, '30', ['status' => 'confirmed']);
        $this->allocate($earlier, 'purchase_settlement_source', $source->id, '30');
        $this->resolve('purchase_settlement_source', $source->id)->assertOk()
            ->assertJsonPath('data.amount', '100.0000')->assertJsonPath('data.allocatedAmount', '30.0000')
            ->assertJsonPath('data.remainingAmount', '70.0000');
        $cash = $this->cash($supplier, '70');
        $this->postJson($this->confirmUrl($cash), ['items' => [$this->row('purchase_settlement_source', $source->id, '70')]])
            ->assertOk()->assertJsonPath('data.unallocated_amount', '0.0000');
        $this->assertSame('paid', $source->fresh()->status);
        $this->assertSame('100.0000', (string) $source->fresh()->allocated_amount);
        $this->assertSame('out', FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $cash->id)->firstOrFail()->direction);
    }

    public function test_source_resolve_balances_respect_sales_refunds_legacy_receipts_and_supplier_refunds(): void
    {
        [$customer, $order] = $this->salesOrder('100');
        $paid = $this->cash($customer, '60', ['status' => 'confirmed']);
        $this->allocate($paid, 'sales_order', $order->id, '60');
        $refund = $this->cash($customer, '20', ['status' => 'confirmed', 'direction' => 'payment']);
        $this->allocate($refund, 'sales_order_refund', $order->id, '20');
        $this->resolve('sales_order_refund', $order->id)->assertOk()->assertJsonPath('data.amount', '60.0000')
            ->assertJsonPath('data.allocatedAmount', '20.0000')->assertJsonPath('data.remainingAmount', '40.0000');

        [$receipt] = $this->purchaseReceiptWithLine();
        $receipt->update(['settlement_amount' => '150']);
        $supplier = $receipt->supplier;
        $baseReturn = ['return_scope' => 'posted_inventory', 'source_receipt_id' => $receipt->id,
            'supplier_id' => $supplier->id, 'currency_snapshot' => 'CNY', 'return_date' => now()->toDateString(),
            'return_status' => 'completed', 'audit_status' => 'approved', 'stock_post_status' => 'posted', 'return_reason' => '余额测试'];
        PurchaseReturn::create([...$baseReturn, 'return_no' => $this->code('OFFSET'), 'settlement_effect_type' => 'AP_OFFSET', 'settlement_amount' => '50']);
        $supplierReturn = PurchaseReturn::create([...$baseReturn, 'return_no' => $this->code('RETURN'), 'settlement_effect_type' => 'SUPPLIER_REFUND', 'settlement_amount' => '40']);
        $payment = $this->cash($supplier, '30', ['status' => 'confirmed']);
        $this->allocate($payment, 'purchase_receipt', $receipt->id, '30');
        $this->resolve('purchase_receipt', $receipt->id)->assertOk()->assertJsonPath('data.amount', '100.0000')
            ->assertJsonPath('data.allocatedAmount', '30.0000')->assertJsonPath('data.remainingAmount', '70.0000');
        $receiptRefund = $this->cash($supplier, '10', ['status' => 'confirmed', 'direction' => 'receipt']);
        $this->allocate($receiptRefund, 'purchase_return_supplier_refund', $supplierReturn->id, '10');
        $this->resolve('purchase_return_supplier_refund', $supplierReturn->id)->assertOk()->assertJsonPath('data.amount', '40.0000')
            ->assertJsonPath('data.allocatedAmount', '10.0000')->assertJsonPath('data.remainingAmount', '30.0000');
    }

    public function test_source_selection_filters_currency_before_pagination_and_uses_frozen_receivable_amount(): void
    {
        [$customer, $order] = $this->salesOrder('100');
        $order->update(['total_amount' => '999', 'funding_policy_snapshot' => ['receivable_amount' => '100', 'policy_type' => 'full_prepay', 'shipment_requires_full_payment' => true]]);
        for ($index = 0; $index < 6; $index++) $this->salesOrder('100', $customer, 'USD');
        $this->getJson('/api/v1/erp/finance/sources?type=sales_order&party_id='.$customer->id.'&currency=CNY&per_page=5')
            ->assertOk()->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $order->id)
            ->assertJsonPath('data.0.amount', '100.0000')->assertJsonPath('data.0.remainingAmount', '100.0000');
        $this->resolve('sales_order', $order->id)->assertOk()->assertJsonPath('data.amount', '100.0000')->assertJsonPath('data.remainingAmount', '100.0000');
    }

    private function salesOrder(string $amount, ?SalesCustomer $customer = null, string $currency = 'CNY'): array
    {
        $customer ??= SalesCustomer::create(['customer_code' => $this->code('CUSTOMER'), 'customer_name' => '组合确认测试客户', 'status' => 'enabled']);
        $order = SalesOrder::create([
            'sales_order_no' => $this->code('SO'), 'customer_id' => $customer->id,
            'customer_name' => $customer->customer_name, 'customer_name_snapshot' => $customer->customer_name,
            'order_status' => 'confirmed', 'confirm_status' => 'confirmed', 'total_amount' => $amount, 'currency' => $currency,
            'funding_policy_snapshot' => ['policy_type' => 'full_prepay', 'shipment_requires_full_payment' => true],
        ]);
        return [$customer, $order];
    }

    private function cash(SalesCustomer|Supplier $party, string $amount, array $attributes = []): FinanceCashDocument
    {
        $supplier = $party instanceof Supplier;
        return FinanceCashDocument::create([
            'direction' => $supplier ? 'payment' : 'receipt', 'document_no' => $this->code('CASH'),
            'party_type' => $supplier ? 'supplier' : 'customer', 'party_id' => $party->id,
            'party_name_snapshot' => $supplier ? $party->supplier_name : $party->customer_name,
            'business_date' => now()->toDateString(), 'finance_account_id' => $this->account->id,
            'currency' => 'CNY', 'amount' => $amount, 'payment_method_id' => $this->method->id,
            'payment_method' => $this->method->method_code, 'status' => 'draft', ...$attributes,
        ]);
    }

    private function row(string $type, int $sourceId, string $amount): array
    {
        return ['source_business_type' => $type, 'source_document_id' => $sourceId,
            'allocated_amount' => $amount, 'idempotency_key' => (string) Str::uuid()];
    }

    private function allocate(FinanceCashDocument $cash, string $type, int $id, string $amount): FinanceAllocation
    {
        return app(FinanceAllocationApplicationService::class)->allocate($cash->id, [$this->row($type, $id, $amount)], 1, '测试')['allocations'][0];
    }

    private function confirmUrl(FinanceCashDocument $cash): string
    {
        return '/api/v1/erp/finance/cash-documents/'.$cash->id.'/confirm';
    }

    private function cashMovements(FinanceCashDocument $cash): int
    {
        return FinanceAccountMovement::where('source_type', 'cash_document')->where('source_id', $cash->id)->count();
    }

    private function logs(FinanceCashDocument $cash, string $action): int
    {
        return FinanceOperationLog::where('document_type', 'cash_document')->where('document_id', $cash->id)->where('action', $action)->count();
    }

    private function assertNoPostedFacts(FinanceCashDocument $cash): void
    {
        $this->assertSame(0, $this->cashMovements($cash));
        $this->assertSame(0, $cash->allocations()->count());
        $this->assertSame(0, FinancePlatformFee::where('cash_document_id', $cash->id)->count());
        $this->assertSame(0, $this->logs($cash, 'confirm'));
        $this->assertSame(0, $this->logs($cash, 'allocate'));
    }

    private function reservation(): DocumentNumberReservation
    {
        return DocumentNumberReservation::create([
            'document_type' => 'finance_receipt', 'creation_session_id' => (string) Str::uuid(),
            'document_no' => $this->code('RESERVED'), 'reservation_token' => (string) Str::uuid(),
            'status' => 'reserved', 'reserved_by_legacy_id' => 1, 'expires_at' => now()->addDay(),
        ]);
    }

    private function purchaseReceiptWithLine(): array
    {
        $supplier = Supplier::create(['supplier_code' => $this->code('SUP'), 'supplier_name' => '组合确认测试供应商', 'supplier_type' => 'manufacturer', 'status' => 'enabled']);
        $unit = Unit::create(['unit_code' => $this->code('UNIT'), 'unit_name' => '件', 'decimal_places' => 0, 'status' => 'enabled']);
        $item = Item::create(['item_code' => $this->code('ITEM'), 'item_name' => '组合确认测试物料', 'unit_id' => $unit->id, 'item_type' => 'consumable', 'is_stock_item' => false, 'status' => 'enabled']);
        $receipt = PurchaseReceipt::create([
            'receipt_no' => $this->code('RECEIPT'), 'supplier_id' => $supplier->id, 'receipt_date' => now()->toDateString(),
            'receipt_status' => 'confirmed', 'confirm_status' => 'confirmed', 'stock_post_status' => 'not_required',
            'settlement_mode' => 'normal', 'currency_snapshot' => 'CNY', 'settlement_amount' => '100',
        ]);
        $line = PurchaseReceiptItem::create([
            'receipt_id' => $receipt->id, 'item_id' => $item->id, 'receipt_qty' => 10, 'qualified_qty' => 10,
            'unit_price' => 10, 'receipt_cost' => 100, 'amount_excl_tax' => 100, 'tax_amount_snapshot' => 0,
            'amount_incl_tax' => 100, 'settlement_amount' => 100, 'qualified_payable_amount' => 100,
            'quality_hold_amount' => 0, 'currency_snapshot' => 'CNY', 'finance_fact_status' => 'frozen',
        ]);
        return [$receipt, $line];
    }

    private function resolve(string $type, int $id): \Illuminate\Testing\TestResponse
    {
        return $this->getJson('/api/v1/erp/finance/sources/resolve?type='.$type.'&id='.$id);
    }

    private function code(string $prefix): string
    {
        return 'CASHFLOW-'.$prefix.'-'.Str::upper(Str::random(10));
    }
}
