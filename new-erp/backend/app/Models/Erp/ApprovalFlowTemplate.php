<?php

namespace App\Models\Erp;

class ApprovalFlowTemplate extends MasterModel
{
    protected $table = 'erp_approval_flow_templates';

    public function versions() { return $this->hasMany(ApprovalFlowVersion::class, 'flow_template_id'); }
    public function currentVersion() { return $this->hasOne(ApprovalFlowVersion::class, 'flow_template_id', 'id')->whereColumn('version_no', 'erp_approval_flow_templates.current_version'); }
}
