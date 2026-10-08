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
    public function getEquipmentNoAttribute($value): ?string
    {
        if ($value !== null || $this->source_type !== 'production_unit_identity') return $value;
        $id = $this->source_id; $seen = [];
        while ($id && ! isset($seen[$id])) {
            $seen[$id] = true;
            $source = \Illuminate\Support\Facades\DB::table($this->table)->where('id', $id)->first();
            if (! $source) return null;
            if ($source->equipment_no !== null) return $source->equipment_no;
            $id = $source->source_type === 'production_unit_identity' ? $source->source_id : null;
        }
        return null;
    }
}
