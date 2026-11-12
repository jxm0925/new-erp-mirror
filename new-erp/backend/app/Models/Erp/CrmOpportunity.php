<?php

namespace App\Models\Erp;

class CrmOpportunity extends MasterModel
{
    protected $table = 'erp_crm_opportunities';

    protected $casts = [
        'source_snapshot' => 'array', 'expected_amount' => 'decimal:2', 'version' => 'integer',
        'expected_close_date' => 'date:Y-m-d', 'stage_entered_at' => 'datetime', 'won_at' => 'datetime', 'lost_at' => 'datetime',
        'first_contact_at' => 'datetime', 'last_contact_at' => 'datetime', 'next_contact_at' => 'datetime',
    ];

    public function customer() { return $this->belongsTo(SalesCustomer::class, 'customer_id'); }
    public function sourceLead() { return $this->belongsTo(CrmLead::class, 'source_lead_id'); }
    public function files() { return $this->hasMany(CrmOpportunityFile::class, 'opportunity_id'); }
}
