<?php

namespace App\Models\Erp;

class PurchaseDefectHandlingLog extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_defect_handling_logs';

    protected $casts = ['payload' => 'array'];

    public function handling()
    {
        return $this->belongsTo(PurchaseDefectHandling::class, 'handling_id');
    }
}
