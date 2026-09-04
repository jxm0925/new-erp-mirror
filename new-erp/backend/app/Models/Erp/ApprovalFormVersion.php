<?php

namespace App\Models\Erp;

class ApprovalFormVersion extends MasterModel
{
    protected $table = 'erp_approval_form_versions';
    protected $casts = ['schema_snapshot' => 'array', 'validation_snapshot' => 'array', 'published_at' => 'datetime'];

    public function template() { return $this->belongsTo(ApprovalFormTemplate::class, 'form_template_id'); }
}
