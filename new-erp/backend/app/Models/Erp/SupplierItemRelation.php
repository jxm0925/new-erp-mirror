<?php

namespace App\Models\Erp;

class SupplierItemRelation extends MasterModel
{
    protected $table = 'erp_supplier_item_relations';

    protected $casts = [
        'is_default' => 'boolean',
        'effective_at' => 'datetime',
        'expired_at' => 'datetime',
    ];

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }

    public function logs()
    {
        return $this->hasMany(SupplierItemRelationLog::class, 'relation_id');
    }
}
