<?php

namespace App\Models\Erp;

class AftersalesReship extends MasterModel
{
    protected $table = 'erp_aftersales_reships';
    protected $casts = ['case_id' => 'integer', 'action_id' => 'integer', 'sales_order_id' => 'integer',
        'carrier_id' => 'integer', 'inventory_transaction_id' => 'integer', 'package_count' => 'integer',
        'shipped_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public function case() { return $this->belongsTo(AftersalesCase::class, 'case_id'); }
    public function action() { return $this->belongsTo(AftersalesAction::class, 'action_id'); }
    public function order() { return $this->belongsTo(SalesOrder::class, 'sales_order_id'); }
    public function items() { return $this->hasMany(AftersalesReshipItem::class, 'reship_id')->orderBy('id'); }
}
