<?php

namespace App\Models\Erp;

class MaterialDeliveryWave extends MasterModel
{
    protected $table = 'erp_material_delivery_waves';
    protected $casts = ['business_version' => 'integer'];

    public function preparationOrder() { return $this->belongsTo(ProductionPreparationOrder::class, 'production_preparation_order_id'); }
    public function masterOrder() { return $this->belongsTo(ProductionMasterOrder::class, 'production_master_order_id'); }
    public function deliveryTasks() { return $this->hasMany(MaterialDelivery::class, 'delivery_wave_id'); }
}
