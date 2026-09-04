<?php

namespace App\Models\Erp;

class InventoryPostingLog extends MasterModel
{
    protected $table = 'erp_inventory_posting_logs';
    protected $casts = ['posted_at' => 'datetime'];
}
