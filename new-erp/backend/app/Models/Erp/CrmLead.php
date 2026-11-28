<?php

namespace App\Models\Erp;

class CrmLead extends MasterModel
{
    protected $table = 'erp_crm_leads';

    protected $casts = [
        'customer_tags' => 'array', 'custom_tags' => 'array', 'customer_business_lines' => 'array',
        'customer_input_snapshot' => 'array', 'legacy_payload' => 'array', 'is_new_inquiry' => 'boolean',
        'expected_amount' => 'decimal:2', 'version' => 'integer', 'confirmed_at' => 'datetime',
        'assigned_at' => 'datetime', 'accepted_at' => 'datetime', 'recycled_at' => 'datetime',
        'first_contact_at' => 'datetime', 'last_contact_at' => 'datetime', 'next_contact_at' => 'datetime',
    ];

    public function latestFollowup() { return $this->hasOne(CrmFollowup::class, 'subject_id')->ofMany(['contacted_at' => 'max', 'id' => 'max'], fn ($q) => $q->where('subject_type', 'lead')); }

    public function customer() { return $this->belongsTo(SalesCustomer::class, 'customer_id'); }
    public function attachments() { return $this->hasMany(CrmAttachment::class, 'subject_id')->where('subject_type', 'lead')->where('is_active', true); }
}
