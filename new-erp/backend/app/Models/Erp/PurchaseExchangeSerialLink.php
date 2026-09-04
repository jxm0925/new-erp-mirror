<?php

namespace App\Models\Erp;

class PurchaseExchangeSerialLink extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_exchange_serial_links';
    protected $casts = ['linked_at' => 'datetime'];
    public function exchangeOrder() { return $this->belongsTo(PurchaseExchangeOrder::class, 'exchange_order_id'); }
}
