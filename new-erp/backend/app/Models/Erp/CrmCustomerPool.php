<?php

namespace App\Models\Erp;

class CrmCustomerPool extends MasterModel
{
    protected $table = 'erp_crm_customer_pools';

    protected $casts = ['applicable_scopes' => 'array', 'legacy_aliases' => 'array', 'sort_order' => 'integer'];
}
