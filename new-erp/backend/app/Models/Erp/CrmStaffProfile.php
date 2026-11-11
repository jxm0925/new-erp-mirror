<?php

namespace App\Models\Erp;

class CrmStaffProfile extends MasterModel
{
    protected $table = 'erp_crm_staff_profiles';

    protected $casts = ['tags' => 'array', 'accepting' => 'boolean', 'confirmed_at' => 'datetime', 'version' => 'integer'];
}
