<?php

namespace App\Models\Erp;

class CancellationProductionDisposition extends MasterModel
{
    protected $table = 'erp_cancellation_production_dispositions';
    protected $casts = ['history' => 'array', 'due_at' => 'datetime', 'deadline_at' => 'datetime', 'resolved_at' => 'datetime', 'business_version' => 'integer'];
}
