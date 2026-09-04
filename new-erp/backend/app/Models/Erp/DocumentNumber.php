<?php

namespace App\Models\Erp;

class DocumentNumber extends MasterModel
{
    protected $table = 'erp_document_numbers';

    protected $casts = [
        'number_date' => 'date',
    ];
}
