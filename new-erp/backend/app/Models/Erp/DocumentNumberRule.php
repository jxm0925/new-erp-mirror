<?php

namespace App\Models\Erp;

class DocumentNumberRule extends MasterModel
{
    protected $table = 'erp_document_number_rules';

    protected $casts = [
        'allow_manual_edit' => 'boolean',
        'enabled' => 'boolean',
    ];
}
