<?php

namespace App\Models\Erp;

class SalesOrderChangeCandidateApproval extends MasterModel
{
    protected $table = 'erp_sales_order_change_candidate_approvals';

    protected $casts = ['decided_at' => 'datetime'];

    public function candidate() { return $this->belongsTo(SalesOrderChangeCandidate::class, 'candidate_id'); }
}
