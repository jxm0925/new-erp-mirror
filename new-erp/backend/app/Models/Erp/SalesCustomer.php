<?php

namespace App\Models\Erp;

class SalesCustomer extends MasterModel
{
    protected $table = 'erp_sales_customers';

    protected $casts = [
        'legacy_snapshot' => 'array',
        'business_lines' => 'array',
        'first_contact_at' => 'datetime',
        'last_contact_at' => 'datetime',
        'next_contact_at' => 'datetime',
        'crm_version' => 'integer',
    ];

    public function contacts() { return $this->hasMany(SalesCustomerContact::class, 'customer_id'); }
    public function addresses() { return $this->hasMany(SalesCustomerAddress::class, 'customer_id'); }
    public function identities() { return $this->hasMany(CrmCustomerIdentity::class, 'customer_id'); }
    public function collaborators() { return $this->hasMany(CrmCustomerCollaborator::class, 'customer_id'); }
}
