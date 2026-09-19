<?php

namespace App\Models\Erp;

class PurchaseReceiptAllocationPhysical extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_receipt_allocation_physicals';

    protected $casts = [
        'dimensions' => 'array',
    ];

    public function allocation() { return $this->belongsTo(PurchaseReceiptItemAllocation::class, 'allocation_id'); }
}
