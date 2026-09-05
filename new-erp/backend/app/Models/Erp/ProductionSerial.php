<?php

namespace App\Models\Erp;

class ProductionSerial extends MasterModel
{
    protected $table = 'erp_production_serials';
    protected $casts = ['generated_at' => 'datetime'];
    public function item() { return $this->belongsTo(Item::class, 'item_id'); }
}
