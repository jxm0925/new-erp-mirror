<?php

namespace App\Models\Erp;

class UnitConversion extends MasterModel
{
    protected $table = 'erp_unit_conversions';
    public function sourceUnit() { return $this->belongsTo(Unit::class, 'source_unit_id'); }
    public function targetUnit() { return $this->belongsTo(Unit::class, 'target_unit_id'); }
}
