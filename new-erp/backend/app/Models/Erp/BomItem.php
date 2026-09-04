<?php

namespace App\Models\Erp;

class BomItem extends MasterModel
{
    protected $table = 'erp_bom_items';
    protected $casts = [
        'replaceable' => 'boolean',
        'qty' => 'decimal:4',
        'loss_rate' => 'decimal:4',
        'fixed_qty' => 'decimal:4',
    ];

    public function bom() { return $this->belongsTo(Bom::class, 'bom_id'); }
    public function componentItem() { return $this->belongsTo(Item::class, 'component_item_id'); }
    public function unit() { return $this->belongsTo(Unit::class); }
}
