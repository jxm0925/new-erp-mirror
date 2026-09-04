<?php

namespace App\Models\Erp;

class InventoryBatch extends MasterModel
{
    protected $table = 'erp_inventory_batches';
    protected $casts = [
        'production_date' => 'date',
        'expire_date' => 'date',
    ];
}
