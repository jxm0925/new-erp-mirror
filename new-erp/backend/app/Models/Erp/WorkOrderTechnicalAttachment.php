<?php

namespace App\Models\Erp;

class WorkOrderTechnicalAttachment extends MasterModel
{
    protected $table = 'erp_work_order_technical_attachments';

    protected $casts = ['confirmed_at' => 'datetime', 'file_size' => 'integer'];
    protected $hidden = ['storage_disk', 'storage_path', 'request_hash', 'client_command_id'];
}
