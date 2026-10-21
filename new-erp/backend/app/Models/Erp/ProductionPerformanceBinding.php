<?php

namespace App\Models\Erp;

class ProductionPerformanceBinding extends MasterModel
{
    public const DEFAULT_KEY = 'default';

    protected $table = 'erp_production_performance_bindings';
    protected $casts = ['routing_id' => 'integer', 'template_id' => 'integer'];

    public static function routingKey(int $routingId): string { return 'routing:'.$routingId; }

    public function template() { return $this->belongsTo(ProductionPerformanceTemplate::class, 'template_id'); }
}
