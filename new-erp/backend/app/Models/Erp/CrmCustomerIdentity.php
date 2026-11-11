<?php

namespace App\Models\Erp;

class CrmCustomerIdentity extends MasterModel
{
    protected $table = 'erp_crm_customer_identities';

    protected $casts = ['bound_at' => 'datetime', 'version' => 'integer'];

    public function customer() { return $this->belongsTo(SalesCustomer::class, 'customer_id'); }
}
