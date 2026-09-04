<?php

namespace App\Models\Erp;

class SalesCustomer extends MasterModel
{
    protected $table = 'erp_sales_customers';

    protected $casts = [
        'legacy_snapshot' => 'array',
    ];

    public function contacts() { return $this->hasMany(SalesCustomerContact::class, 'customer_id'); }
    public function addresses() { return $this->hasMany(SalesCustomerAddress::class, 'customer_id'); }
}
