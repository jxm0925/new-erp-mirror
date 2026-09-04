<?php

namespace App\Models\Erp;

class SalesOrderVersion extends MasterModel
{
    protected $table = 'erp_sales_order_versions';

    protected $casts = [
        'before_snapshot' => 'array',
        'after_snapshot' => 'array',
        'structured_diffs' => 'array',
        'impact_summary' => 'array',
        'approval_reasons' => 'array',
        'immediate_effect' => 'boolean',
    ];

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function candidate() { return $this->belongsTo(SalesOrderChangeCandidate::class, 'candidate_id'); }
}
