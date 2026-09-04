<?php

namespace App\Models\Erp;

class SalesOrderChange extends MasterModel
{
    protected $table = 'erp_sales_order_changes';

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'structured_diffs' => 'array',
        'impact_summary' => 'array',
        'approval_requirements' => 'array',
        'approval_reasons' => 'array',
        'immediate_effect' => 'boolean',
        'applied_at' => 'datetime',
    ];

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function candidate() { return $this->belongsTo(SalesOrderChangeCandidate::class, 'candidate_id'); }
}
