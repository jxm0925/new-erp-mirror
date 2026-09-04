<?php

namespace App\Models\Erp;

class SalesOrderChangeCandidate extends MasterModel
{
    protected $table = 'erp_sales_order_change_candidates';

    protected $casts = [
        'candidate_order_snapshot' => 'array',
        'structured_diffs' => 'array',
        'impact_summary' => 'array',
        'approval_requirements' => 'array',
        'approval_reasons' => 'array',
        'production_impact' => 'array',
        'submitted_at' => 'datetime',
        'activated_at' => 'datetime',
    ];

    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function approvals() { return $this->hasMany(SalesOrderChangeCandidateApproval::class, 'candidate_id'); }
}
