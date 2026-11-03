<?php

namespace App\Models\Erp;

class PurchaseRequestSalesLink extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_request_sales_links';
    protected $casts = ['qty' => 'decimal:8', 'source_snapshot' => 'array', 'reversed_at' => 'datetime'];
}
