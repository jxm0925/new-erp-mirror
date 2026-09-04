<?php

namespace App\Models\Erp;

class DocumentNumberReservation extends MasterModel
{
    protected $table = 'erp_document_number_reservations';

    protected $casts = [
        'expires_at' => 'datetime',
        'consumed_at' => 'datetime',
    ];
}
