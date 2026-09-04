<?php

namespace App\Models\Erp;

class ApprovalBusinessAction extends MasterModel
{
    protected $table = 'erp_approval_business_actions';
    protected $casts = ['config_schema' => 'array', 'enabled' => 'boolean'];
    public function businessObject() { return $this->belongsTo(ApprovalBusinessObject::class, 'business_object_id'); }
}
