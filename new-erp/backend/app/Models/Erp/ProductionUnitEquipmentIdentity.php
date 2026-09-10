<?php

namespace App\Models\Erp;

class ProductionUnitEquipmentIdentity extends MasterModel
{
    protected $table = 'erp_production_unit_equipment_identities';

    protected $casts = [
        'bound_at' => 'datetime',
        'business_version' => 'integer',
    ];

    public function productionUnit() { return $this->belongsTo(ProductionUnit::class, 'production_unit_id'); }
}
