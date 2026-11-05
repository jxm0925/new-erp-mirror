<?php

namespace App\Models\Erp;

class AftersalesAction extends MasterModel
{
    protected $table = 'erp_aftersales_actions';
    protected $casts = ['case_id' => 'integer', 'sales_return_id' => 'integer', 'freight_amount' => 'decimal:4',
        'compensation_amount' => 'decimal:4', 'compensated_at' => 'datetime',
        'completed_at' => 'datetime', 'cancelled_at' => 'datetime'];

    public const TYPES = ['refund', 'exchange', 'repair', 'reship', 'record'];

    public function case() { return $this->belongsTo(AftersalesCase::class, 'case_id'); }
    public function items() { return $this->hasMany(AftersalesActionItem::class, 'action_id')->orderBy('id'); }
    public function salesReturn() { return $this->belongsTo(SalesReturn::class, 'sales_return_id'); }
    public function reships() { return $this->hasMany(AftersalesReship::class, 'action_id')->orderBy('id'); }
}
