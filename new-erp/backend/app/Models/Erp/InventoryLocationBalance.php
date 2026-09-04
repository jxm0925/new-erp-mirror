<?php

namespace App\Models\Erp;

class InventoryLocationBalance extends MasterModel
{
    protected $table = 'erp_inventory_location_balances';
    protected $casts = ['last_transaction_at' => 'datetime'];
}
