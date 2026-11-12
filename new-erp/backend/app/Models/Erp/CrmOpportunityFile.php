<?php

namespace App\Models\Erp;

class CrmOpportunityFile extends MasterModel
{
    protected $table = 'erp_crm_opportunity_files';
    protected $casts = ['version_no' => 'integer', 'confirmed_at' => 'datetime'];

    public function opportunity() { return $this->belongsTo(CrmOpportunity::class, 'opportunity_id'); }
    public function attachment() { return $this->belongsTo(CrmAttachment::class, 'attachment_id'); }
}
