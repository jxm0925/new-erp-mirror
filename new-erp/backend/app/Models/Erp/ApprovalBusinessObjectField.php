<?php

namespace App\Models\Erp;

class ApprovalBusinessObjectField extends MasterModel
{
    protected $table = 'erp_approval_business_object_fields';
    protected $casts = ['options' => 'array', 'condition_enabled' => 'boolean', 'display_enabled' => 'boolean', 'reference_enabled' => 'boolean', 'approval_writable' => 'boolean'];
}
