<?php

namespace App\Models\Erp;

use Illuminate\Database\Eloquent\Model;

class SupplierItemRelationLog extends Model
{
    public $timestamps = false;

    protected $table = 'erp_supplier_item_relation_logs';

    protected $guarded = [];

    protected $casts = [
        'old_snapshot' => 'array',
        'new_snapshot' => 'array',
        'created_at' => 'datetime',
    ];

    public function relation()
    {
        return $this->belongsTo(SupplierItemRelation::class, 'relation_id');
    }

    public function supplier()
    {
        return $this->belongsTo(Supplier::class);
    }

    public function item()
    {
        return $this->belongsTo(Item::class);
    }
}
