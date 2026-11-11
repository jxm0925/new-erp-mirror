<?php

namespace App\Models\Erp;

class CrmCustomerCollaborator extends MasterModel
{
    protected $table = 'erp_crm_customer_collaborators';

    protected $casts = [
        'scope' => 'array',
        'actions' => 'array',
        'effective_from' => 'date:Y-m-d',
        'effective_to' => 'date:Y-m-d',
        'ended_at' => 'datetime',
        'version' => 'integer',
    ];

    public function customer() { return $this->belongsTo(SalesCustomer::class, 'customer_id'); }
}
