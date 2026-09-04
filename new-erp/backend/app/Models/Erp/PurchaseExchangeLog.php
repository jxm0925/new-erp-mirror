<?php

namespace App\Models\Erp;

class PurchaseExchangeLog extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_exchange_logs';
    protected $casts = ['payload' => 'array'];
    public function exchangeOrder() { return $this->belongsTo(PurchaseExchangeOrder::class, 'exchange_order_id'); }
}
