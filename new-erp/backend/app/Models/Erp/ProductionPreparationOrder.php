<?php

namespace App\Models\Erp;

class ProductionPreparationOrder extends MasterModel
{
    protected $table = 'erp_production_preparation_orders';

    protected $casts = ['business_version' => 'integer'];

    public function masterOrder() { return $this->belongsTo(ProductionMasterOrder::class, 'production_master_order_id'); }
    public function lines() { return $this->hasMany(ProductionPreparationOrderLine::class, 'preparation_order_id'); }
}
