<?php

namespace App\Models\Erp;

class ProductionKittingConfirmation extends MasterModel
{
    protected $table = 'erp_production_kitting_confirmations';
    protected $casts = [
        'required_materials_snapshot' => 'array', 'received_materials_snapshot' => 'array',
        'shortage_materials_snapshot' => 'array', 'confirmed_at' => 'datetime', 'business_version' => 'integer',
    ];
    public function lines() { return $this->hasMany(ProductionKittingConfirmationLine::class, 'confirmation_id'); }
}
