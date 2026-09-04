<?php

namespace App\Models\Erp;

class ApprovalFlowVersion extends MasterModel
{
    protected $table = 'erp_approval_flow_versions';
    protected $casts = ['definition_snapshot' => 'array', 'validation_snapshot' => 'array', 'published_at' => 'datetime'];

    public function template() { return $this->belongsTo(ApprovalFlowTemplate::class, 'flow_template_id'); }
}
