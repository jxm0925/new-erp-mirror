<?php

namespace App\Models\Erp;

class ProductionPerformanceTemplate extends MasterModel
{
    protected $table = 'erp_production_performance_templates';
    protected $casts = ['business_version' => 'integer', 'last_applied_at' => 'datetime'];

    public function lines() { return $this->hasMany(ProductionPerformanceTemplateLine::class, 'template_id')->orderBy('id'); }
}
