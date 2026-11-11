<?php

namespace App\Models\Erp;

class CrmFollowup extends MasterModel
{
    protected $table = 'erp_crm_followups';

    protected $casts = ['contacted_at' => 'datetime', 'next_contact_at' => 'datetime', 'version' => 'integer', 'legacy_payload' => 'array'];

    public function attachments() { return $this->hasMany(CrmAttachment::class, 'subject_id')->where('subject_type', 'followup')->where('is_active', true); }
}
