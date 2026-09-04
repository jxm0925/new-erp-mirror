<?php

namespace App\Models\Erp;

class ApprovalFormSubmission extends MasterModel
{
    protected $table = 'erp_approval_form_submissions';
    protected $casts = ['form_data' => 'array', 'submitted_at' => 'datetime'];

    public function template() { return $this->belongsTo(ApprovalFormTemplate::class, 'form_template_id'); }
    public function version() { return $this->belongsTo(ApprovalFormVersion::class, 'form_version_id'); }
}
