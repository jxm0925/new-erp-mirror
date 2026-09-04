<?php

namespace App\Models\Erp;

class FinanceAttachment extends MasterModel
{
    protected $table = 'erp_finance_attachments';
    protected $casts = ['uploaded_at' => 'datetime'];
}
