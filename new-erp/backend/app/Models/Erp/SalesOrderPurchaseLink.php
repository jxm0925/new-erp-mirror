<?php

namespace App\Models\Erp;

class SalesOrderPurchaseLink extends MasterModel
{
    protected $table = 'erp_sales_order_purchase_links';
    protected $casts = ['purchase_qty' => 'decimal:8', 'contract_amount' => 'decimal:4', 'source_snapshot' => 'array', 'reversed_at' => 'datetime'];
}
