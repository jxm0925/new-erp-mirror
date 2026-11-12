<?php

namespace App\Models\Erp;

class CrmSavedView extends MasterModel
{
    protected $table = 'erp_crm_saved_views';

    protected $casts = [
        'configuration' => 'array', 'owner_legacy_id' => 'integer', 'scope_id' => 'integer', 'version' => 'integer',
    ];
}
