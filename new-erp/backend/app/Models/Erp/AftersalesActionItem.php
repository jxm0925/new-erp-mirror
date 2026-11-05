<?php

namespace App\Models\Erp;

class AftersalesActionItem extends MasterModel
{
    protected $table = 'erp_aftersales_action_items';
    protected $casts = ['action_id' => 'integer', 'case_item_id' => 'integer', 'return_qty' => 'decimal:8',
        'reship_qty' => 'decimal:8', 'reship_sku_id' => 'integer', 'reship_item_id' => 'integer'];

    public function action() { return $this->belongsTo(AftersalesAction::class, 'action_id'); }
    public function caseItem() { return $this->belongsTo(AftersalesCaseItem::class, 'case_item_id'); }
}
