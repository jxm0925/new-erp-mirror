<?php

namespace App\Models\Erp;

class ApprovalBusinessEvent extends MasterModel
{
    protected $table = 'erp_approval_business_events';
    protected $casts = ['manual_start_allowed' => 'boolean', 'event_trigger_allowed' => 'boolean', 'enabled' => 'boolean'];
    public function businessObject() { return $this->belongsTo(ApprovalBusinessObject::class, 'business_object_id'); }
}
