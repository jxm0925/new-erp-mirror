<?php

namespace App\Models\Erp;

class AftersalesAttachment extends MasterModel
{
    protected $table = 'erp_aftersales_attachments';
    protected $hidden = ['url', 'storage_disk', 'storage_path', 'draft_token'];
    protected $casts = ['case_id' => 'integer', 'claim_id' => 'integer', 'file_size' => 'integer',
        'uploaded_by_legacy_id' => 'integer', 'uploaded_at' => 'datetime'];

    public function case() { return $this->belongsTo(AftersalesCase::class, 'case_id'); }
    public function claim() { return $this->belongsTo(AftersalesClaim::class, 'claim_id'); }
}
