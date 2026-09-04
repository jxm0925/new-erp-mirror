<?php

namespace App\Models\Erp;

class MaterialReceiptLine extends MasterModel
{
    protected $table = 'erp_material_receipt_lines';
    protected $casts = [
        'delivered_qty_snapshot' => 'decimal:8', 'accepted_qty' => 'decimal:8',
        'rejected_qty' => 'decimal:8', 'accepted_serial_snapshot' => 'array',
        'rejected_serial_snapshot' => 'array',
    ];

    public function receipt() { return $this->belongsTo(MaterialReceipt::class, 'receipt_id'); }
    public function deliveryLine() { return $this->belongsTo(MaterialDeliveryLine::class, 'delivery_line_id'); }
}
