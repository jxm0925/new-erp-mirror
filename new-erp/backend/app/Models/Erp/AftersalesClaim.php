<?php

namespace App\Models\Erp;

class AftersalesClaim extends MasterModel
{
    protected $table = 'erp_aftersales_claims';
    protected $casts = ['case_id' => 'integer', 'carrier_id' => 'integer', 'supplier_id' => 'integer',
        'related_doc_id' => 'integer', 'claim_amount' => 'decimal:4', 'paid_amount' => 'decimal:4',
        'claimed_on' => 'date', 'paid_on' => 'date'];

    public const STATUSES = ['submitted', 'paid', 'partially_paid', 'rejected', 'abandoned'];

    public function case() { return $this->belongsTo(AftersalesCase::class, 'case_id'); }
}
