<?php

namespace App\Models\Erp;

class CrmOpportunityOrder extends MasterModel
{
    protected $table = 'erp_crm_opportunity_orders';

    protected $casts = ['first_effective_at' => 'datetime:Y-m-d H:i:s'];

    public function opportunity() { return $this->belongsTo(CrmOpportunity::class, 'opportunity_id'); }
    public function order() { return $this->belongsTo(SalesOrder::class, 'order_id'); }
}
