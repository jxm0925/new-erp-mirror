<?php

namespace App\Domain\Finance;

final class FinanceConstants
{
    public const DIRECTION_RECEIPT = 'receipt';
    public const DIRECTION_PAYMENT = 'payment';

    public const INVOICE_SALES = 'sales';
    public const INVOICE_PURCHASE = 'purchase';

    public const STATUS_DRAFT = 'draft';
    public const STATUS_CONFIRMED = 'confirmed';
    public const STATUS_RED = 'red';
    public const STATUS_VOIDED = 'voided';

    public const ALLOCATION_ACTIVE = 'active';
    public const ALLOCATION_REVERSED = 'reversed';
    public const ALLOCATION_REVERSAL = 'reversal';

    public const PARTY_CUSTOMER = 'customer';
    public const PARTY_SUPPLIER = 'supplier';
    public const PARTY_OTHER = 'other';

    public const SOURCE_SALES_ORDER = 'sales_order';
    public const SOURCE_SALES_ORDER_REFUND = 'sales_order_refund';
    public const SOURCE_PURCHASE_RECEIPT = 'purchase_receipt';
    public const SOURCE_PURCHASE_SETTLEMENT_SOURCE = 'purchase_settlement_source';
    public const SOURCE_PURCHASE_RETURN_AP_OFFSET = 'purchase_return_ap_offset';
    public const SOURCE_PURCHASE_RETURN_SUPPLIER_REFUND = 'purchase_return_supplier_refund';
    public const SOURCE_PURCHASE_EXCHANGE = 'purchase_exchange';

    public const PURCHASE_EFFECT_AP_OFFSET = 'AP_OFFSET';
    public const PURCHASE_EFFECT_SUPPLIER_REFUND = 'SUPPLIER_REFUND';
    public const PURCHASE_EFFECT_PENDING = 'PENDING';
    public const PURCHASE_EFFECT_REFUSE_PAY = 'REFUSE_PAY';

    public static function directions(): array { return [self::DIRECTION_RECEIPT, self::DIRECTION_PAYMENT]; }
    public static function partyTypes(): array { return [self::PARTY_CUSTOMER, self::PARTY_SUPPLIER, self::PARTY_OTHER]; }
}
