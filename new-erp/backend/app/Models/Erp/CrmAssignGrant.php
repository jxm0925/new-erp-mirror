<?php

namespace App\Models\Erp;

class CrmAssignGrant extends MasterModel
{
    protected $table = 'erp_crm_assign_grants';

    protected $casts = [
        'department_ids' => 'array', 'channels' => 'array', 'actions' => 'array', 'assignee_ids' => 'array',
        'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d', 'version' => 'integer',
    ];
}
