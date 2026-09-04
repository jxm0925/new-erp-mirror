<?php

namespace App\Models\Erp;

class PurchaseAttachment extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_attachments';

    protected $casts = [
        'uploaded_at' => 'datetime',
        'deleted_at' => 'datetime',
        'metadata' => 'array',
    ];
}
