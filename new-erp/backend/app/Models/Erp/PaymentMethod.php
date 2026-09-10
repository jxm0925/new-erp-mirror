<?php

namespace App\Models\Erp;

class PaymentMethod extends MasterModel
{
    protected $table = 'erp_payment_methods';

    protected $casts = [
        'available_for_sales' => 'boolean',
        'available_for_receipt' => 'boolean',
        'available_for_payment' => 'boolean',
        'business_version' => 'integer',
    ];
}
