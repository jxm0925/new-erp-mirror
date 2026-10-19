<?php

namespace App\Models\Erp;

class ProductionPerformanceTemplateLine extends MasterModel
{
    protected $table = 'erp_production_performance_template_lines';
    protected $casts = ['performance_rate' => 'decimal:6'];

    public function operation() { return $this->belongsTo(ProductionOperation::class, 'operation_id'); }
}
