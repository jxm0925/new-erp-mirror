<?php

namespace App\Models\Erp;

class AftersalesLog extends MasterModel
{
    protected $table = 'erp_aftersales_logs';
    public const UPDATED_AT = null;
    protected $casts = ['case_id' => 'integer', 'action_id' => 'integer', 'claim_id' => 'integer'];

    public function case() { return $this->belongsTo(AftersalesCase::class, 'case_id'); }
}
