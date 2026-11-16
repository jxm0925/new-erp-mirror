<?php

namespace App\Models\Erp;

class CrmDrawingRequest extends MasterModel
{
    protected $table = 'erp_crm_drawing_requests';
    protected $casts = ['reference_attachment_ids' => 'array', 'version' => 'integer'];

    public function revisions() { return $this->hasMany(CrmDrawingRequestRevision::class, 'request_id'); }
}
