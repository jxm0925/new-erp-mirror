<?php

namespace App\Models\Erp;

class SupplierCategoryCapability extends MasterModel
{
    protected $table = 'erp_supplier_category_capabilities';

    protected $casts = [
        'effective_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function category()
    {
        return $this->belongsTo(ItemCategory::class, 'item_category_id');
    }
}
