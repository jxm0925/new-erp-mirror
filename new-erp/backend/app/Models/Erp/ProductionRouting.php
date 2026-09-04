<?php

namespace App\Models\Erp;

class ProductionRouting extends MasterModel
{
    protected $table = 'erp_production_routings';
    protected $casts = ['version' => 'integer', 'is_default' => 'boolean', 'business_version' => 'integer'];

    public function operations()
    {
        return $this->hasMany(ProductionRoutingOperation::class, 'routing_id')->orderBy('sequence');
    }

    public function outputItem() { return $this->belongsTo(Item::class, 'output_item_id'); }
    public function product() { return $this->belongsTo(Product::class); }
    public function sku() { return $this->belongsTo(Sku::class); }
}
