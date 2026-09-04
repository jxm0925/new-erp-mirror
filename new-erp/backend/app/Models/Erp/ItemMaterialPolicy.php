<?php

namespace App\Models\Erp;

class ItemMaterialPolicy extends MasterModel
{
    protected $table = 'erp_item_material_policies';

    protected $casts = [
        'is_stock_managed' => 'boolean',
        'requires_custodian' => 'boolean',
        'is_returnable' => 'boolean',
        'requires_capitalization' => 'boolean',
        'parameter_snapshot' => 'array',
        'effective_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function item() { return $this->belongsTo(Item::class); }
}
