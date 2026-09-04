<?php

namespace App\Models\Erp;

class PurchaseLog extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_logs';
    protected $casts = ['evidence' => 'array'];
}
