<?php

namespace App\Models\Erp;

class PurchaseReturn extends PurchaseBaseModel
{
    protected $table = 'erp_purchase_returns';

    protected $casts = [
        'return_date' => 'date:Y-m-d',
        'submitted_at' => 'datetime',
        'approved_at' => 'datetime',
        'posted_at' => 'datetime',
        'cancelled_at' => 'datetime',
        'closed_at' => 'datetime',
        'amount_excl_tax' => 'decimal:4',
        'tax_amount' => 'decimal:4',
        'amount_incl_tax' => 'decimal:4',
        'settlement_amount' => 'decimal:4',
        'cost_amount' => 'decimal:4',
    ];

    public function receipt() { return $this->belongsTo(PurchaseReceipt::class, 'source_receipt_id'); }
    public function supplier() { return $this->belongsTo(Supplier::class); }
    public function items() { return $this->hasMany(PurchaseReturnItem::class, 'return_id'); }
    public function logs() { return $this->hasMany(PurchaseReturnLog::class, 'return_id'); }
}
