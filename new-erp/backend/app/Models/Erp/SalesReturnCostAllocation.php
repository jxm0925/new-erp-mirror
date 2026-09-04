<?php

namespace App\Models\Erp;

class SalesReturnCostAllocation extends MasterModel
{
    protected $table = 'erp_sales_return_cost_allocations';

    protected $casts = [
        'allocated_base_qty' => 'decimal:8',
        'posted_base_qty' => 'decimal:8',
        'unit_cost_snapshot' => 'decimal:8',
        'cost_amount_snapshot' => 'decimal:4',
    ];

    public function salesReturnItem() { return $this->belongsTo(SalesReturnItem::class, 'sales_return_item_id'); }
    public function shipment() { return $this->belongsTo(SalesShipment::class, 'sales_shipment_id'); }
    public function shipmentLine() { return $this->belongsTo(SalesShipmentLine::class, 'sales_shipment_line_id'); }
    public function outboundTransactionItem() { return $this->belongsTo(InventoryTransactionItem::class, 'outbound_transaction_item_id'); }
}
