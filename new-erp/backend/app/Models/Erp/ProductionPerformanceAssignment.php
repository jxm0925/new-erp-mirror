<?php

namespace App\Models\Erp;

class ProductionPerformanceAssignment extends MasterModel
{
    protected $table = 'erp_production_performance_assignments';
    protected $casts = ['scope_snapshot' => 'array', 'version_no' => 'integer', 'noncredited_confirmed' => 'boolean',
        'credited_share_ratio' => 'decimal:8', 'noncredited_share_ratio' => 'decimal:8', 'confirmed_at' => 'datetime:Y-m-d H:i:s'];

    public function shares() { return $this->hasMany(ProductionPerformanceShare::class, 'assignment_id')->orderBy('employee_legacy_id'); }
}
