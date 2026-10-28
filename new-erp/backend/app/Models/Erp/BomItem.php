<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\SoftDeletes;

class BomItem extends MasterModel
{
    use SoftDeletes;

    protected $table = 'erp_bom_items';
    protected $casts = [
        'replaceable' => 'boolean',
        'qty' => 'decimal:4',
        'loss_rate' => 'decimal:4',
        'fixed_qty' => 'decimal:4',
        'cut_length_mm' => 'decimal:2',
        'cut_width_mm' => 'decimal:2',
        'cut_thickness_mm' => 'decimal:2',
        'allow_cut_rotation' => 'boolean',
        'piece_qty' => 'integer',
        'cutting_quantity_snapshot' => 'array',
    ];

    public function bom() { return $this->belongsTo(Bom::class, 'bom_id')->withTrashed(); }
    public function componentItem() { return $this->belongsTo(Item::class, 'component_item_id'); }
    public function unit() { return $this->belongsTo(Unit::class); }
    public function operationBinding() { return $this->hasOne(BomItemOperation::class, 'bom_item_id'); }
}
