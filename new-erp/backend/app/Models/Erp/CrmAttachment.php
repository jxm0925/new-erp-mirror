<?php

namespace App\Models\Erp;

class CrmAttachment extends MasterModel
{
    protected $table = 'erp_crm_attachments';
    protected $hidden = ['storage_disk', 'storage_path'];
    protected $casts = ['is_active' => 'boolean', 'removed_at' => 'datetime'];
}
