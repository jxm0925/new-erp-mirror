<?php

namespace App\Models\Erp;

class SalesCustomerContact extends MasterModel
{
    protected $table = 'erp_sales_customer_contacts';

    protected $casts = ['is_default' => 'boolean'];

    public function customer() { return $this->belongsTo(SalesCustomer::class, 'customer_id'); }
}
