<?php

namespace App\Models\Erp;

class Warehouse extends MasterModel
{
    protected $table = 'erp_warehouses';
    public function locations() { return $this->hasMany(Location::class); }
}
