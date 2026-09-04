<?php

namespace App\Models\Erp;

class ApprovalFormTemplate extends MasterModel
{
    protected $table = 'erp_approval_form_templates';

    public function versions() { return $this->hasMany(ApprovalFormVersion::class, 'form_template_id'); }
    public function submissions() { return $this->hasMany(ApprovalFormSubmission::class, 'form_template_id'); }
}
