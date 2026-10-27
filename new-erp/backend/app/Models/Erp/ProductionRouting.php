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

    public function product() { return $this->belongsTo(Product::class); }
    public function sku() { return $this->belongsTo(Sku::class); }
    public function applicabilities() { return $this->hasMany(ProductionRoutingApplicability::class, 'routing_id')->orderBy('id'); }

    /** 适用于该物料的路线：适用范围包含该物料，或包含其所在类目（含上级类目）。 */
    public function scopeApplicableToItem($query, int $itemId)
    {
        $categories = ProductionRoutingApplicability::categoryChain($itemId);
        return $query->whereHas('applicabilities', fn ($a) => $a->where('item_id', $itemId)->orWhereIn('item_category_id', $categories ?: [0]));
    }
}
