<?php

namespace Tests\Unit\Erp;

use App\Models\Erp\PurchaseExchangeOrder;
use App\Models\Erp\PurchaseReceiptItem;
use App\Models\Erp\PurchaseReturn;
use App\Models\Erp\PurchaseReturnItem;
use App\Services\Erp\InventoryAvailabilityService;
use App\Services\Erp\PurchaseFinancialFactService;
use App\Services\Erp\PurchaseFinanceFactSourceService;
use PHPUnit\Framework\TestCase;

class PurchaseFinancialFactServiceTest extends TestCase
{
    public function test_tax_included_amount_is_split_without_increasing_contract_amount(): void
    {
        $facts = (new PurchaseFinancialFactService)->amountFacts(113, 13, 'tax_included');

        $this->assertSame(100.0, $facts['amount_excl_tax']);
        $this->assertSame(13.0, $facts['tax_amount_snapshot']);
        $this->assertSame(113.0, $facts['amount_incl_tax']);
    }

    public function test_tax_excluded_amount_adds_tax_only_to_included_amount(): void
    {
        $facts = (new PurchaseFinancialFactService)->amountFacts(100, 13, 'tax_excluded');

        $this->assertSame(100.0, $facts['amount_excl_tax']);
        $this->assertSame(13.0, $facts['tax_amount_snapshot']);
        $this->assertSame(113.0, $facts['amount_incl_tax']);
    }

    public function test_return_amount_is_derived_from_frozen_receipt_snapshot(): void
    {
        $source = new PurchaseReceiptItem([
            'order_item_id' => 81,
            'original_received_base_qty' => 3,
            'amount_excl_tax' => 300,
            'tax_amount_snapshot' => 39,
            'amount_incl_tax' => 339,
            'currency_snapshot' => 'CNY',
            'tax_mode_snapshot' => 'tax_included',
            'tax_rate' => 13,
            'finance_fact_status' => 'frozen',
        ]);

        $facts = (new PurchaseFinancialFactService)->proportionalFacts($source, 1);

        $this->assertSame(100.0, $facts['return_amount_excl_tax']);
        $this->assertSame(13.0, $facts['return_tax_amount']);
        $this->assertSame(113.0, $facts['return_amount_incl_tax']);
        $this->assertSame(81, $facts['original_purchase_line_id']);
        $this->assertSame('frozen', $facts['finance_fact_status']);
    }

    public function test_exchange_fact_has_zero_new_payable_but_keeps_original_inventory_cost(): void
    {
        $exchange = new PurchaseExchangeOrder([
            'exchange_no' => 'PEX-UNIT-001',
            'supplier_id' => 17,
            'source_receipt_item_id' => 22,
            'replacement_payable_amount' => 0,
            'replacement_inventory_cost' => 100,
            'currency_snapshot' => 'CNY',
        ]);
        $exchange->id = 9;

        $fact = (new PurchaseFinanceFactSourceService)->purchaseExchange($exchange);

        $this->assertSame('PURCHASE_EXCHANGE', $fact['source_business_type']);
        $this->assertSame(0.0, $fact['settlement_amount']);
        $this->assertSame(0.0, $fact['amount_incl_tax']);
        $this->assertSame(100.0, $fact['cost_amount']);
        $this->assertSame(22, $fact['original_source_id']);
    }

    public function test_return_fact_is_a_reversal_of_the_frozen_original_fact(): void
    {
        $return = new PurchaseReturn([
            'return_no' => 'PRT-UNIT-001',
            'supplier_id' => 17,
            'return_date' => '2026-08-15',
        ]);
        $return->id = 10;
        $line = new PurchaseReturnItem([
            'source_receipt_item_id' => 22,
            'currency_snapshot' => 'CNY',
            'return_amount_excl_tax' => 100,
            'return_tax_amount' => 13,
            'return_amount_incl_tax' => 113,
            'settlement_amount' => 113,
            'inventory_cost_amount' => 100,
        ]);
        $line->id = 11;
        $line->return_id = 10;
        $line->setRelation('purchaseReturn', $return);

        $fact = (new PurchaseFinanceFactSourceService)->purchaseReturn($line);

        $this->assertSame('REVERSAL', $fact['direction']);
        $this->assertSame(-100.0, $fact['amount_excl_tax']);
        $this->assertSame(-13.0, $fact['tax_amount']);
        $this->assertSame(-113.0, $fact['settlement_amount']);
        $this->assertSame(-100.0, $fact['cost_amount']);
    }

    public function test_inventory_availability_has_one_shared_calculation_boundary(): void
    {
        $availability = new InventoryAvailabilityService;

        $this->assertSame(72.0, $availability->calculate(100, 10, 7, 11));
        $this->assertSame(0.0, $availability->calculate(5, 4, 3, 2));
    }
}
